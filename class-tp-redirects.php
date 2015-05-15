<?php
/**
 * Plugin Name: Redirects
 * Description: Redirects. No additional features.
 *
 * Plugin URI: https://github.com/trendwerk/redirects
 * 
 * Author: Trendwerk
 * Author URI: https://github.com/trendwerk
 * 
 * Version: 1.0.2
 *
 * @package Redirects
 */

/**
 * Perform redirects
 */

class TP_Redirects {
	var $table = 'redirects';

	function __construct() {
		//Perform redirects
		add_action( '404_template', array( $this, 'maybe_redirect' ) );
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 9 );

		//Create table
		register_activation_hook( __FILE__, array( $this, 'maybe_create_table' ) );
		add_action( 'init', array( $this, 'register_table' ), 9 );
	}

	/**
	 * Maybe redirect someone to the right URL
	 */
	function get_maybe_redirect_url( $source, $parameters = '' ) {
		global $wpdb;

		/**
		 * Rectify source
		 */
		$source = str_replace( home_url(), '', $source );

		$extension = explode( '.', $source );

		if( $source == end( $extension ) ) //No extension (e.g. .html)
			$source = trailingslashit( $source );

		if( 0 < strlen( $parameters ) )
			$source .= '?' . $parameters;

		/**
		 * Find the destination
		 */
		$destination = $wpdb->get_results( "SELECT * FROM {$wpdb->redirects} WHERE source='" . esc_sql( $source ) . "'" );

		if( 0 < count( $destination ) && isset( $destination[0]->destination ) && 0 < strlen( $destination[0]->destination ) ) {
			$destination = $destination[0]->destination;

			$extension = explode( '.', $destination );

			if( $destination == end( $extension ) ) //No extension (e.g. .html)
				$destination = trailingslashit( $destination );
				
			if( filter_var( $destination, FILTER_VALIDATE_URL ) === false )
				return home_url() . $destination;
			else
				return $destination; //External
		}

		return false;
	}

	/**
	 * Maybe redirect
	 */
	function maybe_redirect( $template ) {
		global $wp;

		if( is_404() ) {
			$parameters = parse_url( $_SERVER['REQUEST_URI'] );

			$url = $this->get_maybe_redirect_url( home_url( $wp->request ), isset( $parameters['query'] ) ? $parameters['query'] : '' );

			if( $url ) {
				wp_redirect( $url, 301 );
				die();
			}
		}

		return $template;
	}

	/**
	 * Maybe create the redirects table if it doesn't yet exists
	 */
	function maybe_create_table() {
		global $wpdb;

		$table_name = $wpdb->prefix . $this->table;

		if( ! empty( $wpdb->charset ) )
			$charset_collate = "DEFAULT CHARACTER SET " . $wpdb->charset;

	    if( ! empty( $wpdb->collate ) )
	        $charset_collate .= " COLLATE " . $wpdb->collate;
		             
		$sql = "CREATE TABLE IF NOT EXISTS " . $table_name . " (
	        `source` varchar(191) NOT NULL DEFAULT '',
	        `destination` varchar(191) NOT NULL DEFAULT '',

	        PRIMARY KEY (`source`)
	    ) " . $charset_collate . ";";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	}

	/**
	 * Register table
	 */
	function register_table() {
		global $wpdb;

		$wpdb->redirects = $wpdb->prefix . $this->table;
	}
} new TP_Redirects;

/**
 * Manage redirects
 */

