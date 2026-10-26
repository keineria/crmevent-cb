<?php
/**
 * REST API for the external CRM: reads, plus the writes in EVR_CRM_Bridge_Writer.
 *
 * @package EVR_CRM_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the evr-crm/v1 routes and builds their payloads.
 *
 * The read routes never write. Orders are always read via wc_get_order() so both
 * legacy posts storage and HPOS work.
 */
final class EVR_CRM_Bridge_API {

	const REST_NAMESPACE = 'evr-crm/v1';

	/**
	 * Return the EVR table names this add-on reads.
	 *
	 * @return array<string, string>
	 */
	public static function tables() {
		global $wpdb;

		return array(
			'activities'             => $wpdb->prefix . 'evr_activities',
			'participants'           => $wpdb->prefix . 'evr_participants',
			'order_participants'     => $wpdb->prefix . 'evr_order_participants',
			'participant_activities' => $wpdb->prefix . 'evr_participant_activities',
		);
	}

	/**
	 * Whether every EVR table needed by the bridge exists.
	 *
	 * @return bool
	 */
	public static function evr_tables_exist() {
		global $wpdb;

		static $exists = null;
		if ( null !== $exists ) {
			return $exists;
		}

		$exists = true;
		foreach ( self::tables() as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! is_string( $found ) || strtolower( $found ) !== strtolower( $table ) ) {
				$exists = false;
				break;
			}
		}

