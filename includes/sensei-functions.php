<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Global Sensei functions
 */

function is_sensei() {
	global $post;

	$is_sensei = false;

	$post_types = array( 'lesson', 'course', 'quiz', 'question' );
	$taxonomies = array( 'course-category', 'quiz-type', 'question-type', 'lesson-tag', 'module' );

	if ( is_post_type_archive( $post_types ) || is_singular( $post_types ) || is_tax( $taxonomies ) ) {

		$is_sensei = true;

	}

	if ( is_object( $post ) && ! is_wp_error( $post ) ) {

		$course_page_id     = intval( Sensei()->settings->settings['course_page'] );
		$my_courses_page_id = intval( Sensei()->settings->settings['my_course_page'] );

		if ( in_array( $post->ID, array( $course_page_id, $my_courses_page_id ) ) ) {

			$is_sensei = true;

		}
	}

	return apply_filters( 'is_sensei', $is_sensei, $post );
}

/**
 * Determine if the current user is and admin that
 * can acess all of Sensei without restrictions
 *
 * @since 1.4.0
 * @return boolean
 */
function sensei_all_access() {

	$access = current_user_can( 'manage_sensei' ) || current_user_can( 'manage_sensei_grades' );

	/**
	 * Filter sensei_all_access function result
	 * which determinse if the current user
	 * can access all of Sensei without restrictions
	 *
	 * @since 1.4.0
	 * @param bool $access
	 */
	return apply_filters( 'sensei_all_access', $access );

} // End sensei_all_access()

if ( ! function_exists( 'sensei_light_or_dark' ) ) {

	/**
	 * Detect if we should use a light or dark colour on a background colour
	 *
	 * @access public
	 * @param mixed  $color
	 * @param string $dark (default: '#000000')
	 * @param string $light (default: '#FFFFFF')
	 * @return string
	 */
	function sensei_light_or_dark( $color, $dark = '#000000', $light = '#FFFFFF' ) {

		$hex = str_replace( '#', '', $color );

		$c_r        = hexdec( substr( $hex, 0, 2 ) );
		$c_g        = hexdec( substr( $hex, 2, 2 ) );
		$c_b        = hexdec( substr( $hex, 4, 2 ) );
		$brightness = ( ( $c_r * 299 ) + ( $c_g * 587 ) + ( $c_b * 114 ) ) / 1000;

		return $brightness > 155 ? $dark : $light;
	}
}

if ( ! function_exists( 'sensei_rgb_from_hex' ) ) {

	/**
	 * Hex darker/lighter/contrast functions for colours
	 *
	 * @access public
	 * @param mixed $color
	 * @return string
	 */
	function sensei_rgb_from_hex( $color ) {
		$color = str_replace( '#', '', $color );
		// Convert shorthand colors to full format, e.g. "FFF" -> "FFFFFF"
		$color = preg_replace( '~^(.)(.)(.)$~', '$1$1$2$2$3$3', $color );

		$rgb      = [];
		$rgb['R'] = hexdec( $color[0] . $color[1] );
		$rgb['G'] = hexdec( $color[2] . $color[3] );
		$rgb['B'] = hexdec( $color[4] . $color[5] );
		return $rgb;
	}
}

if ( ! function_exists( 'sensei_hex_darker' ) ) {

	/**
	 * Hex darker/lighter/contrast functions for colours
	 *
	 * @access public
	 * @param mixed $color
	 * @param int   $factor (default: 30)
	 * @return string
	 */
	function sensei_hex_darker( $color, $factor = 30 ) {
		$base  = sensei_rgb_from_hex( $color );
		$color = '#';

		foreach ( $base as $k => $v ) :
			$amount      = $v / 100;
			$amount      = round( $amount * $factor );
			$new_decimal = $v - $amount;

			$new_hex_component = dechex( $new_decimal );
			if ( strlen( $new_hex_component ) < 2 ) :
				$new_hex_component = '0' . $new_hex_component;
			endif;
			$color .= $new_hex_component;
		endforeach;

		return $color;
	}
}

if ( ! function_exists( 'sensei_hex_lighter' ) ) {

	/**
	 * Hex darker/lighter/contrast functions for colours
	 *
	 * @access public
	 * @param mixed $color
	 * @param int   $factor (default: 30)
	 * @return string
	 */
	function sensei_hex_lighter( $color, $factor = 30 ) {
		$base  = sensei_rgb_from_hex( $color );
		$color = '#';

		foreach ( $base as $k => $v ) :
			$amount      = 255 - $v;
			$amount      = $amount / 100;
			$amount      = round( $amount * $factor );
			$new_decimal = $v + $amount;

			$new_hex_component = dechex( $new_decimal );
			if ( strlen( $new_hex_component ) < 2 ) :
				$new_hex_component = '0' . $new_hex_component;
			endif;
			$color .= $new_hex_component;
		endforeach;

		return $color;
	}
}

/**
 * Provides an interface to allow us to deprecate hooks while still allowing them
 * to work, but giving the developer an error message.
 *
 * @since 1.9.0
 *
 * @param $hook_tag
 * @param $version
 * @param $alternative
 * @param array       $args
 */
function sensei_do_deprecated_action( $hook_tag, $version, $alternative = '', $args = array() ) {

	if ( has_action( $hook_tag ) ) {

		$error_message = sprintf( __( "SENSEI: The hook '%1\$s', has been deprecated since '%2\$s'.", 'sensei-lms' ), $hook_tag, $version );

		if ( ! empty( $alternative ) ) {

			// translators: Placeholder is the alternative action name.
			$error_message .= sprintf( __( "Please use '%s' instead.", 'sensei-lms' ), $alternative );

		}

		trigger_error( esc_html( $error_message ) );
		do_action( $hook_tag, $args );

	}

}

