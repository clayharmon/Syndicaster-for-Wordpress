<?php
/*
Plugin Name: Syndicaster for MorrisNetworks
Description: Allows users to easily add syndicaster videos to a post.
Version: 0.8
Author: Clay Harmon
*/

include 'includes/SyndicasterAPI.php';

/**
 * Saves an image from offsite and sets it as the featured image for a particular post.
 * 
 * @link   http://wordpress.stackexchange.com/questions/100838/how-to-set-featured-image-to-custom-post-from-outside-programmatically
 *
 * @param  string  $image   Full URL of the image.
 * @param  integer $post_id The current post id that the feature image is being set to.
 */

function syn_save_image($image, $post_id){
	// Downloads the image and attaches it to the post.
	$media = media_sideload_image($image, $post_id);

	if(!empty($media) && !is_wp_error($media)){

		// Finds the post with the image.
		$args = array(
			'post_type' => 'attachment',
			'posts_per_page' => -1,
			'post_status' => 'any',
			'post_parent' => $post_id
		);
		$attachments = get_posts($args);

		if(isset($attachments) && is_array($attachments)){
			foreach($attachments as $attachment){
				// Gets the full image src.
				$image_attachement = wp_get_attachment_image_src($attachment->ID, 'full');
				// Determines if it's the same as the image we saved and sets as the thumbnail.
				if(strpos($media, $image_attachement[0]) !== false){
					set_post_thumbnail($post_id, $attachment->ID);
					break;
				}
			}
		}
	}
}


/**
 * Enqueue scripts and styles.
 */
function syn_scripts($hook){
	// For metabox box.
	if( 'post.php' == $hook || 'post-new.php' == $hook ) {
		// Enqueue metabox styles.
		wp_register_style( 'syn_enqueue_css', plugins_url( '/assets/css/styles.css', __FILE__ ), false, '1.0.0' );
	  wp_enqueue_style( 'syn_enqueue_css' );

		// Enqueue metabox scripts.
		wp_enqueue_script( 'syn_enqueue_js', plugins_url( '/assets/js/metabox.js', __FILE__ ), array('jquery'),'1.0.0' );
		wp_localize_script( 'syn_enqueue_js', 'obj',['ajax_url' => admin_url('admin-ajax.php')] );
	}

	// For settings page.
	if( 'settings_page_syn_options' == $hook ) {
		// Enqueue settings styles.
		wp_register_style( 'syn_enqueue_css', plugins_url( '/assets/css/admin.css', __FILE__ ), false, '1.0.0' );
		wp_enqueue_style( 'syn_enqueue_css' );

		// Enqueue settings scripts.
		wp_enqueue_script( 'syn_enqueue_js', plugins_url( '/assets/js/settings.js', __FILE__ ), array('jquery'),'1.0.0' );
		wp_localize_script( 'syn_enqueue_js', 'obj',['ajax_url' => admin_url('admin-ajax.php')] );
	}

}
add_action( 'admin_enqueue_scripts', 'syn_scripts' );

/**
 * Ajax callback for the metabox.
 *
 * @global object $wpdb Gives access to Wordpress $options.
 */
function syn_search_callback() {
  global $wpdb;
  $Syn_API = new SyndicasterAPI();
  $options = get_option('syn_settings');
  $playlist = $options['playlist'];

	// Gets the number to return, default is 12.
  $number = ($options['syndicaster_num_items']) ? $options['syndicaster_num_items'] : 12;
	$term = (isset($_POST['search']) ? $_POST['search'] : '');
  $page = (isset($_POST['page']) ? $_POST['page'] : 1);

	// Gets the playlist to search in, default is pl_all_videos.
	$playlist = (isset($_POST['playlist']) ? $_POST['playlist'] : 'pl_all_videos');

  // Performs search.
  $array = $Syn_API->search($playlist, $term, $number, $page, False);

	// Formats the array a bit.
  $new = $Syn_API->cut($array);
  $data = ['results'=> $new];

	// Contains pagination data.
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

  echo json_encode(array_merge($data,$extra));

	wp_die();
}
add_action( 'wp_ajax_syn_search', 'syn_search_callback' );

