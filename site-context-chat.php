<?php
/**
 * Plugin Name: Site Context Chat
 * Plugin URI: https://getkontor.eu/support-software
 * Description: AI website chat that answers visitors from your own knowledge base, on your own Supabase backend (Claude, OpenAI or Z.ai, your own key). Works on its own or with Kontor.
 * Version: 5.4.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: TechLads
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: site-context-chat
 */

if (!defined('ABSPATH')) {
    exit;
}

// Stamped by scripts/build-wp-plugin.mjs (same number as the npm package and the backend zip).
define('TECHLADS_SCC_VERSION', '5.4.0');
define('TECHLADS_SCC_PLUGIN_FILE', __FILE__);
define('TECHLADS_SCC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TECHLADS_SCC_PLUGIN_URL', plugin_dir_url(__FILE__));

final class Techlads_Site_Context_Chat {
    const OPTION_GROUP = 'techlads_settings_group';
    const OPTION_NAME = 'techlads_settings';
    const SHORTCODE_ADMIN = 'techlads_chat_admin';
    const SETTINGS_PAGE_SLUG = 'techlads-site-context-chat';
    const ADMIN_TAB = 'chat-admin';

    /** @var bool */
    private static $admin_shortcode_requested = false;

    /** @var bool */
    private static $widget_assets_enqueued = false;

    /** @var bool */
    private static $admin_assets_enqueued = false;

    public static function init(): void {
        self::migrate_legacy_settings();

        require_once TECHLADS_SCC_PLUGIN_DIR . 'includes/backend-setup-guide.php';

        add_action('admin_menu', [__CLASS__, 'register_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_docs_styles']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_tab_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend_assets'], 20);
        add_action('wp_footer', [__CLASS__, 'render_widget_mount'], 5);
        add_shortcode(self::SHORTCODE_ADMIN, [__CLASS__, 'render_admin_shortcode']);
        add_filter('the_posts', [__CLASS__, 'detect_admin_shortcode_in_posts'], 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(TECHLADS_SCC_PLUGIN_FILE), [__CLASS__, 'plugin_action_links']);
    }

    private static function migrate_legacy_settings(): void {
        if (get_option(self::OPTION_NAME, null) !== null) {
            return;
        }

        $legacy = get_option('scc_settings', null);
        if (!is_array($legacy)) {
            return;
        }

        unset($legacy['custom_css']);
        update_option(self::OPTION_NAME, $legacy);
        delete_option('scc_settings');
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public static function plugin_action_links($links): array {
        $settings = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('options-general.php?page=' . self::SETTINGS_PAGE_SLUG)),
            esc_html__('Settings', 'site-context-chat')
        );
        array_unshift($links, $settings);
        return $links;
    }

