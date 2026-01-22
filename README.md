# af_filter_enclosures

A TT-RSS plugin that filters enclosures from API responses based on the feed's `always_display_enclosures` setting.

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

## Bug Fix (2026-01-21)

**Issue:** Plugin was crashing silently with database access error.

**Symptoms:**
- Duplicate images still appeared despite plugin enabled
- No error messages visible to user
- Plugin appeared to be installed and enabled correctly

**Root Cause:**
Line 65 incorrectly accessed `$this->host->pdo` (private property):
```php
$sth = $this->host->pdo->prepare("SELECT always_display_enclosures FROM ttrss_feeds WHERE id = ?");
```

This caused PHP error: `Cannot access private property PluginHost::$pdo`

**Fix:**
Changed to use inherited protected property:
```php
$sth = $this->pdo->prepare("SELECT always_display_enclosures FROM ttrss_feeds WHERE id = ?");
```

**How to verify fix:**
```bash
# Check for errors (should be none after fix applied)
docker compose exec db psql -U postgres -d postgres -c "
SELECT COUNT(*) FROM ttrss_error_log WHERE errstr LIKE '%filter_enclosures%';"
```

If you see errors, they should be dated before 2026-01-21. No new errors should appear after plugin update.

## Testing

The plugin includes a comprehensive test suite with 7 tests covering:
- Return value structure (headline/article wrapper)
- Attachment filtering based on `always_display_attachments` setting
- Database fallback when setting not in API response
- Edge cases (null articles, missing feed_id)

**Key Test:** `test_returns_properly_structured_row_for_headline()` - This test catches the critical return value bug that was causing duplicate images.

### Run Tests

#### Option 1: Docker (Recommended)

No PHP installation required:

```bash
docker run --rm -v /home/jayemar/projects/af_filter_enclosures:/app -w /app php:8.1-cli bash -c "
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer &&
  composer install &&
  vendor/bin/phpunit --testdox
"
```

#### Option 2: Local (Requires PHP + Composer)

```bash
cd /home/jayemar/projects/af_filter_enclosures
composer install
./vendor/bin/phpunit --testdox
```

### Test Output

Successful run shows:
```
PHPUnit 9.5.x

......................                                            7 / 7 (100%)

Time: 00:00.050, Memory: 6.00 MB

OK (7 tests, 15 assertions)
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
