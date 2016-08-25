<?php

namespace App\Http\Requests;

use App\Http\Requests\Request;

class LoggedInRequest extends Request {
	public function authorize() {
		if(session()->get('authenticated_member') != "true") {
			return false;
		}
		return true;
	}
	public function rules() {
		return [
			//
		];
	}
}
