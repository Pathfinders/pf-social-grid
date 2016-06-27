<?php
/*
Plugin Name: PF Social Grid
Version: 1.0.4
Author: Pathfinders Advertising
Description: You know... social... stuff
Author URI: http://www.pathfind.com
*/

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

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

/* Create the required ACF fields */
if(is_plugin_active('advanced-custom-fields-pro/acf.php')){
	include_once(plugin_dir_path(__FILE__).'acf-fields.php');
}

/* Include our updater file */
include_once(plugin_dir_path(__FILE__).'updater.php');
$updater = new EM_Updater(__FILE__); // instantiate our class
$updater->set_username('Pathfinders'); // set username
$updater->set_repository('pf-social-grid' ); // set repo
$updater->initialize(); // initialize the updater

/* Enqueue the stylesheet */
wp_enqueue_style('myCSS', plugins_url( 'social_grid.css', __FILE__ ));

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
	$instagram_access_tokens = get_field('instagram_access_tokens', 'option');
	// Store the returned data in transient cache for 12 hours
	if(!get_transient('facebook_data') && $facebook_accounts){
		$facebook_data = array();
		foreach($facebook_accounts as $facebook_account){
			array_push($facebook_data,fetch_facebook(array('screen_name' => $facebook_account['account'],'count' => 10)));
		}
		set_transient( 'facebook_data', base64_encode(serialize($facebook_data)), 60 * 60 * 12 );
	}
	if(!get_transient('twitter_data') && $twitter_accounts){
		$twitter_data = array();
		foreach($twitter_accounts as $twitter_account){
			array_push($twitter_data,fetch_twitter(array('screen_name' => $twitter_account['account'],'count' => 10)));
		}
		set_transient( 'twitter_data', base64_encode(serialize($twitter_data)), 60 * 60 * 12 );
	}
	if(!get_transient('instagram_data') && $instagram_access_tokens){
		$instagram_data = array();
		foreach($instagram_access_tokens as $instagram_access_token){
			array_push($instagram_data,fetch_instagram(array('access_token' => $instagram_access_token['access_token'],'count' => 10)));
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
						if($facebook_post->type == 'photo'){
							$post_date = strtotime($facebook_post->created_time);
							$post_link = $facebook_post->link;
							$post_image = getBigFacebookPhoto((string)$facebook_post->object_id);
							array_push($all_posts,array('post_date' => $post_date, 'post_link' => $post_link, 'post_image' => $post_image, 'source' => 'facebook'));
						}
					}
				}
			}
		}
		$twitter_sources = unserialize(base64_decode(get_transient('twitter_data')));
		if($twitter_sources){
			foreach($twitter_sources as $twitter_posts){
				if($twitter_posts){
					foreach($twitter_posts as $twitter_post){
						if($twitter_post->entities->media[0]->type == 'photo'){
							$screen_name = ($twitter_post->retweeted_status ? $twitter_post->retweeted_status->user->screen_name : $twitter_post->user->screen_name);				
							$post_date = strtotime($twitter_post->created_at);
							$post_link = 'https://twitter.com/'.$screen_name.'/status/'.$twitter_post->id;
							$post_image = $twitter_post->entities->media[0]->media_url;
							array_push($all_posts,array('post_date' => $post_date, 'post_link' => $post_link, 'post_image' => $post_image, 'source' => 'twitter'));
						}
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
						array_push($all_posts,array('post_date' => $post_date, 'post_link' => $post_link, 'post_image' => $post_image, 'source' => 'instagram'));
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
function get_social_grid(){
	$social_grid_data = get_social_grid_data();
?>
<div class="social-grid">
	<ul class="innertube">
		<li>
			<ul>
				<li>
					<ul>
						<li style="background-image:url(<?= $social_grid_data[0]['post_image'] ?>)"><a href="<?= $social_grid_data[0]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[0]['source'] ?>_circle"></span></li>
						<li style="background-image:url(<?= $social_grid_data[1]['post_image'] ?>)"><a href="<?= $social_grid_data[1]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[1]['source'] ?>_circle"></span></li>
					</ul>
				</li>
				<li style="background-image:url(<?= $social_grid_data[2]['post_image'] ?>);padding:0 5px 5px 0;"><a href="<?= $social_grid_data[2]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[2]['source'] ?>_circle"></span></li>
			</ul>
		</li>
		<li>
			<ul>
				<li style="background-image:url(<?= $social_grid_data[3]['post_image'] ?>);padding:0 5px 5px 0;"><a href="<?= $social_grid_data[3]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[3]['source'] ?>_circle"></span></li>
				<li>
					<ul>
						<li style="background-image:url(<?= $social_grid_data[4]['post_image'] ?>)"><a href="<?= $social_grid_data[4]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[4]['source'] ?>_circle"></span></li>
						<li style="background-image:url(<?= $social_grid_data[5]['post_image'] ?>)"><a href="<?= $social_grid_data[5]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[5]['source'] ?>_circle"></span></li>
					</ul>
				</li>
			</ul>
		</li>
		<li>
			<ul>
				<li>
					<ul>
						<li style="background-image:url(<?= $social_grid_data[6]['post_image'] ?>)"><a href="<?= $social_grid_data[6]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[6]['source'] ?>_circle"></span></li>
						<li style="background-image:url(<?= $social_grid_data[7]['post_image'] ?>)"><a href="<?= $social_grid_data[7]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[7]['source'] ?>_circle"></span></li>
						<li style="background-image:url(<?= $social_grid_data[8]['post_image'] ?>)"><a href="<?= $social_grid_data[8]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[8]['source'] ?>_circle"></span></li>
						<li style="background-image:url(<?= $social_grid_data[9]['post_image'] ?>)"><a href="<?= $social_grid_data[9]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[9]['source'] ?>_circle"></span></li>
					</ul>
				</li>
				<li class="double" style="background-image:url(<?= $social_grid_data[10]['post_image'] ?>);padding:0 5px 5px 0;"><a href="<?= $social_grid_data[10]['post_link'] ?>" target="_blank" rel="external"><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" class="spacer"></a><span class="icon-icon_<?= $social_grid_data[10]['source'] ?>_circle"></span></li>
			</ul>
		</li>
	</ul>
</div>
<?php } ?>