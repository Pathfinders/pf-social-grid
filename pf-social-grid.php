<?php
/*
Plugin Name: PF Social Grid
Version: 1.0.0
Author: Pathfinders Advertising
Description: You know... social... stuff
Author URI: http://www.pathfind.com
*/

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

/* Removes the middle part of a string */
function delete_all_between($beginning, $end, $string) {
	$beginningPos = strpos($string, $beginning);
	$endPos = strpos($string, $end);
	if ($beginningPos === false || $endPos === false) {
		return $string;
	}
	$textToDelete = substr($string, $beginningPos, ($endPos + strlen($end)) - $beginningPos);
	return str_replace($textToDelete, '', $string);
}

/* Notice of required plugins */
add_action('admin_notices', 'showAdminMessages');
function showAdminMessages(){
	$plugin_messages = array();
	include_once(ABSPATH.'wp-admin/includes/plugin.php');
	// Download the Advanced Custom Fields PRO plugin
	if(!is_plugin_active('advanced-custom-fields-pro/acf.php')){
		$plugin_messages[] = 'PF Social Grid requires you to install the Advanced Custom Fields PRO plugin, <a href="https://www.advancedcustomfields.com/pro/">download it from here</a>.';
	}
	if(count($plugin_messages) > 0){
		echo '<div id="message" class="error">';
			foreach($plugin_messages as $message){
				echo '<p><strong>'.$message.'</strong></p>';
			}
		echo '</div>';
	}
}

/* Create the required ACF fields and Options page*/
if(is_plugin_active('advanced-custom-fields-pro/acf.php')){
	include_once(plugin_dir_path(__FILE__).'acf-fields.php');
	if(function_exists('acf_add_options_page')) {
		acf_add_options_page();
	}
}

/* Include our updater file */
include_once(plugin_dir_path(__FILE__).'updater.php');
$updater = new EM_Updater(__FILE__); // instantiate our class
$updater->set_username('Pathfinders'); // set username
$updater->set_repository('pf-social-grid' ); // set repo
$updater->initialize(); // initialize the updater

/* Enqueue the stylesheet */
wp_enqueue_style('social_grid', plugins_url( 'social_grid.css', __FILE__ ));
wp_enqueue_style('pf_fa', 'https://maxcdn.bootstrapcdn.com/font-awesome/4.6.3/css/font-awesome.min.css');

// Get Facebook Posts
function fetch_facebook($args){
	if($args){
		$screen_name = $args["screen_name"];
		$count = $args["count"];
	}
	if(!$screen_name || !$count){
		return false;
	}
	$facebook_api_client_id = get_field('facebook_api_client_id', 'option');
	$facebook_api_client_secret = get_field('facebook_api_client_secret', 'option');	
	$client_id = '';
	$client_secret = '';
	$token = file_get_contents('https://graph.facebook.com/oauth/access_token?client_id='.$facebook_api_client_id.'&client_secret='.$facebook_api_client_secret.'&grant_type=client_credentials');
	$url = 'https://graph.facebook.com/'.$screen_name.'/posts?'.$token.'&limit='.$count;
	$results = json_decode(file_get_contents($url));
	return $results;
}

// Get Facebook Username by ID
function facebook_username($id){
	if(!$id){
		return false;
	}
	$facebook_api_client_id = get_field('facebook_api_client_id', 'option');
	$facebook_api_client_secret = get_field('facebook_api_client_secret', 'option');	
	$client_id = '';
	$client_secret = '';
	$token = file_get_contents('https://graph.facebook.com/oauth/access_token?client_id='.$facebook_api_client_id.'&client_secret='.$facebook_api_client_secret.'&grant_type=client_credentials');
	$url = 'https://graph.facebook.com/'.$id.'/?fields=username&'.$token;
	$results = json_decode(file_get_contents($url));
	return $results->username;
}

// Get Twitter Posts
require_once(plugin_dir_path(__FILE__).'lib/twitteroauth/twitteroauth/twitteroauth.php'); //Path to twitteroauth library
function getConnectionWithAccessToken($cons_key, $cons_secret, $oauth_token, $oauth_token_secret) {
	$connection = new TwitterOAuth($cons_key, $cons_secret, $oauth_token, $oauth_token_secret);
	return $connection;
}
function fetch_twitter($args){
	if($args){
		$screen_name = $args["screen_name"];
		$count = $args["count"];
	}
	if(!$screen_name || !$count){
		return false;
	}
	$consumerkey = get_field('twitter_consumer_key', 'option');
	$consumersecret = get_field('twitter_consumer_secret', 'option');
	$accesstoken = get_field('twitter_access_token', 'option');
	$accesstokensecret = get_field('twitter_access_token_secret', 'option');
	$connection = getConnectionWithAccessToken($consumerkey, $consumersecret, $accesstoken, $accesstokensecret);
	$tweets = $connection->get("https://api.twitter.com/1.1/statuses/user_timeline.json?screen_name=".$screen_name."&count=".$count);
	return $tweets;
}

