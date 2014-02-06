<?php
/***
Plugin Name: Dynamic Featured Image
Plugin URI: http://wordpress.org/plugins/dynamic-featured-image/
Description: Dynamically adds multiple featured image or post thumbnail functionality to your posts, pages and custom post types.
Version: 2.0.1
Author: Ankit Pokhrel
Author URI: http://ankitpokhrel.com.np
License: GPL2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: dynamic-featured-image
Domain Path: /languages

	Copyright (C) 2013 Ankit Pokhrel <ankitpokhrel@gmail.com, http://ankitpokhrel.com.np>,

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
  */

//avoid direct calls to this file
if ( !defined( 'ABSPATH' ) ) {
	header( 'Status: 403 Forbidden' );
	header( 'HTTP/1.1 403 Forbidden' );
	exit();
}

class Dynamic_Featured_Image {

	/**
	 * Current version of the plugin.
	 *
	 * @since	1.0.0
	 * @static
	 * @access	public
	 * @var		string	$version
	 */
	public static $version = '2.0.1';

	/**
	 * Constructor. Hooks all interactions to initialize the class.
	 *
	 * @since	1.0.0
	 * @access	public
	 *
	 * @see	add_action()
	 *
	 * @return	void
	 */
	public function __construct() {

		if ( is_admin() ) {
			add_action( 'in_plugin_update_message-' . plugin_basename(__FILE__), array( $this, 'update_notice' ) );
		}

		add_action( 'admin_enqueue_scripts',	array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'add_meta_boxes',			array( $this, 'initialize_featured_box' ) );
		add_action( 'save_post',				array( $this, 'save_meta' ) );
		add_action( 'plugins_loaded',			array( $this, 'load_plugin_textdomain' ) );

		//handle ajax request
		add_action( 'wp_ajax_nopriv_dfiMetaBox_callback',	array( $this, 'ajax_callback' ) );
		add_action( 'wp_ajax_dfiMetaBox_callback',			array( $this, 'ajax_callback' ) );

	} // END __construct()

	public function enqueue_admin_scripts( $hook ) {

		//enqueue styles
		wp_enqueue_style( 'thickbox' );
		wp_enqueue_style( 'style-dfi', plugins_url( '/css/style-dfi.css', __FILE__ ), array(), self::$version );

		//register scripts
		wp_register_script( 'scripts-dfi', plugins_url( '/js/script-dfi.js', __FILE__), array( 'jquery','media-upload','thickbox' ), self::$version );

		//enqueue scripts
		wp_enqueue_script( 'thickbox' );
		wp_enqueue_script( 'media-models' );
		wp_enqueue_script( 'media-upload' );
		wp_enqueue_script( 'scripts-dfi' );

	} // END initialize_components()

	/**
	 * Add featured meta boxes dynamically
	 */
	public function initialize_featured_box() {

		global $post;
		$data = get_post_custom( $post->ID );

		$totalFeatured = 0;
		if ( isset( $data['dfiFeatured'][0] ) && !empty( $data['dfiFeatured'][0] ) ) {
			$featuredData = unserialize($data['dfiFeatured'][0]);
			$totalFeatured = count( $featuredData );
		}

		$filter = array( 'attachment', 'revision', 'nav_menu_item' );
		$postTypes = get_post_types();
		$postTypes = array_diff( $postTypes, $filter );

		if ( $totalFeatured >= 1 ) {
			$i = 2;
			foreach ( $featuredData as $featured ) {
				foreach ( $postTypes as $type ) {
					add_meta_box(
						'dfiFeaturedMetaBox-' . $i,
						__('Featured Image ') . $i,
						array( $this, 'featured_meta_box' ),
						$type,
						'side',
						'low',
						array( $featured, $i + 1 )
					);
					add_filter( "postbox_classes_{$type}_dfiFeaturedMetaBox-" . $i, array( $this, 'add_metabox_classes' ) );
				}

				$i++;
			}
		} else {
			foreach ( $postTypes as $type ) {
				add_meta_box(
					'dfiFeaturedMetaBox',
					__( 'Featured Image 2', 'dynamic-featured-image' ),
					array( $this, 'featured_meta_box' ),
					$type,
					'side',
					'low',
					array( null, null )
				);
				add_filter( "postbox_classes_{$type}_dfiFeaturedMetaBox", array( $this, 'add_metabox_classes' ) );
			}
		}

	} // END initialize_featured_box()

