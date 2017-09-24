<?php
/*
Plugin Name: Syndicaster for MorrisNetworks
Description: Allows users to easily add syndicaster videos to a post.
Version: 0.8
Author: Clay Harmon
*/

include 'includes/SyndicasterAPI.php';

function syn_save_image($image, $post_id){
	//http://wordpress.stackexchange.com/questions/100838/how-to-set-featured-image-to-custom-post-from-outside-programmatically
	$media = media_sideload_image($image, $post_id);
	// therefore we must find it so we can set it as featured ID
	if(!empty($media) && !is_wp_error($media)){
	    $args = array(
	        'post_type' => 'attachment',
	        'posts_per_page' => -1,
	        'post_status' => 'any',
	        'post_parent' => $post_id
	    );
    $attachments = get_posts($args);
    if(isset($attachments) && is_array($attachments)){
			foreach($attachments as $attachment){
				// grab source of full size images (so no 300x150 nonsense in path)
				$image = wp_get_attachment_image_src($attachment->ID, 'full');
				// determine if in the $media image we created, the string of the URL exists
				if(strpos($media, $image[0]) !== false){
					// if so, we found our image. set it as thumbnail
					set_post_thumbnail($post_id, $attachment->ID);
					// only want one image
					break;
				}
			}
		}
	}
}

add_action( 'admin_enqueue_scripts', 'syn_widget_styles' );
add_action( 'admin_enqueue_scripts', 'syn_options_styles' );
function syn_widget_styles($hook) {
	if( 'post.php' == $hook || 'post-new.php' == $hook ) {
		wp_register_style( 'syn_enqueue_css', plugins_url( '/assets/css/styles.css', __FILE__ ), false, '1.0.0' );
	  wp_enqueue_style( 'syn_enqueue_css' );
	}
}
function syn_options_styles($hook) {
	if( 'settings_page_syn_options' == $hook ) {
		wp_register_style( 'syn_enqueue_css', plugins_url( '/assets/css/admin.css', __FILE__ ), false, '1.0.0' );
	  wp_enqueue_style( 'syn_enqueue_css' );
	}
}
add_action( 'admin_enqueue_scripts', 'syn_widget_scripts' );
add_action( 'admin_enqueue_scripts', 'syn_options_scripts' );
function syn_widget_scripts($hook) {
	if( 'post.php' == $hook || 'post-new.php' == $hook ) {
		wp_enqueue_script( 'syn_enqueue_js', plugins_url( '/assets/js/metabox.js', __FILE__ ), array('jquery'),'1.0.0' );
		wp_localize_script( 'syn_enqueue_js', 'obj',['ajax_url' => admin_url('admin-ajax.php')] );
	}
}
function syn_options_scripts($hook) {
	if( 'settings_page_syn_options' == $hook ) {
		wp_enqueue_script( 'syn_enqueue_js', plugins_url( '/assets/js/settings.js', __FILE__ ), array('jquery'),'1.0.0' );
		wp_localize_script( 'syn_enqueue_js', 'obj',['ajax_url' => admin_url('admin-ajax.php')] );
	}
}
add_action( 'wp_ajax_syn_search', 'syn_search_callback' );
add_action( 'wp_ajax_syn_settings', 'syn_settings_callback' );
function syn_search_callback() {
  global $wpdb;
  $Syn_API = new SyndicasterAPI();
  $options = get_option('syn_settings');
  $playlist = $options['playlist'];

  $number = ($options['syndicaster_num_items']) ? $options['syndicaster_num_items'] : 12;
	$term = (isset($_POST['search']) ? $_POST['search'] : '');
  $page = (isset($_POST['page']) ? $_POST['page'] : 1);
	$playlist = (isset($_POST['playlist']) ? $_POST['playlist'] : 'pl_all_videos');

		/*if(!$playlist){
	    $response = ["no_playlist"];
	    echo json_encode($response);
	    wp_die();
	  }*/
  $array = $Syn_API->search($playlist, $term, $number, $page, False);
  $new = $Syn_API->cut($array);
  $data = ['results'=> $new];
  $extra = [
    'paging' => [
      'current' => $page,
      'total_items' => $array->total_entries,
      'per_page'=>$number
    ],
			'returns' => [
        'playlist' => $playlist,
        'term' => $term
      ]
		];

  //echo json_encode($array);
  echo json_encode(array_merge($data,$extra));

	wp_die();
}
function syn_settings_callback() {
  global $wpdb;

	$account = [
		'user'=>'',
		'password'=>'',
		'content_owner'=>'',
		'publisher'=>''
	];
	$app = [
		'id'=>'',
		'secret'=>''
	];

	if(!isset($_POST['syn_command'])){
		echo json_encode(['status'=>'failed','reason'=>'no command set']);
		wp_die();
	}
	$command = trim($_POST['syn_command']);
	if($command == 'deauthorize'){
		$auth = false;
		update_option('syn_auth', $auth);
		update_option('syn_account', $account);
		update_option('syn_app', $app);

		$data = array('status' => 'success','reason'=>'deauthorized');
	}
	if($command == 'authorize'){
		if(!isset($_POST['syn_data'])){
			echo json_encode(['status'=>'failed','reason'=>'no data sent']);
			wp_die();
		}

		$account['user'] = $_POST['syn_data']['user'];
		$account['password'] = md5(stripcslashes($_POST['syn_data']['password']));
		$app['id'] = $_POST['syn_data']['id'];
		$app['secret'] = $_POST['syn_data']['secret'];

		$Syn_API = new SyndicasterAPI($account, $app);

		$token = $Syn_API->get_token();

		if(isset($token->error)){
			echo json_encode(['status'=>'failed','reason'=>$token->error_description]);
			wp_die();
		}

		if(isset($token['expires_in'])){
			update_option('syn_auth', $token);
			update_option('syn_account', $account);
			update_option('syn_app', $app);
		} else {
			echo json_encode(['status'=>'failed','reason'=>'Unknown Token Error']);
			wp_die();
		}

		$data = ['status'=>'success','reason'=>'Updated Options'];
	}
	if($command == 'update'){
		if(!isset($_POST['syn_data'])){
			echo json_encode(['status'=>'failed','reason'=>'no data sent']);
			wp_die();
		}

		$account = get_option('syn_account', false);
		$account['content_owner'] = $_POST['syn_data']['syn_station'][1];
		$account['publisher'] = $_POST['syn_data']['syn_station'][0];

		if(!empty($account['content_owner']) && !empty($account['publisher'])){
			update_option('syn_account', $account);
		} else {
			echo json_encode(['status'=>'failed','reason'=>'Unknown Account Error']);
			wp_die();
		}

		$data = ['status'=>'success','reason'=>'Updated Account'];
	}
  echo json_encode($data);

	wp_die();
}
add_action('admin_menu', 'syndicaster_video_box');
function syndicaster_video_box() {
  add_meta_box('syn-video-box', 'Syndicaster', 'syn_widget_body', 'page', 'side', 'high');
  add_meta_box('syn-video-box', 'Syndicaster', 'syn_widget_body', 'post', 'side', 'high');
}

