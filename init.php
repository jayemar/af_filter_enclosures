<?php
/**
 * af_filter_enclosures - Filter enclosures based on feed settings
 *
 * This plugin respects the "always_display_enclosures" feed setting in API
 * responses. When a feed has this setting disabled, enclosures (attachments)
 * are removed from API responses, preventing duplicate images in clients
 * like Capy Reader.
 *
 * Problem:
 * - Some feeds (e.g., Lemmy) include images as both inline <img> tags AND
 *   as RSS enclosures/<media:content>
 * - TT-RSS's "always_display_enclosures" setting only affects the web UI
 * - API clients (via FreshAPI) still receive all enclosures
 * - This causes duplicate images in mobile RSS readers
 *
 * Solution:
 * - Hook into HOOK_RENDER_ARTICLE_API
 * - Check the feed's always_display_attachments setting
 * - Remove attachments from API response if setting is false
 *
 * Installation:
 * 1. Copy this directory to plugins.local/af_filter_enclosures/
 * 2. Enable the plugin in Preferences -> Plugins
 */
class Af_Filter_Enclosures extends Plugin {

    private $host;

    function about() {
        return array(
            1.0,
            "Filter enclosures in API based on feed's always_display_enclosures setting",
            "jayemar"
        );
    }

    function init($host) {
        $this->host = $host;
        $host->add_hook($host::HOOK_RENDER_ARTICLE_API, $this);
    }

    /**
     * Hook: Filter enclosures from API response based on feed setting
     *
     * @param array $row Contains either 'headline' or 'article' key
     * @return array The modified headline/article
     */
    function hook_render_article_api($row) {
        // Extract the article/headline from the wrapper
        $is_headline = isset($row['headline']);
        $article = $is_headline ? $row['headline'] : ($row['article'] ?? null);

        if (!$article) {
            return $row;
        }

        // Check if we should filter enclosures
        // The setting is 'always_display_attachments' in API responses
        // (mapped from feed's 'always_display_enclosures' database column)
        $always_display = $article['always_display_attachments'] ?? null;

        // If not provided by API (getArticle case), fetch from database
        if ($always_display === null && isset($article['feed_id'])) {
            $sth = $this->host->pdo->prepare("SELECT always_display_enclosures FROM ttrss_feeds WHERE id = ?");
            $sth->execute([$article['feed_id']]);

            if ($row = $sth->fetch()) {
                // Convert database boolean to PHP boolean
                $always_display = sql_bool_to_bool($row['always_display_enclosures']);

                Debug::log("af_filter_enclosures: Fetched setting from DB for feed " .
                    $article['feed_id'] . ": always_display_enclosures=" .
                    ($always_display ? 'true' : 'false'), Debug::LOG_VERBOSE);
            } else {
                $always_display = true; // fallback if feed not found
            }
        } else if ($always_display === null) {
            $always_display = true; // fallback if no feed_id
        }

        if (!$always_display && isset($article['attachments'])) {
            // Remove attachments from the response
            unset($article['attachments']);

            Debug::log("af_filter_enclosures: Removed attachments for article: " .
                ($article['title'] ?? 'unknown') . " (feed setting: always_display_enclosures=false)",
                Debug::LOG_VERBOSE);
        }

        return $article;
    }

    function api_version() {
        return 2;
    }
}
