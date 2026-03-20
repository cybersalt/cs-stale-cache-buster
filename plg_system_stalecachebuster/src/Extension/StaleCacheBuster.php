<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\StaleCacheBuster\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

class StaleCacheBuster extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRender' => 'onAfterRender',
        ];
    }

    public function onAfterRender(): void
    {
        $app = $this->getApplication();

        // Only run on the frontend site
        if (!$app->isClient('site')) {
            return;
        }

        $body = $app->getBody();

        if (empty($body)) {
            return;
        }

        $bustCss      = (int) $this->params->get('bust_css', 1);
        $bustJs       = (int) $this->params->get('bust_js', 1);
        $skipExisting = (int) $this->params->get('skip_existing', 1);

        if ($bustCss) {
            $body = $this->bustCssUrls($body, $skipExisting);
        }

        if ($bustJs) {
            $body = $this->bustJsUrls($body, $skipExisting);
        }

        $app->setBody($body);
    }

    /**
     * Add cache-busting query strings to CSS link hrefs.
     */
    private function bustCssUrls(string $body, int $skipExisting): string
    {
        // Match <link ... href="..." ... > tags with rel="stylesheet"
        $pattern = '/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($match) use ($skipExisting) {
            $tag = $match[0];

            // Extract the href value
            if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                return $tag;
            }

            $url = $hrefMatch[1];
            $newUrl = $this->bustUrl($url, $skipExisting);

            if ($newUrl === $url) {
                return $tag;
            }

            return str_replace($hrefMatch[1], $newUrl, $tag);
        }, $body) ?? $body;
    }

    /**
     * Add cache-busting query strings to JS script srcs.
     */
    private function bustJsUrls(string $body, int $skipExisting): string
    {
        // Match <script ... src="..." ... >
        $pattern = '/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($match) use ($skipExisting) {
            $tag = $match[0];
            $url = $match[1];
            $newUrl = $this->bustUrl($url, $skipExisting);

            if ($newUrl === $url) {
                return $tag;
            }

            return str_replace($url, $newUrl, $tag);
        }, $body) ?? $body;
    }

    /**
     * Append a file-modification timestamp to a local URL if it lacks a query string.
     */
    private function bustUrl(string $url, int $skipExisting): string
    {
        // Skip external URLs (protocol-relative or absolute with host)
        if (preg_match('#^(https?:)?//#i', $url)) {
            return $url;
        }

        // Skip data: URIs
        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        // Check if URL already has a query string
        if ($skipExisting && str_contains($url, '?')) {
            return $url;
        }

        // Resolve the file path on disk
        $filePath = $this->resolveFilePath($url);

        if ($filePath === null || !is_file($filePath)) {
            return $url;
        }

        $mtime = @filemtime($filePath);

        if ($mtime === false) {
            return $url;
        }

        // Use a short hex hash of the modification time for cleaner URLs
        $version = dechex($mtime);

        // Append or update the version parameter
        if (str_contains($url, '?')) {
            return $url . '&v=' . $version;
        }

        return $url . '?v=' . $version;
    }

    /**
     * Resolve a relative URL to an absolute file path on disk.
     */
    private function resolveFilePath(string $url): ?string
    {
        // Remove any query string or fragment for path resolution
        $cleanUrl = strtok($url, '?#');

        if (empty($cleanUrl)) {
            return null;
        }

        // Remove leading slash
        $relativePath = ltrim($cleanUrl, '/');

        // Build absolute path from JPATH_ROOT
        $absolutePath = JPATH_ROOT . '/' . $relativePath;

        // Normalize directory separators
        $absolutePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath);

        // Security: ensure the resolved path is within JPATH_ROOT
        $realPath = realpath($absolutePath);
        $realRoot = realpath(JPATH_ROOT);

        if ($realPath === false || $realRoot === false) {
            return null;
        }

        if (!str_starts_with($realPath, $realRoot)) {
            return null;
        }

        return $realPath;
    }
}
