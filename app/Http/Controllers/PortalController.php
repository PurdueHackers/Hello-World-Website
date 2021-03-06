<?php
	
/*
	@ Harris Christiansen (Harris@HarrisChristiansen.com)
	2016-04-25
	Project: Members Tracking Portal
*/

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Twilio\Rest\Client;
use DB;
use Mail;
use App\Http\Controllers\Controller;

use App\Http\Requests;
use App\Http\Requests\LoggedInRequest;
use App\Http\Requests\EditMemberRequest;
use App\Http\Requests\AdminRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\EditEventRequest;

use App\Models\Application;
use App\Models\Event;
use App\Models\Location;
use App\Models\LocationRecord;
use App\Models\Major;
use App\Models\Member;
use App\Models\Project;

class PortalController extends Controller {
	
	/////////////////////////////// Home ///////////////////////////////
    
    public function getIndex() {
		return view('pages.home');
	}
	
	public function getResources() {
		return view('pages.resources');
	}
	
	public function getLive() {
		return view('pages.live');
	}
	
	public function getDayof() {
		return view('pages.live');
	}
	
	/////////////////////////////// Authentication ///////////////////////////////
	
	public function getLogin() {
		return view('pages.login');
	}

	public function postLogin(Request $request) {
		$email = $request->input('email');
		$password = $request->input('password');
		$passwordMD5 = md5($password);
		
		if($email == "") {
			$request->session()->flash('msg', 'Please enter an email.');
			return $this->getLogin();
		} else {
			$matchingMembers = Member::where('email',$email)->orWhere('email_public', $email)->orWhere('email_edu', $email)->get();
			
			if(count($matchingMembers) == 0) {
				$request->session()->flash('msg', 'No account was found with that email.');
				return $this->getLogin();
			}
			
			foreach($matchingMembers as $member) {
				if(Hash::check($password, $member->password) || $member->password == $passwordMD5) {
					$this->setAuthenticated($request, $member->id, $member->name);
					
					if (Hash::needsRehash($member->password)) { // Check If Password Needs Rehash
						$member->password = Hash::make($password);
					}
					
					$member->authenticated_at = Carbon::now();
					
					if ($member->admin) { // Admin Accounts
						$request->session()->put('authenticated_admin', 'true');
					}
					
					$member->timestamps = false; // Don't update timestamps
					$member->save();
					return $this->getIndex();
				}
			}
			
			// If gets here, no account matched password
			$request->session()->flash('msg', 'Invalid password.');
			return $this->getLogin();
		}

		return $this->getLogin();
	}

	public function getLogout(Request $request) {
		$request->session()->put('member_id',"");
		$request->session()->put('member_name',"");
		$request->session()->put('authenticated_member', 'false');
		$request->session()->put('authenticated_admin', 'false');

		return $this->getIndex();
	}
	
	public function getJoin() { // GET Register
		return view('pages.register');
	}
	
	public function postJoin(RegisterRequest $request) { // POST Register
		$memberName = $request->input('memberName');
		$email = $request->input('email');
		$password = $request->input('password');
		$password_confirm = $request->input('confirmPassword');
		$gradYear = $request->input('gradYear');
		
		if($memberName=="" || $email=="" || $password=="" || $gradYear=="") {
			$request->session()->flash('msg', 'Please enter all fields.');
			return $this->getJoin();
		}
		
		if(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$request->session()->flash('msg', 'Invalid Email Address.');
			return $this->getJoin();
		}
		
		if($password != $password_confirm) {
			$request->session()->flash('msg', 'Passwords did not match.');
			return $this->getJoin();
		}
		
		if(Member::where('email',$email)->first()) {
			$request->session()->flash('msg', 'An account already exists with that email. Please use your '.env('DB_ORG_NAME').' account password if you have one.');
			return $this->getLogin();
		}
		
		// Create Member
		$member = new Member;
		$member->name = $memberName;
		$member->email = $email;
		$member->password = Hash::make($password);
		if(strpos($email, ".edu") !== false) {
			$member->email_edu = $email;
		}
		$member->graduation_year = $gradYear;
		$member->save();
		
		// Authenticate Application
		$this->setAuthenticated($request, $member->id, $member->name);
		
		return $this->getApply($request,1);
	}
	
	public function isAuthenticated($request) {
		return $request->session()->get('authenticated_member') == "true";
	}
	
	public function isAdmin($request) {
		return $request->session()->get('authenticated_admin') == "true";
	}
	
	public function getAuthenticated($request) {
		if ($this->isAuthenticated($request)) {
			return Member::find($this->getAuthenticatedID($request));
		}
		return null;
	}
	
	public function getAuthenticatedID($request) {
		if ($this->isAuthenticated($request)) {
			return $request->session()->get('member_id');
		}
		return null;
	}
	
