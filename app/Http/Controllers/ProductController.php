<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Models\Breeder;
use App\Models\FarmAddress;
use App\Models\Product;
use App\Models\Image;
use App\Models\Video;
use App\Models\Breed;
use Auth;
use Storage;

class ProductController extends Controller
{
    protected $user;

	/**
     * Create new BreederController instance
     */
    public function __construct()
    {
        $this->middleware('role:breeder', ['only' => ['showProducts', 'storeProducts', 'uploadMedia', 'deleteMedium', 'productSummary', 'setPrimaryPicture', 'showcaseProduct']]);
        $this->middleware('updateProfile:breeder',['only' => ['showProducts', 'storeProducts', 'uploadMedia', 'deleteMedium', 'productSummary', 'setPrimaryPicture', 'showcaseProduct']]);
        $this->middleware('role:customer',['only' => ['viewProducts','viewProductDetail']]);
        $this->middleware('updateProfile:customer',['only' => ['viewProducts','viewProductDetail']]);
        $this->user = Auth::user();
    }

    /**
     * ---------------------------------------
     *	BREEDER-SPECIFIC METHODS
     * ---------------------------------------
     */

    /**
     * Show the Breeder's products
     *
     * @return View
     */
    public function showProducts()
    {
        $breeder = $this->user->userable;
        $products = $breeder->products()->where('status_instance','active')->orderBy('id', 'desc')->get();
        $farms = $breeder->farmAddresses;

        foreach ($products as $product) {
            $product->img_path = '/images/product/'.Image::find($product->primary_img_id)->name;
            $product->type = ucfirst($product->type);
            $product->breed = $this->transformBreedSyntax(Breed::find($product->breed_id)->name);
            $product->other_details = $this->transformOtherDetailsSyntax($product->other_details);
        }

        return view('user.breeder.showProducts', compact('products', 'farms'));
    }

    /**
     * Store the Breeder's products
     * AJAX
     *
     * @param Request $request
     * @return JSON
     */
    public function storeProducts(Request $request)
    {
        $breeder = $this->user->userable;
        if($request->ajax()){
            $product = new Product;
            $productDetail= [];

            // Create default primary picture for product
            if($request->type == 'boar') $image = Image::firstOrCreate(['name' => 'boar_default.jpg']);
            else if($request->type == 'sow') $image = Image::firstOrCreate(['name' => 'sow_default.jpg']);
            else $image = Image::firstOrCreate(['name' => 'semen_default.jpg']);

            $product->farm_from_id = $request->farm_from_id;
            $product->primary_img_id = $image->id;
            $product->name = $request->name;
            $product->type = $request->type;
            $product->age = $request->age;
            $product->breed_id = $this->findOrCreateBreed(strtolower($request->breed));
            $product->price = $request->price;
            $product->quantity = $request->quantity;
            $product->adg = $request->adg;
            $product->fcr = $request->fcr;
            $product->backfat_thickness = $request->backfat_thickness;
            $product->other_details = $request->other_details;
            $breeder->products()->save($product);

            $productDetail['product_id'] = $product->id;
            $productDetail['name'] = $product->name;
            $productDetail['type'] = ucfirst($request->type);
            $productDetail['breed'] = $request->breed;

            return collect($productDetail)->toJson();
        }

    }

    /**
     * Upload media for a product
     *
     * @param Request $request
     * @return JSON
     */
    public function uploadMedia(Request $request)
    {
        // Check if request contains media files
        if($request->hasFile('media')) {
            $files = $request->file('media.*');
            $fileDetails = [];

            foreach ($files as $file) {

                // Check if file has no problems in uploading
                if($file->isValid()){
                    $fileExtension = $file->getClientOriginalExtension();
                    $originalName = $file->getClientOriginalName();

                    // Get media (Image/Video) info according to extension
                    if($this->isImage($fileExtension)) $mediaInfo = $this->createMediaInfo($fileExtension, $request->productId, $request->type, $request->breed, $originalName);
                    else if($this->isVideo($fileExtension)) $mediaInfo = $this->createMediaInfo($fileExtension, $request->productId, $request->type, $request->breed, $originalName);

                    Storage::disk('public')->put($mediaInfo['directoryPath'].$mediaInfo['filename'], file_get_contents($file));

                    // Check if file is successfully moved to desired path
                    if($file){
                        $product = Product::find($request->productId);

                        // Make Image/Video instance
                        $media = $mediaInfo['type'];
                        $media->name = $mediaInfo['filename'];

                        if($this->isImage($fileExtension)) $product->images()->save($media);
                        else if($this->isVideo($fileExtension)) $product->videos()->save($media);

                        array_push($fileDetails, ['id' => $media->id, 'name' => $mediaInfo['filename']]);
                    }
                    else return response()->json('Move file failed', 500);
                }
                else return response()->json('Upload failed', 500);
            }

            return response()->json(collect($fileDetails)->toJson(), 200);
        }
        else return response()->json('No files detected', 500);
    }