	public function featured_meta_box( $post, $featured ) {

		$featuredImg	= is_null( $featured['args'][0]) ? '' : $featured['args'][0];
		$featuredId		= is_null( $featured['args'][1]) ? 2 : --$featured['args'][1];

		$featuredImgTrimmed = $featuredImgFull = $featuredImg;
		if ( !empty( $featuredImg ) ) {
			list( $featuredImgTrimmed, $featuredImgFull ) = explode( ',', $featuredImg );
		}

		$thumbnail = ( strpos( $featuredImgFull, 'wp-content' ) !== false ) ? $this->get_image_thumb( site_url() . $featuredImgFull, 'medium' ) : $featuredImgFull;

		//Add a nonce field
		wp_nonce_field(plugin_basename(__FILE__), 'dfi_fimageplug-' . $featuredId);
		?>
			<a href="javascript:void(0)" class='dfiFeaturedImage <?php if (isset($featuredImgTrimmed) && !empty($featuredImgTrimmed)) echo 'hasFeaturedImage' ?>' title="Set Featured Image" data-post-id="<?php the_ID() ?>"><span></span></a><br/>
			<img src="<?php if (isset($thumbnail) && !is_null($thumbnail)) echo $thumbnail; ?>" class='dfiImg <?php if (!isset($featuredImgTrimmed) || is_null($featuredImgTrimmed)) echo 'dfiImgEmpty' ?>'/>
			<div class='dfiLinks'>
				<a href="javascript:void(0)" data-id='<?php echo $featuredId ?>' class='dfiAddNew' title="Add New"></a>
				<a href="javascript:void(0)" class='dfiRemove' title="Remove"></a>
			</div>
			<div class='dfiClearFloat'></div>
			<input type='hidden' name="dfiFeatured[]" value="<?php echo $featuredImg ?>"  class="dfiImageHolder" />
		<?php

	} // END featured_meta_box()

	public function ajax_callback() {

		$featuredId = isset($_POST['id']) ? (int) strip_tags(trim($_POST['id'])) : null;

		if ( is_null( $featuredId ) ) {
			return;
		}

		wp_nonce_field( plugin_basename(__FILE__), 'dfi_fimageplug-' . $featuredId );
		?>
			  <a href="javascript:void(0)" class='dfiFeaturedImage' title="Set Featured Image"><span></span></a><br/>
			   <img src="" class='dfiImg dfiImgEmpty'/>
			   <div class='dfiLinks'>
				<a href="javascript:void(0)" data-id='<?php echo $featuredId ?>' class='dfiAddNew' title="Add New"></a>
				<a href="javascript:void(0)" class='dfiRemove' title="Remove"></a>
			   </div>
			   <div class='dfiClearFloat'></div>
			   <input type='hidden' name="dfiFeatured[]" value="" class="dfiImageHolder" />
		<?php
		die();

	} // END MetaBox_callback())

	/**
	 * Add custom class, featured-meta-box to meta box
	 */
	public function add_metabox_classes( $classes ) {

		array_push( $classes, 'featured-meta-box' );

		return $classes;

	} // END add_metabox_classes()

	/**
	 * Update featured images
	 */
	public function save_meta( $post_id ) {

		$featuredIds = array();
		$keys = array_keys( $_POST );

		foreach ( $keys as $key ) {
			if ( preg_match( '/dfi_fimageplug-.$/', $key ) ) {
				$featuredIds[] = $key;
			}
		}

		//Verify nonce
		foreach ( $featuredIds as $nonceId ) {
			if ( !wp_verify_nonce( $_POST[$nonceId], plugin_basename(__FILE__) ) ) {
				return;
			}
		}

		//Check autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		//Check permission before saving data
		if ( !empty( $_POST ) && current_user_can( 'edit_posts', $post_id ) ) {
			if ( isset( $_POST['dfiFeatured'] ) ) {
				update_post_meta( $post_id, 'dfiFeatured', $_POST['dfiFeatured'] );
			}
		}

	} // END save_meta()