	public function setAuthenticated(Request $request, $memberID, $memberName) {
		$request->session()->put('authenticated_member', 'true');
		$request->session()->put('member_id', $memberID);
		$request->session()->put('member_name', $memberName);
		$request->session()->flash('msg', "Welcome $memberName!");
	}
	
	/////////////////////////////// Resource Pages ///////////////////////////////
    
    public function getAnvilWifi() {
		return view('pages.anvilWifi');
	}
	
	/////////////////////////////// Viewing Members ///////////////////////////////
	
	public function getMembers() {
		$members = Member::with('events')->get()->sortBy(function($member, $key) {
			return sprintf('%04d',1000-$member->publicEventCount())."_".$member->name;
		});
		
		return view('pages.members',compact("members"));
	}
	
	public function getMembersGraphs(AdminRequest $request) {
		$members = Member::orderBy('created_at')->get();
		
		// Join Dates
		$joinDates = $this->graphDataJoinDates($members);
		
		// Member Graduation Year
		$memberYears = $this->graphDataMemberYears($members);
		
		// Major
		$majorsData = $this->graphDataMajor($members);
		
		return view('pages.members-graphs',compact("members","joinDates","memberYears","majorsData"));
	}
	
	public function getMembersAutocomplete(AdminRequest $request, $eventID=0) {
		$requestTerm = $request->input('term');
		$searchFor = "%".$requestTerm.'%';
		
		$members = Member::where('name','LIKE',$searchFor)->orWhere('email','LIKE',$searchFor)->orWhere('email_public','LIKE',$searchFor)->orWhere('email_edu','LIKE',$searchFor)->orWhere('phone','LIKE',$searchFor)->orWhere('description','LIKE',$searchFor)->get();
		
		if ($eventID != 0) {
			$event = Event::findOrFail($eventID);
		}
		
		$results = [];
		$numResults = count($members);
		for($i=0;$i<$numResults;$i++) {
			$results[$i]['value'] = $members[$i]->name;
			$results[$i]['name'] = $members[$i]->name;
			$results[$i]['email'] = $members[$i]->email;
			$results[$i]['phone'] = $members[$i]->phone;
			$results[$i]['attended'] = count($members[$i]->events);
			$results[$i]['graduation_year'] = $members[$i]->graduation_year;
			if ($numResults<=10 && $eventID!=0 && $event->requiresApplication) {
				$results[$i]['registered'] = count($event->applications()->where('member_id',$members[$i]->id)->get());
			}
		}

		return $results;
	}
	
	public function getMember(Request $request, $memberID) {
		$member = Member::find($memberID);
		
		if(is_null($member)) {
			$request->session()->flash('msg', 'Error: Member Not Found.');
			return $this->getMembers();
		}
		
		$locations = $member->locations;
		$events = $member->events;
		$majors = Major::orderByRaw('(id = 1) DESC, name')->get(); // Order by name, but keep first major at top
		
		return view('pages.member',compact("member","locations","events","majors"));
	}
	
	/////////////////////////////// Editing Members ///////////////////////////////
	
	public function postMember(EditMemberRequest $request, $memberID) {
		$member = Member::find($memberID);
		
		$memberName = $request->input('memberName');
		$password = $request->input('password');
		$email = $request->input('email');
		$phone = $request->input('phone');
		$email_public = $request->input('email_public');
		$gradYear = $request->input('gradYear');
		$gender = $request->input('gender');
		$major = $request->input('major');
		$description = $request->input('description');
		$facebook = $request->input('facebook');
		$github = $request->input('github');
		$linkedin = $request->input('linkedin');
		$devpost = $request->input('devpost');
		$website = $request->input('website');
		
		// Verify Input
		if(is_null($member)) {
			$request->session()->flash('msg', 'Error: Member Not Found.');
			return $this->getMembers();
		}
		if($email != $member->email && Member::where('email',$email)->first()) {
			$request->session()->flash('msg', 'An account already exists with that email.');
			return $this->getMember($request, $memberID);
		}
		
		//// Edit Member ////
		$member->name = $memberName;
		
		// Password
		if(strlen($password) > 0) {
			$member->password = Hash::make($password);
			$this->setAuthenticated($request,$member->id,$member->name);
			
			if ($member->admin) { // Admin Accounts
				$request->session()->put('authenticated_admin', 'true');
			}
		}
		
		// Email
		$member->email = $email;
		if(strpos($email, ".edu") !== false) {
			$member->email_edu = $email;
		}
		$member->email_public = $email_public;
		if(strpos($email_public, ".edu") !== false) {
			$member->email_edu = $email_public;
		}
		
		// Text Fields
		$member->phone = $phone;
		$member->graduation_year = $gradYear;
		$member->gender = $gender;
		if ($major > 0) {
			$member->major_id = $major;
		}
		$member->description = $description;
		$member->facebook = $facebook;
		$member->github = $github;
		$member->linkedin = $linkedin;
		$member->devpost = $devpost;
		$member->website = $website;
		
		// Picture
		if ($request->hasFile('picture')) {
			$picture = $request->file('picture');
			if ($picture->isValid() && (strtolower($picture->getClientOriginalExtension())=="jpg" ||
			  strtolower($picture->getClientOriginalExtension())=="png") && (strtolower($picture->getClientMimeType())=="image/jpeg" ||
			  strtolower($picture->getClientMimeType())=="image/jpg" || strtolower($picture->getClientMimeType())=="image/png")) {
				$fileName = $picture->getClientOriginalName();
				$uploadPath = 'uploads/member_pictures/'; // base_path().'/public/uploads/member_pictures/
				$fileName_disk = $member->id."_".substr(md5($fileName), -6).".".$picture->getClientOriginalExtension();
				$picture->move($uploadPath, $fileName_disk);
				$member->picture = $fileName;
			}
		}
		
		// Resume
		if ($request->hasFile('resume')) {
			$resume = $request->file('resume');
			if ($resume->isValid() && strtolower($resume->getClientOriginalExtension())=="pdf" && strtolower($resume->getClientMimeType())=="application/pdf") {
				$fileName = $resume->getClientOriginalName();
				$uploadPath = 'uploads/resumes/'; // base_path().'/public/uploads/resumes/
				$fileName_disk = $member->id."_".substr(md5($fileName), -6).".".$resume->getClientOriginalExtension();
				$resume->move($uploadPath, $fileName_disk);
				$member->resume = $fileName;
			}
		}
		
		
		$member->save();
		
		// Return Response
		$request->session()->flash('msg', 'Profile Saved!');
		return $this->getMember($request, $memberID);
	}
	
