<?php
/**
 * Plugin Name:       E-butik regnskabs-integration
 * Plugin URI:        https://webkonsulenterne.dk
 * Description:       Tilføjer knappen "Kør integration" til WooCommerce-ordrelisten og kan oprette den nødvendige webhook. Site ID konfigureres under Indstillinger &rarr; E-butik Integration.
 * Version:           3.4.0
 * Author:            Jens Kirk
 * Author URI:        https://webkonsulenterne.dk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       e-butik-integration
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 *
 * @package E_Butik_Integration
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declares HPOS (High-Performance Order Storage) compatibility.
 *
 * This is the only hook registered on the front end. It runs once, performs no
 * queries and has no measurable effect on checkout.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/*
 * GitHub updates.
 *
 * Loaded on admin requests and during cron. Cron matters because WordPress runs
 * unattended background updates there, where is_admin() is false — without this
 * the plugin would only ever update when someone opened wp-admin.
 *
 * Both constants can be overridden in wp-config.php, so a private repository's
 * token never has to be committed:
 *
 *     define( 'E_BUTIK_INTEGRATION_REPO', 'https://github.com/Webkonsulenter/E-butik-integration/' );
 *     define( 'E_BUTIK_INTEGRATION_GITHUB_TOKEN', 'github_pat_...' );
 */
if ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
	$e_butik_puc = __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

	if ( is_readable( $e_butik_puc ) ) {
		require_once $e_butik_puc;

		if ( ! defined( 'E_BUTIK_INTEGRATION_REPO' ) ) {
			define( 'E_BUTIK_INTEGRATION_REPO', 'https://github.com/Webkonsulenter/E-butik-integration/' );
		}

		$e_butik_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			E_BUTIK_INTEGRATION_REPO,
			__FILE__,
			'e-butik-integration'
		);

		// The branch used when no release or tag is found.
		$e_butik_update_checker->setBranch( 'main' );

		if ( defined( 'E_BUTIK_INTEGRATION_GITHUB_TOKEN' ) && E_BUTIK_INTEGRATION_GITHUB_TOKEN ) {
			$e_butik_update_checker->setAuthentication( E_BUTIK_INTEGRATION_GITHUB_TOKEN );
		}

		/*
		 * Only enable this if every release carries a properly named .zip asset.
		 * Without a matching asset the update has nothing to download.
		 *
		 * $e_butik_update_checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );
		 */
	}
}

/*
 * Everything below is admin-only functionality.
 *
 * On front-end requests (including checkout and "Godkend ordre") execution stops
 * here: no hooks registered, no options read, no queries.
 */
if ( ! is_admin() ) {
	return;
}

/**
 * E-butik regnskabs-integration: adds the "Kør integration" button to the orders list.
 */
final class E_Butik_Integration {

	/** Option name. All settings live in a single wp_options row. */
	const OPTION = 'e_butik_integration_settings';

	/** Settings group and page slug. */
	const GROUP = 'e_butik_integration_group';
	const SLUG  = 'e-butik-integration';

	/** The CSS class WooCommerce derives from the 'action' key. */
	const ACTION_SLUG = 'e-butik-run';

	/** Target for the order button. Fixed. */
	const ENDPOINT = 'https://e-butik.dk/index.php';

	/** Button icon: dashicons-external. Fixed. */
	const ICON_GLYPH = 'f504';

	/** Icon colour on the button. */
	const ICON_COLOR = '#9a6a5b';

	/** The webhook this plugin can create. */
	const WEBHOOK_URL   = 'https://e-butik.dk/webhooks/woocommerce.php';
	const WEBHOOK_TOPIC = 'order.updated';
	const WEBHOOK_NAME  = 'E-butik integration - ordre opdateret';

	/** admin-post action name for the webhook button. */
	const WEBHOOK_ACTION = 'e_butik_webhook';