    /**
     * Delete and Image of a Product
     * AJAX
     *
     * @param Request $request
     * @return JSON
     */
    public function deleteMedium(Request $request)
    {
        if($request->ajax()){
            if($request->mediaType == 'image'){
                $image = Image::find($request->mediaId);
                // Check if file exists in the storage
                if(Storage::disk('public')->exists('/images/product/'.$image->name)){
                    $fullFilePath = '/images/product/'.$image->name;
                    Storage::disk('public')->delete($fullFilePath);
                }
                $image->delete();
            }
            else if($request->mediaType = 'video'){
                $video = Video::find($request->mediaId);
                if(Storage::disk('public')->exists('/videos/product/'.$video->name)){
                    $fullFilePath = '/videos/product/'.$video->name;
                    Storage::disk('public')->delete($fullFilePath);
                }
                $video->delete();
            }

            return response()->json('File deleted', 200);
        }
    }

    /**
     * Get summary of Product
     *
     * @param Request $request
     * @return JSON
     */
    public function productSummary(Request $request)
    {
        if($request->ajax()){
            $product = Product::find($request->product_id);
            $product->type = ucfirst($product->type);
            $product->breed = $this->transformBreedSyntax(Breed::find($product->breed_id)->name);
            $product->farm_province = FarmAddress::find($product->farm_from_id)->province;
            $product->imageCollection = $product->images;
            $product->videoCollection = $product->videos;

            return $product->toJson();
        }
    }

    /**
     * Set the primary picture of a Product
     *
     * @param Request $request
     * @return String
     */
    public function setPrimaryPicture(Request $request)
    {
        if($request->ajax()){
            $product = Product::find($request->product_id);
            $product->primary_img_id = $request->img_id;
            $product->save();

            return "OK";
        }
    }

    /**
     * Showcase Product
     *
     * @param Request $request
     * @return String
     */
    public function showcaseProduct(Request $request)
    {
        if($request->ajax()){
            $product = Product::find($request->product_id);
            $product->status = 'showcased';
            $product->save();

            return "OK";
        }
    }

    /**
     * ---------------------------------------
     *  CUSTOMER-SPECIFIC METHODS
     * ---------------------------------------
     */

    /**
     * View Products of all Breeders
     *
     * @return View
     */
    public function viewProducts(Request $request)
    {
        // Check if empty search parameters
        if (!$request->type && !$request->breed){
            if($request->sort && $request->sort != 'none'){
                $part = explode('-',$request->sort);
                $products = Product::where('status','showcased')->orderBy($part[0], $part[1])->paginate(10);
            }
            else $products = Product::where('status','showcased')->paginate(10);
        }
        else{
            if($request->type) $products = Product::where('status','showcased')->whereIn('type', explode(' ',$request->type));
            if($request->breed) {
                $breedIds = $this->getBreedIds($request->breed);
                if(!$request->type) $products = Product::where('status','showcased')->whereIn('breed_id', $breedIds);
                else $products = $products->whereIn('breed_id', $breedIds);
            }
            if($request->sort) {
                if($request->sort != 'none'){
                    $part = explode('-',$request->sort);
                    $products = $products->orderBy($part[0], $part[1]);
                }
            }
            $products = $products->paginate(10);
        }

        $filters = $this->parseThenJoinFilters($request->type, $request->breed, $request->sort);
        $breedFilters = Breed::where('name','not like', '%+%')->where('name','not like', '')->orderBy('name','asc')->get();
        $urlFilters = $this->toUrlFilter($request->type, $request->breed, $request->sort);

        foreach ($products as $product) {
            $product->img_path = '/images/product/'.Image::find($product->primary_img_id)->name;
            $product->type = ucfirst($product->type);
            $product->breed = $this->transformBreedSyntax(Breed::find($product->breed_id)->name);
            $product->breeder = Breeder::find($product->breeder_id)->users()->first()->name;
            $product->farm_province = FarmAddress::find($product->farm_from_id)->province;
        }

        return view('user.customer.viewProducts', compact('products', 'filters', 'breedFilters', 'urlFilters'));
    }

    /**
     * View Details of a Product
     *
     * @return View
     */
    public function viewProductDetail($productId)
    {
        $product = Product::find($productId);
        $product->img_path = '/images/product/'.Image::find($product->primary_img_id)->name;
        $product->breeder = Breeder::find($product->breeder_id)->users->first()->name;
        $product->type = ucfirst($product->type);
        $product->breed = $this->transformBreedSyntax(Breed::find($product->breed_id)->name);
        $product->farm_province = FarmAddress::find($product->farm_from_id)->province;
        $product->other_details = $this->transformOtherDetailsSyntax($product->other_details);
        return view('user.customer.viewProductDetail', compact('product'));
    }


    /**
     * ---------------------------------------
     *  PRIVATE METHODS
     * ---------------------------------------
     */

