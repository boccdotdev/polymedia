<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\Mux;
use MuxPhp\Models\Asset;
use MuxPhp\Models\AssetMetadata;
use MuxPhp\Models\PlaybackID;
use MuxPhp\Models\PlaybackPolicy;
use MuxPhp\Models\Upload;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Mux service helpers that do not require Craft bootstrap.
 *
 * @author boccdotdev
 * @since 2.0.0
 */
class MuxServiceTest extends TestCase
{
    // Private Properties
    // =========================================================================

    /**
     * @var Mux
     */
    private Mux $_mux;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();
        $this->_mux = new Mux();
    }

    public function testFirstFrameThumbnailUrlIncludesTimeZero(): void
    {
        $this->assertSame(
            'https://image.mux.com/abc123/thumbnail.jpg?time=0',
            $this->_mux->firstFrameThumbnailUrl('abc123'),
        );
    }

    public function testMapUpload(): void
    {
        $upload = new Upload([
            'id' => 'upload_1',
            'url' => 'https://storage.example/upload',
            'status' => Upload::STATUS_WAITING,
            'asset_id' => null,
        ]);

        $this->assertSame(
            [
                'uploadId' => 'upload_1',
                'uploadUrl' => 'https://storage.example/upload',
                'status' => 'waiting',
                'assetId' => null,
            ],
            $this->_mux->mapUpload($upload),
        );
    }

    public function testMapAssetPrefersPublicPlaybackId(): void
    {
        $asset = new Asset([
            'id' => 'asset_1',
            'status' => Asset::STATUS_READY,
            'duration' => 12.5,
            'aspect_ratio' => '16:9',
            'created_at' => '1700000000',
            'meta' => new AssetMetadata(['title' => 'Demo']),
            'playback_ids' => [
                new PlaybackID([
                    'id' => 'signed_id',
                    'policy' => PlaybackPolicy::SIGNED,
                ]),
                new PlaybackID([
                    'id' => 'public_id',
                    'policy' => PlaybackPolicy::_PUBLIC,
                ]),
            ],
        ]);

        $mapped = $this->_mux->mapAsset($asset);

        $this->assertSame('asset_1', $mapped['assetId']);
        $this->assertSame('public_id', $mapped['playbackId']);
        $this->assertSame('public', $mapped['playbackPolicy']);
        $this->assertSame('Demo', $mapped['title']);
        $this->assertSame('ready', $mapped['status']);
        $this->assertSame(12.5, $mapped['duration']);
        $this->assertSame(
            'https://image.mux.com/public_id/thumbnail.jpg?time=0',
            $mapped['thumbnailUrl'],
        );
    }
}
