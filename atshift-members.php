<?php
/**
 * Plugin Name: atshift Members
 * Plugin URI: https://plugins.at-shift.net/members/
 * Description: Build a simple, easy-to-understand membership site.
 * Version: 1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: @shift
 * Author URI: https://plugins.at-shift.net/
 * License: GPL-2.0-or-later
 * Text Domain: atshift-members
 */
defined('ABSPATH') || exit;
foreach (['legacy','store','services','mail','members','withdrawal','audience','scope','files','content','posting','media','profile','profile-preset','registration','screens','dashboard','directory','admin','plugin-listing'] as $atshift_members_file) {
    require_once __DIR__ . '/includes/class-' . $atshift_members_file . '.php';
}
unset($atshift_members_file);
\Atshift\Membership\Legacy::constants();
function atshift_members() {
    static $instance;
    if (!$instance) {
        global $wpdb;
        $instance = new \Atshift\Membership\Registration(new \Atshift\Membership\Store($wpdb));
    }
    return $instance;
}
register_activation_hook(__FILE__, function($network_wide) {
    if (is_multisite() || $network_wide) wp_die(esc_html__('atshift Members supports single-site installations only.', 'atshift-members'));
    atshift_members()->store->install();
    if (!atshift_members()->store->ready()) wp_die(esc_html__('InnoDB tables are required.', 'atshift-members'));
    \Atshift\Membership\Members::roles();
    \Atshift\Membership\Legacy::maybe_migrate();
    \Atshift\Membership\Content::types();
    if (!wp_next_scheduled('atshme_cleanup')) wp_schedule_event(time()+3600, 'hourly', 'atshme_cleanup');
    if (!wp_next_scheduled('atshme_mail_worker')) wp_schedule_event(time()+60, 'atshme_minute', 'atshme_mail_worker');
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('atshme_cleanup');
    wp_clear_scheduled_hook('atshme_mail_worker');
    flush_rewrite_rules();
});
add_filter('cron_schedules', function($s) { $s['atshme_minute']=['interval'=>60,'display'=>__('atshift Members: Every minute', 'atshift-members')]; return $s; });
add_action('plugins_loaded',[\Atshift\Membership\Legacy::class,'maybe_migrate'],1);
add_action('plugins_loaded', function() {
    \Atshift\Membership\ProfilePreset::hooks();
    \Atshift\Membership\PluginListing::hooks();
    $registration = atshift_members();
    \Atshift\Membership\Members::hooks();
    \Atshift\Membership\Withdrawal::hooks();
    \Atshift\Membership\Content::hooks();
    \Atshift\Membership\Audience::hooks();
    \Atshift\Membership\Scope::hooks();
    \Atshift\Membership\Files::hooks();
    \Atshift\Membership\Posting::hooks();
    \Atshift\Membership\Media::hooks();
    (new \Atshift\Membership\Screens($registration))->hooks();
    \Atshift\Membership\Dashboard::hooks();
    \Atshift\Membership\Directory::hooks();
    (new \Atshift\Membership\Admin($registration))->hooks();
    add_action('init',[\Atshift\Membership\Legacy::class,'shortcode_aliases'],PHP_INT_MAX);
    add_action('admin_notices',function(){if(current_user_can('manage_options')&&is_wp_error(\Atshift\Membership\Legacy::error()))echo '<div class="notice notice-error"><p>'.esc_html(\Atshift\Membership\Legacy::error()->get_error_message()).'</p></div>';});
    add_action('atshme_mail_worker', [$registration,'mail_worker']);
    add_action('atshme_cleanup', [$registration->store,'cleanup']);
});