	/////////////////////////////// Password Reset ///////////////////////////////
	
	public function getRequestReset(Request $request) {
		return view('pages.reset');
	}
	
	public function postRequestReset(Request $request) {
		$email = $request->input('email');
		
		$member = Member::where('email',$email)->first();
		
		if ($member == NULL) {
			$request->session()->flash('msg', 'No account was found with that email!');
			return $this->getRequestReset($request);
		}
		
		$this->emailResetRequest($member);
		
		$request->session()->flash('msg', 'A link to reset your password has been sent to your email!');
		return $this->getRequestReset($request);
	}
	
	public function emailResetRequest($member) {
		Mail::send('emails.resetRequest', ['member'=>$member], function ($message) use ($member) {
			$message->from('purduehackers@gmail.com', 'Purdue Hackers');
			$message->to($member->email);
			$message->subject("Reset your Purdue Hackers account password");
		});
	}
	
	public function getReset(Request $request, $memberID, $reset_token) {
		$member = Member::find($memberID);
		
		if(is_null($member)) {
			$request->session()->flash('msg', 'Error: Member Not Found.');
			return $this->getIndex();
		}
		
		if($reset_token != $member->reset_token()) {
			$request->session()->flash('msg', 'Error: Member Not Found.');
			return $this->getIndex();
		}
		
		$locations = $member->locations;
		$events = $member->events;
		$majors = Major::orderByRaw('(id = 1) DESC, name')->get(); // Order by name, but keep first major at top
		$setPassword = true;
		
		return view('pages.member',compact("member","locations","events","majors","setPassword","reset_token"));
	}
	
	
	/////////////////////////////// Viewing Locations ///////////////////////////////
	
	public function getLocations() {
		$locations = Location::all();
		return view('pages.locations',compact("locations"));
	}
	
	public function getMap() {
		$locations = Location::all();
		return view('pages.map',compact("locations"));
	}
	
	public function getMapData() {
		$locations = Location::all();
		for($i=0;$i<count($locations);$i++) {
			$locations[$i]['members'] = $locations[$i]->members()->count();
		}
		return $locations;
	}
	
	public function getLocationsAutocomplete(LoggedInRequest $request) {
		$requestTerm = $request->input('term');

		$searchFor = "%".$requestTerm.'%';
		$results = Location::where('name','LIKE',$searchFor)->get();
		
		for($i=0;$i<count($results);$i++) {
			$results[$i]['value'] = $results[$i]['name'];
		}

		return $results;
	}
	
	public function getCitiesAutocomplete(LoggedInRequest $request) {
		$requestTerm = $request->input('term');

		$searchFor = "%".$requestTerm.'%';
		$results = Location::where('city','LIKE',$searchFor)->get();
		
		for($i=0;$i<count($results);$i++) {
			$results[$i]['value'] = $results[$i]['city'];
		}

		return $results;
	}
	
	public function getLocation($locationID) {
		$location = Location::find($locationID);
		
		if(is_null($location)) {
			$request->session()->flash('msg', 'Error: Location Not Found.');
			return $this->getLocations();
		}
		
		$members = $location->members;
		
		return view('pages.location',compact("location","members"));
	}
	
	/////////////////////////////// Editing Locations ///////////////////////////////
	