	/**
	 * Add update notice
	 */
	public function update_notice() {

		$info = __( 'ATTENTION! Please read the <a href="https://github.com/ankitpokhrel/Dynamic-Featured-Image/wiki" target="_blank">DOCUMENTATION</a> properly before update.', 'dynamic-featured-image');
		echo '<div style="color:red; padding:7px 0;">' . strip_tags( $info, '<a><b><i><span>' ) . '</div>';

	} // END update_notice()

	/** Helper functions */
	/**
	 * Get attachment id of the image by image url
	 *
	 * @return String
	 */
	public function get_image_id( $image_url ) {

		global $wpdb;
		$prefix = $wpdb->prefix;
		$attachment = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM " . $prefix . "posts" . " WHERE guid= %s", $image_url ) );

		return empty( $attachment ) ? null : $attachment[0];

	} // END get_image_id()

	/**
	 * Get image url of the image by attachment id
	 *
	 * @return String
	 */
	public function get_image_url( $attachmentId, $size = 'full' ) {

		$image_thumb = wp_get_attachment_image_src( $attachmentId, $size );

		return empty( $image_thumb ) ? null : $image_thumb[0];

	} // END get_image_url()

	/**
	 * Get image thumbnail url of specific size by image url
	 *
	 * @return String
	 */
	public function get_image_thumb( $image_url, $size = 'thumbnail' ) {

		$attachment_id = $this->get_image_id( $image_url );
		$image_thumb = wp_get_attachment_image_src( $attachment_id, $size );

		return empty( $image_thumb ) ? null : $image_thumb[0];

	} // END get_image_thumb()

	/**
	 * Get image title
	 *
	 * @return String
	 */
	public function get_image_title( $image_url ) {

		global $wpdb;
		$prefix = $wpdb->prefix;
		$post_title = $wpdb->get_col( $wpdb->prepare( "SELECT post_title FROM " . $prefix . "posts" . " WHERE guid = %s", $image_url ) );

		return empty( $post_title ) ? null : $post_title[0];

	} // END get_image_title()

	/**
	 * Get image title by id
	 *
	 * @return String
	 */
	public function get_image_title_by_id( $attachment_id ) {

		global $wpdb;
		$prefix = $wpdb->prefix;
		$post_title = $wpdb->get_col( $wpdb->prepare( "SELECT post_title FROM " . $prefix . "posts" . " WHERE ID = %d", $attachment_id ) );

		return empty($post_title) ? null : $post_title[0];

	} // END get_image_title_by_id()

	/**
	 * Get image caption
	 *
	 * @return String
	 */
	public function get_image_caption( $image_url ) {

		global $wpdb;
		$prefix = $wpdb->prefix;
		$post_caption = $wpdb->get_col( $wpdb->prepare("SELECT post_excerpt FROM " . $prefix . "posts" . " WHERE guid = %s", $image_url ) );

		return empty( $post_caption ) ? null : $post_caption[0];

	} // END get_image_caption()

	/**
	 * Get image caption by id
	 *
	 * @return String
	 */
	public function get_image_caption_by_id( $attachment_id ) {

		global $wpdb;
		$prefix = $wpdb->prefix;
		$post_caption = $wpdb->get_col($wpdb->prepare("SELECT post_excerpt FROM " . $prefix . "posts" . " WHERE ID = %d", $attachment_id));

		return empty( $post_caption ) ? null : $post_caption[0];

	} // END get_image_caption_by_id()

	/**
	 * Get image alternate text
	 *
	 * @return String
	 */
	public function get_image_alt( $image_url ) {

		global $wpdb;
		$prefix = $wpdb->prefix;
		$attachment = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM " . $prefix . "posts" . " WHERE guid = %s", $image_url ) );

		$alt = null;
		if ( !empty( $attachment ) ) {
			$alt = get_post_meta( $attachment[0], '_wp_attachment_image_alt' );
		}

		return ( is_null( $alt ) || empty( $alt ) ) ? null : $alt[0];

	} // END get_image_alt()

