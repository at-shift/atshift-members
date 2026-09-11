<?php
/**
 * Plugin Name: atshift Members
 * Description: Build a membership site with registration, account management, and protected content. Extend it with optional integrations.
 * Version: 0.1β
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: @shift
 * Author URI: https://plugins.at-shift.net/
 * License: GPL-2.0-or-later
 * Domain Path: /languages
 * Text Domain: atshift-members
 */
defined('ABSPATH') || exit;
foreach (['store','services','mail','members','withdrawal','audience','scope','files','content','posting','media','profile','registration','screens','admin','plugin-listing'] as $atshift_members_file) {
    require_once __DIR__ . '/includes/class-' . $atshift_members_file . '.php';
}
unset($atshift_members_file);
function atshift_members() {
    static $instance;
    if (!$instance) {
        global $wpdb;
        $instance = new \Atshift\Membership\Registration(new \Atshift\Membership\Store($wpdb));
    }
    return $instance;
}
add_action('init', static function () {
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Bundled language catalogs must load in standalone packages before WordPress.org language packs exist.
    load_plugin_textdomain('atshift-members', false, dirname(plugin_basename(__FILE__)) . '/languages');
}, 0);
register_activation_hook(__FILE__, function($network_wide) {
    if (is_multisite() || $network_wide) wp_die(esc_html__('The development version of atshift Members supports single-site installations only.', 'atshift-members'));
    atshift_members()->store->install();
    if (!atshift_members()->store->ready()) wp_die(esc_html__('InnoDB tables are required.', 'atshift-members'));
    \Atshift\Membership\Members::roles();
    \Atshift\Membership\Content::types();
    if (!wp_next_scheduled('asm_cleanup')) wp_schedule_event(time()+3600, 'hourly', 'asm_cleanup');
    if (!wp_next_scheduled('asm_mail_worker')) wp_schedule_event(time()+60, 'asm_minute', 'asm_mail_worker');
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('asm_cleanup');
    wp_clear_scheduled_hook('asm_mail_worker');
    flush_rewrite_rules();
});
add_filter('cron_schedules', function($s) { $s['asm_minute']=['interval'=>60,'display'=>__('atshift Members: Every minute', 'atshift-members')]; return $s; });
add_action('plugins_loaded', function() {
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
    (new \Atshift\Membership\Admin($registration))->hooks();
    add_action('asm_mail_worker', [$registration,'mail_worker']);
    add_action('asm_cleanup', [$registration->store,'cleanup']);
});