class TP_Manage_Redirects {
	function __construct() {
		add_action( 'wp_ajax_tp_redirects_get', array( $this, '_get' ) );
		add_action( 'wp_ajax_tp_redirects_create', array( $this, '_create' ) );
		add_action( 'wp_ajax_tp_redirects_remove', array( $this, '_remove' ) );
		add_action( 'wp_ajax_tp_redirects_save', array( $this, '_save' ) );

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'plugins_loaded', array( $this, 'localization' ) );
	}

	/**
	 * AJAX: Get redirects
	 *
	 * @return json Object data, containing HTML output
	 */
	function _get() {
		global $wpdb;

		$replace = false;
		$page = 0;

		if( 'search' == $_POST['type'] ) {
			$term = esc_attr( $_POST['term'] );
			$replace = true;

			if( $term ) {
				$redirects = $wpdb->get_results( "
					SELECT * FROM " . $wpdb->redirects . " 
					WHERE source LIKE '%" . $term . "%' OR destination LIKE '%" . $term . "%'
					ORDER BY CASE
						WHEN (source LIKE '%" . $term . "%' AND destination LIKE '%" . $term . "%') THEN 1
						WHEN source LIKE '%" . $term . "%' THEN 2
						WHEN destination LIKE '%" . $term . "%' THEN 3
					END
				" );
			} else {
				$redirects = $this->get_redirects( 1 );
				$page = 1;
			}

		} elseif( 'paged' == $_POST['type'] ) {
			$page = intval( $_POST['page'] );
			$redirects = $this->get_redirects( $page );
		}

		$output = '';

		if( $redirects ) {
			//Redirects > HTML
			ob_start();
			$this->display_redirects( $redirects );
			$output = ob_get_clean();
		}

		wp_send_json( array(
			'replace' => $replace,
			'html'    => $output,
			'page'    => $page,
		) );
	}

	/**
	 * AJAX: Create redirect
	 *
	 * @return json Object data, containing HTML output
	 */
	function _create() {
		global $wpdb;

		$source = $this->correct( $_POST['source'] );

		if( $source )
			$redirect = $wpdb->get_results( "SELECT * FROM " . $wpdb->redirects . " WHERE source = '" . $source . "'" );
		else
			die();

		if( 0 == count( $redirect ) )
			$redirect = $wpdb->query( "INSERT INTO " . $wpdb->redirects . " VALUES( '" . $source . "', '' );");

		$_POST['type'] = 'search';
		$_POST['term'] = $source;

		$this->_get();
	}

	/**
	 * AJAX: Save
	 *
	 * @return json Object data, containing HTML output
	 */
	function _save() {
		global $wpdb;

		$reference = esc_attr( $_POST['refSource'] );
		$source = $this->correct( $_POST['source'] );
		$destination = $this->correct( $_POST['destination'] );

		if( $reference )
			$wpdb->query( "UPDATE " . $wpdb->redirects . " SET source = '" . $source . "', destination = '" . $destination . "' WHERE source = '" . $reference . "'" );

		//Retrieve new HTML
		$redirect = $wpdb->get_results( "SELECT * FROM " . $wpdb->redirects. " WHERE source = '" . $source . "'" );
		
		ob_start();
		$this->display_redirects( $redirect );
		$output = ob_get_clean();

		wp_send_json( array(
			'html' => $output,
		) );
	}

	/**
	 * Correct url
	 * 
	 * @param  string $url 
	 * @return string
	 *
	 * @abstract
	 */
	static function correct( $url ) {
		return str_replace( get_home_url(), '', esc_attr( $url ) );
	}

	/**
	 * AJAX: Remove redirect
	 */
	function _remove() {
		global $wpdb;

		$source = esc_attr( $_POST['source'] );
		$redirect = $wpdb->query( "DELETE FROM " . $wpdb->redirects . " WHERE source = '" . $source . "'" );

		wp_send_json( array(
			'removed' => true,
		) );
	}

	/**
	 * Add admin menu
	 */
	function add_menu() {
		add_submenu_page( 'tools.php', __( 'Redirects', 'tp-redirects' ), __( 'Redirects', 'tp-redirects' ), apply_filters( 'tp_redirects_cap', 'publish_pages' ), 'tp-redirects', array( $this, 'manage' ) );
	}

	/**
	 * Manage redirects
	 */
	function manage() {
		?>
		
		<div class="wrap tp-redirects">

			<h2>
				<?php _e( 'Redirects', 'tp-redirects' ); ?>
			</h2>

			<p>
				<input type="text" id="tp-redirects-search" placeholder="<?php _e( 'Find or create redirect..', 'tp-redirects' ); ?>" />
				<input type="button" id="tp-redirects-add" class="button-primary" value="<?php _e( 'Add', 'tp-redirects' ); ?>" />
			</p>
			
			<?php $this->display_redirect_table(); ?>

		</div>

		<?php
	}

	/**
	 * Show redirects table
	 */
	function display_redirect_table() {
		$redirects = $this->get_redirects( 1 );
		
		?>
		
		<table class="tp-redirects-table widefat">

			<thead>

				<tr>

					<th>
						<?php _e( 'Source', 'tp-redirects' ); ?>
					</th>

					<th colspan="2">
						<?php _e( 'Destination', 'tp-redirects' ); ?>
					</th>

				</tr>

			</thead>
			
			<tbody>

				<?php 
					if( $redirects )
						$this->display_redirects( $redirects );
				?>

			</tbody>

			<tfoot>
				
				<tr class="tp-redirects-more">
				
					<td colspan="3">
						<span class="spinner"></span>
					</td>

				</tr>

			</tfoot>

		</table>

		<?php
	}

	/**
	 * Show redirects
	 */
	function display_redirects( $redirects ) {
		foreach( $redirects as $i => $redirect ) { 
			?>

			<tr data-source="<?php echo $redirect->source; ?>" data-destination="<?php echo $redirect->destination; ?>" <?php if( $i % 2 ) echo 'class="alternate"'; ?>>

				<td class="source">
					<?php echo $redirect->source; ?>
				</td>

				<td class="destination">
					<?php echo $redirect->destination; ?>
				</td>

				<td class="actions">
					<a class="dashicons dashicons-edit tp-redirects-edit" title="<?php _e( 'Edit', 'tp-redirects' ); ?>"></a>
					<a class="dashicons dashicons-post-trash tp-redirects-remove" title="<?php _e( 'Remove', 'tp-redirects' ); ?>"></a>
				</td>

			</tr>

			<?php 
		}
	}

	/**
	 * Retrieve redirects
	 * 
	 * @param int $page
	 * @param int $to_page Maybe we want to return data for multiple pages
	 * @return array
	 */
	function get_redirects( $page, $to_page = null ) {
		global $wpdb;

		$limit = '0,100';

		if( 1 < $page )
			$limit = ( ( $page - 1 ) * 100 ) . ',100';

		return $wpdb->get_results( "SELECT * FROM " . $wpdb->redirects . " LIMIT " . $limit );
	}

	/**
	 * Enqueue scripts
	 */
	function enqueue_scripts() {
		wp_enqueue_script( 'tp-redirects', plugins_url( 'assets/js/tp-redirects.js', __FILE__ ), array( 'jquery' ) );

		wp_localize_script( 'tp-redirects', 'TP_Redirects_Labels', array(
			'not_found'      => __( 'This redirect doesn\'t exist yet. Press &ldquo;Enter&rdquo; to create it.', 'tp-redirects' ),
			'delete_confirm' => __( 'Are you sure you want to delete this redirect?', 'tp-redirects' ),
			'edit_finish'    => __( 'Save', 'tp-redirects' ),
			'edit_dismiss'   => __( 'Dismiss', 'tp-redirects' ),
			'home_url'       => get_home_url(),
		) );

		wp_enqueue_style( 'tp-redirects', plugins_url( 'assets/sass/admin.css', __FILE__ ) );

		/**
		 * Add pointers
		 */
		wp_enqueue_style( 'wp-pointer' );
		wp_enqueue_script( 'wp-pointer' );

		$dismissed = explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) );

		$pointers = array(
			'tp-redirects-search' => array(
				'element'         => '#tp-redirects-search',
				'header'          => __( '&ldquo;Enter&rdquo; to create', 'tp-redirects' ),
				'text'            => __( 'Press &ldquo;Enter&rdquo; to create or edit the redirect you\'re searching for.', 'tp-redirects' ),
			),
		);

		foreach( $pointers as $pointer => $settings ) {
			if( in_array( $pointer, $dismissed ) )
				unset( $pointers[ $pointer ] );
		}

		wp_localize_script( 'tp-redirects', 'TP_Redirects_Pointers', $pointers );
	}

	/**
	 * Load localization
	 */
	function localization() {
		load_muplugin_textdomain( 'tp-redirects', dirname( plugin_basename( __FILE__ ) ) . '/assets/lang/' );
	}

} new TP_Manage_Redirects;

/**
 * API
 */

/**
 * Update a redirect. Creates one if it doesnt exist yet.
 *
 * @param string $source Source URL
 * @param string $destination Destination URL
 */
function tp_update_redirect( $source, $destination ) {
	global $wpdb;

	if( ! isset( $wpdb->redirects ) ) {
		_doing_it_wrong( 'tp_update_redirect', 'This function should be called on the init action or later.', '4.0' );
		return;
	}

	$source = TP_Manage_Redirects::correct( $source );
	$destination = TP_Manage_Redirects::correct( $destination );

	if( ! $source )
		return;

	$redirect = $wpdb->get_results( "SELECT * FROM " . $wpdb->redirects . " WHERE source = '" . $source . "'" );

	if( 0 == count( $redirect ) )
		$redirect = $wpdb->query( "INSERT INTO " . $wpdb->redirects . " VALUES( '" . $source . "', '' );");
	else
		$redirect = $wpdb->query( "UPDATE " . $wpdb->redirects . " SET destination = '" . $destination . "' WHERE source = '" . $source . "'" );

	return $wpdb->get_results( "SELECT * FROM " . $wpdb->redirects . " WHERE source = '" . $source . "'" );
}