	public function postLocation(AdminRequest $request, $locationID) {
		$location = Location::find($locationID);
		
		if(is_null($location)) {
			$request->session()->flash('msg', 'Error: Location Not Found.');
			return $this->getLocations();
		}
		
		$location->name = $request->input('locationName');
		$location->city = $request->input('city');
		$location->save();
		
		return $this->getLocation($locationID);
	}
	
	public function postLocationRecordNew(LoggedInRequest $request, $memberID) {
		$locationName = $request->input("locationName");
		$city = $request->input("city");
		$date_start = $request->input("date_start");
		$date_end = $request->input("date_end");
		
		$member = Member::find($memberID);
		$authenticated_id = $request->session()->get('member_id');
		
		if(is_null($member)) {
			$request->session()->flash('msg', 'Error: Member Not Found.');
			return $this->getMembers();
		}
		if($request->session()->get('authenticated_admin') != "true" && $memberID!=$authenticated_id) {
			$request->session()->flash('msg', 'Error: Member Not Found.');
			return $this->getMembers();
		}
		
		$location = Location::firstOrCreate(['name'=>$locationName, 'city'=>$city]);
		
		if($location->loc_lat==0) {
			$this->addLocationLatLng($location);
		}
		
		$locationRecord = new LocationRecord;
		$locationRecord->member_id = $memberID;
		$locationRecord->location_id = $location->id;
		$locationRecord->date_start = new Carbon($date_start);
		$locationRecord->date_end = new Carbon($date_end);
		$locationRecord->save();
		
		$request->session()->flash('msg', 'Location Record Added!');
		return $this->getMember($request, $memberID);
	}
	
	public function addLocationLatLng($location) {
		// Get Correct Latitude / Longitude of Location from Google Places API
		$requestQuery = htmlentities(urlencode($location->name." ".$location->city));
		$requestResult = json_decode(file_get_contents('https://maps.googleapis.com/maps/api/place/textsearch/json?query='.$requestQuery.'&key='.env('KEY_GOOGLESERVER')), true);
		
		if (count($requestResult["results"]) > 0) {
			$location->loc_lat = $requestResult["results"][0]["geometry"]["location"]["lat"];
			$location->loc_lng = $requestResult["results"][0]["geometry"]["location"]["lng"];
		}
		
		$location->save();
	}
	
	public function getLocationRecordDelete(LoggedInRequest $request, $recordID) {
		$locationRecord = LocationRecord::find($recordID);
		$authenticated_id = $request->session()->get('member_id');
		
		if(is_null($locationRecord)) {
			$request->session()->flash('msg', 'Error: Location Record not Found.');
			return $this->getMembers();
		}
		if($request->session()->get('authenticated_member') != "true" && $locationRecord->member->id != $authenticated_id) {
			$request->session()->flash('msg', 'Error: Location Record not Found.');
			return $this->getMembers();
		}
		
		$return_memberID = $locationRecord->member->id;
		$locationRecord->delete();
		
		return redirect()->action('PortalController@getMember', [$return_memberID])->with('msg', 'Location Record Deleted!');
	}
	
	
	/////////////////////////////// Viewing Events ///////////////////////////////
	
	public function getEvents(Request $request) {
		if ($this->isAdmin($request)) {
			$events = Event::orderBy("event_time","desc")->get();
		} else {
			$events = Event::where('privateEvent',false)->orderBy("event_time","desc")->get();
		}
		$checkin = false;
		return view('pages.events',compact("events","checkin"));
	}
	
	public function getEvent(Request $request, $eventID) {
		$event = Event::findOrFail($eventID);
		
		$members = $event->members;
		
		foreach ($members as $member) { // Pre-calculate names of users who checked student in
			$recorded_member = Member::find($member->events()->find($eventID)->pivot->recorded_by);
			$member->recorded_by = $recorded_member;
		}
		
		$requiresApplication = $this->isAuthenticated($request) && $event->requiresApplication;
		$authenticatedMember = $this->getAuthenticated($request);
		if ($authenticatedMember != null) {
			$hasRegistered = count($authenticatedMember->applications()->where('event_id',$eventID)->get()) > 0;
		}
		
		$applications = []; // Get list of applications (if admin)
		if ($request->session()->get('authenticated_admin') == "true") {
			$applications = $event->applications;
		}
		
		return view('pages.event', compact("event","members","requiresApplication","hasRegistered","applications"));
	}
	
	public function getEventNew() {
		$event = new Event;
		$event->id = 0;
		$members = [];
		$requiresApplication = false;
		$hasRegistered = false;
		$applications = [];
		return view('pages.event', compact("event","members","requiresApplication","hasRegistered","applications"));
	}
	
	public function getEventGraphs(AdminRequest $request, $eventID) {
		$event = Event::findOrFail($eventID);
		
		$members = $event->members;
		if(count($members) == 0) {
			$members = $event->getAppliedMembers();
		}
		
		// Join Dates
		$joinDates = $this->graphDataJoinDates($members);
		
		// Member Graduation Year
		$memberYears = $this->graphDataMemberYears($members);
		
		// Major
		$majorsData = $this->graphDataMajor($members);
		
		return view('pages.event-graphs', compact("event","joinDates","memberYears","majorsData"));
	}
	