		return $exists;
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return function_exists( 'wc_get_order' ) && function_exists( 'WC' );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$permission = array( 'EVR_CRM_Bridge_Auth', 'check' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/activities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'activities' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'orders' ),
				'permission_callback' => $permission,
				'args'                => array(
					'after_updated' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'after_order'   => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'created_after' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'         => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/orders/lookup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'lookup_orders' ),
				'permission_callback' => $permission,
			)
		);

		EVR_CRM_Bridge_Writer::register_routes( $permission );
	}

	/**
	 * GET /ping.
	 *
	 * @return WP_REST_Response
	 */
	public static function ping() {
		return new WP_REST_Response(
			array(
				'ok'             => true,
				'bridge_version' => EVR_CRM_BRIDGE_VERSION,
				'evr_active'     => self::evr_tables_exist(),
				'woocommerce'    => self::woocommerce_active(),
				'site'           => home_url(),
				'time'           => gmdate( 'c' ),
			),
			200
		);
	}

	/**
	 * GET /activities.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function activities() {
		global $wpdb;

		if ( ! self::evr_tables_exist() ) {
			return self::unavailable();
		}

		$tables = self::tables();
		$rows   = $wpdb->get_results(
			"SELECT id, type, name, destination, event_date, start_time, end_time, price, capacity_total, reserved_count, confirmed_count, status, updated_at FROM {$tables['activities']} ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$data = array();
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'id'              => (int) $row['id'],
				'type'            => (string) $row['type'],
				'name'            => (string) $row['name'],
				'destination'     => $row['destination'],
				'event_date'      => $row['event_date'],
				'start_time'      => $row['start_time'],
				'end_time'        => $row['end_time'],
				'price'           => (string) $row['price'],
				'capacity_total'  => (int) $row['capacity_total'],
				'reserved_count'  => (int) $row['reserved_count'],
				'confirmed_count' => (int) $row['confirmed_count'],
				'status'          => (string) $row['status'],
				'updated_at'      => $row['updated_at'],
			);
		}

		return new WP_REST_Response( array( 'data' => $data ), 200 );
	}

	/**
	 * GET /orders — cursor paginated by (MAX(updated_at), order_id).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function orders( $request ) {
		if ( ! self::evr_tables_exist() ) {
			return self::unavailable();
		}
		if ( ! self::woocommerce_active() ) {
			return new WP_Error( 'evr_crm_unavailable', __( 'WooCommerce no está activo.', 'evr-crm-bridge' ), array( 'status' => 503 ) );
		}

		$after_updated = trim( (string) $request->get_param( 'after_updated' ) );
		$after_order   = absint( $request->get_param( 'after_order' ) );
		$created_after = trim( (string) $request->get_param( 'created_after' ) );
		// Out-of-range limits are clamped to 1..100 instead of rejected.
		$limit         = min( 100, max( 1, absint( $request->get_param( 'limit' ) ) ) );

		if ( '' !== $after_updated && ! self::is_valid_datetime( $after_updated ) ) {
			return new WP_Error( 'evr_crm_invalid_cursor', __( 'after_updated debe tener el formato Y-m-d H:i:s.', 'evr-crm-bridge' ), array( 'status' => 400 ) );
		}
		if ( '' !== $created_after && ! self::is_valid_date( $created_after ) ) {
			return new WP_Error( 'evr_crm_invalid_date', __( 'created_after debe tener el formato Y-m-d.', 'evr-crm-bridge' ), array( 'status' => 400 ) );
		}

		$page = self::select_order_page( $after_updated, $after_order, $created_after, $limit );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$has_more = count( $page ) > $limit;
		$page     = array_slice( $page, 0, $limit );

		$order_ids    = array_map( 'absint', wp_list_pluck( $page, 'order_id' ) );
		$participants = self::load_participants( $order_ids );

		$data        = array();
		$deleted     = array();
		$next_cursor = null;
		foreach ( $page as $row ) {
			$order_id = (int) $row['order_id'];
			$cursor   = array(
				'updated_at' => (string) $row['cursor_updated'],
				'order_id'   => $order_id,
			);
			// Always advance the cursor, even when the order no longer exists.
			$next_cursor = $cursor;

			$order = wc_get_order( $order_id );
			if ( ! $order || ! is_a( $order, 'WC_Abstract_Order' ) ) {
				// Deleted for good in WooCommerce: the CRM removes its copy.
				$deleted[] = $order_id;
				continue;
			}

			$item                 = self::format_order( $order );
			$item['participants'] = isset( $participants[ $order_id ] ) ? $participants[ $order_id ] : array();
			$item['cursor']       = $cursor;
			$data[]               = $item;
		}

		return new WP_REST_Response(
			array(
				'data'        => $data,
				'deleted'     => $deleted,
				'next_cursor' => $next_cursor,
				'has_more'    => $has_more,
			),
			200
		);
	}

	/**
	 * POST /orders/lookup — the current state of the orders the CRM already has, so it can notice the ones that
	 * were deleted or trashed in WooCommerce. Body: {"ids": [1, 2, ...]} (100 at most).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function lookup_orders( $request ) {
		if ( ! self::evr_tables_exist() ) {
			return self::unavailable();
		}
		if ( ! self::woocommerce_active() ) {
			return new WP_Error( 'evr_crm_unavailable', __( 'WooCommerce no está activo.', 'evr-crm-bridge' ), array( 'status' => 503 ) );
		}

		$params = (array) $request->get_json_params();
		$ids    = isset( $params['ids'] ) && is_array( $params['ids'] ) ? $params['ids'] : array();
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( count( $ids ) > 100 ) {
			return new WP_Error( 'evr_crm_too_many', __( 'Envía como máximo 100 pedidos por consulta.', 'evr-crm-bridge' ), array( 'status' => 422 ) );
		}

		$existing = array();
		$missing  = array();
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order && is_a( $order, 'WC_Abstract_Order' ) ) {
				$existing[ $order_id ] = $order;
			} else {
				$missing[] = $order_id;
			}
		}

		$participants = self::load_participants( array_keys( $existing ) );
		$data         = array();
		foreach ( $existing as $order_id => $order ) {
			$item                 = self::format_order( $order );
			$item['participants'] = isset( $participants[ $order_id ] ) ? $participants[ $order_id ] : array();
			$data[]               = $item;
		}

		return new WP_REST_Response(
			array(
				'data'    => $data,
				'missing' => $missing,
			),
			200
		);
	}

	/**
	 * One order in the same shape as an item of GET /orders, or null when it no longer exists.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string, mixed>|null
	 */
	public static function order_payload( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order || ! is_a( $order, 'WC_Abstract_Order' ) ) {
			return null;
		}

		$participants         = self::load_participants( array( absint( $order_id ) ) );
		$item                 = self::format_order( $order );
		$item['participants'] = isset( $participants[ $order_id ] ) ? $participants[ $order_id ] : array();

		return $item;
	}

	/**
	 * Build and run the cursor query. Returns limit + 1 rows at most.
	 *
	 * @param string $after_updated Cursor datetime ('' for the beginning).
	 * @param int    $after_order   Cursor order ID.
	 * @param string $created_after Optional Y-m-d lower bound on first registration.
	 * @param int    $limit         Page size.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	public static function select_order_page( $after_updated, $after_order, $created_after, $limit ) {
		global $wpdb;

		$tables = self::tables();
		$params = array();
		$join   = '';
		$having = '';

		if ( '' !== $created_after ) {
			$join     = "INNER JOIN (
				SELECT order_id FROM {$tables['order_participants']}
				GROUP BY order_id
				HAVING MIN(created_at) >= %s
			) first_op ON first_op.order_id = pa.order_id";
			$params[] = $created_after . ' 00:00:00';
		}

		if ( '' !== $after_updated ) {
			$having   = 'HAVING ( cursor_updated > %s OR ( cursor_updated = %s AND pa.order_id > %d ) )';
			$params[] = $after_updated;
			$params[] = $after_updated;
			$params[] = $after_order;
		}

		$params[] = $limit + 1;

		$sql = "SELECT pa.order_id, MAX(pa.updated_at) AS cursor_updated
			FROM {$tables['participant_activities']} pa
			{$join}
			GROUP BY pa.order_id
			{$having}
			ORDER BY cursor_updated ASC, pa.order_id ASC
			LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $wpdb->last_error ) {
			return new WP_Error( 'evr_crm_query_failed', __( 'No se pudieron leer los pedidos.', 'evr-crm-bridge' ), array( 'status' => 500 ) );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Load participants and their activity assignments for many orders in two queries.
	 *
	 * @param array<int, int> $order_ids Order IDs.
	 * @return array<int, array<int, array<string, mixed>>> Participants keyed by order ID.
	 */
	private static function load_participants( array $order_ids ) {
		global $wpdb;

		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		if ( empty( $order_ids ) ) {
			return array();
		}

		$tables       = self::tables();
		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

		// Snapshot columns first; fall back to the participant record like EVR's own export does.
		$participant_sql = "SELECT
				op.id AS order_participant_id,
				op.order_id,
				op.participant_id,
				COALESCE(NULLIF(op.name_snapshot, ''), p.full_name, '') AS full_name,
				COALESCE(NULLIF(op.email_snapshot, ''), p.email, '') AS email,
				COALESCE(NULLIF(op.document_type_snapshot, ''), p.document_type, '') AS document_type,
				COALESCE(NULLIF(op.document_number_snapshot, ''), p.document_number, '') AS document_number,
				COALESCE(NULLIF(op.whatsapp_snapshot, ''), p.whatsapp, '') AS whatsapp,
				COALESCE(NULLIF(op.shirt_size_snapshot, ''), p.shirt_size, '') AS shirt_size,
				COALESCE(NULLIF(op.meal_type_snapshot, ''), p.meal_type, '') AS meal_type,
				COALESCE(op.allergies_snapshot, p.allergies, '') AS allergies,
				op.entity_snapshot AS entity,
				op.registered_by_name_snapshot AS registered_by,
				op.created_at
			FROM {$tables['order_participants']} op
			LEFT JOIN {$tables['participants']} p ON p.id = op.participant_id
			WHERE op.order_id IN ({$placeholders})
			ORDER BY op.order_id ASC, op.id ASC";

		$activity_sql = "SELECT
				pa.id AS assignment_id,
				pa.order_participant_id,
				pa.order_id,
				pa.activity_id,
				a.name,
				a.type,
				a.event_date,
				a.start_time,
				a.end_time,
				a.destination,
				pa.status,
				pa.price_snapshot,
				pa.discount_snapshot,
				pa.total_snapshot,
				pa.discount_rule_name_snapshot,
				pa.created_at,
				pa.updated_at,
				pa.cancelled_at,
				pa.removed_at
			FROM {$tables['participant_activities']} pa
			LEFT JOIN {$tables['activities']} a ON a.id = pa.activity_id
			WHERE pa.order_id IN ({$placeholders})
			ORDER BY pa.order_id ASC, pa.id ASC";

		$participant_rows = $wpdb->get_results( $wpdb->prepare( $participant_sql, $order_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$activity_rows    = $wpdb->get_results( $wpdb->prepare( $activity_sql, $order_ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$activities_by_op = array();
		foreach ( (array) $activity_rows as $row ) {
			$activities_by_op[ (int) $row['order_participant_id'] ][] = array(
				'assignment_id' => (int) $row['assignment_id'],
				'activity_id'   => (int) $row['activity_id'],
				'name'          => $row['name'],
				'type'          => $row['type'],
				'event_date'    => $row['event_date'],
				'start_time'    => $row['start_time'],
				'end_time'      => $row['end_time'],
				'destination'   => $row['destination'],
				'status'        => (string) $row['status'],
				'price'         => (string) $row['price_snapshot'],
				'discount'      => (string) $row['discount_snapshot'],
				'total'         => (string) $row['total_snapshot'],
				'discount_label' => (string) $row['discount_rule_name_snapshot'],
				'created_at'    => $row['created_at'],
				'updated_at'    => $row['updated_at'],
				'cancelled_at'  => $row['cancelled_at'],
				'removed_at'    => $row['removed_at'],
			);
		}

		$by_order = array();
		foreach ( (array) $participant_rows as $row ) {
			$op_id                                = (int) $row['order_participant_id'];
			$by_order[ (int) $row['order_id'] ][] = array(
				'order_participant_id' => $op_id,
				'participant_id'       => (int) $row['participant_id'],
				'full_name'            => (string) $row['full_name'],
				'email'                => (string) $row['email'],
				'document_type'        => (string) $row['document_type'],
				'document_number'      => (string) $row['document_number'],
				'whatsapp'             => (string) $row['whatsapp'],
				'shirt_size'           => (string) $row['shirt_size'],
				'meal_type'            => (string) $row['meal_type'],
				'allergies'            => (string) $row['allergies'],
				'entity'               => (string) $row['entity'],
				'registered_by'        => (string) $row['registered_by'],
				'created_at'           => (string) $row['created_at'],
				'activities'           => isset( $activities_by_op[ $op_id ] ) ? $activities_by_op[ $op_id ] : array(),
			);
		}

		return $by_order;
	}

	/**
	 * Order-level fields from the WooCommerce CRUD object (HPOS safe).
	 *
	 * @param WC_Abstract_Order $order Order.
	 * @return array<string, mixed>
	 */
	private static function format_order( $order ) {
		$country = (string) $order->get_billing_country();
		$state   = (string) $order->get_billing_state();

		return array(
			'id'          => (int) $order->get_id(),
			'number'      => (string) $order->get_order_number(),
			'status'      => (string) $order->get_status(),
			'currency'    => (string) $order->get_currency(),
			'total'       => (string) $order->get_total(),
			'created_at'  => self::wc_date( $order->get_date_created() ),
			'paid_at'     => self::wc_date( $order->get_date_paid() ),
			'modified_at' => self::wc_date( $order->get_date_modified() ),
			'billing'     => array(
				'first_name'   => (string) $order->get_billing_first_name(),
				'last_name'    => (string) $order->get_billing_last_name(),
				'company'      => (string) $order->get_billing_company(),
				'email'        => (string) $order->get_billing_email(),
				'phone'        => (string) $order->get_billing_phone(),
				'city'         => (string) $order->get_billing_city(),
				'state'        => $state,
				'state_name'   => self::state_name( $country, $state ),
				'country'      => $country,
				'country_name' => self::country_name( $country ),
			),
		);
	}

	/**
	 * Format a WC_DateTime as ISO 8601.
	 *
	 * @param mixed $date WC_DateTime or null.
	 * @return string|null
	 */
	private static function wc_date( $date ) {
		return $date && is_callable( array( $date, 'date' ) ) ? $date->date( 'c' ) : null;
	}

	/**
	 * Country name, falling back to the code.
	 *
	 * @param string $code ISO country code.
	 * @return string
	 */
	private static function country_name( $code ) {
		if ( '' === $code ) {
			return '';
		}
		$wc        = function_exists( 'WC' ) ? WC() : null;
		$countries = is_object( $wc ) && isset( $wc->countries ) && is_callable( array( $wc->countries, 'get_countries' ) ) ? $wc->countries->get_countries() : array();

		return isset( $countries[ $code ] ) ? html_entity_decode( (string) $countries[ $code ], ENT_QUOTES, 'UTF-8' ) : $code;
	}

	/**
	 * State name, falling back to the code.
	 *
	 * @param string $country ISO country code.
	 * @param string $state   State code.
	 * @return string
	 */
	private static function state_name( $country, $state ) {
		if ( '' === $state ) {
			return '';
		}
		$wc     = function_exists( 'WC' ) ? WC() : null;
		$states = is_object( $wc ) && isset( $wc->countries ) && is_callable( array( $wc->countries, 'get_states' ) ) ? $wc->countries->get_states( strtoupper( $country ) ) : array();

		return is_array( $states ) && isset( $states[ $state ] ) ? html_entity_decode( (string) $states[ $state ], ENT_QUOTES, 'UTF-8' ) : $state;
	}

	/**
	 * Validate a Y-m-d date.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_valid_date( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Validate a Y-m-d H:i:s datetime (the format of EVR's datetime columns).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_valid_datetime( $value ) {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value );

		return $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	/**
	 * Error returned when EVR tables are missing.
	 *
	 * @return WP_Error
	 */
	private static function unavailable() {
		return new WP_Error(
			'evr_crm_unavailable',
			__( 'Las tablas de EVR Event Registration no están disponibles.', 'evr-crm-bridge' ),
			array( 'status' => 503 )
		);
	}
}
