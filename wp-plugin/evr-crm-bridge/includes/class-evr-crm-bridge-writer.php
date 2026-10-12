<?php
/**
 * Write routes used by the CRM registration desk.
 *
 * @package EVR_CRM_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every change goes through EVR's own services (cupos, transactions, history), signed by the
 * WordPress user chosen in Ajustes › CRM 360. The CRM user who made it is written in the order notes.
 */
final class EVR_CRM_Bridge_Writer {

	const ACTOR_OPTION = 'evr_crm_bridge_actor';

	/**
	 * Register the write routes.
	 *
	 * @param callable $permission Shared API-key check.
	 * @return void
	 */
	public static function register_routes( $permission ) {
		$ns = EVR_CRM_Bridge_API::REST_NAMESPACE;

		register_rest_route( $ns, '/participants/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'update_participant' ), 'permission_callback' => $permission ) );
		register_rest_route( $ns, '/assignments/(?P<id>\d+)/replace', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'replace_assignment' ), 'permission_callback' => $permission ) );
		register_rest_route( $ns, '/assignments/(?P<id>\d+)/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'cancel_assignment' ), 'permission_callback' => $permission ) );
		register_rest_route( $ns, '/desk/activities', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'desk_activities' ), 'permission_callback' => $permission ) );
		register_rest_route( $ns, '/desk/preview', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'desk_preview' ), 'permission_callback' => $permission ) );
		register_rest_route( $ns, '/desk/register', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'desk_register' ), 'permission_callback' => $permission ) );
	}

	/**
	 * POST /participants/{id} — contact details of one participant (not the document, which is their identity).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_participant( $request ) {
		global $wpdb;

		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$tables = EVR_CRM_Bridge_API::tables();
		$id     = absint( $request['id'] );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['participants']} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $exists ) {
			return new WP_Error( 'evr_crm_not_found', __( 'El participante no existe en el plugin.', 'evr-crm-bridge' ), array( 'status' => 404 ) );
		}

		$clean = self::clean_details( (array) $request->get_json_params() );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$participant = array();
		$snapshot    = array();
		$map         = array(
			'full_name'  => 'name_snapshot',
			'email'      => 'email_snapshot',
			'whatsapp'   => 'whatsapp_snapshot',
			'shirt_size' => 'shirt_size_snapshot',
			'meal_type'  => 'meal_type_snapshot',
			'allergies'  => 'allergies_snapshot',
		);
		foreach ( $map as $field => $column ) {
			if ( array_key_exists( $field, $clean ) ) {
				$participant[ $field ] = $clean[ $field ];
				$snapshot[ $column ]   = $clean[ $field ];
			}
		}
		if ( array_key_exists( 'email', $clean ) ) {
			$participant['email_normalized'] = strtolower( $clean['email'] );
		}

		$order_participant_id = absint( $request->get_param( 'order_participant_id' ) );
		$order_ids            = array_map( 'absint', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_id FROM {$tables['order_participants']} WHERE participant_id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$now                  = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query( 'START TRANSACTION' );
		$ok = true;
		if ( $participant ) {
			$participant['updated_at'] = $now;
			$ok = false !== $wpdb->update( $tables['participants'], $participant, array( 'id' => $id ) );
		}
		if ( $ok && $snapshot ) {
			$ok = false !== $wpdb->update( $tables['order_participants'], $snapshot, array( 'participant_id' => $id ) );
		}
		if ( $ok && array_key_exists( 'entity', $clean ) && $order_participant_id ) {
			$ok = false !== $wpdb->update( $tables['order_participants'], array( 'entity_snapshot' => $clean['entity'] ), array( 'id' => $order_participant_id, 'participant_id' => $id ) );
		}
		// Moves the orders past the CRM's sync cursor so every copy refreshes.
		if ( $ok && $order_ids ) {
			$ok = false !== $wpdb->query( $wpdb->prepare( "UPDATE {$tables['participant_activities']} SET updated_at = %s WHERE participant_id = %d", $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'evr_crm_write_failed', __( 'No se pudieron guardar los datos del participante.', 'evr-crm-bridge' ), array( 'status' => 500 ) );
		}
		$wpdb->query( 'COMMIT' );

		foreach ( $order_ids as $order_id ) {
			self::note( $order_id, sprintf( 'Datos del participante actualizados desde el CRM 360 por %s: %s.', self::crm_user( $request ), implode( ', ', array_keys( $clean ) ) ) );
		}

		return new WP_REST_Response( array( 'orders' => self::payloads( $order_ids ) ), 200 );
	}

	/**
	 * POST /assignments/{id}/replace — same rules as "Editar" in the plugin's Inscritos.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function replace_assignment( $request ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = self::as_actor(
			static function () use ( $request ) {
				return ( new \EVR\Domain\Orders\RegistrationManagementService() )->replace_assignment( absint( $request['id'] ), absint( $request->get_param( 'activity_id' ) ) );
			}
		);
		if ( is_wp_error( $result ) ) {
			return self::client_error( $result );
		}

		$order_id = absint( $result['order_id'] );
		self::note( $order_id, sprintf( 'Actividad cambiada desde el CRM 360 por %1$s: %2$s → %3$s. El pago y los valores históricos no se modificaron.', self::crm_user( $request ), $result['old_activity_name'], $result['new_activity_name'] ) );

		return new WP_REST_Response( array( 'orders' => self::payloads( array( $order_id ) ) ), 200 );
	}

	/**
	 * POST /assignments/{id}/cancel — removes one activity and frees its cupo; the payment stays as it was.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel_assignment( $request ) {
		global $wpdb;

		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$tables = EVR_CRM_Bridge_API::tables();
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT pa.order_id, a.name FROM {$tables['participant_activities']} pa LEFT JOIN {$tables['activities']} a ON a.id = pa.activity_id WHERE pa.id = %d", absint( $request['id'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return new WP_Error( 'evr_crm_not_found', __( 'La inscripción no existe en el plugin.', 'evr-crm-bridge' ), array( 'status' => 404 ) );
		}

		$result = self::as_actor(
			static function () use ( $request ) {
				return ( new \EVR\Domain\Orders\RegistrationManagementService() )->cancel_assignment( absint( $request['id'] ), 'crm_removed' );
			}
		);
		if ( is_wp_error( $result ) ) {
			return self::client_error( $result );
		}

		$order_id = absint( $row['order_id'] );
		self::note( $order_id, sprintf( 'Actividad retirada desde el CRM 360 por %1$s: %2$s. El pago y los valores históricos no se modificaron.', self::crm_user( $request ), $row['name'] ) );

		return new WP_REST_Response( array( 'orders' => self::payloads( array( $order_id ) ) ), 200 );
	}

	/**
	 * GET /desk/activities — open activities with live free cupos, as "Nuevo Registro" lists them.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function desk_activities() {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$list = self::as_actor(
			static function () {
				return ( new \EVR\Domain\Registration\QuickRegistrationService() )->activities();
			}
		);

		return new WP_REST_Response( array( 'data' => $list ), 200 );
	}

	/**
	 * POST /desk/preview — what "Nuevo Registro" would charge. Takes no cupo and no discount use.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function desk_preview( $request ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$params  = (array) $request->get_json_params();
		$preview = self::as_actor(
			static function () use ( $params ) {
				return ( new \EVR\Domain\Registration\QuickRegistrationService() )->preview( self::desk_input( $params ), ! empty( $params['free'] ) );
			}
		);
		if ( is_wp_error( $preview ) ) {
			return self::client_error( $preview );
		}

		delete_transient( 'evr_desk_' . self::actor_id() . '_' . $preview['token'] );
		unset( $preview['token'], $preview['discount_code'] );

		return new WP_REST_Response( $preview, 200 );
	}

	/**
	 * POST /desk/register — a new order for the participant, exactly like "Nuevo Registro" in the plugin.
	 *
	 * Reviews and confirms in one call so two CRM desks never invalidate each other's review. Refuses when
	 * the total differs from the one the desk showed (`expected_total`).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function desk_register( $request ) {
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$params   = (array) $request->get_json_params();
		$crm_user = self::crm_user( $request );
		$result   = self::as_actor(
			static function () use ( $params ) {
				$service = new \EVR\Domain\Registration\QuickRegistrationService();
				$preview = $service->preview( self::desk_input( $params ), ! empty( $params['free'] ) );
				if ( is_wp_error( $preview ) ) {
					return $preview;
				}
				if ( ! isset( $params['expected_total'] ) || (int) round( (float) $preview['total'] * 100 ) !== (int) round( (float) $params['expected_total'] * 100 ) ) {
					delete_transient( 'evr_desk_' . get_current_user_id() . '_' . $preview['token'] );
					return new WP_Error( 'evr_crm_total_changed', __( 'El valor a cobrar cambió. Revisa de nuevo antes de confirmar.', 'evr-crm-bridge' ) );
				}
				return $service->confirm( $preview['token'] );
			}
		);
		if ( is_wp_error( $result ) ) {
			return self::client_error( $result );
		}

		$order_id = absint( $result['order_id'] );
		self::note( $order_id, sprintf( 'Registro creado desde el CRM 360 por %s.', $crm_user ) );

		return new WP_REST_Response( array( 'order_id' => $order_id, 'orders' => self::payloads( array( $order_id ) ) ), 200 );
	}

	/**
	 * The WordPress user that signs CRM changes, or 0.
	 *
	 * @return int
	 */
	public static function actor_id() {
		$id   = absint( get_option( self::ACTOR_OPTION, 0 ) );
		$user = $id ? get_userdata( $id ) : false;

		return $user && array_intersect( array( 'administrator', 'editor' ), (array) $user->roles ) ? $id : 0;
	}

	/**
	 * Checks shared by every write.
	 *
	 * @return true|WP_Error
	 */
	private static function ready() {
		if ( ! EVR_CRM_Bridge_API::evr_tables_exist() || ! EVR_CRM_Bridge_API::woocommerce_active() || ! class_exists( '\EVR\Domain\Orders\RegistrationManagementService' ) ) {
			return new WP_Error( 'evr_crm_unavailable', __( 'EVR Event Registration o WooCommerce no están activos.', 'evr-crm-bridge' ), array( 'status' => 503 ) );
		}
		if ( ! self::actor_id() ) {
			return new WP_Error( 'evr_crm_no_actor', __( 'Falta elegir en WordPress › Ajustes › CRM 360 el usuario que firma los cambios del CRM.', 'evr-crm-bridge' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Run EVR code as the signing user, so its own permission checks and history apply.
	 *
	 * @param callable $callback Work.
	 * @return mixed
	 */
	private static function as_actor( $callback ) {
		$previous = get_current_user_id();
		wp_set_current_user( self::actor_id() );
		try {
			return $callback();
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Validate the editable details; only the keys present are returned.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, string>|WP_Error
	 */
	private static function clean_details( array $input ) {
		$out = array();

		if ( array_key_exists( 'full_name', $input ) ) {
			$out['full_name'] = sanitize_text_field( (string) $input['full_name'] );
			if ( '' === $out['full_name'] ) {
				return self::invalid( __( 'El nombre no puede quedar vacío.', 'evr-crm-bridge' ) );
			}
		}
		if ( array_key_exists( 'email', $input ) ) {
			$out['email'] = sanitize_email( (string) $input['email'] );
			if ( ! is_email( $out['email'] ) ) {
				return self::invalid( __( 'El correo no es válido.', 'evr-crm-bridge' ) );
			}
		}
		if ( array_key_exists( 'whatsapp', $input ) ) {
			$whatsapp = preg_replace( '/[\s\-().]/u', '', sanitize_text_field( (string) $input['whatsapp'] ) );
			if ( 0 === strpos( $whatsapp, '00' ) ) {
				$whatsapp = '+' . substr( $whatsapp, 2 );
			}
			if ( '' !== $whatsapp && ! preg_match( '/^\+[1-9][0-9]{7,14}$/', $whatsapp ) ) {
				return self::invalid( __( 'El WhatsApp debe incluir el indicativo, por ejemplo +573001234567.', 'evr-crm-bridge' ) );
			}
			$out['whatsapp'] = $whatsapp;
		}
		if ( array_key_exists( 'shirt_size', $input ) ) {
			$out['shirt_size'] = strtoupper( sanitize_text_field( (string) $input['shirt_size'] ) );
			if ( '' !== $out['shirt_size'] && ! in_array( $out['shirt_size'], array( 'S', 'M', 'L', 'XL', 'XXL' ), true ) ) {
				return self::invalid( __( 'La talla de camiseta no es válida.', 'evr-crm-bridge' ) );
			}
		}
		if ( array_key_exists( 'meal_type', $input ) ) {
			$out['meal_type'] = sanitize_key( (string) $input['meal_type'] );
			if ( '' !== $out['meal_type'] && ! in_array( $out['meal_type'], array( 'traditional', 'vegetarian', 'vegan' ), true ) ) {
				return self::invalid( __( 'El tipo de alimentación no es válido.', 'evr-crm-bridge' ) );
			}
		}
		if ( array_key_exists( 'allergies', $input ) ) {
			$allergies        = sanitize_textarea_field( (string) $input['allergies'] );
			$out['allergies'] = function_exists( 'mb_substr' ) ? mb_substr( $allergies, 0, 500 ) : substr( $allergies, 0, 500 );
		}
		if ( array_key_exists( 'entity', $input ) ) {
			$out['entity'] = \EVR\Domain\Registration\QuickRegistrationService::entity( (string) $input['entity'] );
		}

		return $out;
	}

	/**
	 * Input for QuickRegistrationService, taking only the fields it knows.
	 *
	 * @param array<string, mixed> $params Request body.
	 * @return array<string, mixed>
	 */
	private static function desk_input( array $params ) {
		$input = array();
		foreach ( array( 'full_name', 'document_type', 'document_number', 'email', 'whatsapp', 'shirt_size', 'meal_type', 'allergies', 'entity' ) as $key ) {
			$input[ $key ] = isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ? (string) $params[ $key ] : '';
		}
		$input['activity_ids'] = isset( $params['activity_ids'] ) && is_array( $params['activity_ids'] ) ? array_map( 'strval', $params['activity_ids'] ) : array();

		return $input;
	}

	/**
	 * Fresh payloads for the given orders.
	 *
	 * @param array<int, int> $order_ids Order IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private static function payloads( array $order_ids ) {
		$out = array();
		foreach ( array_unique( $order_ids ) as $order_id ) {
			$payload = EVR_CRM_Bridge_API::order_payload( $order_id );
			if ( $payload ) {
				$out[] = $payload;
			}
		}

		return $out;
	}

	/**
	 * Add a private note to the WooCommerce order.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $text     Note.
	 * @return void
	 */
	private static function note( $order_id, $text ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->add_order_note( $text );
		}
	}

	/**
	 * Name of the CRM user who made the change, as the CRM reports it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private static function crm_user( $request ) {
		$name = sanitize_text_field( (string) $request->get_param( 'crm_user' ) );

		return '' !== $name ? $name : 'CRM 360';
	}

	/**
	 * A validation error with HTTP 422.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function invalid( $message ) {
		return new WP_Error( 'evr_crm_invalid', $message, array( 'status' => 422 ) );
	}

	/**
	 * Pass an EVR error to the CRM as a 422 with its message.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private static function client_error( WP_Error $error ) {
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => 422 ) );
	}
}
