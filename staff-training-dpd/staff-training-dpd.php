<?php
/**
 * Plugin Name:       Staff Training - DPD
 * Description:       Tracks and displays monthly staff training hours from a Pods Custom Post Type. Adds a custom column to the Users list to display the total with color-coding.
 * Version:           1.2
 * Author:            Swimming Ideas
 * Author URI:        https://www.swimmingideas.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Staff_Training_DPD {

    /**
     * The single instance of the class.
     */
    protected static $_instance = null;

    /**
     * Main instance to ensure only one instance of the class exists.
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor - Hooks into WordPress actions and filters.
     */
    public function __construct() {
        // Add settings page to the admin menu
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        // Register plugin settings
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        // Enqueue scripts for the color picker on the settings page
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

        // Add custom column to the users list table
        add_filter( 'manage_users_columns', [ $this, 'add_users_column' ] );
        // Render the content for the custom column
        add_action( 'manage_users_custom_column', [ $this, 'render_users_column_content' ], 10, 3 );

        // When a training session is saved, update the hours for the attendees
        add_action( 'save_post_training_session', [ $this, 'on_save_training_session' ], 10, 2 );

        // Custom hook for the cron job
        add_action( 'dpd_staff_training_update_all_users', [ $this, 'update_all_users_training_hours' ] );
    }

    /**
     * Add the plugin's settings page under the main "Settings" menu.
     */
    public function add_admin_menu() {
        add_options_page(
            'Staff Training Settings', // Page Title
            'Staff Training',          // Menu Title
            'manage_options',          // Capability required
            'staff-training-dpd',      // Menu Slug
            [ $this, 'render_settings_page' ] // Callback function to render the page
        );
    }

    /**
     * Enqueue the WordPress color picker script and style, only on our settings page.
     */
    public function enqueue_admin_scripts($hook) {
        // Check if we are on the correct settings page
        if ( 'settings_page_staff-training-dpd' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );
    }

    /**
     * Register the plugin's settings, section, and fields.
     */
    public function register_settings() {
        register_setting( 'dpd_staff_training_options', 'dpd_staff_training_settings' );

        add_settings_section(
            'dpd_settings_section',
            'Display Settings',
            null,
            'dpd_staff_training_options'
        );

        add_settings_field(
            'dpd_required_hours',
            'Required Monthly Hours',
            [ $this, 'render_hours_field' ],
            'dpd_staff_training_options',
            'dpd_settings_section'
        );

        add_settings_field(
            'dpd_success_color',
            'Success Background Color',
            [ $this, 'render_color_field' ],
            'dpd_staff_training_options',
            'dpd_settings_section',
            [ 'name' => 'dpd_success_color' ]
        );

        add_settings_field(
            'dpd_fail_color',
            'Failure Background Color',
            [ $this, 'render_color_field' ],
            'dpd_staff_training_options',
            'dpd_settings_section',
            [ 'name' => 'dpd_fail_color' ]
        );
    }

    /**
     * Render the input field for required hours on the settings page.
     */
    public function render_hours_field() {
        $options = get_option( 'dpd_staff_training_settings' );
        $hours = isset( $options['dpd_required_hours'] ) ? $options['dpd_required_hours'] : '4';
        echo '<input type="number" name="dpd_staff_training_settings[dpd_required_hours]" value="' . esc_attr( $hours ) . '" min="0" step="0.5" />';
        echo '<p class="description">Enter the minimum number of training hours required per month.</p>';
    }

    /**
     * Render the color picker input field on the settings page.
     */
    public function render_color_field( $args ) {
        $options = get_option( 'dpd_staff_training_settings' );
        $name = $args['name'];
        $default_color = ( 'dpd_success_color' === $name ) ? '#d4edda' : '#f8d7da'; // Green / Red defaults
        $color = isset( $options[$name] ) ? $options[$name] : $default_color;
        echo '<input type="text" name="dpd_staff_training_settings[' . esc_attr( $name ) . ']" value="' . esc_attr( $color ) . '" class="dpd-color-picker" />';
    }

    /**
     * Render the HTML structure for the settings page.
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Staff Training - DPD Settings</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields( 'dpd_staff_training_options' );
                do_settings_sections( 'dpd_staff_training_options' );
                submit_button();
                ?>
            </form>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('.dpd-color-picker').wpColorPicker();
            });
        </script>
        <?php
    }

    /**
     * Calculate and update the total training hours for a specific user for the current month.
     */
    public function update_user_training_hours( $user_id ) {
        // Get the start and end dates of the current calendar month
        $current_month_start = date( 'Y-m-01' );
        $current_month_end = date( 'Y-m-t' );

        // Set up arguments for WP_Query to find relevant training sessions
        $args = [
            'post_type'      => 'training_session',
            'posts_per_page' => -1, // Get all matching posts
            'post_status'    => 'publish',
            'date_query'     => [
                [
                    'after'     => $current_month_start,
                    'before'    => $current_month_end,
                    'inclusive' => true,
                ],
            ],
            // Query the relationship field for the user's ID
            'meta_query' => [
                [
                    'key'     => 'attendees', // The name of your Pods relationship field
                    'value'   => '"' . $user_id . '"',
                    'compare' => 'LIKE', // Use LIKE because Pods stores relationship data as a serialized array
                ],
            ],
        ];

        $query = new WP_Query( $args );
        $total_hours = 0.0;

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                // Get the 'hours' value from the custom field of the training session
                $session_hours = get_post_meta( get_the_ID(), 'hours', true );
                if ( is_numeric( $session_hours ) ) {
                    $total_hours += floatval( $session_hours );
                }
            }
        }
        wp_reset_postdata();

        // Update the user's Pods field 'training_hours' with the new total
        update_user_meta( $user_id, 'training_hours', $total_hours );
    }

    /**
     * Add our custom column to the users list table.
     */
    public function add_users_column( $columns ) {
        $columns['monthly_training_hours'] = 'Monthly Training Hours';
        return $columns;
    }

    /**
     * When a training session is saved, update the hours for all attendees.
     */
    public function on_save_training_session( $post_id, $post ) {
        // Check if this is an autosave or a revision
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Check the user's permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Get the list of attendee IDs from the 'attendees' Pods field
        $attendee_ids = get_post_meta( $post_id, 'attendees', true );

        if ( is_array( $attendee_ids ) && ! empty( $attendee_ids ) ) {
            foreach ( $attendee_ids as $user_id ) {
                // The value from pods can be just the ID or an array with the ID
                $id = is_array( $user_id ) ? $user_id['ID'] : $user_id;
                if ( is_numeric( $id ) ) {
                    $this->update_user_training_hours( $id );
                }
            }
        }
    }

    /**
     * Cron job function to update training hours for all users.
     */
    public function update_all_users_training_hours() {
        $users = get_users();
        foreach ( $users as $user ) {
            $this->update_user_training_hours( $user->ID );
        }
    }

    /**
     * On plugin activation, schedule the monthly cron job.
     */
    public function activate() {
        if ( ! wp_next_scheduled( 'dpd_staff_training_update_all_users' ) ) {
            wp_schedule_event( time(), 'monthly', 'dpd_staff_training_update_all_users' );
        }
    }

    /**
     * On plugin deactivation, unschedule the monthly cron job.
     */
    public function deactivate() {
        wp_clear_scheduled_hook( 'dpd_staff_training_update_all_users' );
    }

    /**
     * Display the content for our custom user column based on stored user meta.
     * This function runs for each user row on the Users page.
     */
    public function render_users_column_content( $value, $column_name, $user_id ) {
        if ( 'monthly_training_hours' === $column_name ) {

            // Get plugin settings and set defaults if they are not yet saved
            $options = get_option( 'dpd_staff_training_settings' );
            $required_hours = isset( $options['dpd_required_hours'] ) ? floatval( $options['dpd_required_hours'] ) : 4.0;
            $success_color = isset( $options['dpd_success_color'] ) ? $options['dpd_success_color'] : '#d4edda';
            $fail_color = isset( $options['dpd_fail_color'] ) ? $options['dpd_fail_color'] : '#f8d7da';
            $success_text_color = '#155724'; // Dark green text for light green background
            $fail_text_color = '#721c24';    // Dark red text for light red background

            // Get the total hours from the user's meta field 'training_hours'
            $total_hours = get_user_meta( $user_id, 'training_hours', true );
            $total_hours = is_numeric( $total_hours ) ? floatval( $total_hours ) : 0.0;

            // Determine background and text color based on whether the goal was met
            if ( $total_hours >= $required_hours ) {
                $bg_color = $success_color;
                $text_color = $success_text_color;
            } else {
                $bg_color = $fail_color;
                $text_color = $fail_text_color;
            }

            // Create inline styles for the output
            $style = sprintf(
                'display: inline-block; padding: 4px 8px; border-radius: 4px; background-color: %s; color: %s; font-weight: bold;',
                esc_attr( $bg_color ),
                esc_attr( $text_color )
            );

            // Print the final styled output directly into the column
            echo '<span style="' . $style . '">' . esc_html( $total_hours ) . ' hours</span>';

            return; // Return nothing because we used echo to output the content
        }

        return $value;
    }
}

// Initialize the plugin class
Staff_Training_DPD::instance();

// Activation and deactivation hooks
register_activation_hook( __FILE__, [ Staff_Training_DPD::instance(), 'activate' ] );
register_deactivation_hook( __FILE__, [ Staff_Training_DPD::instance(), 'deactivate' ] );
