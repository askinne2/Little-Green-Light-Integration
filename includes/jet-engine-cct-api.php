<?php

/**
 * JetEngine CCT-related API functions to use in theme or plugin
 *
 * Theme usage - include get_theme_file_path( 'jet-engine-cct-api.php' );
 * Plugin usage - include PLUGIN_PATH . 'path-to-file-inside-plugin/jet-engine-cct-api.php';
 */

/**
 * Shortcode to get any CCT field inside JetEngine loop.
 * Also function can be used to get any current CCT property without shortode, by plain call.
 * Example - jet_cct_api_get_prop( array( 'prop' => '_ID' ) );
 * Optionally can be passed additional parameters - slug and ID - to get specific item data
 */
if ( ! function_exists( 'jet_cct_api_get_prop' ) ) {

	function jet_cct_api_get_prop( $atts = array() ) {

		$atts = shortcode_atts(
			array(
				'prop'   => '_ID',
				'ID'     => false,
				'slug'   => false,
				'filter' => null,
			),
			$atts
		);

		if ( ! function_exists( 'jet_engine' ) ) {
			return false;
		}

		$prop    = ! empty( $atts['prop'] ) ? sanitize_key( $atts['prop'] ) : '_ID';
		$item_id = ! empty( $atts['ID'] ) ? absint( $atts['ID'] ) : false;
		$slug    = ! empty( $atts['slug'] ) ? sanitize_key( $atts['slug'] ) : false;

		if ( $item_id && $slug ) {
			$current_object = jet_cct_api_get_item( $slug, $item_id );
		} else {
			$current_object = jet_engine()->listings->data->get_current_object();
		}

		if ( ! $current_object ) {
			return false;
		}

		if ( is_array( $current_object ) && ! isset( $current_object['cct_slug'] ) ) {
			return false;
		} elseif ( is_object( $current_object ) && ! isset( $current_object->cct_slug ) ) {
			return false;
		}

		if ( is_object( $current_object ) ) {
			$current_object = get_object_vars( $current_object );
		}

		if ( isset( $current_object[ $prop ] ) ) {
			return ! empty( $atts['filter'] ) && is_callable( $atts['filter'] ) ? call_user_func( $atts['filter'], $current_object[ $prop ] ) : $current_object[ $prop ];
		} else {
			return null;
		}

	}

	add_action( 'init', function() {
		add_shortcode( 'jet_cct_api_get_prop', 'jet_cct_api_get_prop' );
	} );

}

/**
 * Function to query CCT items list by list of arguments
 */
if ( ! function_exists( 'jet_cct_api_query' ) ) {

	/**
	 * Format:
	 * $args = array(
	 * 	array(
	 * 		'field'    => 'field_name',
	 * 		'operator' => '=',
	 * 		'value'    => 'value to compare',
	 * 		'type'     => 'auto',
	 * 	),
	 * 	array(
	 * 		'field'    => 'field_name_2',
	 * 		'operator' => 'IN',
	 * 		'value'    => array( 'value 1', 'value 2' ),
	 * 		'type'     => 'auto',
	 * 	)
	 * )
	 *
	 * allowed operators: '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'
	 * allowed types: 'auto', 'integer', 'float', 'timestamp', 'date', 'char'
	 *
	 * $order = array(
	 * 	array(
	 * 		'orderby' => 'field_name',
	 * 		'order'   => 'desc',
	 * 		'type'    => 'integer',
	 * 	)
	 * )
	 *
	 * allowed orders: 'asc', 'desc'
	 * allowed types: 'integer', 'float', 'timestamp', 'date', 'char'
	 */
	function jet_cct_api_query( $slug = false, $args = array(), $limit = 0, $offset = 0, $order = array() ) {

		if ( ! $slug ) {
			return false;
		}

		$cct = jet_cct_api_get_type( $slug );

		if ( ! $cct ) {
			return false;
		}

		return $cct->db->query( $args, $limit, $offset, $order );

	}

}

/**
 * Function to query CCT items list by list of arguments
 */
if ( ! function_exists( 'jet_cct_api_update_item' ) ) {
	/**
	 * $itemarray = array(
	 * 	'_ID' => 15,
	 * 	'field_1' => 'value 1',
	 * 	'field_2' => 'value 2',
	 * );
	 *
	 * if _ID is set function will update existing item,
	 * if not set - function will insert new item
	 */
	function jet_cct_api_update_item( $slug = false, $itemarray = array() ) {

		if ( ! $slug ) {
			return false;
		}

		$cct = jet_cct_api_get_type( $slug );

		if ( ! $cct ) {
			return false;
		}

		$handler = $cct->get_item_handler();

		return $handler->update_item( $itemarray );

	}
}

/**
 * Function to get specific CCT item object by CCT slug and item ID
 */
if ( ! function_exists( 'jet_cct_api_get_item' ) ) {
	function jet_cct_api_get_item( $slug = false, $item_id = false ) {

		if ( ! $slug || ! $item_id ) {
			return false;
		}

		$cct = jet_cct_api_get_type( $slug );

		if ( ! $cct ) {
			return false;
		}

		$item = $cct->db->get_item( $item_id );

		if ( $item ) {
			if ( is_array( $item ) ) {
				$item['cct_slug'] = $slug;
			} else {
				$item->cct_slug = $slug;
			}
		}

		return $item;
	}
}

/**
 * Function to get related CCT item for the given post ID (or for the crrent post)
 */
if ( ! function_exists( 'jet_cct_api_get_item_for_post' ) ) {
	function jet_cct_api_get_item_for_post( $post_id = null ) {

		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return false;
		}

		$cached = wp_cache_get( 'item_for_' . $post_id, 'jet_cct_api' );

		if ( $cached ) {
			return $cached;
		}

		$module = jet_cct_api_get_module();

		if ( ! $module ) {
			return false;
		}

		$post_type    = get_post_type( $post_id );
		$content_type = $module->manager->get_content_type_for_post_type( $post_type );

		if ( ! $content_type ) {
			return false;
		}

		$slug = $content_type->get_arg( 'slug' );
		$item = Module::instance()->manager->get_item_for_post( $post_id, $content_type );

		return $item;

	}
}

/**
 * Function to get specific CCT object by CCT slug
 */
if ( ! function_exists( 'jet_cct_api_get_type' ) ) {
	function jet_cct_api_get_type( $slug = false ) {

		if ( ! $slug ) {
			return false;
		}

		$module = jet_cct_api_get_module();

		if ( ! $module ) {
			return false;
		}

		return $module->manager->get_content_types( $slug );

	}
}

/**
 * Function to get CCT module object
 */
if ( ! function_exists( 'jet_cct_api_get_module' ) ) {
	function jet_cct_api_get_module() {

		if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) ) {
			return false;
		}

		return \Jet_Engine\Modules\Custom_Content_Types\Module::instance();

	}
}