function syn_widget_body($post){
  $Syn_API = new SyndicasterAPI();
	$playlists = $Syn_API->get_playlists();
	if(!$playlists){
		echo "<div class='syn-novid'><span>Please <a href='" . admin_url('options-general.php?page=syn_options'). "'>setup</a> your API connection.</span></div>";
		return;
	}
  $file_id = esc_html( get_post_meta( $post->ID, 'syn_file_id', true ) );
  $parent_id = esc_html( get_post_meta( $post->ID, 'syn_parent_id', true ) );

  $options = get_option('syn_settings');
  $playlist_default = $options['playlist'];
  $number = ($options['per_page']) ? $options['per_page'] : 12;
  //var_dump($playlists);

  if($file_id){
    echo '<div id="syndicaster-attached" style="display:block;"><span>Attached Video:</span>';
    $feed = $Syn_API->get_video_info($file_id);
    echo "<div class='syn-added' title='".$feed[0]['title']."' data-id='".$feed[0]['id']."'>";
    echo   "<span class='syn-x dashicons dashicons-dismiss'></span>";
    echo   "<div class='syn-thumb'><img src='".$feed[0]['thumb']."'/></div>";
    echo   "<div class='syn-meta'><span class='syn-title ellipsis'>".$feed[0]['title']."</span>";
    echo  "<span class='syn-date'>".$feed[0]['date']."</span></div></div></div><hr>";
  } else {
    echo '<div id="syndicaster-attached"><span>Attached Video:</span></div>';
  }
  ?>
  <div id="syndicaster-search">
    <input type="text" placeholder="Search Videos..."/>
  </div>

  <div id="syndicaster-playlist">
    <select>
      <option value='' selected='selected'>No Playlist</option>

    <?php
    for($i=0;$i<count($playlists);$i++){
      if($playlists[$i]->keyword == $playlist_default){
        echo "<option value='".$playlists[$i]->keyword."' selected='selected'>".$playlists[$i]->name."</option>";
      } else if($playlists[$i]->show){
        echo "<option value='".$playlists[$i]->keyword."'>".$playlists[$i]->name."</option>";
      }
    }
    ?>

  </select>

  </div>
  <div id="syndicaster-loader"><img src='<?= admin_url('images/spinner-2x.gif')?>' /></div>
  <div id='syndicaster-videos'>
    <div class='syn-novid'><span>Loading, Please Wait.</span></div>
  </div>
  <div id="syndicaster-pagination" class="tablenav"></div>
  <input name="syn_file_id" type="hidden" value="<?php echo trim($file_id) ; ?>">
  <input name="syn_parent_id" type="hidden" value="<?php echo trim($parent_id) ; ?>">
	<input name="syn_image_url" type="hidden" value="">
  <?php wp_nonce_field( 'syn_set_meta_box', 'syn_meta_box' ); ?>

  <?php


}
add_action('save_post','syn_update_meta');