    /**
     * Find breed_id through breed name ($breed)
     * or create another breed if not found
     *
     * @param String $Breed
     * @return Integer
     */
    private function findOrCreateBreed($breed)
    {
        $breedInstance = Breed::where('name','like',$breed)->get()->first();
        if($breedInstance) return $breedInstance->id;
        else{
            $newBreed = Breed::create(['name' => $breed]);
            return $newBreed->id;
        }
    }

    /**
     * Get appropriate media (Image/Video) info depending on extension
     *
     * @param String $extension
     * @return Associative Array $mediaInfo
     */
    private function createMediaInfo($extension, $productId, $type, $breed, $originalName)
    {
        $mediaInfo = [];

        if($this->isImage($extension)){
            $mediaInfo['directoryPath'] = '/images/product/';
            $mediaInfo['filename'] = $productId . '_' . $type . '_' . $breed . str_random(6) . '.' . $extension;
            $mediaInfo['type'] = new Image;
        }

        else if($this->isVideo($extension)){
            $mediaInfo['directoryPath'] = '/videos/product/';
            $mediaInfo['filename'] = $productId . '_' . $type . '_' . $breed . str_random(6) . '.' . $extension;
            $mediaInfo['type'] = new Video;
        }

        return $mediaInfo;

    }

    /**
     * Check if media is Image depending on extension
     *
     * @param String $extension
     * @return Boolean
     */
    private function isImage($extension)
    {
        return ($extension == 'jpg' || $extension == 'jpeg' || $extension == 'png') ? true : false;
    }

    /**
     * Check if media is Video depending on extension
     *
     * @param String $extension
     * @return Boolean
     */
    private function isVideo($extension)
    {
        return ($extension == 'mp4' || $extension == 'mkv' || $extension == 'avi' || $extension == 'flv') ? true : false;
    }

    /**
     * Parse the Filters according to Type, Breed, and Sort By
     *
     * @param   $typeParameter String
     * @param   $breedParameter String
     * @param   $sortParameter String
     * @return  Assocative Array
     */
    private function parseThenJoinFilters($typeParameter, $breedParameter, $sortParameter)
    {
        $tempFilters = [];

        if($typeParameter){
            // Parse if there is more than one type filter value
            $types = explode(' ',$typeParameter);
            foreach ($types as $type) {
                $tempFilters[$type] = 'checked';
            }
        }

        if($breedParameter){
            // Parse if there is more than one breed filter value
            $breeds = explode(' ',$breedParameter);
            foreach ($breeds as $breed) {
                $tempFilters[$breed] = 'checked';
            }
        }

        $tempFilters[$sortParameter] = 'selected';

        return $tempFilters;
    }

    /**
     * Parse the Filters according to Type, Breed, and Sort By
     *
     * @param   $typeParameter String
     * @param   $breedParameter String
     * @param   $sortParameter String
     * @return  Assocative Array
     */
    private function toUrlFilter($typeParameter, $breedParameter, $sortParameter)
    {
        $tempUrlFilters = [];

        if($typeParameter)  $tempUrlFilters['type'] = $typeParameter;
        if($breedParameter) $tempUrlFilters['breed'] = $breedParameter;
        if($sortParameter) $tempUrlFilters['sort'] = $sortParameter;

        return $tempUrlFilters;
    }

    /**
     * Get breed ids of products based from breed filter value
     *
     * @param   $breedParameter String
     * @return  Array
     */
    private function getBreedIds($breedParameter)
    {
        $tempBreedIds = [];
        foreach (explode(' ', $breedParameter) as $breedName) {
            if($breedName == 'crossbreed') {
                // Get all breed ids that contain '+' in their breed name
                $crossbreeds = Breed::where('name','like','%+%')->get();
                foreach ($crossbreeds as $crossbreed) {
                    array_push($tempBreedIds, $crossbreed->id);
                }
                continue;
            }
            else $breedInstance = Breed::where('name',$breedName)->get()->first()->id;
            array_push($tempBreedIds, $breedInstance);
        }

        // dd($tempBreedIds);
        return $tempBreedIds;
    }

    /**
     * Parse $breed if it contains '+' (ex. landrace+duroc)
     * to "Landrace x Duroc"
     *
     * @param  String $breed
     * @return String
     */
    private function transformBreedSyntax($breed)
    {
        if(str_contains($breed,'+')){
            $part = explode("+", $breed);
            $breed = ucfirst($part[0])." x ".ucfirst($part[1]);
            return $breed;
        }

        return ucfirst($breed);
    }

    /**
     * Parse $other_details
     *
     * @param String $otherDetails
     * @return String
     */
    private function transformOtherDetailsSyntax($otherDetails)
    {
        $details = explode(',',$otherDetails);
        $transformedSyntax = '';
        foreach ($details as $detail) {
            $transformedSyntax .= $detail."<br>";
        }
        return $transformedSyntax;
    }


}