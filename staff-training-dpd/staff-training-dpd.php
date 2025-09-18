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
     * Add our custom column to the users list table.
     */
    public function add_users_column( $columns ) {
        $columns['monthly_training_hours'] = 'Monthly Training Hours';
        return $columns;
    }

    /**
     * Calculate and display the content for our custom user column.
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
                // This is the key part: query the relationship field for the user's ID
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
            update_user_meta($user_id, 'training_hours', $total_hours);

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