	/**
	 * The finished URL without order_id. Built once per request and reused for
	 * every order row in the list.
	 *
	 * @var string|null
	 */
	private static $base_url = null;

	/**
	 * Registers hooks. Each hook is scoped to the screen that uses it.
	 */
	public static function init() {
		global $pagenow;

		add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'add_order_action' ), 100, 2 );
		add_action( 'admin_head', array( __CLASS__, 'print_button_style' ) );
		add_action( 'admin_footer', array( __CLASS__, 'print_new_tab_script' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_setup_notice' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );

		// The Settings API is only needed while the settings page is displayed or saved.
		if ( 'options-general.php' === $pagenow || 'options.php' === $pagenow ) {
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		}

		// The webhook button posts here.
		if ( 'admin-post.php' === $pagenow ) {
			add_action( 'admin_post_' . self::WEBHOOK_ACTION, array( __CLASS__, 'handle_webhook_action' ) );
		}

		// Shortcut to the settings page from the plugins list.
		if ( 'plugins.php' === $pagenow ) {
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'add_settings_link' ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * The button in the orders list
	 * ------------------------------------------------------------------ */

	/**
	 * Adds the button to the "Actions" column in the orders list.
	 *
	 * Called once per order row. All setup work (option lookup, capability
	 * check, URL construction) happens on the first call only.
	 *
	 * @param array    $actions Existing actions.
	 * @param WC_Order $order   The order for this row.
	 * @return array
	 */
	public static function add_order_action( $actions, $order ) {
		$base_url = self::base_url();

		if ( '' === $base_url || ! $order instanceof WC_Order ) {
			return $actions;
		}

		$actions['e_butik_run'] = array(
			'url'    => $base_url . '&order_id=' . $order->get_id(),
			'name'   => __( 'Kør integration', 'e-butik-integration' ),
			'title'  => __( 'Kør integrationen for denne ordre (åbner i ny fane)', 'e-butik-integration' ),
			'action' => self::ACTION_SLUG,
		);

		return $actions;
	}

	/**
	 * The button URL without order_id. Computed once per request.
	 *
	 * Returns an empty string when no Site ID is configured or the current user
	 * is not allowed to manage orders.
	 *
	 * @return string
	 */
	private static function base_url() {
		if ( null !== self::$base_url ) {
			return self::$base_url;
		}

		self::$base_url = '';

		$site_id = self::get_settings()['site_id'];

		if ( $site_id > 0 && current_user_can( 'edit_shop_orders' ) ) {
			self::$base_url = add_query_arg(
				array(
					'option'  => 'com_economic',
					'task'    => 'processor',
					'action'  => 'order_reload',
					'site_id' => $site_id,
				),
				self::ENDPOINT
			);
		}

		return self::$base_url;
	}

	/**
	 * Prints the button icon. Only on the orders list, and only when the button
	 * is actually rendered.
	 *
	 * WooCommerce already positions ::after for buttons in the actions column,
	 * so only the font, glyph and colour are set here.
	 */
	public static function print_button_style() {
		if ( ! self::is_orders_screen() || '' === self::base_url() ) {
			return;
		}

		printf(
			'<style>.wc-action-button-%1$s::after{font-family:Dashicons;content:"\%2$s";color:%3$s !important;}</style>',
			esc_attr( self::ACTION_SLUG ),
			esc_attr( self::ICON_GLYPH ),
			esc_attr( self::ICON_COLOR )
		);
	}

	/**
	 * Makes the button open in a new tab.
	 *
	 * WooCommerce builds the <a> element itself in wc_render_action_buttons()
	 * using a fixed set of attributes, so target cannot be supplied through the
	 * actions array. Hence this one line in the footer, where the rows are
	 * already present in the DOM.
	 */
	public static function print_new_tab_script() {
		if ( ! self::is_orders_screen() || '' === self::base_url() ) {
			return;
		}

		printf(
			'<script>document.querySelectorAll("a.%s").forEach(function(a){a.target="_blank";a.rel="noopener noreferrer";});</script>',
			esc_js( self::ACTION_SLUG )
		);
	}

	/**
	 * Shown on the orders list until a Site ID has been saved.
	 */
	public static function maybe_show_setup_notice() {
		if ( ! self::is_orders_screen() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::get_settings()['site_id'] >= 1 ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'E-butik Integration: knappen er skjult, indtil der er angivet et Site ID.', 'e-butik-integration' ),
			esc_url( self::settings_url() ),
			esc_html__( 'Åbn indstillinger', 'e-butik-integration' )
		);
	}

	/**
	 * Are we on the orders list? Supports both HPOS and the legacy post-based list.
	 *
	 * @return bool
	 */
	private static function is_orders_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		// HPOS: woocommerce_page_wc-orders (optionally suffixed for other order types).
		// Legacy: edit-shop_order.
		return 'edit-shop_order' === $screen->id || 0 === strpos( $screen->id, 'woocommerce_page_wc-orders' );
	}

	/* ---------------------------------------------------------------------
	 * Webhook
	 * ------------------------------------------------------------------ */

	/**
	 * Finds the webhook this plugin manages, matched on topic and delivery URL.
	 *
	 * @return WC_Webhook|null
	 */
	private static function find_webhook() {
		if ( ! function_exists( 'wc_get_webhook' ) || ! class_exists( 'WC_Data_Store' ) ) {
			return null;
		}

		try {
			$data_store = WC_Data_Store::load( 'webhook' );
		} catch ( Exception $e ) {
			return null;
		}

		foreach ( $data_store->get_webhooks_ids() as $id ) {
			$webhook = wc_get_webhook( $id );

			if (
				$webhook
				&& self::WEBHOOK_TOPIC === $webhook->get_topic()
				&& self::WEBHOOK_URL === $webhook->get_delivery_url()
			) {
				return $webhook;
			}
		}

		return null;
	}

	/**
	 * Creates the webhook, or activates it if it already exists but is not active.
	 *
	 * Matching on topic plus delivery URL means pressing the button twice cannot
	 * produce a duplicate.
	 *
	 * Note: WooCommerce's own admin screen sends a test delivery when a webhook
	 * is activated. That is deliberately skipped here — it is a blocking HTTP
	 * call, and a slow receiving endpoint would hang the admin request.
	 */
	public static function handle_webhook_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Du har ikke rettigheder til at gøre dette.', 'e-butik-integration' ) );
		}

		check_admin_referer( self::WEBHOOK_ACTION );

		$result  = 'error';
		$webhook = self::find_webhook();

		if ( $webhook ) {
			if ( 'active' === $webhook->get_status() ) {
				$result = 'exists';
			} else {
				$webhook->set_status( 'active' );
				$webhook->save();
				$result = 'activated';
			}
		} elseif ( class_exists( 'WC_Webhook' ) ) {
			$webhook = new WC_Webhook();
			$webhook->set_name( self::WEBHOOK_NAME );
			$webhook->set_user_id( get_current_user_id() );
			$webhook->set_topic( self::WEBHOOK_TOPIC );
			$webhook->set_delivery_url( self::WEBHOOK_URL );
			$webhook->set_api_version( 'wp_api_v3' );
			$webhook->set_status( 'active' );
			$webhook->set_pending_delivery( false );

			$result = $webhook->save() ? 'created' : 'error';
		}

		wp_safe_redirect( add_query_arg( 'e_butik_webhook', $result, self::settings_url() ) );
		exit;
	}

	/**
	 * Renders the webhook section of the settings page.
	 */
	private static function render_webhook_section() {
		echo '<h2>' . esc_html__( 'Webhook', 'e-butik-integration' ) . '</h2>';

		if ( ! class_exists( 'WC_Webhook' ) ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html__( 'WooCommerce er ikke aktiv, så webhooken kan ikke håndteres herfra.', 'e-butik-integration' )
			);

			return;
		}

		$webhook = self::find_webhook();

		if ( $webhook && 'active' === $webhook->get_status() ) {
			printf(
				'<div class="notice notice-success inline"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Webhooken "ordre opdateret" er oprettet og aktiv.', 'e-butik-integration' ),
				esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=webhooks&edit-webhook=' . $webhook->get_id() ) ),
				esc_html__( 'Vis webhooken', 'e-butik-integration' )
			);

			return;
		}

		if ( $webhook ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'Webhooken "ordre opdateret" findes, men er ikke aktiv.', 'e-butik-integration' )
			);
			self::render_webhook_button( __( 'Aktivér webhook', 'e-butik-integration' ) );

			return;
		}

		printf(
			'<p>%s</p>',
			esc_html__( 'Integrationen har brug for én webhook, der udløses når en ordre opdateres. Opret den her, hvis den ikke allerede findes.', 'e-butik-integration' )
		);
		self::render_webhook_button( __( 'Opret webhook', 'e-butik-integration' ) );
	}

	/**
	 * Renders the webhook form. Separate from the settings form, since forms
	 * cannot be nested.
	 *
	 * @param string $label Button label.
	 */
	private static function render_webhook_button( $label ) {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::WEBHOOK_ACTION ); ?>">
			<?php
			wp_nonce_field( self::WEBHOOK_ACTION );
			submit_button( $label, 'secondary', 'submit', false );
			?>
		</form>
		<?php
	}

	/**
	 * Shows the outcome of the webhook button after the redirect.
	 */
	private static function render_webhook_result() {
		if ( ! isset( $_GET['e_butik_webhook'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$result = sanitize_key( wp_unslash( $_GET['e_butik_webhook'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'created'   => array( 'success', __( 'Webhooken blev oprettet og er aktiv.', 'e-butik-integration' ) ),
			'activated' => array( 'success', __( 'Webhooken fandtes allerede og er nu aktiveret.', 'e-butik-integration' ) ),
			'exists'    => array( 'info', __( 'Webhooken findes allerede og er aktiv. Der blev ikke oprettet en ny.', 'e-butik-integration' ) ),
			'error'     => array( 'error', __( 'Webhooken kunne ikke oprettes.', 'e-butik-integration' ) ),
		);

		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_html( $messages[ $result ][1] )
		);
	}

	/* ---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	/**
	 * The plugin's settings page URL.
	 *
	 * @return string
	 */
	private static function settings_url() {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Returns the settings with defaults applied and types normalised.
	 *
	 * @return array{site_id:int,delete_data:bool}
	 */
	public static function get_settings() {
		static $settings = null;

		if ( null !== $settings ) {
			return $settings;
		}

		$stored = get_option( self::OPTION, array() );

		/*
		 * One-time migration from the pre-3.4.0 option key, so renaming the
		 * handle does not discard a saved Site ID. Once migrated the new option
		 * exists, so this lookup never runs again. Safe to delete once every
		 * client site is on 3.4.0 or later.
		 */
		if ( ! is_array( $stored ) || array() === $stored ) {
			$legacy = get_option( 'ebutik_integration_settings' );

			if ( is_array( $legacy ) && ! empty( $legacy ) ) {
				$stored = $legacy;
				update_option( self::OPTION, $legacy );
				delete_option( 'ebutik_integration_settings' );
			}
		}

		$settings = array(
			'site_id'     => isset( $stored['site_id'] ) ? absint( $stored['site_id'] ) : 0,
			'delete_data' => ! empty( $stored['delete_data'] ),
		);

		return $settings;
	}

	/**
	 * Registers the setting, its section and its fields.
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'show_in_rest'      => false,
				'default'           => array(
					'site_id'     => 0,
					'delete_data' => false,
				),
			)
		);

		add_settings_section( 'e_butik_integration_main', '', '__return_false', self::SLUG );

		add_settings_field(
			'site_id',
			__( 'Site ID', 'e-butik-integration' ),
			array( __CLASS__, 'render_site_id_field' ),
			self::SLUG,
			'e_butik_integration_main',
			array( 'label_for' => 'e_butik_site_id' )
		);

		add_settings_field(
			'delete_data',
			__( 'Ved afinstallation', 'e-butik-integration' ),
			array( __CLASS__, 'render_delete_data_field' ),
			self::SLUG,
			'e_butik_integration_main',
			array( 'label_for' => 'e_butik_delete_data' )
		);
	}

	/**
	 * Validates and sanitises submitted input. An invalid Site ID is rejected and
	 * the previously stored value is kept.
	 *
	 * @param mixed $input Raw input from the form.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		$current = self::get_settings();
		$input   = is_array( $input ) ? $input : array();

		$site_id = isset( $input['site_id'] ) ? absint( $input['site_id'] ) : 0;

		if ( $site_id < 1 ) {
			add_settings_error(
				self::OPTION,
				'e_butik_site_id',
				__( 'Site ID skal være et positivt heltal. Den tidligere værdi er bevaret.', 'e-butik-integration' )
			);
			$site_id = $current['site_id'];
		}

		return array(
			'site_id'     => $site_id,
			'delete_data' => ! empty( $input['delete_data'] ),
		);
	}

	/**
	 * Adds the subpage under Settings.
	 */
	public static function add_settings_page() {
		add_options_page(
			__( 'E-butik Integration', 'e-butik-integration' ),
			__( 'E-butik Integration', 'e-butik-integration' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Renders the Site ID field.
	 */
	public static function render_site_id_field() {
		$settings = self::get_settings();

		printf(
			'<input type="number" id="e_butik_site_id" name="%s[site_id]" value="%s" class="small-text" min="1" step="1" required> <p class="description">%s</p>',
			esc_attr( self::OPTION ),
			esc_attr( $settings['site_id'] > 0 ? $settings['site_id'] : '' ),
			esc_html__( 'Webshoppens ID hos E-butik. Udleveres af E-butik.', 'e-butik-integration' )
		);
	}

	/**
	 * Renders the uninstall behaviour checkbox.
	 *
	 * Unchecked by default, so deleting the plugin leaves the settings in place
	 * and a reinstall picks up where it left off.
	 */
	public static function render_delete_data_field() {
		$settings = self::get_settings();

		printf(
			'<label><input type="checkbox" id="e_butik_delete_data" name="%s[delete_data]" value="1"%s> %s</label> <p class="description">%s</p>',
			esc_attr( self::OPTION ),
			checked( $settings['delete_data'], true, false ),
			esc_html__( 'Slet indstillingerne, når pluginet afinstalleres', 'e-butik-integration' ),
			esc_html__( 'Som standard bevares Site ID, også hvis pluginet slettes, så det er der igen efter en geninstallation. Sæt flueben her, hvis du vil rydde helt op.', 'e-butik-integration' )
		);
	}

	/**
	 * Renders the settings page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'E-butik regnskabs-integration', 'e-butik-integration' ); ?></h1>
			<?php self::render_webhook_result(); ?>
			<p><?php esc_html_e( 'Angiv det Site ID, som denne webshop har hos E-butik. Knappen "Kør integration" vises først i ordrelisten, når feltet er udfyldt.', 'e-butik-integration' ); ?></p>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::SLUG );
				submit_button();
				?>
			</form>

			<?php self::render_webhook_section(); ?>
		</div>
		<?php
	}

	/**
	 * Adds a settings shortcut to the plugins list.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function add_settings_link( $links ) {
		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::settings_url() ),
			esc_html__( 'Indstillinger', 'e-butik-integration' )
		);

		array_unshift( $links, $link );

		return $links;
	}
}

E_Butik_Integration::init();
