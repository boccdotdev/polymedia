<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * Universal media field for Craft CMS — HLS, YouTube, Vimeo, Spotify, MP4
 * and audio as first-class assets, with Media Chrome compatible player rendering.
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\behaviors;

use boccdotdev\polymedia\Plugin;
use craft\elements\Asset;
use Twig\Markup;
use yii\base\Behavior;

/**
 * Adds polymedia accessor methods to {@see Asset} elements.
 *
 * Lets templates call player/element/data/poster/tracks/transcript directly
 * on the asset, e.g. `asset.getPlayer()` or `asset.poster`.
 *
 * @property-read Asset $owner
 * @author boccdotdev
 * @since 1.0.2
 */
class PolymediaAssetBehavior extends Behavior
{
    // Public Methods
    // =========================================================================

    /**
     * Renders a full `<media-controller>` player for the asset.
     *
     * @param array $options rendering options
     * @return Markup
     *
     * @author boccdotdev
     * @since 1.0.2
     */
    public function getPlayer(array $options = []): Markup
    {
        return Plugin::getInstance()->getRenderer()->player($this->owner, $options);
    }

    /**
     * Renders just the media element (no `<media-controller>` wrapper).
     *
     * @param array $options rendering options
     * @return Markup
     *
     * @author boccdotdev
     * @since 1.0.2
     */
    public function getElement(array $options = []): Markup
    {
        return Plugin::getInstance()->getRenderer()->element($this->owner, $options);
    }

    /**
     * Returns the parsed manifest data for the asset.
     *
     * @return array
     *
     * @author boccdotdev
     * @since 1.0.2
     */
    public function getData(): array
    {
        return Plugin::getInstance()->getRenderer()->data($this->owner);
    }

    /**
     * Returns the resolved poster URL for the asset.
     *
     * @return ?string
     *
     * @author boccdotdev
     * @since 1.0.2
     */
    public function getPoster(): ?string
    {
        return Plugin::getInstance()->getRenderer()->poster($this->owner);
    }

    /**
     * Returns track-type related assets (captions, subtitles, descriptions).
     *
     * @param string $role the track role
     * @param ?int $siteId optional site filter
     * @return Asset[]
     *
     * @author boccdotdev
     * @since 1.0.2
     */
    public function getTracks(string $role = 'captions', ?int $siteId = null): array
    {
        return Plugin::getInstance()->getRenderer()->tracks($this->owner, $role, $siteId);
    }

    /**
     * Returns the transcript related asset.
     *
     * @return ?Asset
     *
     * @author boccdotdev
     * @since 1.0.2
     */
    public function getTranscript(): ?Asset
    {
        return Plugin::getInstance()->getRenderer()->transcript($this->owner);
    }

    /**
     * Whether the asset is a polymedia asset.
     *
     * @return bool
     *
     * @author boccdotdev
     * @since 1.0.2
     */
    public function getIsPolymedia(): bool
    {
        return $this->owner->kind === 'polymedia';
    }
}