    /** @return array<string, mixed> */
    public static function get_settings(): array {
        $defaults = [
            'enabled' => '1',
            'supabase_url' => '',
            'supabase_anon_key' => '',
            'site_id' => 'default',
            'hide_fab_selector' => '',
            'admin_page_slug' => 'chat-admin',
            'theme' => 'dark',
            'admin_secret' => '',
        ];

        $stored = get_option(self::OPTION_NAME, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge($defaults, $stored);
    }

    public static function enqueue_admin_docs_styles(string $hook_suffix): void {
        if ($hook_suffix !== 'settings_page_' . self::SETTINGS_PAGE_SLUG) {
            return;
        }

        $path = TECHLADS_SCC_PLUGIN_DIR . 'wp-admin-docs.css';
        if (!file_exists($path)) {
            return;
        }

        wp_enqueue_style(
            'techlads-admin-docs',
            TECHLADS_SCC_PLUGIN_URL . 'wp-admin-docs.css',
            [],
            (string) filemtime($path)
        );
    }

    public static function register_settings_page(): void {
        add_options_page(
            __('Site Context Chat', 'site-context-chat'),
            __('Site Context Chat', 'site-context-chat'),
            'manage_options',
            self::SETTINGS_PAGE_SLUG,
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            [
                'type' => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
                'default' => self::get_settings(),
            ]
        );
    }

    /**
     * @param array<string, mixed>|mixed $input
     * @return array<string, string>
     */
    public static function sanitize_settings($input): array {
        if (!is_array($input)) {
            return self::get_settings();
        }

        $clean = self::get_settings();

        $clean['enabled'] = !empty($input['enabled']) ? '1' : '0';

        $url = trim((string) ($input['supabase_url'] ?? ''));
        $clean['supabase_url'] = $url !== '' ? untrailingslashit(esc_url_raw($url)) : '';

        $clean['supabase_anon_key'] = sanitize_text_field(trim((string) ($input['supabase_anon_key'] ?? '')));

        $site_id = trim((string) ($input['site_id'] ?? 'default'));
        $clean['site_id'] = $site_id !== '' ? sanitize_key($site_id) : 'default';
        if ($clean['site_id'] === '') {
            $clean['site_id'] = 'default';
        }

        $clean['hide_fab_selector'] = sanitize_text_field(trim((string) ($input['hide_fab_selector'] ?? '')));
        $clean['admin_page_slug'] = sanitize_title(trim((string) ($input['admin_page_slug'] ?? 'chat-admin'))) ?: 'chat-admin';

        $theme = sanitize_key((string) ($input['theme'] ?? 'dark'));
        $clean['theme'] = in_array($theme, ['dark', 'light', 'auto'], true) ? $theme : 'dark';

        // Password field: left empty = keep the saved secret; "clear" checkbox removes it.
        $secret = trim((string) ($input['admin_secret'] ?? ''));
        if (!empty($input['admin_secret_clear'])) {
            $clean['admin_secret'] = '';
        } elseif ($secret !== '') {
            $clean['admin_secret'] = sanitize_text_field($secret);
        }

        return $clean;
    }

    private static function initial_theme_attribute(): string {
        $theme = self::get_settings()['theme'] ?? 'dark';
        if ($theme === 'light') {
            return 'light';
        }
        return 'dark';
    }

    public static function is_widget_configured(): bool {
        $settings = self::get_settings();
        return $settings['supabase_url'] !== '' && $settings['supabase_anon_key'] !== '';
    }

    public static function is_widget_enabled(): bool {
        $settings = self::get_settings();
        return $settings['enabled'] === '1' && self::is_widget_configured();
    }

    public static function get_admin_page_url(): string {
        $settings = self::get_settings();
        $slug = $settings['admin_page_slug'] ?: 'chat-admin';

        $page = get_page_by_path($slug);
        if ($page instanceof WP_Post) {
            return get_permalink($page);
        }

        return home_url('/' . ltrim($slug, '/'));
    }

    public static function is_admin_page_request(): bool {
        if (self::$admin_shortcode_requested) {
            return true;
        }

        $settings = self::get_settings();
        $slug = $settings['admin_page_slug'] ?? 'chat-admin';

        if (is_page($slug)) {
            return true;
        }

        global $post;
        if ($post instanceof WP_Post && $post->post_name === $slug) {
            return true;
        }

        if ($post instanceof WP_Post && has_shortcode($post->post_content, self::SHORTCODE_ADMIN)) {
            return true;
        }

        return false;
    }

    /**
     * @param \WP_Post[] $posts
     * @return \WP_Post[]
     */
    public static function detect_admin_shortcode_in_posts($posts, $query) {
        if (empty($posts) || !is_array($posts)) {
            return $posts;
        }

        foreach ($posts as $post) {
            if (!empty($post->post_content) && has_shortcode($post->post_content, self::SHORTCODE_ADMIN)) {
                self::$admin_shortcode_requested = true;
                break;
            }
        }

        return $posts;
    }

    /** @return array<string, string> */
    private static function shared_script_config(): array {
        $settings = self::get_settings();

        return [
            'url' => $settings['supabase_url'],
            'anonKey' => $settings['supabase_anon_key'],
            'siteId' => $settings['site_id'] ?: 'default',
            'theme' => $settings['theme'] ?? 'dark',
        ];
    }

    /**
     * Chat admin config: the shared values plus the admin secret, which is only printed for
     * users who can manage options (the secret stays in WordPress options, never in the
     * browser's storage: the panel gets it as its credential).
     *
     * @return array<string, string>
     */
    private static function admin_script_config(): array {
        $config = self::shared_script_config();
        $config['adminSecret'] = current_user_can('manage_options')
            ? (string) (self::get_settings()['admin_secret'] ?? '')
            : '';
        return $config;
    }

    private static function style_version(): string {
        $style_path = TECHLADS_SCC_PLUGIN_DIR . 'assets/site-context-chat.css';
        return file_exists($style_path) ? (string) filemtime($style_path) : TECHLADS_SCC_VERSION;
    }

    private static function enqueue_widget_assets(): void {
        if (self::$widget_assets_enqueued || !self::is_widget_enabled()) {
            return;
        }

        $widget_path = TECHLADS_SCC_PLUGIN_DIR . 'assets/widget.js';
        if (!file_exists($widget_path)) {
            return;
        }

        self::$widget_assets_enqueued = true;
        $style_ver = self::style_version();

        wp_enqueue_style(
            'techlads-widget',
            TECHLADS_SCC_PLUGIN_URL . 'assets/site-context-chat.css',
            [],
            $style_ver
        );

        wp_enqueue_script(
            'techlads-widget',
            TECHLADS_SCC_PLUGIN_URL . 'assets/widget.js',
            [],
            (string) filemtime($widget_path),
            true
        );

        $config = self::shared_script_config();
        $config['hideFabSelector'] = self::get_settings()['hide_fab_selector'];

        wp_localize_script('techlads-widget', 'techladsWidgetConfig', $config);
    }

    /**
     * @param bool $in_wp_admin true on the plugin's Chat admin tab; false on a front-end page
     *                          with the [techlads_chat_admin] shortcode (older setups).
     */
    private static function enqueue_admin_assets(bool $in_wp_admin = false): void {
        if (self::$admin_assets_enqueued || !self::is_widget_configured()) {
            return;
        }
        if (!$in_wp_admin && !self::is_admin_page_request()) {
            return;
        }

        $admin_path = TECHLADS_SCC_PLUGIN_DIR . 'assets/admin.js';
        if (!file_exists($admin_path)) {
            return;
        }

        self::$admin_assets_enqueued = true;
        $style_ver = self::style_version();

        wp_enqueue_style(
            'techlads-admin',
            TECHLADS_SCC_PLUGIN_URL . 'assets/site-context-chat.css',
            [],
            $style_ver
        );

        $layout_path = TECHLADS_SCC_PLUGIN_DIR . 'wp-admin-layout.css';
        if (file_exists($layout_path)) {
            wp_enqueue_style(
                'techlads-admin-layout',
                TECHLADS_SCC_PLUGIN_URL . 'wp-admin-layout.css',
                ['techlads-admin'],
                (string) filemtime($layout_path)
            );
        }

        wp_enqueue_script(
            'techlads-admin',
            TECHLADS_SCC_PLUGIN_URL . 'assets/admin.js',
            [],
            (string) filemtime($admin_path),
            true
        );

        wp_localize_script('techlads-admin', 'techladsAdminConfig', self::admin_script_config());
    }

    private static function is_admin_tab(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch
        return isset($_GET['tab']) && sanitize_key(wp_unslash($_GET['tab'])) === self::ADMIN_TAB;
    }

    public static function enqueue_admin_tab_assets(string $hook_suffix): void {
        if ($hook_suffix !== 'settings_page_' . self::SETTINGS_PAGE_SLUG || !self::is_admin_tab()) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        self::enqueue_admin_assets(true);
    }

    public static function enqueue_frontend_assets(): void {
        global $post;
        if ($post instanceof WP_Post && has_shortcode($post->post_content, self::SHORTCODE_ADMIN)) {
            self::$admin_shortcode_requested = true;
        }

        self::enqueue_widget_assets();
        self::enqueue_admin_assets();
    }

    public static function render_widget_mount(): void {
        if (!self::is_widget_enabled()) {
            return;
        }

        printf(
            '<div id="scc-root" data-theme="%s"></div>',
            esc_attr(self::initial_theme_attribute())
        );
    }

    /** @return string */
    public static function render_admin_shortcode(): string {
        if (!self::is_widget_configured()) {
            return '<p class="scc-wp-notice">' . esc_html__(
                'Site Context Chat is not configured. Add your Supabase URL and anon key under Settings → Site Context Chat.',
                'site-context-chat'
            ) . '</p>';
        }

        // The chat admin now lives in wp-admin; this older front-end page only works for
        // signed-in administrators (the panel gets the saved admin secret, nobody else does).
        if (!current_user_can('manage_options')) {
            return '<p class="scc-wp-notice">' . esc_html__(
                'The chat admin is in WordPress: Settings → Site Context Chat → Chat admin.',
                'site-context-chat'
            ) . '</p>';
        }

        self::$admin_shortcode_requested = true;
        self::enqueue_admin_assets();

        $fallback = esc_html__('Loading chat admin…', 'site-context-chat');
        $noscript = esc_html__(
            'Chat admin requires JavaScript. If this message persists, check that admin.js loaded (browser devtools → Network).',
            'site-context-chat'
        );

        return '<div id="scc-admin-root" class="scc-wp-admin-mount" data-theme="' . esc_attr(self::initial_theme_attribute()) . '">'
            . '<p class="scc-wp-loading">' . $fallback . '</p></div>'
            . '<noscript><p class="scc-wp-notice">' . $noscript . '</p></noscript>';
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get_settings();
        $assets_built = file_exists(TECHLADS_SCC_PLUGIN_DIR . 'assets/widget.js') && file_exists(TECHLADS_SCC_PLUGIN_DIR . 'assets/admin.js');
        $shortcode = self::SHORTCODE_ADMIN;
        $page_url = admin_url('options-general.php?page=' . self::SETTINGS_PAGE_SLUG);
        $admin_tab_url = add_query_arg('tab', self::ADMIN_TAB, $page_url);
        $on_admin_tab = self::is_admin_tab();
        $has_secret = ($settings['admin_secret'] ?? '') !== '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Site Context Chat', 'site-context-chat'); ?></h1>

            <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('Site Context Chat', 'site-context-chat'); ?>">
                <a href="<?php echo esc_url($page_url); ?>" class="nav-tab<?php echo $on_admin_tab ? '' : ' nav-tab-active'; ?>"><?php echo esc_html__('Settings', 'site-context-chat'); ?></a>
                <a href="<?php echo esc_url($admin_tab_url); ?>" class="nav-tab<?php echo $on_admin_tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html__('Chat admin', 'site-context-chat'); ?></a>
            </nav>

            <?php if ($on_admin_tab) : ?>
                <p class="description" style="margin: 1em 0;">
                    <?php echo esc_html__('Knowledge base, appearance, conversations and enquiries.', 'site-context-chat'); ?>
                </p>
                <?php if (!self::is_widget_configured()) : ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__('Add your Supabase URL and publishable key on the Settings tab first.', 'site-context-chat'); ?></p></div>
                <?php elseif (!$has_secret) : ?>
                    <div class="notice notice-warning inline"><p><?php echo esc_html__('Add your admin secret on the Settings tab to open the chat admin.', 'site-context-chat'); ?></p></div>
                <?php else : ?>
                    <div id="scc-admin-root" class="scc-wp-admin-mount scc-wp-admin-tab" data-theme="<?php echo esc_attr(self::initial_theme_attribute()); ?>">
                        <p class="scc-wp-loading"><?php echo esc_html__('Loading chat admin…', 'site-context-chat'); ?></p>
                    </div>
                <?php endif; ?>
        </div>
                <?php
                return;
            endif;
            ?>

            <div class="notice notice-info inline" style="margin: 1em 0; padding: 1em;">
                <p><strong><?php echo esc_html__('Status', 'site-context-chat'); ?></strong></p>
                <ul style="list-style: disc; margin-left: 1.5em;">
                    <li><?php echo $assets_built ? '✅' : '❌'; ?> <?php echo esc_html__('Plugin files complete (widget.js, admin.js)', 'site-context-chat'); ?></li>
                    <li><?php echo self::is_widget_configured() ? '✅' : '❌'; ?> <?php echo esc_html__('Backend URL and key saved', 'site-context-chat'); ?></li>
                    <li><?php echo $settings['enabled'] === '1' ? '✅' : '⚠️'; ?> <?php echo esc_html__('Widget enabled', 'site-context-chat'); ?></li>
                    <li><?php echo $has_secret ? '✅' : '⚠️'; ?> <?php echo esc_html__('Admin secret saved (for the Chat admin tab)', 'site-context-chat'); ?></li>
                </ul>
            </div>

            <?php if (!$assets_built) : ?>
                <div class="notice notice-error">
                    <p>
                        <?php echo esc_html__(
                            'Plugin files are missing. Reinstall Site Context Chat from Plugins → Add New.',
                            'site-context-chat'
                        ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable widget', 'site-context-chat'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[enabled]" value="1" <?php checked($settings['enabled'], '1'); ?> />
                                <?php echo esc_html__('Show chat widget on the public site', 'site-context-chat'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="techlads_supabase_url"><?php echo esc_html__('Supabase URL', 'site-context-chat'); ?></label></th>
                        <td>
                            <input type="url" class="regular-text" id="techlads_supabase_url" name="<?php echo esc_attr(self::OPTION_NAME); ?>[supabase_url]" value="<?php echo esc_attr($settings['supabase_url']); ?>" placeholder="https://YOUR_PROJECT.supabase.co" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="techlads_supabase_anon_key"><?php echo esc_html__('Publishable key', 'site-context-chat'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text code" id="techlads_supabase_anon_key" name="<?php echo esc_attr(self::OPTION_NAME); ?>[supabase_anon_key]" value="<?php echo esc_attr($settings['supabase_anon_key']); ?>" autocomplete="off" />
                            <p class="description"><?php echo esc_html__('Your Supabase publishable key (sb_publishable_…) or legacy anon key (eyJ…). It is public: your visitors\' browsers use it. With Kontor, copy it from Settings → Visitor chat → Website embed.', 'site-context-chat'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="techlads_admin_secret"><?php echo esc_html__('Admin secret', 'site-context-chat'); ?></label></th>
                        <td>
                            <input type="password" class="regular-text code" id="techlads_admin_secret" name="<?php echo esc_attr(self::OPTION_NAME); ?>[admin_secret]" value="" autocomplete="new-password" placeholder="<?php echo $has_secret ? esc_attr__('Saved (leave empty to keep it)', 'site-context-chat') : ''; ?>" />
                            <?php if ($has_secret) : ?>
                                <label style="margin-left: .5em;"><input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[admin_secret_clear]" value="1" /> <?php echo esc_html__('Remove', 'site-context-chat'); ?></label>
                            <?php endif; ?>
                            <p class="description"><?php echo esc_html__('The SITE_CHAT_ADMIN_SECRET of your backend (with Kontor, your admin secret). Opens the Chat admin tab. Kept in WordPress, shown only to administrators.', 'site-context-chat'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="techlads_site_id"><?php echo esc_html__('Site ID', 'site-context-chat'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="techlads_site_id" name="<?php echo esc_attr(self::OPTION_NAME); ?>[site_id]" value="<?php echo esc_attr($settings['site_id']); ?>" />
                            <p class="description"><?php echo esc_html__('Usually "default" unless you configured multiple sites in Supabase.', 'site-context-chat'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="techlads_hide_fab_selector"><?php echo esc_html__('Hide FAB selector (optional)', 'site-context-chat'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="techlads_hide_fab_selector" name="<?php echo esc_attr(self::OPTION_NAME); ?>[hide_fab_selector]" value="<?php echo esc_attr($settings['hide_fab_selector']); ?>" placeholder="#hero" />
                            <p class="description"><?php echo esc_html__('CSS selector — hide the chat button while this element is visible (e.g. homepage hero).', 'site-context-chat'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="techlads_admin_page_slug"><?php echo esc_html__('Admin page slug (optional)', 'site-context-chat'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" id="techlads_admin_page_slug" name="<?php echo esc_attr(self::OPTION_NAME); ?>[admin_page_slug]" value="<?php echo esc_attr($settings['admin_page_slug']); ?>" />
                            <p class="description">
                                <?php
                                echo esc_html__(
                                    'Not needed any more: the chat admin is on the Chat admin tab above. Only for an existing front-end page with the shortcode (it works for signed-in administrators):',
                                    'site-context-chat'
                                );
                                ?>
                                <code>[<?php echo esc_html($shortcode); ?>]</code>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php echo esc_html__('Appearance', 'site-context-chat'); ?></h2>
                <p class="description">
                    <?php echo esc_html__('Widget and chat admin styling. Brand colours from the Supabase admin panel apply via --scc-fab-accent.', 'site-context-chat'); ?>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="techlads_theme"><?php echo esc_html__('Theme', 'site-context-chat'); ?></label></th>
                        <td>
                            <select id="techlads_theme" name="<?php echo esc_attr(self::OPTION_NAME); ?>[theme]">
                                <option value="dark" <?php selected($settings['theme'], 'dark'); ?>><?php echo esc_html__('Dark (default)', 'site-context-chat'); ?></option>
                                <option value="light" <?php selected($settings['theme'], 'light'); ?>><?php echo esc_html__('Light', 'site-context-chat'); ?></option>
                                <option value="auto" <?php selected($settings['theme'], 'auto'); ?>><?php echo esc_html__('Auto — match visitor OS', 'site-context-chat'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr />

            <h2><?php echo esc_html__('Setup guide', 'site-context-chat'); ?></h2>
            <?php techlads_render_backend_setup_guide($admin_tab_url); ?>
        </div>
        <?php
    }
}

Techlads_Site_Context_Chat::init();