	/////////////////////////////// Editing Events ///////////////////////////////
	
	public function postEvent(EditEventRequest $request, $eventID) {
		$eventName = $request->input("eventName");
		$eventPrivate = $request->input("privateEvent")=="true" ? true : false;
		$requiresApplication = $request->input("requiresApplication")=="true" ? true : false;
		$eventDate = $request->input("date");
		$eventHour = $request->input("hour");
		$eventMinute = $request->input("minute");
		$eventLocation = $request->input("location");
		$eventFB = $request->input("facebook");
		
		if($eventID == 0) {
			$event = new Event;
		} else {
			$event = Event::find($eventID);
		}
		
		// Verify Input
		if(is_null($event)) {
			$request->session()->flash('msg', 'Error: Event Not Found.');
			return $this->getEvents($request);
		}
		
		// Edit Event
		$event->name = $eventName;
		$event->privateEvent = $eventPrivate;
		$event->requiresApplication = $requiresApplication;
		$event->event_time = new Carbon($eventDate." ".$eventHour.":".$eventMinute);
		$event->location = $eventLocation;
		$event->facebook = $eventFB;
		$event->save();
		
		// Return Response
		if($eventID == 0) { // New Event
			return redirect()->action('PortalController@getEvent', [$event->id])->with('msg', 'Event Created!');
		} else {
			$request->session()->flash('msg', 'Event Updated!');
			return $this->getEvent($request, $eventID);
		}
	}
	
	public function getEventDelete(AdminRequest $request, $eventID) {
		Event::findOrFail($eventID)->delete();
		
		return redirect()->action('PortalController@getEvents')->with('msg', 'Event Deleted! If this was done by mistake, contact the site administrator to restore this event.');
	}
	
	/////////////////////////////////// Event Emails ///////////////////////////////////
	
	public function getEventMessage(AdminRequest $request, $eventID) {
		$event = Event::findOrFail($eventID);
		
		return view('pages.event-message', compact("event"));
	}
	
	public function postEventMessage(AdminRequest $request, $eventID) {
		$event = Event::findOrFail($eventID);
		
		$method = $request->input("method");
		$subject = $request->input("subject");
		$msg = nl2br(e($request->input("message")));
		$target = $request->input("target");
		
		// Get Recipient Members
		$members = null;
		if ($target == "all") {
			$members = Member::all();
		} elseif ($target == "both") {
			$members_att = $event->members;
			$members_reg = $event->getAppliedMembers();
			$members = $members_att->merge($members_reg)->all();
		} elseif ($target == "att") {
			$members = $event->members;
		} elseif ($target == "reg") {
			$members = $event->getAppliedMembers();
		} elseif ($target == "not") {
			$members_all = Member::all();
			$members_reg = $event->getAppliedMembers();
			$members = $members_all->diff($members_reg)->all();
		} else {
			$members = $event->members;
		}
		
		$members_copy = [$this->getAuthenticated($request), Member::find(1)];
		$members = collect($members)->merge($members_copy)->unique()->all();
		
		// Send Messages to Recipients
		foreach ($members as $member) {
			// Fill Placeholders
			$placeholder_values = [
				'{{name}}' => $member->name,
				'{{setpassword}}' => $member->reset_url(),
				'{{register}}' => $member->apply_url($event->id),
				'{{link}}' => '<a href="',
				'{{link-text}}' => '">',
				'{{/link}}' => '</a>',
			];
			$memberMsg = str_replace(array_keys($placeholder_values), array_values($placeholder_values), $msg);
			
			// Send Message
			if ($method == "email") { // Send Email
				if (in_array($member, $members_copy)) {
					$this->sendEmail($member, "COPY: ".$subject, $memberMsg);
				} else {
					$this->sendEmail($member, $subject, $memberMsg);
				}
			} elseif ($method == "sms") { // Send SMS
				if (strlen($member->phone) > 9) { // If valid #
					$this->sendSMS($member, $memberMsg);
				}
			}
		}
		
		return redirect()->action('PortalController@getEventMessage', [$eventID])->with('msg', 'Success, message sent!');
	}
	
	/////////////////////////////// Event Checkin System ///////////////////////////////
	
	public function getCheckinEvents(AdminRequest $request) {
		$events = Event::orderBy("event_time")->get();
		$checkin = true;
		return view('pages.events',compact("events","checkin"));
	}
	
	public function getCheckin(AdminRequest $request, $eventID) {
		$event = Event::find($eventID);
		
		if(is_null($event)) {
			$request->session()->flash('msg', 'Error: Event Not Found.');
			return $this->getEvents($request);
		}
		
		return view('pages.checkin',compact("event","eventID"));
	}
	
