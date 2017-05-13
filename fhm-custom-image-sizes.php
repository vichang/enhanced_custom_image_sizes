<?php
/*
Plugin Name: Custom Image Sizes With Resize/Delete
Plugin URI: http://theblemish.com
Description: A plugin that creates custom image sizes for image attachments. Based on Filosofo custom image sizes
Author: Freakishly Huge Media
Author URI: http://theblemish.com
Version: 1.2
*/

class FHM_Custom_Image_Sizes {

	public function __construct()
	{
		add_filter('image_downsize', array(&$this, 'filter_image_downsize'), 99, 3);

	}

	/**
	 * Callback for the "image_downsize" filter.
	 *
	 * @param bool $ignore A value meant to discard unfiltered info returned from this filter.
	 * @param int $attachment_id The ID of the attachment for which we want a certain size.
	 * @param string $size_name The name of the size desired.
	 */
	public function filter_image_downsize($ignore = false, $attachment_id = 0, $size_name = 'thumbnail')
	{
		global $_wp_additional_image_sizes;

		// don't resize images in admin panel
		if ( is_admin() ) return false;

		if( is_array($size_name) ) $size_name = implode('x', $size_name);

		$attachment_id = (int) $attachment_id;
		$size_name = trim( $size_name );
		$original_width = 0;
		$original_height = 0;

		$meta = wp_get_attachment_metadata($attachment_id);
		if ( !$meta ) return false;

		// get set dimensions
		if ( isset( $_wp_additional_image_sizes[$size_name]['width'] ) )
			$image_sizes[$size_name]['width'] = intval( $_wp_additional_image_sizes[$size_name]['width'] );
		else
			$image_sizes[$size_name]['width'] = get_option( "{$size_name}_size_w" );

		if ( isset( $_wp_additional_image_sizes[$size_name]['height'] ) )
			$image_sizes[$size_name]['height'] = intval( $_wp_additional_image_sizes[$size_name]['height'] );
		else
			$image_sizes[$size_name]['height'] = get_option( "{$size_name}_size_h" );

		if ( isset( $_wp_additional_image_sizes[$size_name]['crop'] ) )
			$image_sizes[$size_name]['crop'] = intval( $_wp_additional_image_sizes[$size_name]['crop'] );
		else
			$image_sizes[$size_name]['crop'] = get_option( "{$size_name}_crop" );

		if ( isset( $image_sizes[$size_name]['width'] ) || isset( $image_sizes[$size_name]['height'] ) ) {
			// sizes to resize to
			$height = (int) $image_sizes[$size_name]['height'];
			$width = (int) $image_sizes[$size_name]['width'];
			$crop = (bool) $image_sizes[$size_name]['crop'];

			// original file dim
			if (is_array($meta)) {
				if (array_key_exists('width', $meta)) $original_width = $meta['width'];
				if (array_key_exists('height', $meta)) $original_height = $meta['height'];
			}

			// current resized dim
			$current_width = $current_height = 0;

			if (!empty($meta['sizes'][$size_name])) {
				if (array_key_exists('width', $meta['sizes'][$size_name])) $current_width = $meta['sizes'][$size_name]['width'];
				if (array_key_exists('height', $meta['sizes'][$size_name])) $current_height = $meta['sizes'][$size_name]['height'];
			}

			// resized dimensions to compare against
			list(,,,,$comp_x,$comp_y,,) = image_resize_dimensions( $original_width, $original_height, $width, $height, $crop );
		} else {
			$current_width = $comp_x = $current_height = $comp_y = 0;
		}

		/* the requested size does not yet exist for this attachment */
		/* compare resized width and heigh to current */
		if ( ( empty( $meta['sizes'] ) || empty( $meta['sizes'][$size_name] ) ) ||
			( $current_width != $comp_x || $current_height != $comp_y )
		) {
			// if not, see if name is of form [width]x[height] and use that to crop
			if ( preg_match('#^(\d+)x(\d+)$#', $size_name, $matches) ) {
				$height = (int) $matches[2];
				$width = (int) $matches[1];
				$crop = true;
			}

			// important to use isset, !empty will return since 0 = false
			if ( isset( $height ) && isset( $width ) ) {
				$resized_path = $this->_generate_attachment($attachment_id, $comp_x, $comp_y, $crop);
				$fullsize_url = wp_get_attachment_url($attachment_id);
				$file_name = basename($resized_path);

				// to populate metadata
				$uploads = wp_upload_dir();

				// path and url for metadata
				$new_path = _wp_relative_upload_path($resized_path);
				$new_url = str_replace(basename($fullsize_url), $file_name, $fullsize_url);

				// file to delete
				//$unlink_file = $uploads['basedir'] . '/' . str_replace(basename($meta['file']), $meta['sizes'][$size_name]['file'], $meta['file']);

				if ( ! empty( $resized_path ) ) {
					$meta['sizes'][$size_name] = array(
						'file' => $file_name,
						'width' => $comp_x,
						'height' => $comp_y,
						'path' => $new_path,
						'url' => $new_url,
					);

					// delete old file on metadata update success
					//if (wp_update_attachment_metadata($attachment_id, $meta)) unlink($unlink_file);
					wp_update_attachment_metadata($attachment_id, $meta);

					return array(
						$new_url,
						$comp_x,
						$comp_y,
						true
					);
				}
			}
		}

		return false;
	}

	/**
	 * Creates a cropped version of an image for a given attachment ID.
	 *
	 * @param int $attachment_id The attachment for which to generate a cropped image.
	 * @param int $width The width of the cropped image in pixels.
	 * @param int $height The height of the cropped image in pixels.
	 * @param bool $crop Whether to crop the generated image.
	 * @return string The full path to the cropped image.  Empty if failed.
	 */
	private function _generate_attachment($attachment_id = 0, $width = 0, $height = 0, $crop = true)
	{
		$attachment_id = (int) $attachment_id;
		$width = (int) $width;
		$height = (int) $height;
		$crop = (bool) $crop;

		$original_path = get_attached_file($attachment_id);

		// fix a WP bug up to 2.9.2
		if ( ! function_exists('wp_load_image') ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// $resized_path = @image_resize($original_path, $width, $height, $crop);
		$resized_path = wp_get_image_editor( $original_path );

		if (
			! is_wp_error($resized_path) &&
			! is_array($resized_path)
		) {
			$resized_path->resize( $width, $height, $crop );
			$resized_path->set_quality( 70 );
			$saved = $resized_path->save();
			return $saved['path'];

		// perhaps this image already exists.  If so, return it.
		} else {
			$orig_info = pathinfo($original_path);
			$suffix = "{$width}x{$height}";
			if ( isset ( $orig_info['dirname'] ) ) $dir = $orig_info['dirname'];
			else $dir = '';
			if ( isset ( $orig_info['extension'] ) ) $ext = $orig_info['extension'];
			else $ext = '';
			$name = basename($original_path, ".{$ext}");
			$destfilename = "{$dir}/{$name}-{$suffix}.{$ext}";
			if ( file_exists( $destfilename ) ) {
				return $destfilename;
			}
		}

		return '';
	}
}

function initialize_custom_image_sizes()
{
	new FHM_Custom_Image_Sizes;
}

add_action('plugins_loaded', 'initialize_custom_image_sizes');
