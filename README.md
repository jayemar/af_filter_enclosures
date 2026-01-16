# af_filter_enclosures

A TT-RSS plugin that filters enclosures from API responses based on the feed's `always_display_enclosures` setting.

## Problem

TT-RSS has a per-feed setting called `always_display_enclosures` that controls whether RSS enclosures (attachments) are shown in the web UI. However, this setting is **not respected by the API** - plugins like FreshAPI always receive all enclosures regardless of this setting.

This causes duplicate images in mobile RSS readers like Capy Reader when a feed includes the same image both:
1. As an inline `<img>` tag in the HTML content
2. As an RSS `<enclosure>` or `<media:content>` element

Example feeds affected:
- Lemmy RSS feeds (like Calvin and Hobbes)
- Many podcast feeds that embed cover art in content AND as enclosures

## Solution

This plugin hooks into `HOOK_RENDER_ARTICLE_API` to intercept API responses before they're sent to clients. When a feed has `always_display_enclosures = false`, the plugin removes the `attachments` array from the API response.

This approach:
- Works with any API client (FreshAPI, Fever, etc.)
- Doesn't require modifying any existing plugins
- Respects the existing TT-RSS setting
- Is fully reproducible on new installations

## Installation

### Option 1: Copy directly

```bash
cp -r af_filter_enclosures /path/to/tt-rss/plugins.local/
```

### Option 2: For Docker with manage-plugins.sh

Add to your `plugins.conf`:

```
/path/to/af_filter_enclosures
```

Or if hosted on a git repository:

```
https://gitlab.com/jayemar/af_filter_enclosures.git
```

Then run:

```bash
./manage-plugins.sh
```

### Option 3: Docker volume mount

Add to `docker-compose.yaml`:

```yaml
services:
  app:
    volumes:
      - ./plugins/af_filter_enclosures:/var/www/html/tt-rss/plugins.local/af_filter_enclosures:ro
```

## Enabling the Plugin

1. Log into TT-RSS as admin
2. Go to Preferences -> Plugins
3. Enable "af_filter_enclosures"
4. Save

## Configuration

No configuration needed. The plugin automatically uses the existing `always_display_enclosures` feed setting.

To disable enclosures for a specific feed:

```sql
UPDATE ttrss_feeds SET always_display_enclosures = false WHERE id = <FEED_ID>;
```

Or use the smart enclosure settings script to automatically configure all feeds:

```bash
docker compose cp scripts/smart-enclosure-settings.sql db:/tmp/
docker compose exec db psql -U postgres -d postgres -f /tmp/smart-enclosure-settings.sql
```

## How It Works

The plugin intercepts `HOOK_RENDER_ARTICLE_API` which is called for every article/headline returned via the API:

```php
function hook_render_article_api($row) {
    // Extract article from wrapper
    $is_headline = isset($row['headline']);
    $article = $is_headline ? $row['headline'] : ($row['article'] ?? null);

    // Check feed's always_display_attachments setting
    // (API uses 'always_display_attachments', DB uses 'always_display_enclosures')
    $always_display = $article['always_display_attachments'] ?? true;

    if (!$always_display && isset($article['attachments'])) {
        // Remove attachments from API response
        unset($article['attachments']);
    }

    return $article;
}
```

## Verification

After enabling, test with a feed that has `always_display_enclosures = false`:

1. Check the feed setting:
   ```sql
   SELECT id, title, always_display_enclosures
   FROM ttrss_feeds WHERE always_display_enclosures = false;
   ```

2. Force sync in your mobile app

3. Verify articles from that feed no longer show duplicate images

You can also check the TT-RSS debug log for messages like:
```
af_filter_enclosures: Removed attachments for article: Article Title (feed setting: always_display_enclosures=false)
```

## Notes

- This plugin affects **all API clients** (FreshAPI, Fever, etc.)
- The setting is per-feed, not global
- Enclosures are still stored in the database - they're just filtered from API responses
- Audio/video enclosures (podcasts) are also filtered if the setting is false

## Related

- `af_fix_enclosure_type` - Fixes enclosures with empty content_type
- `af_remove_lazy_loading` - Removes lazy loading from images

## License

MIT License

## Author

jayemar