	public function getCheckinPhone(AdminRequest $request, $eventID) {
		$getCheckin = $this->getCheckin($request, $eventID);
		
		return $getCheckin->with('checkinPhone',true);
	}
	
	public function postCheckinMember(AdminRequest $request) {
		$successResult = "true";
		$memberName = $request->input("memberName");
		$memberEmail = $request->input("memberEmail");
		$memberPhone = $request->input("memberPhone");
		$event = Event::find($request->input("eventID"));
		
		if ($request->input("memberID") > 0) { // Search By memberID
			$member = Member::find($request->input("memberID"));
			if ($memberEmail != $member->email) {
				$member = null;
			}
		} else { $member = null; }
		
		if ($member == null) { // Search By Name
			$member = Member::where('name',$memberName)->where('email',$memberEmail)->first();
		}
		
		if (strlen($memberName)<2 || !filter_var($memberEmail, FILTER_VALIDATE_EMAIL)) { // Validate Input
			return "invalid";
		}
		
		if (is_null($event)) { // Verify Event Exists
			return "false";
		}
		
		if (is_null($member)) { // New Member
			$member = new Member;
			
			if (Member::where('email',$memberEmail)->first()) {
				return "exists";
			}
			
			$member->name = $memberName;
			$member->email = $memberEmail;
			
			$member->save();
			$successResult = "new";
			$this->emailAccountCreated($member, $event);
		} else { // Existing Member, If account not setup, send creation email
			if ($member->graduation_year == 0) {
				$this->emailAccountCreated($member, $event);
			}
		}
		
		if ($event->members()->find($member->id)) { // Check if Repeat
			return "repeat";
		}
		
		$event->members()->attach($member->id,['recorded_by' => $this->getAuthenticatedID($request)]); // Save Record
		
		if (strlen($memberPhone) > 9) {
			$member->phone = $memberPhone;
			$member->save();
		} elseif (strlen($memberPhone)>2) {
			return "phone";
		}
		
		return $successResult;
	}
	
	/////////////////////////////// Account Setup Emails ///////////////////////////////
	
	public function getSetupAccountEmails(AdminRequest $request) { // Batch email all accounts that have not been setup, prompting them to setup.
		$members = Member::where('graduation_year',0)->get();
		
		$nowDate = Carbon::now();
		
		foreach ($members as $member) {
			if ($member->setupEmailSent->diffInDays($nowDate) > 30) {
				$this->emailAccountCreated($member, $member->events()->first());
			}
		}
		
		$request->session()->flash('msg', 'Success, setup account emails have been sent!');
		return $this->getIndex();
	}
	
	public function emailAccountCreated($member, $event) {
		Mail::send('emails.accountCreated', ['member'=>$member, 'event'=>$event], function ($message) use ($member) {
			$message->from('purduehackers@gmail.com', 'Purdue Hackers');
			$message->to($member->email);
			$message->subject("Welcome ".$member->name." to Purdue Hackers!");
		});
		$member->setupEmailSent = Carbon::now();
		$member->save();
	}
	
	/////////////////////////////// Applications ///////////////////////////////
	
	public function getApply(Request $request, $eventID=-1) { // GET Apply
		$event = Event::findOrFail($eventID);
		$authenticatedMember = $this->getAuthenticated($request);
		if (!$authenticatedMember) { $authenticatedMember = new Member(); }
		$majors = Major::orderByRaw('(id = 1) DESC, name')->get(); // Order by name, but keep first major at top
		
		if ($authenticatedMember != null) {
			$hasRegistered = count($authenticatedMember->applications()->where('event_id',$eventID)->get()) > 0;
		}
		
		return view('pages.apply',compact('event', 'authenticatedMember', 'majors', 'hasRegistered'));
	}
	
	public function getApplyAuth(Request $request, $eventID, $memberID, $reset_token) {
		$event = Event::findOrFail($eventID);
		$member = Member::findOrFail($memberID);
		
		if ($reset_token != $member->reset_token()) {
			$request->session()->flash('msg','Error: Invalid Authentication Token');
			return $this->getIndex();
		}
		
		$this->setAuthenticated($request, $memberID, $member->name);
		
		return $this->getApply($request, $eventID);
	}
	
	public function getRegister(LoggedInRequest $request, $eventID) { // Submit Empty Application
		$event = Event::findOrFail($eventID);
		
		if ($event->requiresApplication) {
			$request->session()->flash('msg','Error: Invalid Authentication Token');
			return $this->getEvent($request, $eventID);
		}
		
		$memberID = $this->getAuthenticatedID($request);
		
		if ($event->applications()->where('member_id',$memberID)->first()) {
			$request->session()->flash('msg','Error: You are already registered for '.$event->name.".");
			return $this->getEvent($request, $eventID);
		}
		
		$application = new Application();
		$application->member_id = $memberID;
		$application->event_id = $eventID;
		$application->save();
		
		$request->session()->flash('msg','Success: You are registered for '.$event->name.'!');
		return $this->getEvent($request, $eventID);
	}
	
