<?php
/**
 * Integrate the Smush.it API into WordPress.
 * @version 1.3.0
 * @package WP_SmushIt
 */
/*
Plugin Name: WP Smush.it
Plugin URI: http://dialect.ca/code/wp-smushit/
Description: Reduce image file sizes and improve performance using the <a href="http://smush.it/">Smush.it</a> API within WordPress.
Author: Dialect
Version: 1.3.0
Author URI: http://dialect.ca/?wp_smush_it
*/

if ( !function_exists('json_encode') ) {
	require_once('JSON/JSON.php');
}

/**
 * Constants
 */
define('SMUSHIT_REQ_URL', 'http://www.smushit.com/ysmush.it/ws.php?img=%s');
define('SMUSHIT_BASE_URL', 'http://www.smushit.com/');

define('WP_SMUSHIT_DOMAIN', 'wp_smushit');
define('WP_SMUSHIT_UA', 'WP Smush.it/1.3.0 (+http://dialect.ca/code/wp-smushit)');
define('WP_SMUSHIT_GIF_TO_PNG', intval(get_option('wp_smushit_gif_to_png')));
define('WP_SMUSHIT_PLUGIN_DIR', dirname(plugin_basename(__FILE__)));


/**
 * Hooks
 */
register_activation_hook(__FILE__,'wp_smushit_install');

add_filter('wp_generate_attachment_metadata', 'wp_smushit_resize_from_meta_data', 10, 2);
add_filter('manage_media_columns', 'wp_smushit_columns');
add_action('manage_media_custom_column', 'wp_smushit_custom_column', 10, 2);
add_action('admin_init', 'wp_smushit_admin_init');
add_action('admin_action_wp_smushit_manual', 'wp_smushit_manual');
//add_action('admin_action_wp_smushit_theme', 'wp_smushit_theme');


/**
 * Plugin admin functions
 */
function wp_smushit_install() {
	// clean up from previous versions
	delete_option('wp_smushit_gif_to_png');
}

function wp_smushit_admin_init() {
	load_plugin_textdomain(WP_SMUSHIT_DOMAIN);
	wp_enqueue_script('common');
}

function wp_smushit_filter_plugin_actions($links, $file) {
	if ( 'wp-smushit/wp-smushit.php' === $file ) {
		$settings_link = '<a href="options-general.php?page=wp-smushit/options.php">' . __('Settings') . '</a>';
		array_unshift( $links, $settings_link ); // before other links
	}
	return $links;
}

/**
 * Process all the images from a given theme
 */
function wp_smushit_theme() {
	require_once('theme.php');
}


/**
 * Manually process an image from the Media Library
 */
function wp_smushit_manual() {
	if ( FALSE === current_user_can('upload_files') ) {
		wp_die(__('You don\'t have permission to work with uploaded files.', WP_SMUSHIT_DOMAIN));
	}

	if ( FALSE === isset($_GET['attachment_ID'])) {
		wp_die(__('No attachment ID was provided.', WP_SMUSHIT_DOMAIN));
	}

	$attachment_ID = intval($_GET['attachment_ID']);

	$original_meta = wp_get_attachment_metadata( $attachment_ID);


	$new_meta = wp_smushit_resize_from_meta_data( $original_meta, $attachment_ID );
	wp_update_attachment_metadata( $attachment_ID, $new_meta );

	$sendback = wp_get_referer();
	$sendback = preg_replace('|[^a-z0-9-~+_.?#=&;,/:]|i', '', $sendback);
	wp_redirect($sendback);
	exit(0);
}

/**
 * Process an image with Smush.it.
 *
 * Returns an array of the $file $results.
 *
 * @param   string $file            Full absolute path to the image file
 * @returns array
 */
function wp_smushit($file) {
	// dont't run on localhost
	if( '127.0.0.1' == $_SERVER['SERVER_ADDR'] )
		return array($file, __('Not processed (local file)', WP_SMUSHIT_DOMAIN));

	// canonicalize path
	$file_path = realpath($file);

	// check that the file exists
	if ( FALSE === file_exists($file_path) || FALSE === is_file($file_path) ) {
		$msg = sprintf(__("Could not $file_path find <span class='code'>%s</span>", WP_SMUSHIT_DOMAIN), $file_path);
		return array($file, $msg);
	}

	// check that the file is writable
	if ( FALSE === is_writable($file_path) ) {
		$msg = sprintf(__("<span class='code'>%s</span> is not writable", WP_SMUSHIT_DOMAIN), $file_path);
		return array($file, $msg);
	}

	// check that the file is within the WP_CONTENT_DIR
	if ( 0 !== stripos(realpath($file_path), realpath(WP_CONTENT_DIR)) ) {
		$msg = sprintf(__("<span class='code'>%s</span> must be within the content directory (<span class='code'>%s</span>)", WP_SMUSHIT_DOMAIN), htmlentities($file_path), WP_CONTENT_DIR);

		return array($file, $msg);
	}

	// determine the public URL
	$file_url = str_replace( WP_CONTENT_DIR, WP_CONTENT_URL, $file );

	$data = wp_smushit_post($file_url);

	if ( FALSE === $data )
		return array($file, __('Error posting to Smush.it', WP_SMUSHIT_DOMAIN));

	// make sure the response looks like JSON -- added 2008-12-19 when
	// Smush.it was returning PHP warnings before the JSON output
	if ( strpos( trim($data), '{' ) != 0 ) {
		return array($file, __('Bad response from Smush.it', WP_SMUSHIT_DOMAIN));
	}

	// read the JSON response
	if ( function_exists('json_decode') ) {
		$data = json_decode( $data );
	} else {
		$json = new Services_JSON();
		$data = $json->decode($data);
	}

	if ( -1 === intval($data->dest_size) )
		return array($file, __('No savings', WP_SMUSHIT_DOMAIN));

	if ( !$data->dest ) {
		$err = ($data->error ? 'Smush.it error: ' . $data->error : 'unknown error');
		return array($file, __($err, WP_SMUSHIT_DOMAIN) );
	}

	$processed_url = $data->dest;

	// The smush.it web service does not append the domain;
	// smushit.com web service does
	if ( 0 !== stripos($processed_url, 'http://') ) {
		$processed_url = SMUSHIT_BASE_URL . $processed_url;
	}

	$temp_file = download_url($processed_url);

	if ( is_wp_error($temp_file) ) {
		@unlink($tmp);
		$results_msg = sprintf(__("Error downloading file (%s)", WP_SMUSHIT_DOMAIN),
	                 $temp_file->get_error_message());
		return array($file, $results_msg );
	}

	@rename( $temp_file, $file_path );

	$savings = intval($data->src_size) - intval($data->dest_size);
	$savings_str = wp_smushit_format_bytes($savings, 1);
	$savings_str = str_replace(' ', '&nbsp;', $savings_str);

	$results_msg = sprintf(__("Reduced by %01.1f%% (%s)", WP_SMUSHIT_DOMAIN),
	                 $data->percent,
	                 $savings_str);

	return array($file, $results_msg);
}


