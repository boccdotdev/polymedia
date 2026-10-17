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

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use craft\helpers\App;
use yii\base\Component;

/**
 * Bunny Stream client for one video library and its native playback host.
 *
 * Credentials come from plugin settings. The API key must be an environment
 * reference; literal keys are treated as unset so they can't reach project
 * config or the API.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class Bunny extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns whether the library ID, API key and native delivery hostname all
     * resolve to valid values.
     *
     * @return bool
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function isConfigured(): bool
    {
        try {
            self::validateLibraryId($this->getLibraryId());
            self::validateHostname($this->getCdnHostname());
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $this->getApiKey() !== '';
    }

    /**
     * Returns the resolved Bunny Stream library ID.
     *
     * @return string an empty string when unset
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getLibraryId(): string
    {
        return $this->_setting('bunnyLibraryId');
    }

    /**
     * Returns the resolved native delivery hostname, lowercased.
     *
     * @return string an empty string when unset
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getCdnHostname(): string
    {
        return strtolower($this->_setting('bunnyCdnHostname'));
    }

    /**
     * Validates a Bunny Stream library ID.
     *
     * @param string $id the library ID
     * @return string the valid ID
     * @throws \InvalidArgumentException when the ID isn't a positive integer
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function validateLibraryId(string $id): string
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Bunny library ID.');
        }

        return $id;
    }

    /**
     * Validates a Bunny Stream video ID (a lowercase GUID).
     *
     * @param string $id the video ID
     * @return string the valid ID
     * @throws \InvalidArgumentException when the ID isn't a GUID
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function validateVideoId(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Bunny video ID.');
        }

        return $id;
    }

    /**
     * Validates a native Bunny delivery hostname.
     *
     * Only `*.b-cdn.net` hosts pass. That also keeps server-side probes away
     * from arbitrary hosts, private addresses, ports, paths and userinfo.
     *
     * @param string $host the hostname
     * @return string the valid hostname
     * @throws \InvalidArgumentException when the host isn't a native Bunny host
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function validateHostname(string $host): string
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.b-cdn\.net$/D', $host)) {
            throw new \InvalidArgumentException('Use the native Bunny CDN hostname, such as vz-example.b-cdn.net, without a scheme or path.');
        }

        return $host;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns the resolved library API key, or an empty string when the
     * setting isn't a set environment reference.
     *
     * @return string
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    protected function getApiKey(): string
    {
        return $this->_setting('bunnyApiKey', true);
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves a Bunny setting.
     *
     * @param string $name the settings attribute
     * @param bool $secret whether only an environment reference is accepted
     * @return string the trimmed value, or an empty string when unset
     */
    private function _setting(string $name, bool $secret = false): string
    {
        $raw = (string)(Plugin::getInstance()->getSettings()->$name ?? '');

        if ($raw === '' || ($secret && !preg_match(Settings::ENV_REFERENCE_PATTERN, $raw))) {
            return '';
        }

        $value = App::parseEnv($raw);

        if (!is_string($value) || ($value === $raw && str_starts_with($raw, '$'))) {
            return '';
        }

        return trim($value);
    }
}
