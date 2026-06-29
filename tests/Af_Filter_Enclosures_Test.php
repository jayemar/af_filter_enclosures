<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Filter_Enclosures;

/**
 * Test suite for Af_Filter_Enclosures plugin
 *
 * These tests verify that the plugin correctly:
 * 1. Returns properly structured $row (not just the article)
 * 2. Removes attachments when always_display_attachments = false
 * 3. Preserves attachments when always_display_attachments = true
 * 4. Handles edge cases (null articles, missing feed_id)
 */
class Af_Filter_Enclosures_Test extends TestCase {

    private $plugin;
    private $mockHost;
    private $mockPdo;
    private $mockStatement;

    protected function setUp(): void {
        // Mock the PluginHost
        $this->mockHost = $this->createMock(\PluginHost::class);

        // Mock PDO and PDOStatement
        $this->mockPdo = $this->createMock(\PDO::class);
        $this->mockStatement = $this->createMock(\PDOStatement::class);

        // Assign mocked PDO to host
        $this->mockHost->pdo = $this->mockPdo;

        // Suppress the host->add_hook call during init
        $this->mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);

        // Create plugin instance
        $this->plugin = new Af_Filter_Enclosures();
        $this->plugin->init($this->mockHost);
    }

    /**
     * Test 1: Return Value Structure - Headline Format
     *
     * THIS TEST WOULD HAVE CAUGHT THE BUG
     * The bug returned just $article instead of $row with the headline wrapper
     */
    public function test_returns_properly_structured_row_for_headline() {
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'feed_id' => 123,
                'attachments' => [['href' => 'image.jpg']],
                'always_display_attachments' => false
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        // Critical assertions that would have caught the bug
        $this->assertIsArray($result, 'Result should be an array');
        $this->assertArrayHasKey('headline', $result, 'Result must have headline key (bug returned just article)');
        $this->assertArrayNotHasKey('attachments', $result['headline'], 'Attachments should be removed when always_display_attachments=false');
    }

    /**
     * Test 2: Return Value Structure - Article Format
     *
     * THIS TEST WOULD HAVE CAUGHT THE BUG
     * The bug returned just $article instead of $row with the article wrapper
     */
    public function test_returns_properly_structured_row_for_article() {
        $row = [
            'article' => [
                'title' => 'Test Article',
                'feed_id' => 456,
                'attachments' => [['href' => 'image.jpg']],
                'always_display_attachments' => false
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        // Critical assertions that would have caught the bug
        $this->assertIsArray($result, 'Result should be an array');
        $this->assertArrayHasKey('article', $result, 'Result must have article key (bug returned just article content)');
        $this->assertArrayNotHasKey('attachments', $result['article'], 'Attachments should be removed when always_display_attachments=false');
    }

    /**
     * Test 3: Attachments Removed When always_display_attachments = false
     *
     * Verifies the core functionality: filtering attachments based on feed setting
     */
    public function test_removes_attachments_when_always_display_false() {
        $row = [
            'headline' => [
                'title' => 'Article With Images',
                'feed_id' => 789,
                'attachments' => [
                    ['href' => 'image1.jpg', 'type' => 'image/jpeg'],
                    ['href' => 'image2.jpg', 'type' => 'image/jpeg']
                ],
                'always_display_attachments' => false
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertArrayHasKey('headline', $result);
        $this->assertArrayNotHasKey('attachments', $result['headline'], 'Attachments should be filtered out');
        $this->assertEquals('Article With Images', $result['headline']['title'], 'Other article data should be preserved');
    }

    /**
     * Test 4: Attachments Preserved When always_display_attachments = true
     *
     * Verifies that attachments are NOT removed when the setting is true
     */
    public function test_preserves_attachments_when_always_display_true() {
        $attachments = [
            ['href' => 'image1.jpg', 'type' => 'image/jpeg'],
            ['href' => 'image2.jpg', 'type' => 'image/jpeg']
        ];

        $row = [
            'headline' => [
                'title' => 'Article With Images',
                'feed_id' => 321,
                'attachments' => $attachments,
                'always_display_attachments' => true
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertArrayHasKey('headline', $result);
        $this->assertArrayHasKey('attachments', $result['headline'], 'Attachments should be preserved when always_display=true');
        $this->assertEquals($attachments, $result['headline']['attachments'], 'Attachments should be unchanged');
    }

    /**
     * Test 5: Null Article Handling
     *
     * Verifies graceful handling when article data is missing
     */
    public function test_handles_null_article_gracefully() {
        $row = [];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertEquals($row, $result, 'Empty row should be returned unchanged');
    }

    /**
     * Test 6: Database Fallback When always_display_attachments Not Provided
     *
     * Verifies that plugin queries database when the setting isn't in API response
     */
    public function test_fetches_setting_from_database_when_not_provided() {
        $row = [
            'headline' => [
                'title' => 'Article Without Setting',
                'feed_id' => 555,
                'attachments' => [['href' => 'image.jpg']]
                // Note: always_display_attachments is missing
            ]
        ];

        // Mock database query behavior
        $this->mockPdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT always_display_enclosures FROM ttrss_feeds WHERE id = ?')
            ->willReturn($this->mockStatement);

        $this->mockStatement->expects($this->once())
            ->method('execute')
            ->with([555]);

        // Mock database returning false (don't display enclosures)
        $this->mockStatement->expects($this->once())
            ->method('fetch')
            ->willReturn(['always_display_enclosures' => false]);

        // Mock the sql_bool_to_bool function (TT-RSS helper)
        if (!function_exists('sql_bool_to_bool')) {
            function sql_bool_to_bool($val) {
                return (bool)$val;
            }
        }

        // Mock Debug class if not available
        if (!class_exists('Debug')) {
            eval('class Debug {
                const LOG_VERBOSE = 1;
                static function log($msg, $level = 0) {}
            }');
        }

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertArrayHasKey('headline', $result);
        $this->assertArrayNotHasKey('attachments', $result['headline'],
            'Attachments should be removed based on database setting');
    }

    /**
     * Test 7a: og:thumbnail Preserved When always_display_attachments = false
     *
     * og:thumbnail attachments are added by af_enhance_images as fallback thumbnails
     * for list-view display. They must be kept even when always_display_enclosures=false
     * because they don't cause duplicate-image issues (they're not RSS enclosures).
     */
    public function test_preserves_og_thumbnail_attachments_when_always_display_false() {
        // The hook returns the UNWRAPPED article (not the $row wrapper).
        // og:thumbnail enclosures are added by af_enhance_images as fallback thumbnails
        // and must survive even when always_display_enclosures=false, because they
        // don't duplicate inline content - they're standalone list-view thumbnails.
        $row = [
            'headline' => [
                'title' => 'Article With No RSS Enclosures',
                'feed_id' => 789,
                'attachments' => [
                    ['content_url' => 'https://example.com/og-image.jpg',
                     'content_type' => 'image/jpeg',
                     'title' => 'og:thumbnail',
                     'duration' => '0', 'width' => 0, 'height' => 0]
                ],
                'always_display_attachments' => false
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        // hook returns unwrapped article
        $this->assertArrayHasKey('attachments', $result,
            'og:thumbnail attachment must be preserved even when always_display=false');
        $this->assertCount(1, $result['attachments']);
        $this->assertEquals('og:thumbnail', $result['attachments'][0]['title']);
    }

    /**
     * Test 7b: Regular RSS Enclosures Still Stripped When always_display_attachments = false
     *
     * Regular enclosures (without the og:thumbnail marker) must still be stripped
     * to prevent duplicate images in feeds with inline content.
     */
    public function test_strips_regular_attachments_but_keeps_og_thumbnail() {
        $row = [
            'headline' => [
                'title' => 'Article With Mixed Attachments',
                'feed_id' => 789,
                'attachments' => [
                    ['content_url' => 'https://example.com/rss-enclosure.jpg',
                     'content_type' => 'image/jpeg',
                     'title' => 'Some image',
                     'duration' => '0', 'width' => 0, 'height' => 0],
                    ['content_url' => 'https://example.com/og-image.jpg',
                     'content_type' => 'image/jpeg',
                     'title' => 'og:thumbnail',
                     'duration' => '0', 'width' => 0, 'height' => 0]
                ],
                'always_display_attachments' => false
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        // hook returns unwrapped article
        $this->assertArrayHasKey('attachments', $result);
        $this->assertCount(1, $result['attachments'],
            'Only the og:thumbnail attachment should remain');
        $this->assertEquals('og:thumbnail', $result['attachments'][0]['title'],
            'The preserved attachment should be the og:thumbnail');
    }

    /**
     * Test 7: Missing feed_id Fallback
     *
     * Verifies default behavior when no feed_id is available
     */
    public function test_defaults_to_keeping_attachments_when_no_feed_id() {
        $attachments = [['href' => 'image.jpg']];

        $row = [
            'headline' => [
                'title' => 'Article Without Feed ID',
                'attachments' => $attachments
                // Note: no feed_id and no always_display_attachments
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertArrayHasKey('headline', $result);
        $this->assertArrayHasKey('attachments', $result['headline'],
            'Attachments should be preserved when feed_id is missing (safe default)');
        $this->assertEquals($attachments, $result['headline']['attachments']);
    }
}