/**
 * Read the image paths from an attachment's meta data and process each image
 * with wp_smushit().
 *
 * This method also adds a `wp_smushit` meta key for use in the media library.
 *
 * Called after `wp_generate_attachment_metadata` is completed.
 */
function wp_smushit_resize_from_meta_data($meta, $attachment_ID) {

  	if ( empty($meta['file']) ) {
	  $url = wp_get_attachment_url($attachment_ID);
	  $path = preg_replace('/.*\/wp-content\/uploads\//', '', $url);
	  $meta['file'] = $path;
	}

	$file_path = $meta['file'];
	$store_absolute_path = true;
	$upload_dir = wp_upload_dir();
	$upload_path = trailingslashit( $upload_dir['basedir'] );

	// WordPress >= 2.6.2: determine the absolute $file_path (http://core.trac.wordpress.org/changeset/8796)
	if ( FALSE === strpos($file, WP_CONTENT_DIR) ) {
		$store_absolute_path = false;
		$file_path =  $upload_path . $file_path;
	}
	

	list($file, $msg) = wp_smushit($file_path);
	$meta['file'] = $file;
	$meta['wp_smushit'] = $msg;

	// strip absolute path for Wordpress >= 2.6.2
	if ( FALSE === $store_absolute_path ) {
		$meta['file'] = str_replace($upload_path, '', $meta['file']);
	}

	// no resized versions, so we can exit
	if ( !isset($meta['sizes']) )
		return $meta;

	// meta sizes don't contain a path, so we calculate one
	$base_dir = dirname($file_path) . '/';


	foreach($meta['sizes'] as $size => $data) {
		list($smushed_file, $results) = wp_smushit($base_dir . $data['file']);

		$meta['sizes'][$size]['file'] = str_replace($base_dir, '', $smushed_file);
		$meta['sizes'][$size]['wp_smushit'] = $results;
	}

	return $meta;
}


/**
 * Post an image to Smush.it.
 *
 * @param   string          $file_url     URL of the file to send to Smush.it
 * @return  string|boolean  Returns the JSON response on success or else FALSE
 */
function wp_smushit_post($file_url) {
	$req = sprintf( SMUSHIT_REQ_URL, urlencode( $file_url ) );

	$data = false;

	if ( function_exists('wp_remote_get') ) {
		$response = wp_remote_get($req, array('user-agent' => WP_SMUSHIT_UA));

		if( is_wp_error( $response ) ) {
			wp_die( $response );
		}

		$data = wp_remote_retrieve_body($response);
	} else {
		wp_die( __('WP Smush.it requires WordPress 2.8 or greater', WP_SMUSHIT_DOMAIN) );
	}
	
	return $data;
}


/**
 * Print column header for Smush.it results in the media library using
 * the `manage_media_columns` hook.
 */
function wp_smushit_columns($defaults) {
	$defaults['smushit'] = 'Smush.it';
	return $defaults;
}

/**
 * Return the filesize in a humanly readable format.
 * Taken from http://www.php.net/manual/en/function.filesize.php#91477
 */
function wp_smushit_format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Print column data for Smush.it results in the media library using
 * the `manage_media_custom_column` hook.
 */
function wp_smushit_custom_column($column_name, $id) {
    if( $column_name == 'smushit' ) {
    	$data = wp_get_attachment_metadata($id);
    	if ( isset($data['wp_smushit']) && !empty($data['wp_smushit']) ) {
    		print $data['wp_smushit'];
    		printf("<br><a href=\"admin.php?action=wp_smushit_manual&amp;attachment_ID=%d\">%s</a>",
			         $id,
			         __('Re-smush', WP_SMUSHIT_DOMAIN));
    	} else {
    		print __('Not processed', WP_SMUSHIT_DOMAIN);
    		printf("<br><a href=\"admin.php?action=wp_smushit_manual&amp;attachment_ID=%d\">%s</a>",
			         $id,
			         __('Smush.it now!', WP_SMUSHIT_DOMAIN));
    	}
    }
}