/**
 * Ajax callback for the settings page.
 *
 * @global object $wpdb Gives access to Wordpress $options.
 */
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

	// Needs a syn_command.
	if(!isset($_POST['syn_command'])){
		echo json_encode(['status'=>'failed','reason'=>'no command set']);
		wp_die();
	}

	// Checks what command is given.
	$command = trim($_POST['syn_command']);

	if($command == 'deauthorize'){
		// Empties options.
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

		// TODO: Data is being assumed.
		$account['user'] = $_POST['syn_data']['user'];
		$account['password'] = md5(stripcslashes($_POST['syn_data']['password']));
		$app['id'] = $_POST['syn_data']['id'];
		$app['secret'] = $_POST['syn_data']['secret'];

		// Sends the info and gets the token.
		$Syn_API = new SyndicasterAPI($account, $app);
		$token = $Syn_API->get_token();

		// Something went wrong.
		if(isset($token->error)){
			echo json_encode(['status'=>'failed','reason'=>$token->error_description]);
			wp_die();
		}

		// Something went right, so update the options.
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

		// TODO: Data is being assumed.
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
add_action( 'wp_ajax_syn_settings', 'syn_settings_callback' );

/**
 * The html output for the metabox.
 *
 * @param  object $post An object containing the data for the current post.
 */
function syn_widget_body($post){
  $Syn_API = new SyndicasterAPI();
	$playlists = $Syn_API->get_playlists();

	// If there's no playlists, we need don't have a token.
	if(!$playlists){
		echo "<div class='syn-novid'><span>Please <a href='" . admin_url('options-general.php?page=syn_options'). "'>setup</a> your API connection.</span></div>";
		return;
	}

  $file_id = esc_html(get_post_meta($post->ID, 'syn_file_id', true));
  $parent_id = esc_html(get_post_meta($post->ID, 'syn_parent_id', true));

	// TODO: Currently there is no way to set a default playlist or per page.
  $options = get_option('syn_settings');
  $playlist_default = $options['playlist'];
  $number = ($options['per_page']) ? $options['per_page'] : 12;

	// Display the attached video.
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
  <?php wp_nonce_field( 'syn_set_meta_box', 'syn_meta_box' );
}

/**
 * Adds metabox to post and page.
 */
function syndicaster_video_box() {
  add_meta_box('syn-video-box', 'Syndicaster', 'syn_widget_body', 'page', 'side', 'high');
  add_meta_box('syn-video-box', 'Syndicaster', 'syn_widget_body', 'post', 'side', 'high');
}
add_action('admin_menu', 'syndicaster_video_box');

/**
 * Updates the metadata when a post is saved.
 *
 * @param  int $post_id The current post's id.
 */
function syn_update_meta($post_id) {

	// Returns if: no metabox, no nonce, or doing autosave.
  if(!isset($_POST['syn_meta_box'])) { return; }

  if(!wp_verify_nonce($_POST['syn_meta_box'], 'syn_set_meta_box')) { return; }

  if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }


  if(isset($_POST['syn_file_id'])) {
    update_post_meta( $post_id, 'syn_file_id', trim(htmlentities($_POST['syn_file_id'])) );
  }

  if(!empty($_POST['syn_parent_id'])) {
    $parent_id = trim(htmlentities($_POST['syn_parent_id']));
    $Syn_API = new SyndicasterAPI();
    $clip_id = $Syn_API->get_clip_id($parent_id);
    update_post_meta( $post_id, 'syn_parent_id',  $parent_id);

		// TODO: This is a theme-specific metafield.
    update_post_meta( $post_id, 'news_story_video',  $clip_id);
  }

	if(!empty( $_POST['syn_image_url'])){
		// Gets the thumbnail of the video, downloads it, and sets it as the featured image.
    syn_save_image(trim(htmlentities($_POST['syn_image_url'])), $post_id);
  }

}
add_action('save_post','syn_update_meta');


/**
 * Register settings for options.
 */
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
add_action('admin_init', 'syn_register_settings');

/**
 * The html output for the settings page.
 */