/**
 * Check the given post or post type id is a of the
 * the course post type.
 *
 * @since 1.9.0
 *
 * @param $post_id
 * @return bool
 */
function sensei_is_a_course( $post ) {

	return 'course' == get_post_type( $post );

}

/**
 * Get registration url.
 *
 * @since 3.15.0
 *
 * @param bool   $return_wp_registration_url Whether return the url if it should use the WP registration url.
 * @param string $redirect                   Redirect url after registration.
 *
 * @return string|null The registration url.
 *                     If wp registration is the return case and $return_wp_registration_url is
 *                     true, it returns the url, otherwise it returns null.
 */
function sensei_user_registration_url( bool $return_wp_registration_url = true, string $redirect = '' ) {
	/**
	 * Filter to force Sensei to output the default WordPress user
	 * registration link.
	 *
	 * @param bool $wp_register_link default false
	 *
	 * @since 1.9.0
	 */
	$wp_register_link = apply_filters( 'sensei_use_wp_register_link', false );
	$registration_url = '';
	$settings         = Sensei()->settings->get_settings();

	if ( empty( $settings['my_course_page'] ) || $wp_register_link ) {
		if ( ! $return_wp_registration_url ) {
			return null;
		}

		$registration_url = wp_registration_url();
	} else {
		$registration_url = get_permalink( intval( $settings['my_course_page'] ) );
	}

	if ( ! empty( $redirect ) ) {
		$registration_url = add_query_arg( 'redirect_to', $redirect, $registration_url );
	}

	/**
	 * Filter the registration URL.
	 *
	 * @since x.x.x
	 * @hook sensei_registration_url
	 *
	 * @param {string} $registration_url Registration URL.
	 * @param {string} $redirect         Redirect url after registration.
	 *
	 * @return {string} Returns filtered registration URL.
	 */
	return apply_filters( 'sensei_registration_url', $registration_url, $redirect );
}

/**
 * Determine the login link
 * on the frontend.
 *
 * This function will return the my-courses page link
 * or the wp-login link.
 *
 * @since 1.9.0
 * @since 3.15.0 Introduce redirect param.
 *
 * @param string $redirect Redirect url after login.
 *
 * @return string The login url.
 */
function sensei_user_login_url( string $redirect = '' ) {
	$login_url          = '';
	$my_courses_page_id = intval( Sensei()->settings->get( 'my_course_page' ) );
	$page               = get_post( $my_courses_page_id );

	if ( $my_courses_page_id && isset( $page->ID ) && 'page' == get_post_type( $page->ID ) ) {
		$my_courses_url = get_permalink( $page->ID );
		if ( ! empty( $redirect ) ) {
			$my_courses_url = add_query_arg( 'redirect_to', $redirect, $my_courses_url );
		}

		$login_url = $my_courses_url;
	} else {
		$login_url = wp_login_url( $redirect );
	}

	/**
	 * Filter the login URL.
	 *
	 * @since x.x.x
	 * @hook sensei_login_url
	 *
	 * @param {string} $login_url Login URL.
	 * @param {string} $redirect  Redirect url after login.
	 *
	 * @return {string} Returns filtered login URL.
	 */
	return apply_filters( 'sensei_login_url', $login_url, $redirect );
}

/**
 * Checks the settings to see
 * if a user must be logged in to view content
 *
 * duplicate of Sensei()->access_settings().
 *
 * @since 1.9.0
 * @return bool
 */
function sensei_is_login_required() {

	$login_required = isset( Sensei()->settings->settings['access_permission'] ) && ( true == Sensei()->settings->settings['access_permission'] );

	return $login_required;

}

/**
 * Checks if this theme supports Sensei templates.
 *
 * @since 1.12.0
 * @return bool
 */
function sensei_does_theme_support_templates() {
	$current_theme = wp_get_theme()->get_template();
	$themes        = Sensei()->theme_integration_loader->get_supported_themes();

	return in_array( $current_theme, $themes, true ) || current_theme_supports( 'sensei' );
}

if ( ! function_exists( 'sensei_check_woocommerce_version' ) ) {
	/**
	 * Check if WooCommerce version is greater than the one specified.
	 *
	 * @deprecated 2.0.0
	 *
	 * @param string $version Version to check against.
	 * @return boolean
	 */
	function sensei_check_woocommerce_version( $version = '2.1' ) {
		_deprecated_function( __FUNCTION__, '2.0.0' );

		if ( method_exists( 'Sensei_WC', 'is_woocommerce_active' ) && Sensei_WC::is_woocommerce_active() ) {
			global $woocommerce;
			if ( version_compare( $woocommerce->version, $version, '>=' ) ) {
				return true;
			}
		}
		return false;
	}
}

/**
 * Track a Sensei event.
 *
 * @since 2.1.0
 *
 * @param string $event_name The name of the event, without the `sensei_` prefix.
 * @param array  $properties The event properties to be sent.
 */
function sensei_log_event( $event_name, $properties = [] ) {
	$properties = array_merge(
		Sensei_Usage_Tracking_Data::get_event_logging_base_fields(),
		$properties
	);

	/**
	 * Explicitly disable usage tracking from being sent.
	 *
	 * @since 2.1.0
	 *
	 * @param bool   $log_event    Whether we should log the event.
	 * @param string $event_name   The name of the event, without the `sensei_` prefix.
	 * @param array  $properties   The event properties to be sent.
	 */
	if ( false === apply_filters( 'sensei_log_event', true, $event_name, $properties ) ) {
		return;
	}

	Sensei_Usage_Tracking::get_instance()->send_event( $event_name, $properties );
}