	/**
	 * Get image alternate text by attachment id
	 *
	 * @return String
	 */
	public function get_image_alt_by_id( $attachment_id ) {

		$alt = get_post_meta($attachment_id, '_wp_attachment_image_alt');

		return empty($alt) ? null : $alt[0];

	} // END get_image_alt_by_id()

	/**
	 * Get all attachment ids of the post
	 *
	 * @return Array
	 */
	public function get_post_attachment_ids( $post_id ) {

		$dfiImages = get_post_custom( $post_id );
		$dfiImages = ( isset( $dfiImages['dfiFeatured'][0] ) ) ? @array_filter( unserialize( $dfiImages['dfiFeatured'][0] ) ) : array();

		$retVal = array();
		if ( !empty( $dfiImages ) && is_array( $dfiImages ) ) {
			foreach ( $dfiImages as $dfiImage ) {
				list( $dfiImageTrimmed, $dfiImageFull ) = explode( ',', $dfiImage );

				$retVal[] = $this->get_image_id( site_url() . $dfiImageFull );
			}
		}

		return $retVal;

	} // END get_post_attachment_ids()

	/**
	 * Check if the image is attached with the particular post
	 *
	 * @return boolean
	 */
	public function is_attached( $attachment_id, $post_id ) {

		$attachment_ids = $this->get_post_attachment_ids( $post_id );

		return in_array( $attachment_id, $attachment_ids ) ? true : false;

	} // END is_attached()

	/**
	 * Retrieve featured images for specific post(s)
	 *
	 * @return Array
	 */
	public function get_featured_images( $post_id = null ) {

		if ( is_null( $post_id ) ) {
			global $post;
			$post_id = $post->ID;
		}

		$dfiImages = get_post_custom( $post_id );
		$dfiImages = ( isset( $dfiImages['dfiFeatured'][0] ) ) ? @array_filter( unserialize( $dfiImages['dfiFeatured'][0] ) ) : array();

		$retImages = array();
		if ( !empty( $dfiImages ) && is_array( $dfiImages ) ) {
			$count = 0;
			foreach ( $dfiImages as $dfiImage ) {
				@list( $dfiImageTrimmed, $dfiImageFull ) = explode( ',', $dfiImage );
				if ( strpos( $dfiImageFull, 'wp-content' ) !== false ) {
					$retImages[$count]['thumb']			= site_url() . $dfiImageTrimmed;
					$retImages[$count]['full']			= site_url() . $dfiImageFull;
					$retImages[$count]['attachment_id']	= $this->get_image_id( site_url() . $dfiImageFull );
				} else {
					$retImages[$count]['thumb']			= $dfiImageTrimmed;
					$retImages[$count]['full']			= $dfiImageFull;
					$retImages[$count]['attachment_id']	= $this->get_image_id( site_url() . $dfiImageFull );
				}


				$count++;
			}
		}

		return ( !empty( $retImages ) ) ? $retImages : null;

	} // END get_featured_images()

	/**
	 * Load the plugin's textdomain hooked to 'plugins_loaded'.
	 *
	 * @since	1.0.0
	 * @access	public
	 *
	 * @see		load_plugin_textdomain()
	 * @see		plugin_basename()
	 * @action	plugins_loaded
	 *
	 * @return	void
	 */
	public function load_plugin_textdomain() {

		load_plugin_textdomain(
			'dynamic-featured-images',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages/'
		);

	} // END load_plugin_textdomain()

} // END class Dynamic_Featured_Image

/**
 * Instantiate the main class
 *
 * @since	1.0.0
 * @access	public
 *
 * @var	object	$dynamic_featured_image holds the instantiated class {@uses Dynamic_Featured_Image}
 */
global $dynamic_featured_image;
$dynamic_featured_image = new Dynamic_Featured_Image();
