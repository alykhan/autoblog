<?php

// +----------------------------------------------------------------------+
// | Copyright Incsub (http://incsub.com/)                                |
// +----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License, version 2, as  |
// | published by the Free Software Foundation.                           |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to the Free Software          |
// | Foundation, Inc., 51 Franklin St, Fifth Floor, Boston,               |
// | MA 02110-1301 USA                                                    |
// +----------------------------------------------------------------------+

/**
 * Feeds pages module.
 *
 * @category Autoblog
 * @package Module
 * @subpackage Page
 *
 * @since 4.0.0
 */
class Autoblog_Module_Page_Feeds extends Autoblog_Module {

	const NAME = __CLASS__;

	/**
	 * Determines whether the plugin is network wide activated or not.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 * @var boolean
	 */
	private $_is_network_wide = false;

	/**
	 * Constructor.
	 *
	 * @since 4.0.0
	 *
	 * @access public
	 * @param Autoblog_Plugin $plugin The instance of the plugin.
	 */
	public function __construct( Autoblog_Plugin $plugin ) {
		parent::__construct( $plugin );

		$this->_is_network_wide = is_multisite();
		if ( $this->_is_network_wide ) {
			$sitewide_plugins = get_site_option( 'active_sitewide_plugins' );
			$this->_is_network_wide &= isset( $sitewide_plugins[plugin_basename( AUTOBLOG_BASEFILE )] );
			$this->_is_network_wide &= is_network_admin();
		}

		$this->_add_action( 'autoblog_handle_feeds_page', 'handle' );

		// ajax actions
		$this->_add_ajax_action( 'autoblog-get-blog-categories', 'get_blog_categories' );
		$this->_add_ajax_action( 'autoblog-get-blog-authors', 'get_blog_authors' );
	}

