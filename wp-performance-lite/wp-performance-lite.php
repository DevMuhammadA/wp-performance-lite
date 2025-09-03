<?php
/**
 * Plugin Name: WP Performance Lite
 * Description: Safe, toggleable performance tweaks: disable emojis & embeds, remove dashicons for visitors, trim wp_head cruft, smarter comment-reply loading, optional front-end Heartbeat control, and DNS prefetch hints.
 * Version: 1.0.0
 * Author: Muhammad Ahmed
 * License: GPL-2.0-or-later
 * Text Domain: wp-performance-lite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class WPL_Performance_Lite {
    const OPTION_KEY = 'wpl_perf_options';

    public static function instance() {
        static $inst = null;
        if ( null === $inst ) { $inst = new self(); }
        return $inst;
    }

    private function __construct() {
        // Admin UI
        add_action('admin_init',  [ $this, 'register_settings' ]);
        add_action('admin_menu',  [ $this, 'add_settings_page' ]);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [ $this, 'settings_link' ]);

        // Apply tweaks
        add_action('init', [ $this, 'apply_tweaks' ], 1);
        add_action('wp_enqueue_scripts', [ $this, 'maybe_dequeue_dashicons' ], 100);
        add_action('wp_enqueue_scripts', [ $this, 'maybe_dequeue_comment_reply' ], 100);
        add_action('wp_head', [ $this, 'dns_prefetch_links' ], 1);
        add_filter('heartbeat_settings', [ $this, 'filter_heartbeat' ]);
        add_filter('tiny_mce_plugins', [ $this, 'disable_emojis_tinymce' ]);
    }

    public static function defaults() {
        return [
            'disable_emojis'      => 1,
            'disable_embeds'      => 1,
            'trim_wp_head'        => 1,
            'no_dashicons_visitors'=> 1,
            'smart_comment_reply' => 1,
            'heartbeat_frontend'  => 'reduce', // 'reduce' | 'disable' | 'default'
            'dns_prefetch'        => [ 'https://fonts.gstatic.com', 'https://fonts.googleapis.com' ],
        ];
    }

    public function options() {
        $opts = get_option(self::OPTION_KEY, []);
        return wp_parse_args($opts, self::defaults());
    }

    /** Admin **/
    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [ $this, 'sanitize' ]);

        add_settings_section('wpl_main', __('Performance Tweaks', 'wp-performance-lite'), function(){
            echo '<p>'.esc_html__('Toggle safe optimizations. Each option is conservative and theme-agnostic.', 'wp-performance-lite').'</p>';
        }, self::OPTION_KEY);

        $fields = [
            'disable_emojis'       => __('Disable Emojis (front & admin)', 'wp-performance-lite'),
            'disable_embeds'       => __('Disable oEmbed discovery & REST endpoints', 'wp-performance-lite'),
            'trim_wp_head'         => __('Trim wp_head (shortlink, RSD, wlwmanifest, generator)', 'wp-performance-lite'),
            'no_dashicons_visitors'=> __('Remove Dashicons for non-logged-in users', 'wp-performance-lite'),
            'smart_comment_reply'  => __('Only load comment-reply where needed', 'wp-performance-lite'),
        ];
        foreach ($fields as $key => $label) {
            add_settings_field($key, $label, function() use ($key){
                $o = $this->options();
                printf('<label><input type="checkbox" name="%1$s[%2$s]" %3$s></label>',
                    esc_attr(self::OPTION_KEY),
                    esc_attr($key),
                    checked(!empty($o[$key]), true, false)
                );
            }, self::OPTION_KEY, 'wpl_main');
        }

        add_settings_field('heartbeat_frontend', __('Heartbeat (front-end)', 'wp-performance-lite'), function(){
            $o = $this->options();
            $val = $o['heartbeat_frontend'];
            ?>
            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[heartbeat_frontend]">
                <option value="default" <?php selected($val, 'default'); ?>><?php esc_html_e('Default (WordPress default)', 'wp-performance-lite'); ?></option>
                <option value="reduce"  <?php selected($val, 'reduce');  ?>><?php esc_html_e('Reduce frequency (60s)', 'wp-performance-lite'); ?></option>
                <option value="disable" <?php selected($val, 'disable'); ?>><?php esc_html_e('Disable on front-end', 'wp-performance-lite'); ?></option>
            </select>
            <?php
        }, self::OPTION_KEY, 'wpl_main');

        add_settings_field('dns_prefetch', __('DNS Prefetch / Preconnect', 'wp-performance-lite'), function(){
            $o = $this->options();
            $list = is_array($o['dns_prefetch']) ? implode("\n", $o['dns_prefetch']) : '';
            echo '<p>'.esc_html__('One domain per line, including protocol. Example: https://cdn.example.com', 'wp-performance-lite').'</p>';
            printf('<textarea name="%1$s[dns_prefetch]" rows="4" cols="60">%2$s</textarea>',
                esc_attr(self::OPTION_KEY),
                esc_textarea($list)
            );
        }, self::OPTION_KEY, 'wpl_main');
    }

    public function sanitize($input) {
        $out = self::defaults();
        foreach (['disable_emojis','disable_embeds','trim_wp_head','no_dashicons_visitors','smart_comment_reply'] as $k) {
            $out[$k] = !empty($input[$k]) ? 1 : 0;
        }
        $allowed = ['default','reduce','disable'];
        $out['heartbeat_frontend'] = in_array($input['heartbeat_frontend'] ?? 'reduce', $allowed, true) ? $input['heartbeat_frontend'] : 'reduce';

        $domains = [];
        if (!empty($input['dns_prefetch'])) {
            $lines = is_array($input['dns_prefetch']) ? $input['dns_prefetch'] : explode("
", $input['dns_prefetch']);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line) {
                    $domains[] = esc_url_raw($line);
                }
            }
        }
        $out['dns_prefetch'] = $domains ? $domains : [];
        return $out;
    }

    public function add_settings_page() {
        add_options_page(
            __('WP Performance Lite','wp-performance-lite'),
            __('Performance Lite','wp-performance-lite'),
            'manage_options',
            self::OPTION_KEY,
            [ $this, 'render_settings_page' ]
        );
    }

    public function settings_link($links) {
        $url = admin_url('options-general.php?page=' . self::OPTION_KEY);
        $links[] = '<a href="'.esc_url($url).'">'.esc_html__('Settings','wp-performance-lite').'</a>';
        return $links;
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('WP Performance Lite', 'wp-performance-lite'); ?></h1>
            <form action="options.php" method="post">
                <?php
                    settings_fields(self::OPTION_KEY);
                    do_settings_sections(self::OPTION_KEY);
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /** Tweaks **/
    public function apply_tweaks() {
        $o = $this->options();

        if ( ! empty($o['disable_emojis']) ) {
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_styles', 'print_emoji_styles');
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        }

        if ( ! empty($o['disable_embeds']) ) {
            remove_action('rest_api_init', 'wp_oembed_register_route');
            remove_filter('oembed_dataparse', 'wp_filter_oembed_result', 10);
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_filter('pre_oembed_result', 'wp_filter_pre_oembed_result', 10);
        }

        if ( ! empty($o['trim_wp_head']) ) {
            remove_action('wp_head', 'rsd_link');
            remove_action('wp_head', 'wlwmanifest_link');
            remove_action('wp_head', 'wp_generator');
            remove_action('wp_head', 'wp_shortlink_wp_head');
        }
    }

    public function maybe_dequeue_dashicons() {
        $o = $this->options();
        if ( ! empty($o['no_dashicons_visitors']) && ! is_user_logged_in() ) {
            wp_deregister_style('dashicons');
            wp_dequeue_style('dashicons');
        }
    }

    public function maybe_dequeue_comment_reply() {
        $o = $this->options();
        if ( empty($o['smart_comment_reply']) ) return;

        if ( is_singular() && comments_open() && get_option('thread_comments') ) {
            // keep it
        } else {
            wp_deregister_script('comment-reply');
            wp_dequeue_script('comment-reply');
        }
    }

    public function dns_prefetch_links() {
        $o = $this->options();
        if ( empty($o['dns_prefetch']) || ! is_array($o['dns_prefetch']) ) return;
        foreach ($o['dns_prefetch'] as $domain) {
            if ( ! $domain ) continue;
            printf("<link rel='dns-prefetch' href='%s' />
", esc_url($domain));
            printf("<link rel='preconnect' href='%s' crossorigin />
", esc_url($domain));
        }
    }

    public function filter_heartbeat($settings) {
        $o = $this->options();
        if ( is_admin() ) return $settings; // admin untouched

        if ( $o['heartbeat_frontend'] === 'disable' ) {
            wp_deregister_script('heartbeat');
        } elseif ( $o['heartbeat_frontend'] === 'reduce' ) {
            $settings['interval'] = 60;
        }
        return $settings;
    }

    /** TinyMCE plugin filter for emojis */
    public function disable_emojis_tinymce($plugins) {
        $o = $this->options();
        if ( ! empty($o['disable_emojis']) ) {
            if ( is_array($plugins) ) {
                return array_diff($plugins, ['wpemoji']);
            }
        }
        return $plugins;
    }
}

WPL_Performance_Lite::instance();