	public function getUnregister(LoggedInRequest $request, $eventID) { // Delete Application
		$event = Event::findOrFail($eventID);
		
		$memberID = $this->getAuthenticatedID($request);
		
		$event->applications()->where('member_id',$memberID)->first()->delete();
		
		$request->session()->flash('msg','You are no longer registered for '.$event->name.'.');
		return $this->getEvent($request, $eventID);
	}
	
	public function postApply(LoggedInRequest $request, $eventID) { // POST Apply
		// Member Details
		$gender = $request->input('gender');
		$major = $request->input('major');
		
		$member = $this->getAuthenticated($request);
		$member->gender = $gender;
		$member->major_id = $major;
		$member->save();
		
		// Application Details
		$tshirt = $request->input('tshirt');
		$interests = $request->input('interests');
		$dietary = $request->input('dietary');
		
		$application = new Application();
		$application->member_id = $member->id;
		$application->event_id = $eventID;
		$application->tshirt = $tshirt;
		$application->interests = $interests;
		$application->dietary = $dietary;
		$application->save();
		
		$request->session()->flash('msg', 'Success, your application has been submitted!');
		return $this->getMember($request, $member->id);
	}
	
	public function getApplications(AdminRequest $request, $eventID=-1) {
		$event = Event::findOrFail($eventID);
		$applications = $event->applications;
		
		return view('pages.applications',compact("event","applications"));
	}
	
	public function getApplicationsUpperclassmen(AdminRequest $request, $eventID=-1) {
		$event = Event::findOrFail($eventID);
		$members = $event->getAppliedMembers();
		$upperclassmen = [];
		foreach ($members as $member) {
			if ($member->graduation_year > 2016 && $member->graduation_year < 2020) {
				array_push($upperclassmen, $member);
			}
		}
		$upperclassmen = collect($upperclassmen)->pluck("email","name");
		
		return $upperclassmen;
	}
	
	/////////////////////////////// Viewing Projects ///////////////////////////////
	
	public function getProjects(LoggedInRequest $request) {
		$projects = $this->getAuthenticated($request)->projects;
		
		return view('pages.projects',compact("projects"));
	}
	
	public function getProjectsAll(AdminRequest $request) {
		$projects = Project::all();
		$allProjects = true;
		
		return view('pages.projects',compact("projects","allProjects"));
	}
	
	public function getProject(LoggedInRequest $request, $projectID) {
		$project = Project::findOrFail($projectID);
		
		if ($this->canAccessProject($request, $project) == false) {
			$request->session()->flash('msg', 'Error: Project Not Found.');
			return $this->getProjects($request);
		}
		
		$members = $project->members;
		
		return view('pages.project', compact("project","members"));
	}
	
	public function canAccessProject($request, $project) {
		$member = $this->getAuthenticated($request);
		
		return $project->members->contains($member) || $this->isAdmin($request);
	}
	
	/////////////////////////////// Creating Projects ///////////////////////////////
	
	public function getProjectNew(LoggedInRequest $request) {
		$project = new Project;
		$project->id = 0;
		$members = [];
		
		return view('pages.project', compact("project","members"));
	}
	
	/////////////////////////////// Editing Projects ///////////////////////////////
	
	public function postProject(LoggedInRequest $request, $projectID) {
		$projectName = $request->input("name");
		$projectDescription = $request->input("description");
		
		if($projectID == 0) { // Create New Project
			$project = new Project;
		} else {
			$project = Project::find($projectID);
			if ($this->canAccessProject($request, $project) == false) { // Verify Permissions
				$request->session()->flash('msg', 'Error: Project Not Found.');
				return $this->getProjects($request);
			}
		}
		
		// Verify Input
		if(is_null($project)) {
			$request->session()->flash('msg', 'Error: Project Not Found.');
			return $this->getProjects($request);
		}
		
		// Edit Project
		$project->name = $projectName;
		$project->description = $projectDescription;
		$project->save();
		
		// Return Response
		if($projectID == 0) { // New Project
			$member = $this->getAuthenticated($request);
			$project->members()->attach($member->id); // Attach Project to Member
			return redirect()->action('PortalController@getProject', [$project->id])->with('msg', 'Project Created!');
		} else {
			$request->session()->flash('msg', 'Project Updated!');
			return $this->getProject($request, $projectID);
		}
	}
	
	public function getProjectDelete(LoggedInRequest $request, $projectID) {
		$project = Project::findOrFail($projectID);
		
		if ($this->canAccessProject($request, $project) == false) {
			$request->session()->flash('msg', 'Error: Project Not Found.');
			return $this->getProjects($request);
		}
		
		$project->delete();
		
		return redirect()->action('PortalController@getProjects')->with('msg', 'Success: Project Deleted. If this was by mistake, contact an organizer to reverse the change.');
	}
	
	/////////////////////////////// Editing Project Members ///////////////////////////////
	