	/**
	 * Returns blog categories list.
	 *
	 * @since 4.0.0
	 * @action wp_ajax_autoblog-get-blog-categories
	 *
	 * @access public
	 */
	public function get_blog_categories() {
		$bid = filter_input( INPUT_GET, 'id', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( !$bid ) {
			wp_send_json_error();
		}

		switch_to_blog( $bid );
		$categories = get_categories();
		restore_current_blog();

		$data = array();
		$data[] = array(
			'term_id' => '-1',
			'name'    => __( 'None', 'autoblogtext' )
		);

		foreach ( $categories as $category ) {
			$data[] = array(
				'term_id' => $category->term_id,
				'name'    => $category->name,
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * Returns blog authors list.
	 *
	 * @sicne 4.0.0
	 * @action wp_ajax_autoblog-get-blog-authors
	 *
	 * @access public
	 */
	public function get_blog_authors() {
		$bid = filter_input( INPUT_GET, 'id', FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( !$bid ) {
			wp_send_json_error();
		}

		$data = array();
		$blogusers = get_users( array(
			'blog_id' => $bid,
		) );

		foreach ( $blogusers as $buser ) {
			$data[] = array(
				'user_id'    => $buser->user_id,
				'user_login' => $buser->user_login,
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * Handles feeds page.
	 *
	 * @since 4.0.0
	 * @action autoblog_handle_feeds_page
	 *
	 * @access public
	 */
	public function handle() {
		$table = new Autoblog_Table_Feeds( array(
			'nonce'           => wp_create_nonce( 'autoblog_feeds' ),
			'is_network_wide' => $this->_is_network_wide,
			'actions'         => array(
				'process' => __( 'Process', 'autoblogtext' ),
				'delete'  => __( 'Delete', 'autoblogtext' ),
			),
		) );

		switch ( $table->current_action() ) {
			case 'add':
				$this->_handle_feed_form();
				break;
			case 'edit':
				$this->_handle_feed_form( filter_input( INPUT_GET, 'item', FILTER_VALIDATE_INT ) );
				break;
			case 'process':
				$this->_process_feeds();
				break;
			case 'delete':
				$this->_delete_feeds();
				break;
			case 'test':
				$this->_test_feed();
				break;
			default:
				if ( filter_input( INPUT_GET, 'noheader', FILTER_VALIDATE_BOOLEAN ) ) {
					wp_redirect( add_query_arg( 'noheader', false ) );
					exit;
				}

				$testlog = array();
				if ( filter_input( INPUT_GET, 'tested', FILTER_VALIDATE_BOOLEAN ) ) {
					$testlog = get_transient( 'autoblog_last_test_log' );
					if ( $testlog !== false && !empty( $testlog['log'] ) ) {
						$testlog = $testlog['log'];
						delete_transient( 'autoblog_last_test_log' );
					} else {
						$testlog = array();
					}
				}

				$template = new Autoblog_Render_Feeds_Table();

				$template->table = $table;
				$template->testlog = $testlog;
				$template->is_network_wide = $this->_is_network_wide;

				$template->render();
				break;
		}
	}

	/**
	 * Handles feed create/edit form.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 * @param int $feed_id The id of a feed to edit.
	 * @return boolean TRUE to prevent table rendering.
	 */
	private function _handle_feed_form( $feed_id = false ) {
		$feed = $feed_data = array();
		if ( $feed_id ) {
			$feed = $this->_wpdb->get_row( sprintf(
				$this->_is_network_wide
					? 'SELECT * FROM %s WHERE feed_id = %d LIMIT 1'
					: 'SELECT * FROM %s WHERE feed_id = %d AND blog_id = %d LIMIT 1',
				AUTOBLOG_TABLE_FEEDS,
				$feed_id,
				get_current_blog_id()
			), ARRAY_A );

			if ( !$feed ) {
				wp_die( __( 'Feed not found.', 'autoblogtext' ) );
			}
		}

		if ( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
			check_admin_referer( 'autoblog_feeds' );

			$post = $_POST['abtble'];
			if ( !empty( $post['startfromday'] ) && !empty( $post['startfrommonth'] ) && !empty( $post['startfromyear'] ) ) {
				$post['startfrom'] = strtotime( "{$post['startfromyear']}-{$post['startfrommonth']}-{$post['startfromday']}" );
			}

			if ( !empty( $post['endonday'] ) && !empty( $post['endonmonth'] ) && !empty( $post['endonyear'] ) ) {
				$post['endon'] = strtotime( "{$post['endonyear']}-{$post['endonmonth']}-{$post['endonday']}" );
			}

			$feed['feed_meta'] = serialize( $post );
			$feed['blog_id'] = (int)$post['blog'];
			$feed['nextcheck'] = isset( $post['processfeed'] ) && intval( $post['processfeed'] ) > 0 ? current_time( 'timestamp' ) + absint( $post['processfeed'] ) * 60 : 0;

			$action = 'created';
			$result = 'false';
			if ( isset( $feed['feed_id'] ) ) {
				$action = 'updated';
				$result = $this->_wpdb->update( AUTOBLOG_TABLE_FEEDS, $feed, array( 'feed_id' => $feed['feed_id'] ) ) ? 'true' : 'false';
			} else {
				$result = $this->_wpdb->insert( AUTOBLOG_TABLE_FEEDS, $feed ) ? 'true' : 'false';
			}

			wp_safe_redirect( add_query_arg( $action, $result, 'admin.php?page=' . filter_input( INPUT_GET, 'page' ) ) );
			exit;
		}

		if ( !empty( $feed ) ) {
			$feed_data = @unserialize( $feed['feed_meta'] );
			$feed_data['feed_meta'] = $feed['feed_meta'];
			$feed_data['feed_id'] = $feed_id;
		}

		if ( empty( $feed_data['blog'] ) ) {
			$feed_data['blog'] = get_current_blog_id();
		}

		if ( empty( $feed_data['posttype'] ) ) {
			$feed_data['posttype'] = 'post';
		}

		$template = new Autoblog_Render_Feeds_Form( $feed_data );
		$template->render();

		return true;
	}

	/**
	 * Deletes feeds.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 */
	private function _delete_feeds() {
		check_admin_referer( 'autoblog_feeds' );

		$feeds = isset( $_REQUEST['items'] ) ? (array)$_REQUEST['items'] : array();
		$feeds = array_filter( array_map( 'intval', $feeds ) );
		if ( empty( $feeds ) ) {
			wp_safe_redirect( 'admin.php?page=' . $_REQUEST['page'] );
			exit;
		}

		$this->_wpdb->query( sprintf(
			$this->_is_network_wide
				? 'DELETE FROM %s WHERE feed_id IN (%s)'
				: 'DELETE FROM %s WHERE feed_id IN (%s) AND blog_id = %d',
			AUTOBLOG_TABLE_FEEDS,
			implode( ', ', $feeds ),
			get_current_blog_id()
		) );

		wp_safe_redirect( 'admin.php?page=' . $_REQUEST['page'] . '&deleted=true' );
		exit;
	}

	/**
	 * Processes feeds.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 */
	private function _process_feeds() {
		check_admin_referer( 'autoblog_feeds' );

		$feeds = isset( $_REQUEST['items'] ) ? (array)$_REQUEST['items'] : array();
		$feeds = array_filter( array_map( 'intval', $feeds ) );
		if ( empty( $feeds ) ) {
			wp_safe_redirect( 'admin.php?page=' . $_REQUEST['page'] );
			exit;
		}

		$cron = $this->_plugin->get_module( Autoblog_Module_Cron::NAME );
		if ( $cron ) {
			$cron->process_feeds( $feeds );
		}

		wp_safe_redirect( 'admin.php?page=' . $_REQUEST['page'] . '&processed=true' );
		exit;
	}

	/**
	 * Test feed.
	 *
	 * @since 4.0.0
	 *
	 * @access private
	 */
	private function _test_feed() {
		check_admin_referer( 'autoblog_feeds' );

		$feed = filter_input( INPUT_GET, 'item', FILTER_VALIDATE_INT );
		$cron = $this->_plugin->get_module( Autoblog_Module_Cron::NAME );
		if ( $feed && $cron ) {
			$feed = $cron->get_autoblogentry( $feed );
			if ( $feed ) {
				$cron->test_the_feed( $feed->feed_id, unserialize( $feed->feed_meta ) );
			}
		}

		wp_safe_redirect( 'admin.php?page=' . $_REQUEST['page'] . '&tested=true' );
		exit;
	}

}