function syn_update_meta($post_id) {
  if ( !isset( $_POST['syn_meta_box'] ) ) { return; }
  if ( ! wp_verify_nonce( $_POST['syn_meta_box'], 'syn_set_meta_box' ) ) {return;}
  if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {return;}

  if ( isset( $_POST['syn_file_id'] ) ) {
    update_post_meta( $post_id, 'syn_file_id', trim(htmlentities($_POST['syn_file_id'])) );
  }
  if ( !empty( $_POST['syn_parent_id'] ) ) {
    $parent_id = trim(htmlentities($_POST['syn_parent_id']));
    $Syn_API = new SyndicasterAPI();
    $clip_id = $Syn_API->get_clip_id($parent_id);
    update_post_meta( $post_id, 'syn_parent_id',  $parent_id);
    update_post_meta( $post_id, 'news_story_video',  $clip_id);
  }

	if ( !empty( $_POST['syn_image_url'] ) ) {
    syn_save_image(trim(htmlentities($_POST['syn_image_url'])), $post_id);
  }

}




add_action('admin_init', 'syn_register_settings');
function syn_register_settings(){
  register_setting(
    'syn_options',
    'syn_account'
  );
  register_setting(
    'syn_options',
    'syn_app'
  );
}
add_action( 'admin_menu', 'syn_add_admin_menu' );
function syn_add_admin_menu() {
	add_options_page(
    'Syndicaster',
    'Syndicaster',
    'manage_options',
    'syn_options',
    'syn_dynamic_options_page'
  );
}
function syn_dynamic_options_page(){
	?>
	<div class='wrap'>
		<h1>Syndicaster Settings</h1>
	<?php
	$Syn_API = new SyndicasterAPI();
	$auth = $Syn_API->auth;
	$account = $Syn_API->account;

	if($auth && !empty($account['user'])){
		$content_owners = $Syn_API->content_owners();
		?>
		<div class="syn-account">
		<table class="form-table">
			<tr>
				<th scope="row" class="syn-auth"><a href="javascript:void(0);" class="syn-deauthorize delete button-secondary">De-Authorize</a></th>
			</tr>
			<tr>
				<th scope="row">Content Owner:</th>
				<td>
					<select id="syn_station" value="" data-owner="" class="regular-text">
						<option value="" data-owner="">None</option>
						<?php

						for($i = 0; $i < count($content_owners); $i++){
							if($content_owners[$i]->cs_wpid == $account['publisher'] && $content_owners[$i]->id == $account['content_owner']){
				        echo '<option selected="selected" value="'.$content_owners[$i]->cs_wpid.'" data-owner="'.$content_owners[$i]->id.'">'.$content_owners[$i]->call_sign.'</option>';
				      } else {
								echo '<option value="'.$content_owners[$i]->cs_wpid.'" data-owner="'.$content_owners[$i]->id.'">'.$content_owners[$i]->call_sign.'</option>';
							}
						}

						?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row" class="syn-auth"><a href="javascript:void(0);" class="syn-update button-primary">Update</a></th>
			</tr>
		</table>
		<div id="syndicaster-loader"><img src='<?= admin_url('images/spinner-2x.gif')?>' /></div>
		</div>

		<?php
	} else {
		?>
		<div class="syn-app">
		<table class="form-table">
			<tr>
				<th scope="row">Username</th>
				<td><input id="user" type="text" value="" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row">Password</th>
				<td><input id="password" type="password" value="" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row">Client ID</th>
				<td><input id="id" type="text" value="" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row">Client Secret</th>
				<td><input id="secret" type="password" value="" class="regular-text"></td>
			</tr>
			<tr>
				<th scope="row" class="syn-auth"><a href="javascript:void(0);" class="syn-authorize button-primary">Authorize</a></th>
			</tr>
		</table>
		<div id="syndicaster-loader"><img src='<?= admin_url('images/spinner-2x.gif')?>' /></div>
		</div>

		<?php
	}
	?>
	</div>


	<?php
}