	public function postProjectAddMember(LoggedInRequest $request, $projectID) {
		$project = Project::findOrFail($projectID);
		$memberInput = $request->input("member");
		$member = Member::where('name',$memberInput)->orWhere('email',$memberInput)->first();
		
		if ($this->canAccessProject($request, $project) == false) {
			$request->session()->flash('msg', 'Error: Project Not Found');
			return $this->getProjects($request);
		}
		
		if ($member == null) {
			$request->session()->flash('msg', 'Error: Member not found. Do they have a '.env("ORG_NAME").' account?');
			return $this->getProject($request, $projectID);
		}
		
		if ($project->members()->find($member->id)) {
			$request->session()->flash('msg', 'Error: Member already in team');
			return $this->getProject($request, $projectID);
		}
		
		$project->members()->attach($member->id);
		
		return redirect()->action('PortalController@getProject', [$projectID])->with('msg', 'Success: Added '.$member->name.' to project '.$project->name);
	}
	
	public function getProjectRemoveMember(LoggedInRequest $request, $projectID, $memberID) {
		$project = Project::findOrFail($projectID);
		$member = Member::findOrFail($memberID);
		
		if ($this->canAccessProject($request, $project) == false) {
			$request->session()->flash('msg', 'Error: Project Not Found');
			return $this->getProjects($request);
		}
		
		if ($member == null) {
			$request->session()->flash('msg', 'Error: Member Not Found');
			return $this->getProject($request, $projectID);
		}
		
		if (count($project->members) <= 1) {
			$request->session()->flash('msg', 'Error: Cannot leave project with only 1 member. Please delete project to remove.');
			return $this->getProject($request, $projectID);
		}
		
		if ($project->members()->find($member->id) == false) {
			$request->session()->flash('msg', 'Error: Member is not in team');
			return $this->getProject($request, $projectID);
		}
		
		$project->members()->detach($member->id);
		
		return redirect()->action('PortalController@getProject', [$projectID])->with('msg', 'Success: Removed '.$member->name.' from project '.$project->name);
	}

	/////////////////////////////// Helper Functions ///////////////////////////////
	
	public static function generateRandomInt() {
        srand();
        $characters = '0123456789';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < 9; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
	
	public function sendEmail($member, $subject, $msg) {
		if (true) {
			Mail::send('emails.default', ['member'=>$member, 'msg'=>$msg], function ($message) use ($member, $subject) {
				$message->from('purduehackers@gmail.com', 'Purdue Hackers');
				$message->to($member->email);
				$message->subject($subject);
			});
		}
	}
    
    static $twilioClient;
    public  function TwilioClient() {
	    if (null === static::$twilioClient) {
            static::$twilioClient = new Client(env("TWILIO_SID"), env("TWILIO_TOKEN"));
        }
        
        return static::$twilioClient;
    }
	
	public function sendSMS($member, $msg) {
		if (strlen($member->phone) > 7) {
			$phoneNum = preg_replace("/[^0-9]/", "", $member->phone);
			$this->TwilioClient()->messages->create($phoneNum, ['from'=>'+17652312066', 'body'=>$msg]);
		}
	}
    
    public function graphDataJoinDates($members) {
	    $joinDatesDict = [];
	    $start = Member::orderBy('created_at')->first()->created_at;
		$end = Carbon::now()->modify('+1 day');
		for ($i = $start; $i < $end; $i->modify('+1 day')) {
			$joinDatesDict[$i->toDateString()] = 0;
		}
		foreach ($members as $member) {
			$dateString = $member->created_at->toDateString();
			$joinDatesDict[$dateString]++;
		}
		$joinDates = [];
		foreach ($joinDatesDict as $date=>$count) {
			array_push($joinDates, compact("date","count"));
		}
		
		return $joinDates;
    }
    
    public function graphDataMemberYears($members) {
	    $memberYearsDict = [];
		foreach ($members as $member) {
			$memberYear = $member->graduation_year;
			$memberYearsDict[$memberYear] = isset($memberYearsDict[$memberYear]) ? $memberYearsDict[$memberYear]+1 : 1;
		}
		$memberYears = [];
		foreach ($memberYearsDict as $key=>$count) {
			array_push($memberYears, compact("key","count"));
		}
		$memberYears = array_values(array_sort($memberYears, function ($value) {
			return $value['key'];
		}));
		
		return $memberYears;
    }
    
    public function graphDataMajor($members) {
	    $majors = Major::all();
		$majorsDict = [];
		foreach ($majors as $major) {
			$majorsDict[$major->name] = 0;
		}
		foreach ($members as $member) {
			if(isset($member->major)) {
				$majorsDict[$member->major->name]++;
			}
		}
		$majorsData = [];
		foreach ($majorsDict as $key=>$count) {
			$key = preg_replace('~\b(\w)|.~', '$1', $key);
			array_push($majorsData, compact("key","count"));
		}
		
		return $majorsData;
    }
    
}