<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Handles all admin views, assets and navigation.
 *
 * @package Views
 * @author Automattic
 * @since 1.0.0
 */
class Sensei_Admin {

	/**
	 * @var $course_order_page_slug The slug for the Order Courses page.
	 */
	private $course_order_page_slug;

	/**
	 * @var $lesson_order_page_slug The slug for the Order Lessons page.
	 */
	private $lesson_order_page_slug;

	/**
	 * Constructor.
	 *
	 * @since  1.0.0
	 */
	public function __construct() {

		$this->course_order_page_slug = 'course-order';
		$this->lesson_order_page_slug = 'lesson-order';

		// register admin styles
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles_global' ) );

		// register admin scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'register_scripts' ) );

		add_action( 'admin_print_styles', array( $this, 'admin_notices_styles' ) );
		add_action( 'settings_before_form', array( $this, 'install_pages_output' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 10 );
		add_action( 'menu_order', array( $this, 'admin_menu_order' ) );
		add_action( 'admin_head', array( $this, 'admin_menu_highlight' ) );
		add_action( 'admin_init', array( $this, 'page_redirect' ) );
		add_action( 'admin_init', array( $this, 'sensei_add_custom_menu_items' ) );
		add_action( 'admin_init', array( __CLASS__, 'install_pages' ) );

		// Duplicate lesson & courses
		add_filter( 'post_row_actions', array( $this, 'duplicate_action_link' ), 10, 2 );
		add_action( 'admin_action_duplicate_lesson', array( $this, 'duplicate_lesson_action' ) );
		add_action( 'admin_action_duplicate_course', array( $this, 'duplicate_course_action' ) );
		add_action( 'admin_action_duplicate_course_with_lessons', array( $this, 'duplicate_course_with_lessons_action' ) );

		// Handle course and lesson ordering.
		add_action( 'admin_post_order_courses', array( $this, 'handle_order_courses' ) );
		add_action( 'admin_post_order_lessons', array( $this, 'handle_order_lessons' ) );

		// Handle lessons list table filtering
		add_action( 'restrict_manage_posts', array( $this, 'lesson_filter_options' ) );
		add_filter( 'request', array( $this, 'lesson_filter_actions' ) );

		// Add Sensei items to 'at a glance' widget
		add_filter( 'dashboard_glance_items', array( $this, 'glance_items' ), 10, 1 );

		// Handle course and lesson deletions
		add_action( 'trash_course', array( $this, 'delete_content' ), 10, 2 );
		add_action( 'trash_lesson', array( $this, 'delete_content' ), 10, 2 );

		// Delete user activity when user is deleted
		add_action( 'deleted_user', array( $this, 'delete_user_activity' ), 10, 1 );

		// Add notices to WP dashboard
		add_action( 'admin_notices', array( $this, 'theme_compatibility_notices' ) );
		// warn users in case admin_email is not a real WP_User
		add_action( 'admin_notices', array( $this, 'notify_if_admin_email_not_real_admin_user' ) );

		// remove a course from course order when trashed
		add_action( 'transition_post_status', array( $this, 'remove_trashed_course_from_course_order' ) );

		// Add workaround for block editor bug on CPT pages. See the function doc for more information.
		add_action( 'admin_footer', array( $this, 'output_cpt_block_editor_workaround' ) );

		// Add AJAX endpoint for event logging.
		add_action( 'wp_ajax_sensei_log_event', array( $this, 'ajax_log_event' ) );

		Sensei_Extensions::instance()->init();

	}

	/**
	 * Add items to admin menu
	 *
	 * @since  1.4.0
	 * @return void
	 */
	public function admin_menu() {
		global $menu;
		$menu_cap = '';
		if ( current_user_can( 'manage_sensei' ) ) {
			$menu_cap = 'manage_sensei';
		} else {
			if ( current_user_can( 'manage_sensei_grades' ) ) {
				$menu_cap = 'manage_sensei_grades';
			}
		}

		if ( $menu_cap ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Only way to add separator above our menu group.
			$menu[] = array( '', 'read', 'separator-sensei', '', 'wp-menu-separator sensei' );
			add_menu_page( 'Sensei LMS', 'Sensei LMS', $menu_cap, 'sensei', array( Sensei()->analysis, 'analysis_page' ), '', '50' );
		}

		add_submenu_page( 'edit.php?post_type=course', __( 'Order Courses', 'sensei-lms' ), __( 'Order Courses', 'sensei-lms' ), 'manage_sensei', $this->course_order_page_slug, array( $this, 'course_order_screen' ) );
		add_submenu_page( 'edit.php?post_type=lesson', __( 'Order Lessons', 'sensei-lms' ), __( 'Order Lessons', 'sensei-lms' ), 'edit_lessons', $this->lesson_order_page_slug, array( $this, 'lesson_order_screen' ) );
	}

	/**
	 * [admin_menu_order description]
	 *
	 * @since  1.4.0
	 * @param  array $menu_order Existing menu order
	 * @return array             Modified menu order for Sensei
	 */
	public function admin_menu_order( $menu_order ) {

		// Initialize our custom order array
		$sensei_menu_order = array();

		// Get the index of our custom separator
		$sensei_separator = array_search( 'separator-sensei', $menu_order );

		// Loop through menu order and do some rearranging
		foreach ( $menu_order as $index => $item ) :

			if ( ( ( 'sensei' ) == $item ) ) :
				$sensei_menu_order[] = 'separator-sensei';
				$sensei_menu_order[] = $item;
				unset( $menu_order[ $sensei_separator ] );
			elseif ( ! in_array( $item, array( 'separator-sensei' ) ) ) :
				$sensei_menu_order[] = $item;
			endif;

		endforeach;

		// Return order
		return $sensei_menu_order;
	}

	/**
	 * Handle highlighting of admin menu items
	 *
	 * @since 1.4.0
	 * @return void
	 */
	public function admin_menu_highlight() {
		global $parent_file, $submenu_file, $post_type, $taxonomy;

		$screen = get_current_screen();

		if ( empty( $screen ) ) {
			return;
		}

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Only way to highlight our special pages in menu.
		if ( $screen->base == 'post' && $post_type == 'course' ) {

			$parent_file = 'edit.php?post_type=course';

		} elseif ( $screen->base == 'edit-tags' && $taxonomy == 'course-category' ) {

			$submenu_file = 'edit-tags.php?taxonomy=course-category&amp;post_type=course';
			$parent_file  = 'edit.php?post_type=course';

		} elseif ( $screen->base == 'edit-tags' && $taxonomy == 'module' ) {

			$submenu_file = 'edit-tags.php?taxonomy=module';
			$parent_file  = 'edit.php?post_type=course';

		} elseif ( in_array( $screen->id, array( 'sensei_message', 'edit-sensei_message' ) ) ) {

			$submenu_file = 'edit.php?post_type=sensei_message';
			$parent_file  = 'sensei';

		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Redirect Sensei menu item to Analysis page
	 *
	 * @since  1.4.0
	 * @return void
	 */
	public function page_redirect() {
		if ( isset( $_GET['page'] ) && $_GET['page'] == 'sensei' ) {
			wp_safe_redirect( 'admin.php?page=sensei_analysis' );
			exit;
		}
	}

	/**
	 * install_pages_output function.
	 *
	 * Handles installation of the 2 pages needs for courses and my courses
	 *
	 * @access public
	 * @return void
	 */
	function install_pages_output() {

		if ( isset( $_GET['sensei_install_complete'] ) && 'true' == $_GET['sensei_install_complete'] ) {

			?>
			<div id="message" class="updated sensei-message sensei-connect">
				<p><?php echo wp_kses_post( __( '<strong>Congratulations!</strong> &#8211; Sensei LMS has been installed and set up.', 'sensei-lms' ) ); ?></p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=course' ) ); ?>" class="button-primary">
						<?php esc_html_e( 'Create a Course', 'sensei-lms' ); ?>
					</a>
				</p>
			</div>
			<?php

		}

	} // End install_pages_output()


	/**
	 * create_page function.
	 *
	 * @access public
	 * @param mixed  $slug
	 * @param mixed  $option
	 * @param string $page_title (default: '')
	 * @param string $page_content (default: '')
	 * @param int    $post_parent (default: 0)
	 * @return integer $page_id
	 */
	function create_page( $slug, $page_title = '', $page_content = '', $post_parent = 0 ) {
		global $wpdb;

		$page_id = $wpdb->get_var( $wpdb->prepare( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE post_name = %s LIMIT 1;', $slug ) );
		if ( $page_id ) :
			return $page_id;
		endif;

		$page_data = array(
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_author'    => 1,
			'post_name'      => $slug,
			'post_title'     => $page_title,
			'post_content'   => $page_content,
			'post_parent'    => $post_parent,
			'comment_status' => 'closed',
		);

		$page_id = wp_insert_post( $page_data );

		return $page_id;

	} // End create_page()


	/**
	 * create_pages function.
	 *
	 * @access public
	 * @return void
	 */
	function create_pages() {

		// Courses page
		$new_course_page_id = $this->create_page( esc_sql( _x( 'courses-overview', 'page_slug', 'sensei-lms' ) ), __( 'Courses', 'sensei-lms' ), '' );
		Sensei()->settings->set( 'course_page', $new_course_page_id );

		// User Dashboard page
		$new_my_course_page_id = $this->create_page( esc_sql( _x( 'my-courses', 'page_slug', 'sensei-lms' ) ), __( 'My Courses', 'sensei-lms' ), '[sensei_user_courses]' );
		Sensei()->settings->set( 'my_course_page', $new_my_course_page_id );

	} // End create_pages()

	/**
	 * Load the global admin styles for the menu icon and the relevant page icon.
	 *
	 * @access public
	 * @since 1.0.0
	 * @return void
	 */
	public function admin_styles_global( $hook ) {
		global $post_type;

		$allowed_post_types      = apply_filters( 'sensei_scripts_allowed_post_types', array( 'lesson', 'course', 'question' ) );
		$allowed_post_type_pages = apply_filters( 'sensei_scripts_allowed_post_type_pages', array( 'edit.php', 'post-new.php', 'post.php', 'edit-tags.php' ) );
		$allowed_pages           = apply_filters( 'sensei_scripts_allowed_pages', array( 'sensei_grading', 'sensei_analysis', 'sensei_learners', 'sensei_updates', 'sensei-settings', $this->lesson_order_page_slug, $this->course_order_page_slug ) );

		// Global Styles for icons and menu items
		wp_register_style( 'sensei-global', Sensei()->plugin_url . 'assets/css/global.css', '', Sensei()->version, 'screen' );
		wp_enqueue_style( 'sensei-global' );
		$select_two_location = '/assets/vendor/select2/select2.min.css';

		// Select 2 styles
		wp_enqueue_style( 'sensei-core-select2', Sensei()->plugin_url . $select_two_location, '', Sensei()->version, 'screen' );

		// Test for Write Panel Pages
		if ( ( ( isset( $post_type ) && in_array( $post_type, $allowed_post_types ) ) && ( isset( $hook ) && in_array( $hook, $allowed_post_type_pages ) ) ) || ( isset( $_GET['page'] ) && in_array( $_GET['page'], $allowed_pages ) ) ) {

			wp_register_style( 'sensei-admin-custom', Sensei()->plugin_url . 'assets/css/admin-custom.css', '', Sensei()->version, 'screen' );
			wp_enqueue_style( 'sensei-admin-custom' );

		}

	}


	/**
	 * Globally register all scripts needed in admin.
	 *
	 * The script users should enqueue the script when needed.
	 *
	 * @since 1.8.2
	 * @access public
	 */
	public function register_scripts( $hook ) {
		$screen = get_current_screen();

		// Allow developers to load non-minified versions of scripts
		$suffix              = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$select_two_location = '/assets/vendor/select2/select2.full';

		// Select2 script used to enhance all select boxes
		wp_register_script( 'sensei-core-select2', Sensei()->plugin_url . $select_two_location . $suffix . '.js', array( 'jquery' ), Sensei()->version );

		// Load ordering script on Order Courses and Order Lessons pages.
		if ( in_array( $screen->id, [ 'course_page_course-order', 'lesson_page_lesson-order' ], true ) ) {
			wp_enqueue_script(
				'sensei-ordering',
				Sensei()->plugin_url . 'assets/js/admin/ordering' . $suffix . '.js',
				array( 'jquery', 'jquery-ui-sortable', 'sensei-core-select2' ),
				Sensei()->version,
				true
			);
		}

		// load edit module scripts
		if ( 'edit-module' == $screen->id ) {
			wp_enqueue_script( 'sensei-chosen-ajax', Sensei()->plugin_url . 'assets/chosen/ajax-chosen.jquery.min.js', array( 'jquery', 'sensei-chosen' ), Sensei()->version, true );
		}

		wp_enqueue_script( 'sensei-message-menu-fix', Sensei()->plugin_url . 'assets/js/admin/message-menu-fix.js', array( 'jquery' ), Sensei()->version, true );

		// Event logging.
		wp_enqueue_script( 'sensei-event-logging', Sensei()->plugin_url . 'assets/js/admin/event-logging' . $suffix . '.js', array( 'jquery' ), Sensei()->version, true );
		wp_localize_script( 'sensei-event-logging', 'sensei_event_logging', [ 'enabled' => Sensei_Usage_Tracking::get_instance()->get_tracking_enabled() ] );
	}


	/**
	 * admin_install_notice function.
	 *
	 * @access public
	 * @return void
	 */
	function admin_install_notice() {
		?>
		<div id="message" class="updated sensei-message sensei-connect">

			<p>
				<?php echo wp_kses_post( __( '<strong>Welcome to Sensei LMS</strong> &#8211; You\'re almost ready to create some courses!', 'sensei-lms' ) ); ?>
			</p>

			<p class="submit">

				<a href="<?php echo esc_url( add_query_arg( 'install_sensei_pages', 'true', admin_url( 'admin.php?page=sensei-settings' ) ) ); ?>"
				   class="button-primary">

					<?php esc_html_e( 'Install Sensei LMS Pages', 'sensei-lms' ); ?>

				</a>

				<a class="skip button" href="<?php echo esc_url( add_query_arg( 'skip_install_sensei_pages', 'true', admin_url( 'admin.php?page=sensei-settings' ) ) ); ?>">

					<?php esc_html_e( 'Skip setup', 'sensei-lms' ); ?>

				</a>

			</p>
		</div>
		<?php
	} // End admin_install_notice()


	/**
	 * admin_installed_notice function.
	 *
	 * @access public
	 * @return void
	 */
	function admin_installed_notice() {
		?>
		<div id="message" class="updated sensei-message sensei-connect">

			<p>
				<?php echo wp_kses_post( __( '<strong>Sensei LMS has been installed</strong> &#8211; You\'re ready to start creating courses!', 'sensei-lms' ) ); ?>
			</p>

			<p class="submit">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=sensei-settings' ) ); ?>" class="button-primary"><?php esc_html_e( 'Settings', 'sensei-lms' ); ?></a> <a class="docs button" href="http://www.woothemes.com/sensei-docs/">
					<?php esc_html_e( 'Documentation', 'sensei-lms' ); ?>
				</a>
			</p>

			<p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=course' ) ); ?>" class="button-primary">
					<?php esc_html_e( 'Create a Course', 'sensei-lms' ); ?>
				</a>
			</p>

		</div>
		<?php

		// Set installed option
		update_option( 'sensei_installed', 0 );
	} // End admin_installed_notice()

	/**
	 * admin_notices_styles function.
	 *
	 * @access public
	 * @return void
	 */
	function admin_notices_styles() {

		// Installed notices
		if ( 1 == get_option( 'sensei_installed' ) ) {

			wp_enqueue_style( 'sensei-activation', plugins_url( '/assets/css/activation.css', dirname( __FILE__ ) ), '', Sensei()->version );

			if ( get_option( 'skip_install_sensei_pages' ) != 1 && Sensei()->get_page_id( 'course' ) < 1 && ! isset( $_GET['install_sensei_pages'] ) && ! isset( $_GET['skip_install_sensei_pages'] ) ) {
				add_action( 'admin_notices', array( $this, 'admin_install_notice' ) );
			} elseif ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'sensei-settings' ) {
				add_action( 'admin_notices', array( $this, 'admin_installed_notice' ) );
			} // End If Statement
		} // End If Statement

	} // End admin_notices_styles()

	/**
	 * Add links for duplicating lessons & courses
	 *
	 * @param  array  $actions Default actions
	 * @param  object $post    Current post
	 * @return array           Modified actions
	 */
	public function duplicate_action_link( $actions, $post ) {
		switch ( $post->post_type ) {
			case 'lesson':
				$confirm              = __( 'This will duplicate the lesson quiz and all of its questions. Are you sure you want to do this?', 'sensei-lms' );
				$actions['duplicate'] = "<a onclick='return confirm(\"" . $confirm . "\");' href='" . $this->get_duplicate_link( $post->ID ) . "' title='" . esc_attr( __( 'Duplicate this lesson', 'sensei-lms' ) ) . "'>" . __( 'Duplicate', 'sensei-lms' ) . '</a>';
				break;

			case 'course':
				$confirm                           = __( 'This will duplicate the course lessons along with all of their quizzes and questions. Are you sure you want to do this?', 'sensei-lms' );
				$actions['duplicate']              = '<a href="' . $this->get_duplicate_link( $post->ID ) . '" title="' . esc_attr( __( 'Duplicate this course', 'sensei-lms' ) ) . '">' . __( 'Duplicate', 'sensei-lms' ) . '</a>';
				$actions['duplicate_with_lessons'] = '<a onclick="return confirm(\'' . $confirm . '\');" href="' . $this->get_duplicate_link( $post->ID, true ) . '" title="' . esc_attr( __( 'Duplicate this course with its lessons', 'sensei-lms' ) ) . '">' . __( 'Duplicate (with lessons)', 'sensei-lms' ) . '</a>';
				break;
		}

		return $actions;
	}

	/**
	 * Generate duplicationlink
	 *
	 * @param  integer $post_id      Post ID
	 * @param  boolean $with_lessons Include lessons or not
	 * @return string                Duplication link
	 */
	private function get_duplicate_link( $post_id = 0, $with_lessons = false ) {

		$post = get_post( $post_id );

		$action = 'duplicate_' . $post->post_type;

		if ( 'course' == $post->post_type && $with_lessons ) {
			$action .= '_with_lessons';
		}

		$bare_url = admin_url( 'admin.php?action=' . $action . '&post=' . $post_id );
		$url      = wp_nonce_url( $bare_url, $action . '_' . $post_id );

		return apply_filters( $action . '_link', $url, $post_id );
	}

	/**
	 * Duplicate lesson
	 *
	 * @return void
	 */
	public function duplicate_lesson_action() {
		$this->duplicate_content( 'lesson' );
	}

	/**
	 * Duplicate course
	 *
	 * @return void
	 */
	public function duplicate_course_action() {
		$this->duplicate_content( 'course' );
	}

	/**
	 * Duplicate course with lessons.
	 *
	 * @return void
	 */
	public function duplicate_course_with_lessons_action() {
		$this->duplicate_content( 'course', true );
	}

	/**
	 * Redirect the user safely.
	 *
	 * @access private
	 * @param  string $redirect_url URL to redirect the user.
	 * @return void
	 */
	protected function safe_redirect( $redirect_url ) {
		wp_safe_redirect( esc_url_raw( $redirect_url ) );
		exit;
	}

	/**
	 * Duplicate content.
	 *
	 * @param  string  $post_type    Post type being duplicated.
	 * @param  boolean $with_lessons Include lessons or not.
	 * @return void
	 */
	private function duplicate_content( $post_type = 'lesson', $with_lessons = false ) {
		if ( ! isset( $_GET['post'] ) ) {
			// translators: Placeholder is the post type string.
			wp_die( esc_html( sprintf( __( 'Please supply a %1$s ID.', 'sensei-lms' ) ), $post_type ) );
		}

		$post_id = $_GET['post'];
		$post    = get_post( $post_id );
		if ( ! in_array( get_post_type( $post_id ), array( 'lesson', 'course' ), true ) ) {
			wp_die( esc_html__( 'Invalid post type. Can duplicate only lessons and courses', 'sensei-lms' ) );
		}

		$event = false;
		if ( 'course' === $post_type ) {
			$event = 'course_duplicate';
		} elseif ( 'lesson' === $post_type ) {
			$event = 'lesson_duplicate';
		}

		$event_properties = [
			$post_type . '_id' => $post_id,
		];

		$action = 'duplicate_' . $post_type;
		if ( $with_lessons ) {
			$action .= '_with_lessons';
		}
		check_admin_referer( $action . '_' . $post_id );
		if ( ! current_user_can( 'manage_sensei_grades' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'sensei-lms' ) );
		}

		if ( ! is_wp_error( $post ) ) {

			$new_post = $this->duplicate_post( $post );

			if ( $new_post && ! is_wp_error( $new_post ) ) {

				if ( 'lesson' == $new_post->post_type ) {
					$this->duplicate_lesson_quizzes( $post_id, $new_post->ID );
				}

				if ( 'course' == $new_post->post_type && $with_lessons ) {
					$event                            = 'course_duplicate_with_lessons';
					$event_properties['lesson_count'] = $this->duplicate_course_lessons( $post_id, $new_post->ID );
				}

				$redirect_url = admin_url( 'post.php?post=' . $new_post->ID . '&action=edit' );
			} else {
				$redirect_url = admin_url( 'edit.php?post_type=' . $post->post_type . '&message=duplicate_failed' );
			}

			// Log event.
			if ( $event ) {
				sensei_log_event( $event, $event_properties );
			}

			$this->safe_redirect( $redirect_url );
		}
	}

	/**
	 * Duplicate quizzes inside lessons.
	 *
	 * @param  integer $old_lesson_id ID of original lesson.
	 * @param  integer $new_lesson_id ID of duplicate lesson.
	 * @return void
	 */
	private function duplicate_lesson_quizzes( $old_lesson_id, $new_lesson_id ) {
		$old_quiz_id = Sensei()->lesson->lesson_quizzes( $old_lesson_id );

		if ( empty( $old_quiz_id ) ) {
			return;
		}

		$old_quiz_questions = Sensei()->lesson->lesson_quiz_questions( $old_quiz_id );

		// duplicate the generic wp post information
		$new_quiz = $this->duplicate_post( get_post( $old_quiz_id ), '' );

		// update the new lesson data
		add_post_meta( $new_lesson_id, '_lesson_quiz', $new_quiz->ID );

		// update the new quiz data
		add_post_meta( $new_quiz->ID, '_quiz_lesson', $new_lesson_id );
		wp_update_post(
			array(
				'ID'          => $new_quiz->ID,
				'post_parent' => $new_lesson_id,
			)
		);

		foreach ( $old_quiz_questions as $question ) {

			// copy the question order over to the new quiz
			$old_question_order = get_post_meta( $question->ID, '_quiz_question_order' . $old_quiz_id, true );
			$new_question_order = str_ireplace( $old_quiz_id, $new_quiz->ID, $old_question_order );
			add_post_meta( $question->ID, '_quiz_question_order' . $new_quiz->ID, $new_question_order );

			// Add question to quiz
			add_post_meta( $question->ID, '_quiz_id', $new_quiz->ID, false );

		}
	}

	/**
	 * Update prerequisite ids after course duplication.
	 *
	 * @param  array $lessons_to_update    List with lesson_id and old_prerequisite_id id to update.
	 * @param  array $new_lesson_id_lookup History with the id before and after duplication.
	 * @return void
	 */
	private function update_lesson_prerequisite_ids( $lessons_to_update, $new_lesson_id_lookup ) {
		foreach ( $lessons_to_update as $lesson_to_update ) {
			$old_prerequisite_id = $lesson_to_update['old_prerequisite_id'];
			$new_prerequisite_id = $new_lesson_id_lookup[ $old_prerequisite_id ];
			add_post_meta( $lesson_to_update['lesson_id'], '_lesson_prerequisite', $new_prerequisite_id );
		}
	}

	/**
	 * Get an prerequisite update object.
	 *
	 * @param  integer $old_lesson_id ID of the lesson before the duplication.
	 * @param  integer $new_lesson_id New ID of the lesson.
	 * @return array                  Object with the id of the lesson to update and its old prerequisite id.
	 */
	private function get_prerequisite_update_object( $old_lesson_id, $new_lesson_id ) {
		$lesson_prerequisite = get_post_meta( $old_lesson_id, '_lesson_prerequisite', true );

		if ( ! empty( $lesson_prerequisite ) ) {
			return array(
				'lesson_id'           => $new_lesson_id,
				'old_prerequisite_id' => $lesson_prerequisite,
			);
		}

		return null;
	}

	/**
	 * Duplicate lessons inside a course.
	 *
	 * @param  integer $old_course_id ID of original course.
	 * @param  integer $new_course_id ID of duplicated course.
	 * @return int Number of lessons duplicated.
	 */
	private function duplicate_course_lessons( $old_course_id, $new_course_id ) {
		$lessons              = Sensei()->course->course_lessons( $old_course_id );
		$new_lesson_id_lookup = array();
		$lessons_to_update    = array();

		foreach ( $lessons as $lesson ) {
			$new_lesson = $this->duplicate_post( $lesson, '', true );
			add_post_meta( $new_lesson->ID, '_lesson_course', $new_course_id );

			$update_prerequisite_object = $this->get_prerequisite_update_object( $lesson->ID, $new_lesson->ID );

			if ( ! is_null( $update_prerequisite_object ) ) {
				$lessons_to_update[] = $update_prerequisite_object;
			}

			$new_lesson_id_lookup[ $lesson->ID ] = $new_lesson->ID;
			$this->duplicate_lesson_quizzes( $lesson->ID, $new_lesson->ID );
		}

		$this->update_lesson_prerequisite_ids( $lessons_to_update, $new_lesson_id_lookup );

		return count( $lessons );
	}

	/**
	 * Duplicate post.
	 *
	 * @param  object  $post          Post to be duplicated.
	 * @param  string  $suffix        Suffix for duplicated post title.
	 * @param  boolean $ignore_course Ignore lesson course when dulicating.
	 * @return object                 Duplicate post object.
	 */
	private function duplicate_post( $post, $suffix = null, $ignore_course = false ) {

		$new_post = array();

		foreach ( $post as $k => $v ) {
			if ( ! in_array( $k, array( 'ID', 'post_status', 'post_date', 'post_date_gmt', 'post_name', 'post_modified', 'post_modified_gmt', 'guid', 'comment_count' ) ) ) {
				$new_post[ $k ] = $v;
			}
		}

		$new_post['post_title']       .= empty( $suffix ) ? __( '(Duplicate)', 'sensei-lms' ) : $suffix;
		$new_post['post_date']         = current_time( 'mysql' );
		$new_post['post_date_gmt']     = get_gmt_from_date( $new_post['post_date'] );
		$new_post['post_modified']     = $new_post['post_date'];
		$new_post['post_modified_gmt'] = $new_post['post_date_gmt'];

		switch ( $post->post_type ) {
			case 'course':
				$new_post['post_status'] = 'draft';
				break;
			case 'lesson':
				$new_post['post_status'] = 'draft';
				break;
			case 'quiz':
				$new_post['post_status'] = 'publish';
				break;
			case 'question':
				$new_post['post_status'] = 'publish';
				break;
		}

		// As per wp_update_post() we need to escape the data from the db.
		$new_post = wp_slash( $new_post );

		$new_post_id = wp_insert_post( $new_post );

		if ( ! is_wp_error( $new_post_id ) ) {

			$post_meta = get_post_custom( $post->ID );
			if ( $post_meta && count( $post_meta ) > 0 ) {

				$ignore_meta = array( '_quiz_lesson', '_quiz_id', '_lesson_quiz', '_lesson_prerequisite' );

				if ( $ignore_course ) {
					$ignore_meta[] = '_lesson_course';
				}

				foreach ( $post_meta as $key => $meta ) {
					foreach ( $meta as $value ) {
						$value = maybe_unserialize( $value );
						if ( ! in_array( $key, $ignore_meta ) ) {
							add_post_meta( $new_post_id, $key, $value );
						}
					}
				}
			}

			add_post_meta( $new_post_id, '_duplicate', $post->ID );

			$taxonomies = get_object_taxonomies( $post->post_type, 'objects' );

			foreach ( $taxonomies as $slug => $tax ) {
				$terms = get_the_terms( $post->ID, $slug );
				if ( isset( $terms ) && is_array( $terms ) && 0 < count( $terms ) ) {
					foreach ( $terms as $term ) {
						wp_set_object_terms( $new_post_id, $term->term_id, $slug, true );
					}
				}
			}

			$new_post = get_post( $new_post_id );

			return $new_post;
		}

		return false;
	}

	/**
	 * Add options to filter lessons
	 *
	 * @return void
	 */
	public function lesson_filter_options() {
		global $typenow;

		if ( is_admin() && 'lesson' == $typenow ) {

			$args    = array(
				'post_type'        => 'course',
				'post_status'      => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'posts_per_page'   => -1,
				'suppress_filters' => 0,
				'orderby'          => 'menu_order date',
				'order'            => 'ASC',
			);
			$courses = get_posts( $args );

			$selected       = isset( $_GET['lesson_course'] ) ? $_GET['lesson_course'] : '';
			$course_options = '';
			foreach ( $courses as $course ) {
				$course_options .= '<option value="' . esc_attr( $course->ID ) . '" ' . selected( $selected, $course->ID, false ) . '>' . esc_html( get_the_title( $course->ID ) ) . '</option>';
			}

			$output  = '<select name="lesson_course" id="dropdown_lesson_course">';
			$output .= '<option value="">' . esc_html__( 'Show all courses', 'sensei-lms' ) . '</option>';
			$output .= $course_options;
			$output .= '</select>';

			$allowed_html = array(
				'option' => array(
					'selected' => array(),
					'value'    => array(),
				),
				'select' => array(
					'id'   => array(),
					'name' => array(),
				),
			);

			echo wp_kses( $output, $allowed_html );
		}
	}

	/**
	 * Filter lessons
	 *
	 * @param  array $request Current request
	 * @return array          Modified request
	 */
	public function lesson_filter_actions( $request ) {
		global $typenow;

		if ( is_admin() && 'lesson' == $typenow ) {
			$lesson_course = isset( $_GET['lesson_course'] ) ? $_GET['lesson_course'] : '';

			if ( $lesson_course ) {
				$request['meta_key']     = '_lesson_course';
				$request['meta_value']   = $lesson_course;
				$request['meta_compare'] = '=';
			}
		}

		return $request;
	}

	/**
	 * Adding Sensei items to 'At a glance' dashboard widget
	 *
	 * @param  array $items Existing items
	 * @return array        Updated items
	 */
	public function glance_items( $items = array() ) {

		$types = array( 'course', 'lesson', 'question' );

		foreach ( $types as $type ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}

			$num_posts = wp_count_posts( $type );

			if ( $num_posts ) {

				$published = intval( $num_posts->publish );
				$post_type = get_post_type_object( $type );

				$text = '%s ' . $post_type->labels->singular_name;
				$text = sprintf( $text, number_format_i18n( $published ) );

				if ( current_user_can( $post_type->cap->edit_posts ) ) {
					$items[] = sprintf( '<a class="%1$s-count" href="edit.php?post_type=%1$s">%2$s</a>', $type, $text ) . "\n";
				} else {
					$items[] = sprintf( '<span class="%1$s-count">%2$s</span>', $type, $text ) . "\n";
				}
			}
		}

		return $items;
	}

	/**
	 * Process lesson and course deletion
	 *
	 * @param  integer $post_id Post ID
	 * @param  object  $post    Post object
	 * @return void
	 */
	public function delete_content( $post_id, $post ) {

		$type = $post->post_type;

		if ( in_array( $type, array( 'lesson', 'course' ) ) ) {

			$meta_key = '_' . $type . '_prerequisite';

			$args = array(
				'post_type'      => $type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => $meta_key,
				'meta_value'     => $post_id,
			);

			$posts = get_posts( $args );

			foreach ( $posts as $post ) {
				delete_post_meta( $post->ID, $meta_key );
			}
		}
	}

	/**
	 * Delete all user activity when user is deleted
	 *
	 * @param  integer $user_id User ID
	 * @return void
	 */
	public function delete_user_activity( $user_id = 0 ) {
		if ( $user_id ) {
			Sensei_Utils::delete_all_user_activity( $user_id );
		}
	}

	public function render_settings( $settings = array(), $post_id = 0, $group_id = '' ) {

		$html = '';

		if ( 0 == count( $settings ) ) {
			return $html;
		}

		$html .= '<div class="sensei-options-panel">' . "\n";

			$html .= '<div class="options_group" id="' . esc_attr( $group_id ) . '">' . "\n";

		foreach ( $settings as $field ) {

			$data = '';

			if ( $post_id ) {
				if ( 'plain-text' !== $field['type'] ) {
					$data = get_post_meta( $post_id, '_' . $field['id'], true );
					if ( ! $data && isset( $field['default'] ) ) {
						$data = $field['default'];
					}
				} else {
					$data = $field['default'];
				}
			} else {
				$option = get_option( $field['id'] );
				if ( isset( $field['default'] ) ) {
					$data = $field['default'];
					if ( $option ) {
						$data = $option;
					}
				}
			}

			if ( ! isset( $field['disabled'] ) ) {
				$field['disabled'] = false;
			}

			if ( 'hidden' != $field['type'] ) {

				$class_tail = '';

				if ( isset( $field['class'] ) ) {
					$class_tail .= $field['class'];
				}

				if ( isset( $field['disabled'] ) && $field['disabled'] ) {
					$class_tail .= ' disabled';
				}

				$html .= '<p class="form-field ' . esc_attr( $field['id'] ) . ' ' . esc_attr( $class_tail ) . '">' . "\n";
			}

			if ( ! in_array( $field['type'], array( 'hidden', 'checkbox_multi', 'radio' ) ) ) {
					$html .= '<label for="' . esc_attr( $field['id'] ) . '">' . "\n";
			}

			if ( $field['label'] ) {
				$html .= '<span class="label">' . esc_html( $field['label'] ) . '</span>';
			}

			switch ( $field['type'] ) {
				case 'plain-text':
					$html .= '<strong>' . esc_html( $data ) . '</strong>';
					break;
				case 'text':
				case 'password':
					$html .= '<input id="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'type="' . esc_attr( $field['type'] ) . '" ';
					$html .= 'name="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'placeholder="' . esc_attr( $field['placeholder'] ) . '" ';
					$html .= 'value="' . esc_attr( $data ) . '" ';
					$html .= disabled( $field['disabled'], true, false );
					$html .= ' />' . "\n";
					break;

				case 'number':
					$min = '';
					if ( isset( $field['min'] ) ) {
						$min = 'min="' . esc_attr( $field['min'] ) . '"';
					}

					$max = '';
					if ( isset( $field['max'] ) ) {
						$max = 'max="' . esc_attr( $field['max'] ) . '"';
					}

					$html .= '<input id="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'type="' . esc_attr( $field['type'] ) . '" ';
					$html .= 'name="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'placeholder="' . esc_attr( $field['placeholder'] ) . '" ';
					$html .= 'value="' . esc_attr( $data ) . '" ';
					$html .= $min . '  ' . $max . ' '; // $min and $max are escaped above, for better readibility
					$html .= 'class="small-text" ';
					$html .= disabled( $field['disabled'], true, false );
					$html .= ' />' . "\n";
					break;

				case 'textarea':
					$html .= '<textarea id="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'rows="5" cols="50" ';
					$html .= 'name="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'placeholder="' . esc_attr( $field['placeholder'] ) . '" ';
					$html .= disabled( $field['disabled'], true, false );
					$html .= '>' . strip_tags( $data ) . '</textarea><br/>' . "\n";
					break;

				case 'checkbox':
					// backwards compatibility
					if ( empty( $data ) || 'on' == $data ) {
						$checked_value = 'on';
					} elseif ( 'yes' == $data ) {

						$checked_value = 'yes';

					} elseif ( 'auto' == $data ) {

						$checked_value = 'auto';

					} else {
						$checked_value = 1;
						$data          = intval( $data );
					}

					$html .= '<input id="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'type="' . esc_attr( $field['type'] ) . '" ';
					$html .= 'name="' . esc_attr( $field['id'] ) . '" ';
					$html .= checked( $checked_value, $data, false );
					$html .= disabled( $field['disabled'], true, false );
					$html .= " /> \n";

					// Input hidden to identify if checkbox is present.
					$html .= '<input type="hidden" ';
					$html .= 'name="contains_' . esc_attr( $field['id'] ) . '" ';
					$html .= 'value="1" ';
					$html .= " /> \n";
					break;

				case 'checkbox_multi':
					foreach ( $field['options'] as $k => $v ) {
						$checked = false;
						if ( in_array( $k, $data ) ) {
							$checked = true;
						}

						$html     .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '">';
							$html .= '<input type="checkbox" ';
							$html .= checked( $checked, true, false ) . ' ';
							$html .= 'name="' . esc_attr( $field['id'] ) . '[]" ';
							$html .= 'value="' . esc_attr( $k ) . '" ';
							$html .= 'id="' . esc_attr( $field['id'] . '_' . $k ) . '" ';
							$html .= disabled( $field['disabled'], true, false );
							$html .= ' /> ' . esc_html( $v );
						$html     .= "</label> \n";
					}
					break;

				case 'radio':
					foreach ( $field['options'] as $k => $v ) {
						$checked = false;
						if ( $k == $data ) {
							$checked = true;
						}

						$html     .= '<label for="' . esc_attr( $field['id'] . '_' . $k ) . '">';
							$html .= '<input type="radio" ';
							$html .= checked( $checked, true, false ) . ' ';
							$html .= 'name="' . esc_attr( $field['id'] ) . '" ';
							$html .= 'value="' . esc_attr( $k ) . '" ';
							$html .= 'id="' . esc_attr( $field['id'] . '_' . $k ) . '" ';
							$html .= disabled( $field['disabled'], true, false );
							$html .= ' /> ' . esc_html( $v );
						$html     .= "</label> \n";
					}
					break;

				case 'select':
					$html .= '<select name="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'id="' . esc_attr( $field['id'] ) . '" ';
					$html .= disabled( $field['disabled'], true, false );
					$html .= ">\n";

					foreach ( $field['options'] as $k => $v ) {
						$selected = false;
						if ( $k == $data ) {
							$selected = true;
						}

						$html .= '<option ' . selected( $selected, true, false ) . ' ';
						$html .= 'value="' . esc_attr( $k ) . '">' . esc_html( $v ) . "</option>\n";
					}

					$html .= "</select><br/>\n";
					break;

				case 'select_multi':
					$html .= '<select name="' . esc_attr( $field['id'] ) . '[]" ';
					$html .= 'id="' . esc_attr( $field['id'] ) . '" multiple="multiple" ';
					$html .= disabled( $field['disabled'], true, false );
					$html .= ">\n";

					foreach ( $field['options'] as $k => $v ) {
						$selected = false;
						if ( in_array( $k, $data ) ) {
							$selected = true;
						}
						$html .= '<option ' . selected( $selected, true, false ) . ' ';
						$html .= 'value="' . esc_attr( $k ) . '" />' . esc_html( $v ) . "</option>\n";
					}

					$html .= "</select>\n";
					break;

				case 'hidden':
					$html .= '<input id="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'type="' . esc_attr( $field['type'] ) . '" ';
					$html .= 'name="' . esc_attr( $field['id'] ) . '" ';
					$html .= 'value="' . esc_attr( $data ) . '" ';
					$html .= disabled( $field['disabled'], true, false );
					$html .= "/>\n";
					break;

			}

			if ( $field['description'] ) {
				$html .= ' <span class="description">' . esc_html( $field['description'] ) . '</span>' . "\n";
			}

			if ( ! in_array( $field['type'], array( 'hidden', 'checkbox_multi', 'radio' ) ) ) {
						$html .= '</label>' . "\n";
			}

			if ( 'hidden' != $field['type'] ) {
				$html .= "</p>\n";
			}
		}

			$html .= "</div>\n";
		$html     .= "</div>\n";

		return $html;
	}

	/**
	 * Handle the POST request for reordering the Courses.
	 *
	 * @since 1.12.2
	 */
	public function handle_order_courses() {
		check_admin_referer( 'order_courses' );

		if ( isset( $_POST['course-order'] ) && 0 < strlen( $_POST['course-order'] ) ) {
			$ordered = $this->save_course_order( esc_attr( $_POST['course-order'] ) );
		}

		wp_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'post_type' => 'course',
						'page'      => $this->course_order_page_slug,
						'ordered'   => $ordered,
					),
					admin_url( 'edit.php' )
				)
			)
		);
	}

	/**
	 * Dsplay Course Order screen
	 *
	 * @return void
	 */
	public function course_order_screen() {

		$should_update_order = false;
		$new_course_order    = array();

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		?>
		<div id="<?php echo esc_attr( $this->course_order_page_slug ); ?>" class="wrap <?php echo esc_attr( $this->course_order_page_slug ); ?>">
		<h1><?php esc_html_e( 'Order Courses', 'sensei-lms' ); ?></h1>
							  <?php

								$html = '';

								if ( isset( $_GET['ordered'] ) && $_GET['ordered'] ) {
									$html .= '<div class="updated fade">' . "\n";
									$html .= '<p>' . esc_html__( 'The course order has been saved.', 'sensei-lms' ) . '</p>' . "\n";
									$html .= '</div>' . "\n";
								}

								$courses = Sensei()->course->get_all_courses();

								if ( 0 < count( $courses ) ) {

									// order the courses as set by the users
									$all_course_ids = array();
									foreach ( $courses as $course ) {

										$all_course_ids[] = (string) $course->ID;

									}
									$order_string = $this->get_course_order();

									if ( ! empty( $order_string ) ) {
										$ordered_course_ids = explode( ',', $order_string );
										$all_course_ids     = array_unique( array_merge( $ordered_course_ids, $all_course_ids ) );
									}

									$html .= '<form id="editgrouping" method="post" action="'
										. esc_url( admin_url( 'admin-post.php' ) ) . '" class="validate">' . "\n";
									$html .= '<ul class="sortable-course-list">' . "\n";
									$count = 0;
									foreach ( $all_course_ids as $course_id ) {
										$course = get_post( $course_id );
										if ( empty( $course ) || in_array( $course->post_status, array( 'trash', 'auto-draft' ), true ) ) {
											$should_update_order = true;
											continue;
										}
										$new_course_order[] = $course_id;
										$count++;
										$class = 'course';
										if ( $count == 1 ) {
											$class .= ' first'; }
										if ( $count == count( $all_course_ids ) ) {
											$class .= ' last'; }
										if ( $count % 2 != 0 ) {
											$class .= ' alternate';
										}

										$title = $course->post_title;
										if ( $course->post_status === 'draft' ) {
											$title .= ' (Draft)';
										}

										$html .= '<li class="' . esc_attr( $class ) . '"><span rel="' . esc_attr( $course->ID ) . '" style="width: 100%;"> ' . esc_html( $title ) . '</span></li>' . "\n";
									}
									$html .= '</ul>' . "\n";

									$html .= '<input type="hidden" name="action" value="order_courses" />' . "\n";
									$html .= wp_nonce_field( 'order_courses', '_wpnonce', true, false ) . "\n";
									$html .= '<input type="hidden" name="course-order" value="' . esc_attr( $order_string ) . '" />' . "\n";
									$html .= '<input type="submit" class="button-primary" value="' . esc_attr__( 'Save course order', 'sensei-lms' ) . '" />' . "\n";
									$html .= '</form>';
								}

								echo wp_kses(
									$html,
									array_merge(
										wp_kses_allowed_html( 'post' ),
										array(
											// Explicitly allow form tag for WP.com.
											'form'  => array(
												'action' => array(),
												'class'  => array(),
												'id'     => array(),
												'method' => array(),
											),
											'input' => array(
												'class' => array(),
												'name'  => array(),
												'type'  => array(),
												'value' => array(),
											),
											'span'  => array(
												'rel'   => array(),
												'style' => array(),
											),
										)
									)
								);

								?>
		</div>
		<?php

		if ( $should_update_order ) {
			$this->save_course_order( implode( ',', $new_course_order ) );
		}
	}

	public function get_course_order() {
		return get_option( 'sensei_course_order', '' );
	}

	public function save_course_order( $order_string = '' ) {
		$order = array();

		$i = 1;
		foreach ( explode( ',', $order_string ) as $course_id ) {
			if ( $course_id ) {
				$order[]     = $course_id;
				$update_args = array(
					'ID'         => absint( $course_id ),
					'menu_order' => $i,
				);

				wp_update_post( $update_args );

				++$i;
			}
		}

		update_option( 'sensei_course_order', implode( ',', $order ) );

		return true;
	}

	/**
	 * Handle the POST request for reordering the Lessons.
	 *
	 * @since 1.12.2
	 */
	public function handle_order_lessons() {
		check_admin_referer( 'order_lessons' );

		if ( isset( $_POST['lesson-order'] ) ) {
			$ordered = $this->save_lesson_order( esc_attr( $_POST['lesson-order'] ), esc_attr( $_POST['course_id'] ) );
		}

		wp_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'post_type' => 'lesson',
						'page'      => $this->lesson_order_page_slug,
						'ordered'   => $ordered,
						'course_id' => $_POST['course_id'],
					),
					admin_url( 'edit.php' )
				)
			)
		);
	}

	/**
	 * Dsplay Lesson Order screen
	 *
	 * @return void
	 */
	public function lesson_order_screen() {

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		?>
		<div id="<?php echo esc_attr( $this->lesson_order_page_slug ); ?>" class="wrap <?php echo esc_attr( $this->lesson_order_page_slug ); ?>">
		<h1><?php esc_html_e( 'Order Lessons', 'sensei-lms' ); ?></h1>
							  <?php

								$html = '';

								if ( isset( $_GET['ordered'] ) && $_GET['ordered'] ) {
									$html .= '<div class="updated fade">' . "\n";
									$html .= '<p>' . esc_html__( 'The lesson order has been saved.', 'sensei-lms' ) . '</p>' . "\n";
									$html .= '</div>' . "\n";
								}

								$args = array(
									'post_type'      => 'course',
									'post_status'    => array( 'publish', 'draft', 'future', 'private' ),
									'posts_per_page' => -1,
									'orderby'        => 'name',
									'order'          => 'ASC',
								);
																			$courses = get_posts( $args );

																			$html .= '<form action="' . esc_url( admin_url( 'edit.php' ) ) . '" method="get">' . "\n";
																			$html .= '<input type="hidden" name="post_type" value="lesson" />' . "\n";
																			$html .= '<input type="hidden" name="page" value="lesson-order" />' . "\n";
																			$html .= '<select id="lesson-order-course" name="course_id">' . "\n";
																			$html .= '<option value="">' . esc_html__( 'Select a course', 'sensei-lms' ) . '</option>' . "\n";

								foreach ( $courses as $course ) {
									$course_id = '';
									if ( isset( $_GET['course_id'] ) ) {
										$course_id = intval( $_GET['course_id'] );
									}
									$html .= '<option value="' . esc_attr( intval( $course->ID ) ) . '" ' . selected( $course->ID, $course_id, false ) . '>' . esc_html( get_the_title( $course->ID ) ) . '</option>' . "\n";
								}

																			$html .= '</select>' . "\n";
																			$html .= '<input type="submit" class="button-primary lesson-order-select-course-submit" value="' . esc_attr__( 'Select', 'sensei-lms' ) . '" />' . "\n";
																			$html .= '</form>' . "\n";

								if ( isset( $_GET['course_id'] ) ) {
									$course_id = intval( $_GET['course_id'] );
									if ( $course_id > 0 ) {

										$order_string = $this->get_lesson_order( $course_id );

										$html .= '<form id="editgrouping" method="post" action="'
											. esc_url( admin_url( 'admin-post.php' ) ) . '" class="validate">' . "\n";

										$displayed_lessons = array();

										$modules = Sensei()->modules->get_course_modules( intval( $course_id ) );

										foreach ( $modules as $module ) {

											$args = array(
												'post_type' => 'lesson',
												'post_status' => array( 'publish', 'draft', 'future', 'private' ),
												'posts_per_page' => -1,
												'meta_query' => array(
													array(
														'key'     => '_lesson_course',
														'value'   => intval( $course_id ),
														'compare' => '=',
													),
												),
												'tax_query' => array(
													array(
														'taxonomy' => Sensei()->modules->taxonomy,
														'field'    => 'id',
														'terms'    => intval( $module->term_id ),
													),
												),
												'meta_key' => '_order_module_' . $module->term_id,
												'orderby'  => 'meta_value_num date',
												'order'    => 'ASC',
												'suppress_filters' => 0,
											);

											$lessons = get_posts( $args );

											if ( count( $lessons ) > 0 ) {
												$html .= '<h3>' . esc_html( $module->name ) . '</h3>' . "\n";
												$html .= '<ul class="sortable-lesson-list" data-module-id="' . esc_attr( $module->term_id ) . '">' . "\n";

												$count = 0;
												foreach ( $lessons as $lesson ) {
													$count++;
													$class = 'lesson';
													if ( $count == 1 ) {
														$class .= ' first'; }
													if ( $count == count( $lessons ) ) {
														$class .= ' last'; }
													if ( $count % 2 != 0 ) {
														$class .= ' alternate';
													}

													$html .= '<li class="' . esc_attr( $class ) . '"><span rel="' . esc_attr( $lesson->ID ) . '" style="width: 100%;"> ' . esc_html( $lesson->post_title ) . '</span></li>' . "\n";

													$displayed_lessons[] = $lesson->ID;
												}

												$html .= '</ul>' . "\n";

												$html .= '<input type="hidden" name="lesson-order-module-' . esc_attr( $module->term_id ) . '" value="" />' . "\n";
											}
										}

										// Other Lessons
										$lessons = Sensei()->course->course_lessons( $course_id, array( 'publish', 'draft', 'future', 'private' ) );

										if ( 0 < count( $lessons ) ) {

											// get module term ids, will be used to exclude lessons
											$module_items_ids = array();
											if ( ! empty( $modules ) ) {
												foreach ( $modules as $module ) {
													$module_items_ids[] = $module->term_id;
												}
											}

											if ( 0 < count( $displayed_lessons ) ) {
												$html .= '<h3>' . esc_html__( 'Other Lessons', 'sensei-lms' ) . '</h3>' . "\n";
											}

											$html         .= '<ul class="sortable-lesson-list" data-module-id="0">' . "\n";
											$count         = 0;
											$other_lessons = array();

											foreach ( $lessons as $lesson ) {
												// Exclude course modules.
												if ( has_term( $module_items_ids, 'module', $lesson->ID ) ) {
													continue;
												}

												$other_lessons[] = $lesson;
											}

											foreach ( $other_lessons as $other_lesson ) {
												$count++;
												$class = 'lesson';

												if ( $count == 1 ) {
													$class .= ' first'; }
												if ( $count === count( $other_lessons ) ) {
													$class .= ' last'; }
												if ( $count % 2 != 0 ) {

													$class .= ' alternate';

												}
												$html .= '<li class="' . esc_attr( $class ) . '"><span rel="' . esc_attr( $other_lesson->ID ) . '" style="width: 100%;"> ' . esc_html( $other_lesson->post_title ) . '</span></li>' . "\n";

												$displayed_lessons[] = $other_lesson->ID;
											}
											$html .= '</ul>' . "\n";
										} else {
											if ( 0 == count( $displayed_lessons ) ) {
												$html .= '<p><em>' . esc_html__( 'There are no lessons in this course.', 'sensei-lms' ) . '</em></p>';
											}
										}

										if ( 0 < count( $displayed_lessons ) ) {
											$html .= '<input type="hidden" name="action" value="order_lessons" />' . "\n";
											$html .= wp_nonce_field( 'order_lessons', '_wpnonce', true, false ) . "\n";
											$html .= '<input type="hidden" name="lesson-order" value="' . esc_attr( $order_string ) . '" />' . "\n";
											$html .= '<input type="hidden" name="course_id" value="' . esc_attr( $course_id ) . '" />' . "\n";
											$html .= '<input type="submit" class="button-primary" value="' . esc_attr__( 'Save lesson order', 'sensei-lms' ) . '" />' . "\n";
											$html .= '</form>';
										}
									}
								}

								echo wp_kses(
									$html,
									array_merge(
										wp_kses_allowed_html( 'post' ),
										array(
											// Explicitly allow form tag for WP.com.
											'form'   => array(
												'action' => array(),
												'class'  => array(),
												'id'     => array(),
												'method' => array(),
											),
											'input'  => array(
												'class' => array(),
												'name'  => array(),
												'type'  => array(),
												'value' => array(),
											),
											'option' => array(
												'selected' => array(),
												'value'    => array(),
											),
											'select' => array(
												'id'   => array(),
												'name' => array(),
											),
											'span'   => array(
												'rel'   => array(),
												'style' => array(),
											),
											'ul'     => array(
												'class' => array(),
												'data-module-id' => array(),
											),
										)
									)
								);

								?>
		</div>
		<?php
	}

	public function get_lesson_order( $course_id = 0 ) {
		$order_string = get_post_meta( $course_id, '_lesson_order', true );
		return $order_string;
	}

	public function save_lesson_order( $order_string = '', $course_id = 0 ) {

		/**
		 * It is safe to ignore nonce validation here, because this is called
		 * from `handle_order_lessons` and the nonce validation is done there.
		 */

		if ( $course_id ) {

			$modules = Sensei()->modules->get_course_modules( intval( $course_id ) );

			foreach ( $modules as $module ) {

				// phpcs:ignore WordPress.Security.NonceVerification
				if ( isset( $_POST[ 'lesson-order-module-' . $module->term_id ] ) && $_POST[ 'lesson-order-module-' . $module->term_id ] ) {

					// phpcs:ignore WordPress.Security.NonceVerification
					$order = explode( ',', $_POST[ 'lesson-order-module-' . $module->term_id ] );
					$i     = 1;
					foreach ( $order as $lesson_id ) {

						if ( $lesson_id ) {
							update_post_meta( $lesson_id, '_order_module_' . $module->term_id, $i );
							++$i;
						}
					}// end for each order
				}// end if
			} // end for each modules

			if ( $order_string ) {
				update_post_meta( $course_id, '_lesson_order', $order_string );

				$order = explode( ',', $order_string );

				$i = 1;
				foreach ( $order as $lesson_id ) {
					if ( $lesson_id ) {
						update_post_meta( $lesson_id, '_order_' . $course_id, $i );
						++$i;
					}
				}
			}

			return true;
		}

		return false;
	}

	function sensei_add_custom_menu_items() {
		global $pagenow;

		if ( 'nav-menus.php' == $pagenow ) {
			add_meta_box( 'add-sensei-links', 'Sensei LMS', array( $this, 'wp_nav_menu_item_sensei_links_meta_box' ), 'nav-menus', 'side', 'low' );
		}
	}

	function wp_nav_menu_item_sensei_links_meta_box( $object ) {
		global $nav_menu_selected_id;

		$menu_items = array(
			'#senseicourses'        => __( 'Courses', 'sensei-lms' ),
			'#senseilessons'        => __( 'Lessons', 'sensei-lms' ),
			'#senseimycourses'      => __( 'My Courses', 'sensei-lms' ),
			'#senseilearnerprofile' => __( 'My Profile', 'sensei-lms' ),
			'#senseimymessages'     => __( 'My Messages', 'sensei-lms' ),
			'#senseiloginlogout'    => __( 'Login', 'sensei-lms' ) . '|' . __( 'Logout', 'sensei-lms' ),
		);

		$menu_items_obj = array();
		foreach ( $menu_items as $value => $title ) {
			$menu_items_obj[ $title ]                   = new stdClass();
			$menu_items_obj[ $title ]->object_id        = esc_attr( $value );
			$menu_items_obj[ $title ]->title            = esc_attr( $title );
			$menu_items_obj[ $title ]->url              = esc_attr( $value );
			$menu_items_obj[ $title ]->description      = 'description';
			$menu_items_obj[ $title ]->db_id            = 0;
			$menu_items_obj[ $title ]->object           = 'sensei';
			$menu_items_obj[ $title ]->menu_item_parent = 0;
			$menu_items_obj[ $title ]->type             = 'custom';
			$menu_items_obj[ $title ]->target           = '';
			$menu_items_obj[ $title ]->attr_title       = '';
			$menu_items_obj[ $title ]->classes          = array();
			$menu_items_obj[ $title ]->xfn              = '';
		}

		$walker = new Walker_Nav_Menu_Checklist( array() );
		?>

		<div id="sensei-links" class="senseidiv taxonomydiv">
			<div id="tabs-panel-sensei-links-all" class="tabs-panel tabs-panel-view-all tabs-panel-active">

				<ul id="sensei-linkschecklist" class="list:sensei-links categorychecklist form-no-clear">
					<?php echo walk_nav_menu_tree( array_map( 'wp_setup_nav_menu_item', $menu_items_obj ), 0, (object) array( 'walker' => $walker ) ); ?>
				</ul>

			</div>
			<p class="button-controls">
				<span class="add-to-menu">
					<input type="submit"<?php disabled( $nav_menu_selected_id, 0 ); ?> class="button-secondary submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu', 'sensei-lms' ); ?>" name="add-sensei-links-menu-item" id="submit-sensei-links" />
					<span class="spinner"></span>
				</span>
			</p>
		</div><!-- .senseidiv -->
		<?php
	}

	/**
	 * Adding admin notice if the current
	 * installed theme is not compatible
	 *
	 * @return void
	 */
	public function theme_compatibility_notices() {

		if ( isset( $_GET['sensei_hide_notice'] ) ) {
			switch ( esc_attr( $_GET['sensei_hide_notice'] ) ) {
				case 'menu_settings':
					add_user_meta( get_current_user_id(), 'sensei_hide_menu_settings_notice', true );
					break;
			}
		}
	}

	/**
	 * Hooked onto admin_init. Listens for install_sensei_pages and skip_install_sensei_pages query args
	 * on the sensei settings page.
	 *
	 * The function
	 *
	 * @since 1.8.7
	 */
	public static function install_pages() {

		// only fire on the settings page
		if ( ! isset( $_GET['page'] )
			|| 'sensei-settings' !== $_GET['page']
			|| 1 == get_option( 'skip_install_sensei_pages' ) ) {

			return;

		}

		// Install/page installer
		$install_complete = false;

		// Add pages button
		$settings_url = '';
		if ( isset( $_GET['install_sensei_pages'] ) && $_GET['install_sensei_pages'] ) {

			Sensei()->admin->create_pages();

			update_option( 'skip_install_sensei_pages', 1 );

			$install_complete = true;
			$settings_url     = remove_query_arg( 'install_sensei_pages' );

			// Skip button
		} elseif ( isset( $_GET['skip_install_sensei_pages'] ) && $_GET['skip_install_sensei_pages'] ) {

			update_option( 'skip_install_sensei_pages', 1 );
			$install_complete = true;
			$settings_url     = remove_query_arg( 'skip_install_sensei_pages' );

		}

		if ( $install_complete ) {

			// refresh the rewrite rules on init
			update_option( 'sensei_flush_rewrite_rules', '1' );

			// Set installed option
			update_option( 'sensei_installed', 0 );

			$complete_url = add_query_arg( 'sensei_install_complete', 'true', $settings_url );
			wp_redirect( $complete_url );

		}

	}//end install_pages()

	/**
	 * Remove a course from course order option when trashed
	 *
	 * @since 1.9.8
	 * @param $new_status null|string
	 * @param $old_status null|string
	 * @param $post null|WP_Post
	 */
	public function remove_trashed_course_from_course_order( $new_status = null, $old_status = null, $post = null ) {
		if ( empty( $new_status ) || empty( $old_status ) || $new_status === $old_status ) {
			return;
		}

		if ( empty( $post ) || 'course' !== $post->post_type ) {
			return;
		}

		if ( 'trash' === $new_status ) {
			$order_string = $this->get_course_order();

			if ( ! empty( $order_string ) ) {
				$course_id          = $post->ID;
				$ordered_course_ids = array_map( 'intval', explode( ',', $order_string ) );
				$course_id_position = array_search( $course_id, $ordered_course_ids );
				if ( false !== $course_id_position ) {
					array_splice( $ordered_course_ids, $course_id_position, 1 );
					$this->save_course_order( implode( ',', $ordered_course_ids ) );
				}
			}
		}
	}

	public function notify_if_admin_email_not_real_admin_user() {
		$maybe_admin = get_user_by( 'email', get_bloginfo( 'admin_email' ) );

		if ( false === $maybe_admin || false === user_can( $maybe_admin, 'manage_options' ) ) {
			$general_settings_url         = '<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings > General', 'sensei-lms' ) . '</a>';
			$add_new_user_url             = '<a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">' . esc_html__( 'add a new Administrator', 'sensei-lms' ) . '</a>';
			$existing_administrators_link = '<a href="' . esc_url( admin_url( 'users.php?role=administrator' ) ) . '">' . esc_html__( 'existing Administrator', 'sensei-lms' ) . '</a>';
			$current_setting              = get_bloginfo( 'admin_email' );

			/*
			 * translators: The %s placeholders are as follows:
			 *
			 * - A link to the General Settings page with the translated text "Settings > General".
			 * - A link to add an admin user with the translated text "add a new Administrator".
			 * - The current admin email address from the Settings.
			 * - A link to view the existing admin users, with the translated text "existing Administrator".
			 */
			$warning = __( 'To prevent issues with Sensei LMS module names, your Email Address in %1$s should also belong to an Administrator user. You can either %2$s with the email address %3$s, or change that email address to match the email of an %4$s.', 'sensei-lms' );

			?>
			<div id="message" class="error sensei-message sensei-connect">
				<p>
					<strong>
						<?php printf( esc_html( $warning ), wp_kses_post( $general_settings_url ), wp_kses_post( $add_new_user_url ), esc_html( $current_setting ), wp_kses_post( $existing_administrators_link ) ); ?>
					</strong>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Adds a workaround for fixing an issue with CPT's in the block editor.
	 *
	 * See https://github.com/WordPress/gutenberg/pull/15375
	 *
	 * Once that PR makes its way into WP Core, this function (and its
	 * attachment to the action in `__construct`) can be removed.
	 *
	 * @access private
	 *
	 * @since 2.1.0
	 */
	public function output_cpt_block_editor_workaround() {
		$screen = get_current_screen();

		if ( ! ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) ) {
			return;
		}

		?>
<script type="text/javascript">
	jQuery( document ).ready( function() {
		if ( wp.apiFetch ) {
			wp.apiFetch.use( function( options, next ) {
				let url = options.path || options.url;
				if ( 'POST' === options.method && wp.url.getQueryArg( url, 'meta-box-loader' ) ) {
					if ( options.body instanceof FormData && 'undefined' === options.body.get( 'post_author' ) ) {
						options.body.delete( 'post_author' );
					}
				}
				return next( options );
			} );
		}
	} );
</script>
		<?php
	}

	/**
	 * Attempt to log a Sensei event.
	 *
	 * @since 2.1.0
	 * @access private
	 */
	public function ajax_log_event() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! isset( $_REQUEST['event_name'] ) ) {
			wp_die();
		}

		$event_name = $_REQUEST['event_name'];
		$properties = isset( $_REQUEST['properties'] ) ? $_REQUEST['properties'] : [];

		// Set the source to js-event.
		add_filter(
			'sensei_event_logging_source',
			function() {
				return 'js-event';
			}
		);

		sensei_log_event( $event_name, $properties );
		// phpcs:enable WordPress.Security.NonceVerification
	}

}

/**
 * Legacy Class WooThemes_Sensei_Admin
 *
 * @ignore only for backward compatibility
 * @since 1.9.0
 * @ignore
 */
class WooThemes_Sensei_Admin extends Sensei_Admin{ }