// Get Instagram Posts
function fetch_instagram($args){
	if($args){
		$access_token = $args["access_token"];
		$count = $args["count"];
	}
	$json = file_get_contents('https://api.instagram.com/v1/users/self/media/recent/?access_token='.$access_token);
	$posts = json_decode($json);
	return $posts;
}

// Get High-Res Photo from Facebook
function getBigFacebookPhoto($object_id){
	$facebook_api_client_id = get_field('facebook_api_client_id', 'option');
	$facebook_api_client_secret = get_field('facebook_api_client_secret', 'option');
	$facebook_token = file_get_contents('https://graph.facebook.com/oauth/access_token?client_id='.$facebook_api_client_id.'&client_secret='.$facebook_api_client_secret.'&grant_type=client_credentials');
	$url = "https://graph.facebook.com/".$object_id."?fields=images&".$facebook_token;
	if($object_id){
		$results = json_decode(file_get_contents($url));
		return $results->images[0]->source;
	}
}

// Parse the data, grab what we need, and store in transient cache for 24 hours
function get_social_grid_data(){
	$facebook_accounts = get_field('facebook_accounts', 'option');
	$twitter_accounts = get_field('twitter_accounts', 'option');
	$instagram_accounts = get_field('instagram_accounts', 'option');
	// Store the returned data in transient cache for 12 hours
	if(!get_transient('facebook_data') && $facebook_accounts){
		$facebook_data = array();
		foreach($facebook_accounts as $facebook_account){
			array_push($facebook_data,fetch_facebook(array('screen_name' => $facebook_account['account'],'count' => 20)));
		}
		set_transient( 'facebook_data', base64_encode(serialize($facebook_data)), 60 * 60 * 12 );
	}
	if(!get_transient('twitter_data') && $twitter_accounts){
		$twitter_data = array();
		foreach($twitter_accounts as $twitter_account){
			array_push($twitter_data,fetch_twitter(array('screen_name' => $twitter_account['account'],'count' => 20)));
		}
		set_transient( 'twitter_data', base64_encode(serialize($twitter_data)), 60 * 60 * 12 );
	}
	if(!get_transient('instagram_data') && $instagram_access_tokens){
		$instagram_data = array();
		foreach($instagram_accounts as $instagram_account){
			array_push($instagram_data,fetch_instagram(array('access_token' => $instagram_account['access_token'],'count' => 20)));
		}
		set_transient( 'instagram_data', base64_encode(serialize($instagram_data)), 60 * 60 * 12 );
	}
	if(!get_transient('social_grid_data')){
		$all_posts = array();
		$facebook_sources = unserialize(base64_decode(get_transient('facebook_data')));
		if($facebook_sources){
			foreach($facebook_sources as $facebook_posts){
				if($facebook_posts->data){
					foreach($facebook_posts->data as $facebook_post){
						//if($facebook_post->type == 'photo'){
							$id = $facebook_post->from->id;
							$screen_name = facebook_username($id);
							$profile_image_url = "http://graph.facebook.com/".$id."/picture";
							$post_date = strtotime($facebook_post->created_time);
							$post_link = $facebook_post->link;
							$post_image = getBigFacebookPhoto((string)$facebook_post->object_id);
							array_push($all_posts,array('post_date' => $post_date, 'post_link' => $post_link, 'post_image' => $post_image, 'source' => 'facebook', 'profile_image_url' => $profile_image_url, 'screen_name' => $screen_name));
						//}
					}
				}
			}
		}
		$twitter_sources = unserialize(base64_decode(get_transient('twitter_data')));
		if($twitter_sources){
			foreach($twitter_sources as $twitter_posts){
				if($twitter_posts){
					foreach($twitter_posts as $twitter_post){
						//if($twitter_post->entities->media[0]->type == 'photo'){
							$screen_name = ($twitter_post->retweeted_status ? $twitter_post->retweeted_status->user->screen_name : $twitter_post->user->screen_name);				
							$post_date = ($twitter_post->retweeted_status ? $twitter_post->retweeted_status->created_at : $twitter_post->created_at);
							$post_image = ($twitter_post->entities->media[0]->media_url ? $twitter_post->entities->media[0]->media_url : NULL);
							$post_text = delete_all_between("RT",":",$twitter_post->text);
							$profile_image_url = ($twitter_post->retweeted_status ? $twitter_post->retweeted_status->user->profile_image_url : $twitter_post->user->profile_image_url);
							$post_link = 'https://twitter.com/'.$screen_name.'/status/'.$twitter_post->id;
							array_push($all_posts,array('post_date' => $post_date, 'post_link' => $post_link, 'post_image' => $post_image, 'post_text' => $post_text, 'source' => 'twitter', 'profile_image_url' => $profile_image_url, 'screen_name' => $screen_name));
						//}
					}
				}
			}
		}
		$instagram_sources = unserialize(base64_decode(get_transient('instagram_data')));
		if($instagram_sources){
			foreach($instagram_sources as $instagram_posts){
				if($instagram_posts->data){
					foreach($instagram_posts->data as $instagram_post){
						$post_date = $instagram_post->created_time; 						
						$post_link = $instagram_post->link;
						$post_image = $instagram_post->images->standard_resolution->url;
						$screen_name = $instagram_post->user->username;	
						$profile_image_url= $instagram_post->user->profile_picture;
						array_push($all_posts,array('post_date' => $post_date, 'post_link' => $post_link, 'post_image' => $post_image, 'source' => 'instagram', 'profile_image_url' => $profile_image_url, 'screen_name' => $screen_name));
					}
				}
			}
		}
		set_transient( 'social_grid_data', base64_encode(serialize($all_posts)), 60 * 60 * 24 );
	}
	$social_grid_data = unserialize(base64_decode(get_transient('social_grid_data')));
	$post_date= array();
	if($social_grid_data){
		foreach ($social_grid_data as $key => $row){
			$post_date[$key] = $row['post_date'];
		}
	}
	array_multisort($post_date, SORT_DESC, $social_grid_data);
	return $social_grid_data;
}
?>
<?php
add_shortcode( 'social_grid', 'get_social_grid' );
function get_social_grid($atts){
	$a = shortcode_atts( array(
		'captions' => false,
		'textblocks' => false,
	), $atts );
	$textblocks = array();
	if($a['textblocks']){
		$textblocks = explode(',', $a['textblocks']);
	}
 	$social_grid_data = get_social_grid_data();
	$social_grid_data_images = array();
	$social_grid_data_text = array();
	foreach($social_grid_data as $item){
		if($item['post_image']){
			$social_grid_data_images[] = $item;
		}else if($item['post_text']){
			$social_grid_data_text[] = $item;
		}
	}
	$image_pointer = 0;
	$text_pointer = 0;
?>
<div class="social-grid<?= $a['captions'] ? ' social-grid-captions' : '' ?>">
	<ul class="innertube">
		<li>
			<ul>
				<li>
					<ul>
						<?php if(in_array(1,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                        <li id="social-cell-1"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
						<?php $text_pointer++; }else{ ?>
                        <li id="social-cell-1" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                        <?php $image_pointer++; } ?>
                        <?php if(in_array(2,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                        <li id="social-cell-2"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
						<?php $text_pointer++; }else{ ?>
                        <li id="social-cell-2" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                        <?php $image_pointer++; } ?>
					</ul>
				</li>
				<?php if(in_array(3,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                <li id="social-cell-3"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
                <?php $text_pointer++; }else{ ?>
                <li id="social-cell-3" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                <?php $image_pointer++; } ?>
			</ul>
		</li>
		<li>
			<ul>
				<?php if(in_array(4,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                <li id="social-cell-4"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
                <?php $text_pointer++; }else{ ?>
                <li id="social-cell-4" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                <?php $image_pointer++; } ?>
				<li>
					<ul>
						<?php if(in_array(5,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                        <li id="social-cell-5"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
                        <?php $text_pointer++; }else{ ?>
                        <li id="social-cell-5" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                        <?php $image_pointer++; } ?>
                        <?php if(in_array(6,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                        <li id="social-cell-6"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
                        <?php $text_pointer++; }else{ ?>
                        <li id="social-cell-6" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                        <?php $image_pointer++; } ?>
					</ul>
				</li>
			</ul>
		</li>
		<li>
			<ul>
				<li>
					<ul>
						<?php if(in_array(7,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                        <li id="social-cell-7"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
                        <?php $text_pointer++; }else{ ?>
                        <li id="social-cell-7" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                        <?php $image_pointer++; } ?>
                        <?php if(in_array(8,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                        <li id="social-cell-8"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
                        <?php $text_pointer++; }else{ ?>
                        <li id="social-cell-8" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                        <?php $image_pointer++; } ?>
                        <?php if(in_array(9,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                        <li id="social-cell-9"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
                        <?php $text_pointer++; }else{ ?>
                        <li id="social-cell-9" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                        <?php $image_pointer++; } ?>
                        <?php if(in_array(10,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                        <li id="social-cell-10"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
                        <?php $text_pointer++; }else{ ?>
                        <li id="social-cell-10" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                        <?php $image_pointer++; } ?>
					</ul>
				</li>
				<?php if(in_array(11,$textblocks) && $social_grid_data_text[$text_pointer]){ ?>
                <li class="double" id="social-cell-11"><a href="<?= $social_grid_data_text[$text_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="copy"><span><?= $social_grid_data_text[$text_pointer]['post_text'] ?></span></span><span class="label"><span class="fa fa-<?= $social_grid_data_text[$text_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data[$text_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data[$text_pointer]['screen_name'] ?></span></span></li>
                <?php $text_pointer++; }else{ ?>
                <li class="double" id="social-cell-11" style="background-image:url(<?= $social_grid_data_images[$image_pointer]['post_image'] ?>)"><a href="<?= $social_grid_data_images[$image_pointer]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="label"><span class="fa fa-<?= $social_grid_data_images[$image_pointer]['source'] ?>"></span><span class="text"><img src="<?= $social_grid_data_images[$image_pointer]['profile_image_url'] ?>" />@<?= $social_grid_data_images[$image_pointer]['screen_name'] ?></span></span></li>
                <?php $image_pointer++; } ?>
            </ul>
		</li>
	</ul>
</div>
<?php } ?>