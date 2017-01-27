<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

use App\Http\Requests;
use App\Models\Message;
use App\Models\User;

use Auth;

class MessageController extends Controller
{

	/*
		Cases to catch:
			Create new thread and place at top of thread list

			Unread/new messages 

		Todo
			Run chat server on php artisan serve

			email when acc is about to expire

			messages admin


	*/


	public function getMessages($threadId = '')
    {
		$chatPort = 9090;
    	$userName = Auth::user()->name;
    	$userId = Auth::user()->id;

    	if(Auth::user()->userable_type == 'App\Models\Customer'){
    		$userType = 'Customer';
    		$otherName = '';

    		$threads = Message::where('customer_id', '=', $userId)
	    		->orderBy('created_at', 'DESC')
	    		//->groupBy('breeder_id')
	    		->get()
	    		->unique('breeder_id');

	    	$tampered = false;
	    	if($threadId == '' && isset($threads[0])){
	    		$tampered = true;
	    		$threadId = $threads[0]->breeder_id;
	    	}

	    	$messages = Message::where('customer_id', '=', $userId)
	    		->where('breeder_id',  $threadId)
	    		->orderBy('created_at', 'ASC')
	    		->get();


			foreach($messages as $message){
	    		if($message->read_at == NULL){
	    			$message->read_at = date('Y-m-d H:i:s');
	    			$message->save();
	    		}
	    	}


	    	
	    	if(!$tampered && sizeof($messages) <= 0 && sizeof($threads) > 0){
	    		$user = User::where('id', $threadId)->first();
	    		if(!isset($user) || $user->userable_type != 'App\Models\Breeder')
	    			return Redirect::route('messages');
	    		$otherName = $user->name;
	    	}else if($threadId != ''){
	    		$user = User::where('id', $threadId)->first();
	    		$otherName = $user->name;
	    	}

			    	


			return view('user.customer.messages', compact("chatPort", "userName", "userId", "userType", "threads", "threadId", "messages", "otherName"));
    	}
    	elseif (Auth::user()->userable_type == 'App\Models\Breeder') {
    		$userType = 'Breeder';
    		$otherName = '';

    		$threads = Message::where('breeder_id', '=', $userId)
	    		->orderBy('created_at', 'DESC')
	    		//->groupBy('customer_id')
	    		->get()
	    		->unique('customer_id');

	    	$tampered = false;
	    	if($threadId == '' && isset($threads[0])){
	    		$tampered = true;
	    		$threadId = $threads[0]->customer_id;
	    	}

    		$messages = Message::where('breeder_id', '=', $userId)
	    		->where('customer_id',  $threadId)
	    		->orderBy('created_at', 'ASC')
	    		->get();

	    	foreach($messages as $message){
	    		if($message->read_at == NULL){
	    			$message->read_at = date('Y-m-d H:i:s');
	    			$message->save();
	    		}
	    	}

	    	
	    	if(!$tampered && sizeof($messages) <= 0 && sizeof($threads) > 0){
	    		$user = User::where('id', $threadId)->first();
	    		if(!isset($user) || $user->userable_type == 'App\Models\Customer')
	    			return Redirect::route('messages');
	    		$otherName = $user->name;
	    	}else if($threadId != ''){
	    		$user = User::where('id', $threadId)->first();
	    		$otherName = $user->name;
	    	}

			return view('user.breeder.messages', compact("chatPort", "userName", "userId", "userType", "threads", "threadId", "messages", "otherName"));
    	}
    	else{
    		return 'Unsupported user type.';
    	}

    }

    public function countUnread(){
    	$userId = Auth::user()->id;
    	if(Auth::user()->userable_type == 'App\Models\Customer'){
    		$count = Message::where('customer_id', '=', $userId)
				->where('read_at', NULL) 
				->where('direction', 1) //from breeder to customer
	    		->orderBy('created_at', 'ASC')
	    		->groupBy('breeder_id')
	    		->get();
    	}
    	else{
	    	$count = Message::where('breeder_id', '=', $userId)
				->where('read_at', NULL) 
				->where('direction', 0) //from customer to breeder
	    		->orderBy('created_at', 'ASC')
	    		->groupBy('customer_id')
	    		->get();
    		
    	}


    	return sizeof($count);
    }

}