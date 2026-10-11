<?php
/**
 * Settings › CRM 360 admin page.
 *
 * @package EVR_CRM_Bridge
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the REST URL and API key, and lets an administrator rotate the key.
 */
final class EVR_CRM_Bridge_Admin {

	const PAGE_SLUG    = 'evr-crm-bridge';
	const NONCE_ACTION = 'evr_crm_bridge_regenerate';

	/**
	 * Hook into WordPress admin.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_' . self::NONCE_ACTION, array( __CLASS__, 'handle_regenerate' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EVR_CRM_BRIDGE_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Register the page under Ajustes.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_options_page(
			__( 'CRM 360', 'evr-crm-bridge' ),
			__( 'CRM 360', 'evr-crm-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Add a "Ajustes" link in the plugins list.
	 *
	 * @param array<int|string, string> $links Links.
	 * @return array<int|string, string>
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ajustes', 'evr-crm-bridge' ) . '</a>' );

		return $links;
	}

	/**
	 * Handle the "Regenerar clave" POST.
	 *
	 * @return void
	 */
	public static function handle_regenerate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para realizar esta acción.', 'evr-crm-bridge' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		EVR_CRM_Bridge_Auth::regenerate_key();

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'regenerated' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		EVR_CRM_Bridge_Auth::ensure_key();

		$key         = EVR_CRM_Bridge_Auth::get_key();
		$rest_url    = rest_url( EVR_CRM_Bridge_API::REST_NAMESPACE );
		$evr_ok      = EVR_CRM_Bridge_API::evr_tables_exist();
		$wc_ok       = EVR_CRM_Bridge_API::woocommerce_active();
		$regenerated = isset( $_GET['regenerated'] ) && '1' === $_GET['regenerated']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CRM 360', 'evr-crm-bridge' ); ?></h1>

			<?php if ( $regenerated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Se generó una nueva clave. Actualízala en el CRM: la anterior ya no funciona.', 'evr-crm-bridge' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $evr_ok ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'No se encontraron las tablas de EVR Event Registration. Activa ese plugin para que el CRM pueda leer las inscripciones.', 'evr-crm-bridge' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $wc_ok ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'WooCommerce no está activo. El CRM no podrá leer los pedidos.', 'evr-crm-bridge' ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Copia estos datos en el CRM, en Configuración › Plugin de inscripciones. La API es de solo lectura.', 'evr-crm-bridge' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="evr-crm-url"><?php esc_html_e( 'URL de la API', 'evr-crm-bridge' ); ?></label></th>
					<td>
						<input type="text" id="evr-crm-url" class="large-text code" readonly value="<?php echo esc_attr( $rest_url ); ?>" />
						<p><button type="button" class="button evr-crm-copy" data-target="evr-crm-url"><?php esc_html_e( 'Copiar URL', 'evr-crm-bridge' ); ?></button></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="evr-crm-key"><?php esc_html_e( 'Clave de API', 'evr-crm-bridge' ); ?></label></th>
					<td>
						<input type="text" id="evr-crm-key" class="large-text code" readonly autocomplete="off" value="<?php echo esc_attr( $key ); ?>" />
						<p><button type="button" class="button evr-crm-copy" data-target="evr-crm-key"><?php esc_html_e( 'Copiar clave', 'evr-crm-bridge' ); ?></button></p>
						<p class="description"><?php esc_html_e( 'El CRM la envía en la cabecera "Authorization: Bearer <clave>" o "X-CRM-Key: <clave>". Trátala como una contraseña.', 'evr-crm-bridge' ); ?></p>
					</td>
				</tr>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( '¿Regenerar la clave? El CRM dejará de sincronizar hasta que pegues la nueva clave.', 'evr-crm-bridge' ) ); ?>');">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<?php submit_button( __( 'Regenerar clave', 'evr-crm-bridge' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<script>
		( function () {
			document.querySelectorAll( '.evr-crm-copy' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var input = document.getElementById( button.getAttribute( 'data-target' ) );
					var label = button.textContent;
					var done  = function () {
						button.textContent = <?php echo wp_json_encode( __( 'Copiado', 'evr-crm-bridge' ) ); ?>;
						setTimeout( function () { button.textContent = label; }, 1500 );
					};
					if ( navigator.clipboard && window.isSecureContext ) {
						navigator.clipboard.writeText( input.value ).then( done );
					} else {
						input.select();
						document.execCommand( 'copy' );
						done();
					}
				} );
			} );
		}() );
		</script>
		<?php
	}
}
