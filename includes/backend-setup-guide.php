<?php
/**
 * The setup guide on Settings → Site Context Chat.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param string $admin_tab_url URL of the plugin's Chat admin tab.
 */
function techlads_render_backend_setup_guide(string $admin_tab_url): void {
    $downloads = 'https://getkontor.eu/support-software';
    $backend_zip = 'https://getkontor.eu/downloads/site-context-chat-backend.zip';
    ?>
    <div class="scc-setup-guide">
        <p class="scc-setup-guide__intro">
            <?php echo esc_html__('The plugin is the chat your visitors see. It answers from a backend on your own Supabase project, with your own AI key.', 'site-context-chat'); ?>
        </p>

        <details open>
            <summary>
                <?php echo esc_html__('Using Kontor?', 'site-context-chat'); ?>
                <span class="scc-setup-guide__meta"><?php echo esc_html__('~2 min', 'site-context-chat'); ?></span>
            </summary>
            <div class="scc-setup-guide__body">
                <p><?php echo esc_html__('Your Kontor backend already runs the chat. Nothing to install:', 'site-context-chat'); ?></p>
                <ol>
                    <li><?php echo esc_html__('In Kontor, open Settings → Visitor chat → Website embed and copy the Supabase URL and the publishable key.', 'site-context-chat'); ?></li>
                    <li><?php echo esc_html__('Paste them above, add your Kontor admin secret, turn the widget on and save.', 'site-context-chat'); ?></li>
                </ol>
                <p><?php echo esc_html__('Enquiries from the chat then show up in Kontor as prospects, and Kontor\'s own knowledge base answers your visitors.', 'site-context-chat'); ?></p>
            </div>
        </details>

        <details>
            <summary>
                <?php echo esc_html__('Without Kontor: set up the free backend', 'site-context-chat'); ?>
                <span class="scc-setup-guide__meta"><?php echo esc_html__('~20–30 min', 'site-context-chat'); ?></span>
            </summary>
            <div class="scc-setup-guide__body">
                <p>
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            /* translators: 1: backend zip URL, 2: downloads page URL */
                            __('Download the <a href="%1$s">free chat backend</a> (also on <a href="%2$s" target="_blank" rel="noopener noreferrer">getkontor.eu</a>). Its README has every step in detail; in short:', 'site-context-chat'),
                            esc_url($backend_zip),
                            esc_url($downloads)
                        )
                    );
                    ?>
                </p>
                <ol>
                    <li><?php echo esc_html__('Create a project at supabase.com (the free plan is enough to start).', 'site-context-chat'); ?></li>
                    <li><?php echo esc_html__('SQL Editor → paste schema.sql from the zip → Run.', 'site-context-chat'); ?></li>
                    <li><?php echo esc_html__('Edge Functions → Secrets: your AI key (ANTHROPIC_API_KEY, OPENAI_API_KEY or ZAI_API_KEY) and SITE_CHAT_ADMIN_SECRET (a long random password). Optional, for enquiry emails: RESEND_API_KEY, SITE_CHAT_NOTIFY_EMAIL, SITE_CHAT_FROM_EMAIL.', 'site-context-chat'); ?></li>
                    <li><?php echo esc_html__('Deploy the two functions with the Supabase CLI (commands in the README).', 'site-context-chat'); ?></li>
                    <li><?php echo esc_html__('Paste your Project URL, publishable key and admin secret above, turn the widget on and save.', 'site-context-chat'); ?></li>
                </ol>
            </div>
        </details>

        <details open>
            <summary><?php echo esc_html__('Teach it about your business', 'site-context-chat'); ?></summary>
            <div class="scc-setup-guide__body">
                <p>
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            /* translators: %s: Chat admin tab URL */
                            __('Open the <a href="%s">Chat admin</a> tab and add your knowledge base: services, prices, opening hours, FAQs, in plain Markdown. The chat answers from it and offers to pass on an enquiry when it can\'t help.', 'site-context-chat'),
                            esc_url($admin_tab_url)
                        )
                    );
                    ?>
                </p>
            </div>
        </details>

        <details>
            <summary><?php echo esc_html__('Troubleshooting', 'site-context-chat'); ?></summary>
            <div class="scc-setup-guide__body">
                <ul>
                    <li><?php echo esc_html__('The chat doesn\'t answer: check the AI key secret and that both functions are deployed.', 'site-context-chat'); ?></li>
                    <li><?php echo esc_html__('"Unauthorized" in the Chat admin: the admin secret here doesn\'t match SITE_CHAT_ADMIN_SECRET (or your Kontor admin secret).', 'site-context-chat'); ?></li>
                    <li><?php echo esc_html__('No chat button: tick "Show chat widget", check the URL and key, and that a caching plugin isn\'t serving an old page.', 'site-context-chat'); ?></li>
                </ul>
            </div>
        </details>
    </div>
    <?php
}
