<?php
/**
 * Tells the CRM when an order is trashed, restored or deleted.
 *
 * @package EVR_CRM_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * EVR does not react when a WooCommerce order goes to the trash or is deleted, so its rows keep their old
 * updated_at and GET /orders never returns that order again. These hooks move the updated_at of the order's
 * enrollments to now: on the next sync the CRM sees the order as trashed, or in the `deleted` list.
 * Only the timestamp changes; statuses and cupos stay as EVR left them.
 */
final class EVR_CRM_Bridge_Changes {

	/**
	 * Hook into WooCommerce (HPOS and posts storage) and WordPress post events.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'touch_order' ), 20, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'touch_order' ), 20, 1 );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'touch_order' ), 20, 1 );
		add_action( 'trashed_post', array( __CLASS__, 'touch_post' ), 20, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'touch_post' ), 20, 1 );
		add_action( 'deleted_post', array( __CLASS__, 'touch_post' ), 20, 2 );
	}

	/**
	 * Posts storage: only shop orders (and the placeholders HPOS keeps in wp_posts).
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post, passed by deleted_post (the row is already gone by then).
	 * @return void
	 */
	public static function touch_post( $post_id, $post = null ) {
		$type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( ! in_array( $type, array( 'shop_order', 'shop_order_placehold' ), true ) ) {
			return;
		}

		self::touch_order( $post_id );
	}

	/**
	 * Move the order's enrollments to the front of the CRM sync.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public static function touch_order( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );
		if ( ! $order_id || ! EVR_CRM_Bridge_API::evr_tables_exist() ) {
			return;
		}

		$tables = EVR_CRM_Bridge_API::tables();
		// EVR writes these columns in UTC (gmdate), so the cursor keeps its order.
		$wpdb->update( $tables['participant_activities'], array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'order_id' => $order_id ), array( '%s' ), array( '%d' ) );
	}
}