//NOT USED BUT KEPT FOR LEGACY PURPOSES
function syn_options_page() {
  ?>
  <div class='wrap'>
  <h1>Syndicaster Settings</h1>
  <form method="post" action="options.php">
  <?php
  settings_fields('syn_options');
  $options_account = get_option('syn_account');
  $options_app = get_option('syn_app');
  ?>
    <h2>Account Information</h2>
    <table class="form-table">
      <tr valign="top"><th scope="row">Username:</th>
        <td><input type='text' name='syn_account[user]' value='<?=esc_attr($options_account["user"])?>' /></td>
      </tr>
      <tr valign="top"><th scope="row">Password:</th>
        <td><input type='text' name='syn_account[password]' value='<?=(esc_attr($options_account["password"]))?>' /></td>
      </tr>
      <tr valign="top"><th scope="row">Content Owner ID:</th>
        <td><input type='text' name='syn_account[content_owner]' value='<?=esc_attr($options_account["content_owner"])?>' /></td>
      </tr>
      <tr valign="top"><th scope="row">Publisher ID:</th>
        <td><input type='text' name='syn_account[publisher]' value='<?=esc_attr($options_account["publisher"])?>' /></td>
      </tr>
    </table>
    <h2>App Information</h2>
    <table class="form-table">
      <tr valign="top"><th scope="row">Client ID:</th>
        <td><input type='text' name='syn_app[id]' value='<?=esc_attr($options_app["id"])?>' /></td>
      </tr>
      <tr valign="top"><th scope="row">Client Secret:</th>
        <td><input type='text' name='syn_app[secret]' value='<?=esc_attr($options_app["secret"])?>' /></td>
      </tr>
    </table>
    <?php submit_button();?>
  </form>
  <?php echo "</div>";
}

///QUICK FIX.


add_shortcode('syndicaster', 'synshort');
function synshort( $atts ) {
  extract( shortcode_atts( array(
    'id' => '0'
  ), $atts ) );

  $output = "<div class='embed-responsive embed-responsive-16by9'><iframe frameborder='0' marginheight='0' scrolling='no' style='overflow: hidden; width: 100%; height: 100%;' src='http://play.syndicaster.tv/v1/widgets/1a8b1d60-4f40-0133-abd7-7a163e1f7c79/player.html?pl_length=5&vid=".$id."&wmode=opaque' allowfullscreen=''></iframe></div>";
  return $output;
}

function return_60( $seconds ) {return 60; }
function rss_feed_parse( $feed_url ) {
  include_once(ABSPATH.WPINC.'/rss.php');
  add_filter( 'wp_feed_cache_transient_lifetime' , 'return_60' );
  $rss=fetch_feed($feed_url);
  remove_filter( 'wp_feed_cache_transient_lifetime' , 'return_60' );
  return $rss;
}
function syn_return_items($object, $num) {
  $items = $object->data["child"][""]["rss"][0]["child"][""]["channel"][0]["child"][""]["item"];
  $thumbUrl=$items[$num]["child"]["http://search.yahoo.com/mrss/"]["thumbnail"][0]["attribs"][""]["url"];
  $title=$items[$num]["child"]["http://search.yahoo.com/mrss/"]["title"][0]["data"];
  $urlID=$items[$num]["child"][""]["guid"][0]["data"];
  $urlID = substr($urlID, -7);
  $description=$items[$num]["child"]["http://search.yahoo.com/mrss/"]["description"][0]["data"];
  return array($urlID, $thumbUrl, $title, $description);
}



add_shortcode('syndicaster-latest', 'syncatshort');
function syncatshort( $atts ) {
  extract( shortcode_atts( array(
    'cat' => '0'
  ), $atts ) );
  $synFeed = rss_feed_parse("http://www.clipsyndicate.com/rss/feed/".$cat."?wpid=12754&embed=script&page_size=1");
  $array = syn_return_items($synFeed, 0);
  $output = "<div class='embed-responsive embed-responsive-16by9'><iframe frameborder='0' marginheight='0' scrolling='no' style='overflow: hidden; width: 100%; height: 100%;' src='http://play.syndicaster.tv/v1/widgets/1a8b1d60-4f40-0133-abd7-7a163e1f7c79/player.html?pl_length=5&vid=".$array[0]."&wmode=opaque' allowfullscreen=''></iframe></div>";
  return $output;
}