function syn_dynamic_options_page(){
	?>
	<div class='wrap'>
		<h1>Syndicaster Settings</h1>
	<?php
	$Syn_API = new SyndicasterAPI();
	$auth = $Syn_API->auth;
	$account = $Syn_API->account;

	// Checks if the user has already authorized the account.
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

						// Lists the content owners in a select box.
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

		// Not logged in form.

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

/**
 * Add option to settings menu.
 */
function syn_add_admin_menu() {
	add_options_page(
    'Syndicaster',
    'Syndicaster',
    'manage_options',
    'syn_options',
    'syn_dynamic_options_page'
  );
}
add_action( 'admin_menu', 'syn_add_admin_menu' );

/**
 * Shortcode to show a video using the videos id.
 *
 * @param  array $atts This is the id of the video.
 *
 * @return string      HTML output.
 */
function synshort( $atts ) {
  extract( shortcode_atts( array(
    'id' => '0'
  ), $atts ) );

  $output = "<div class='embed-responsive embed-responsive-16by9'><iframe frameborder='0' marginheight='0' scrolling='no' style='overflow: hidden; width: 100%; height: 100%;' src='http://play.syndicaster.tv/v1/widgets/1a8b1d60-4f40-0133-abd7-7a163e1f7c79/player.html?pl_length=5&vid=".$id."&wmode=opaque' allowfullscreen=''></iframe></div>";
  return $output;
}
add_shortcode('syndicaster', 'synshort');

/**
 * Changes the transient lifetime to 60 seconds.
 *
 * @param  int $seconds
 *
 * @return int
 */
function return_60( $seconds ) {return 60; }

/**
 * Pulls an RSS feed.
 *
 * @param  string $feed_url Full URL for RSS feed.
 *
 * @return object           Object containing the data from the RSS feed.
 */
function rss_feed_parse( $feed_url ) {
  include_once(ABSPATH.WPINC.'/rss.php');

	// Change transient lifetime to 60 seconds.
  add_filter( 'wp_feed_cache_transient_lifetime' , 'return_60' );
	// Wordpress function that pulls RSS feeds.
  $rss = fetch_feed($feed_url);
	// Change back transient lifetime.
  remove_filter( 'wp_feed_cache_transient_lifetime' , 'return_60' );
  return $rss;
}

/**
 * Formats object to only data that's needed, returns only 1 item.
 *
 * @param  object $object The RSS feed object.
 * @param  int    $num    The position of the item that's needed in the array
 *
 * @return array          The video id, the video thumbnail URL, the video title, and the video description.
 */
function syn_return_items($object, $num) {
  $items = $object->data["child"][""]["rss"][0]["child"][""]["channel"][0]["child"][""]["item"];
  $thumbUrl=$items[$num]["child"]["http://search.yahoo.com/mrss/"]["thumbnail"][0]["attribs"][""]["url"];
  $title=$items[$num]["child"]["http://search.yahoo.com/mrss/"]["title"][0]["data"];
  $urlID=$items[$num]["child"][""]["guid"][0]["data"];
  $urlID = substr($urlID, -7);
  $description=$items[$num]["child"]["http://search.yahoo.com/mrss/"]["description"][0]["data"];
  return array($urlID, $thumbUrl, $title, $description);
}

/**
 * Shortcode to show a video category.
 *
 * @param  array $atts This is the id of the category.
 *
 * @return string      HTML output.
 */
function syncatshort( $atts ) {
  extract( shortcode_atts( array(
    'cat' => '0'
  ), $atts ) );
  $synFeed = rss_feed_parse("http://www.clipsyndicate.com/rss/feed/".$cat."?wpid=12754&embed=script&page_size=1");
  $array = syn_return_items($synFeed, 0);
  $output = "<div class='embed-responsive embed-responsive-16by9'><iframe frameborder='0' marginheight='0' scrolling='no' style='overflow: hidden; width: 100%; height: 100%;' src='http://play.syndicaster.tv/v1/widgets/1a8b1d60-4f40-0133-abd7-7a163e1f7c79/player.html?pl_length=5&vid=".$array[0]."&wmode=opaque' allowfullscreen=''></iframe></div>";
  return $output;
}
add_shortcode('syndicaster-latest', 'syncatshort');
