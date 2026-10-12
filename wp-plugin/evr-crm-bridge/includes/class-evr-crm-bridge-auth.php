<?php
/**
 * API key management and request authentication.
 *
 * @package EVR_CRM_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores the shared secret and validates incoming REST requests.
 */
final class EVR_CRM_Bridge_Auth {

	/**
	 * Create the API key when it does not exist yet.
	 *
	 * @return void
	 */
	public static function ensure_key() {
		if ( '' === self::get_key() ) {
			self::regenerate_key();
		}
	}

	/**
	 * Replace the API key with a fresh random value.
	 *
	 * @return string New key.
	 */
	public static function regenerate_key() {
		$key = wp_generate_password( 48, false );

		// Delete first so add_option() can force autoload = no.
		delete_option( EVR_CRM_BRIDGE_KEY_OPTION );
		add_option( EVR_CRM_BRIDGE_KEY_OPTION, $key, '', 'no' );

		return $key;
	}

	/**
	 * Return the stored key or an empty string.
	 *
	 * @return string
	 */
	public static function get_key() {
		$key = get_option( EVR_CRM_BRIDGE_KEY_OPTION, '' );

		return is_string( $key ) ? $key : '';
	}

	/**
	 * REST permission callback shared by every route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check( $request ) {
		$stored   = self::get_key();
		$provided = self::extract_key( $request );

		if ( '' !== $stored && '' !== $provided && hash_equals( $stored, $provided ) ) {
			return true;
		}

		return new WP_Error(
			'evr_crm_unauthorized',
			__( 'Clave de API inválida o ausente.', 'evr-crm-bridge' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Read the key from `Authorization: Bearer` or `X-CRM-Key`.
	 *
	 * Some Apache/LiteSpeed setups strip the Authorization header, which is why
	 * X-CRM-Key is accepted too.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	public static function extract_key( $request ) {
		$custom = $request->get_header( 'x_crm_key' );
		if ( is_string( $custom ) && '' !== trim( $custom ) ) {
			return trim( $custom );
		}

		$authorization = $request->get_header( 'authorization' );
		if ( ( ! is_string( $authorization ) || '' === $authorization ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$authorization = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( is_string( $authorization ) && preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $match ) ) {
			return $match[1];
		}

		return '';
	}
}
