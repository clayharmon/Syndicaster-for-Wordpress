<?php

class SyndicasterAPI {

  private $syndicaster_url = 'http://api.syndicaster.tv/';

  public $auth = ''; //get from Wordpress Options
  public $account = [
    'user'=>'',
    'password'=>'',
    'content_owner'=>'',
    'publisher'=>''
  ];
  private $app = [
    'id'=>'',
    'secret'=>''
  ];

  public function __construct($account = false, $app = false) {
    $this->auth = get_option('syn_auth', false);
    $this->account = ($account) ? $account : get_option('syn_account', false); //Store the md5 password here.
    $this->app = ($app) ? $app : get_option('syn_app', false);
  }

  public function cut($array){
    date_default_timezone_set('America/Chicago');
  	$data = (isset($array->results)) ? $array->results : [$array];
    $amount = count($data);
    $new_array = [];
    for($i = 0; $i < $amount; $i++){
      $date = strtotime($data[$i]->completed_at);
      $new_array[] = [
        "id" => $data[$i]->id,
        "parent_id" => $data[$i]->parent_file_set_id,
        "title" => $data[$i]->metadata->title,
        "date" => date('D, m/d/y \a\t h:i a', $date),
        "thumb" => $data[$i]->files[0]->uri,
        "image" => $data[$i]->files[1]->uri
      ];
    }
    return $new_array;
  }

  private function api_request($method, $data, $path, $json = 1, $is_auth = 1) {
    $data = ($json) ? json_encode($data) : $data;
    $ch = curl_init($this->syndicaster_url . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS,($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if($is_auth) {
      $access = $this->auth['access_token'];
      $header = ["Content-Type: application/json","Authorization: OAuth ".$access];
      curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    date_default_timezone_set('America/Chicago');
    if($httpCode == 401){
      //get new token
      $token = $this->get_token();
      $header = ["Content-Type: application/json","Authorization: OAuth ".$token['access_token']];
      curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
      $response = curl_exec($ch);
      curl_close($ch);
      return json_decode($response);
    } else {
      curl_close($ch);
      return json_decode($response);
    }
  }

  public function get_token($refresh_token = 0){
    $post_data = ($refresh_token) ? array(
      'grant_type' => 'refresh_token',
      'client_id' => $this->app['id'],
      'client_secret' => $this->app['secret'],
      'refresh_token' => $refresh_token,
    ) : array(
    	'grant_type' => 'password',
    	'client_id' => $this->app['id'],
    	'client_secret' => $this->app['secret'],
    	'scope' => 'read',
    	'username' => $this->account['user'],
    	'password'=> $this->account['password']
    );
    $response = $this->api_request("POST", $post_data, "oauth/access_token", 0, 0);
    if(!isset($response->expires_in)){  return $response; }

    $options = get_object_vars($response);
    $options['expires_in'] = $options['expires_in'] + time();
    update_option('syn_auth', $options);
    return $options;
  }

  public function get_playlists(){
    if(empty($this->account['publisher'])) { return false; }
    $path='syndi_playlists.json?publisher_id[]='.$this->account['publisher'];
    $response = $this->api_request("GET", '', $path);
    return $response;

  }

  public function search($playlist, $lookup = '', $per_page = 12, $page = 1, $format = True){
    $path = 'file_sets/search.json';
    $query = trim($lookup . ' ' . $playlist);
    $post_data = array(
     'content_owner_ids' => array($this->account['content_owner']),
     'distributable'=> true,
     'per_page'=>$per_page,
     'page'=>$page,
     'media_type_ids'=>array('3'),
     'query'=>$query,
     'status_ids'=>array('3')
   );
    $response = $this->api_request("POST", $post_data, $path);
    if($format){return $this->cut($response);}
    return $response;
  }

  public function get_video_info($file_id, $options = 'metadata,files', $format = True){
    $path = 'file_sets/'.$file_id .'/'.$options;
    $response = $this->api_request("GET", '', $path);
    if($format){return $this->cut($response);}
    return $response;
  }

  public function get_clip_id($file_id, $return_id = True) {
  	$path = 'file_sets/'.$file_id.'/distributions';
    $response = $this->api_request("GET", '', $path);

    if($return_id){return $response[0]->repo_guid;}
    return $response;
  }

  public function content_owners($lookup = ''){
    $path = '/admin/content_owners';
    $post_data = array(
     'query' => $lookup,
   );
    $response = $this->api_request("GET", '', $path);
    return $response;
  }
}
