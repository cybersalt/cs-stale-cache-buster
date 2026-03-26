<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 3
 */

namespace Cybersalt\Plugin\System\StaleCacheBuster\Extension;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

class StaleCacheBuster extends CMSPlugin implements SubscriberInterface
{
    /**
     * Cached array of exclusion path patterns.
     */
    private ?array $excludePatterns = null;

    /**
     * Debug log of modifications made during this request.
     */
    private array $debugLog = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterInitialise'        => 'onAfterInitialise',
            'onAfterRender'            => 'onAfterRender',
            'onAjaxStalecachebuster'   => 'onAjaxStalecachebuster',
        ];
    }

    /**
     * Send no-cache headers for the administrator backend.
     */
    public function onAfterInitialise(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        if (!(int) $this->params->get('no_cache_admin', 0)) {
            return;
        }

        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $app->setHeader('Pragma', 'no-cache', true);
        $app->setHeader('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT', true);
    }

    public function onAfterRender(): void
    {
        $app = $this->getApplication();

        // Only run cache-busting on the frontend site
        if (!$app->isClient('site')) {
            return;
        }

        // Only run on HTML document types
        $document = $app->getDocument();
        if ($document->getType() !== 'html') {
            return;
        }

        $body = $app->getBody();

        if (empty($body)) {
            return;
        }

        $bustCss      = (int) $this->params->get('bust_css', 1);
        $bustJs       = (int) $this->params->get('bust_js', 1);
        $skipExisting = (int) $this->params->get('skip_existing', 1);
        $debugMode    = (int) $this->params->get('debug_mode', 0);
        $enableLog    = (int) $this->params->get('enable_logging', 0);

        $pageUrl = Uri::getInstance()->toString();

        if ($bustCss) {
            $body = $this->bustCssUrls($body, $skipExisting);
        }

        if ($bustJs) {
            $body = $this->bustJsUrls($body, $skipExisting);
        }

        // In debug mode, append a summary as an HTML comment before </body>
        if ($debugMode && !empty($this->debugLog)) {
            $comment = "\n<!-- CS Stale Cache Buster Debug Log\n";
            $comment .= "     Modified " . count($this->debugLog) . " URL(s):\n";
            foreach ($this->debugLog as $entry) {
                $comment .= "     " . $entry['original'] . "\n";
                $comment .= "       => " . $entry['modified'] . "\n";
            }
            $comment .= "-->\n";
            $body = str_replace('</body>', $comment . '</body>', $body);
        } elseif ($debugMode) {
            $body = str_replace('</body>', "\n<!-- CS Stale Cache Buster: No URLs modified -->\n</body>", $body);
        }

        // Write to log file if logging is enabled
        if ($enableLog && !empty($this->debugLog)) {
            $this->writeLog($pageUrl, $this->debugLog);
        }

        $app->setBody($body);
    }

    // ---------------------------------------------------------------
    // Logging
    // ---------------------------------------------------------------

    /**
     * Get the path to the log file.
     */
    private function getLogFile(): string
    {
        return JPATH_ROOT . '/logs/stalecachebuster.log';
    }

    /**
     * Write a log entry for this request.
     */
    private function writeLog(string $pageUrl, array $modifications): void
    {
        $logFile = $this->getLogFile();
        $maxSize = 2 * 1024 * 1024; // 2 MB

        // Rotate if needed
        if (file_exists($logFile) && filesize($logFile) > $maxSize) {
            $backup = $logFile . '.old';
            @unlink($backup);
            @rename($logFile, $backup);
        }

        $entry = [
            'timestamp'     => date('Y-m-d H:i:s'),
            'page'          => $pageUrl,
            'modifications' => $modifications,
        ];

        $logLine = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    // ---------------------------------------------------------------
    // AJAX handler for log viewer
    // ---------------------------------------------------------------

    public function onAjaxStalecachebuster(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator')) {
            $this->sendJsonResponse(['error' => 'Access denied']);
            return;
        }

        $user = Factory::getApplication()->getIdentity();
        if (!$user->authorise('core.manage', 'com_plugins')) {
            $this->sendJsonResponse(['error' => 'Insufficient permissions']);
            return;
        }

        if (!Session::checkToken('get') && !Session::checkToken('post')) {
            $this->sendJsonResponse(['error' => 'Invalid token']);
            return;
        }

        $action = $app->getInput()->get('action', 'view', 'cmd');

        switch ($action) {
            case 'view':
                $result = $this->ajaxViewLog();
                break;

            case 'clear':
                $result = $this->ajaxClearLog();
                break;

            case 'download':
                $this->ajaxDownloadLog();
                return;

            case 'viewer':
                $this->loadLanguage();
                echo $this->ajaxRenderViewer();
                $app->close();
                return;

            default:
                $result = json_encode(['error' => 'Unknown action']);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo $result;
        $app->close();
    }

    private function sendJsonResponse(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        $this->getApplication()->close();
    }

    private function ajaxViewLog(): string
    {
        $logFile = $this->getLogFile();
        $input   = $this->getApplication()->getInput();
        $lines   = (int) $input->get('lines', 100, 'int');
        $offset  = (int) $input->get('offset', 0, 'int');

        if (!file_exists($logFile)) {
            return json_encode([
                'success' => true,
                'entries' => [],
                'total'   => 0,
                'message' => 'Log file does not exist yet.',
            ]);
        }

        $content  = file_get_contents($logFile);
        $allLines = array_filter(explode("\n", $content));
        $entries  = [];

        foreach ($allLines as $line) {
            $entry = json_decode($line, true);
            if ($entry) {
                $entries[] = $entry;
            }
        }

        // Newest first
        $entries = array_reverse($entries);
        $total   = count($entries);

        if ($offset > 0 || $lines > 0) {
            $entries = array_slice($entries, $offset, $lines);
        }

        return json_encode([
            'success' => true,
            'entries' => $entries,
            'total'   => $total,
            'offset'  => $offset,
            'limit'   => $lines,
        ]);
    }

    private function ajaxClearLog(): string
    {
        $logFile = $this->getLogFile();
        $existed = file_exists($logFile);

        if ($existed) {
            $result = @unlink($logFile);
            if (!$result) {
                @file_put_contents($logFile, '');
            }
        }

        // Also remove the .old backup
        @unlink($logFile . '.old');

        return json_encode([
            'success' => true,
            'message' => 'Log cleared.',
        ]);
    }

    private function ajaxDownloadLog(): void
    {
        $logFile = $this->getLogFile();

        if (!file_exists($logFile)) {
            echo '';
            $this->getApplication()->close();
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="stalecachebuster_' . date('Y-m-d_His') . '.log"');
        header('Content-Length: ' . filesize($logFile));
        readfile($logFile);
        $this->getApplication()->close();
    }

    private function ajaxRenderViewer(): string
    {
        $token      = Session::getFormToken();
        $ajaxUrl    = Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system&format=raw&' . $token . '=1';
        $lang       = Factory::getLanguage()->getTag();
        $logFilePath = $this->getLogFile();

        ob_start();
        include __DIR__ . '/../../tmpl/viewer.php';
        return ob_get_clean();
    }

    // ---------------------------------------------------------------
    // Cache-busting logic
    // ---------------------------------------------------------------

    /**
     * Add cache-busting query strings to CSS link hrefs.
     */
    private function bustCssUrls(string $body, int $skipExisting): string
    {
        $pattern = '/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($match) use ($skipExisting) {
            $tag = $match[0];

            if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                return $tag;
            }

            $url    = $hrefMatch[1];
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
        $pattern = '/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($match) use ($skipExisting) {
            $tag    = $match[0];
            $url    = $match[1];
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
        // Skip data: URIs
        if (str_starts_with($url, 'data:')) {
            return $url;
        }

        // Normalize full same-domain URLs to relative paths for processing
        $workingUrl = $this->normalizeSameDomainUrl($url);

        // Skip truly external URLs (still has protocol after normalization)
        if (preg_match('#^(https?:)?//#i', $workingUrl)) {
            return $url;
        }

        // Check if URL already has a query string
        if ($skipExisting && str_contains($workingUrl, '?')) {
            return $url;
        }

        // Check exclusion list
        if ($this->isExcluded($workingUrl)) {
            return $url;
        }

        // Resolve the file path on disk
        $filePath = $this->resolveFilePath($workingUrl);

        if ($filePath === null || !is_file($filePath)) {
            return $url;
        }

        $mtime = @filemtime($filePath);

        if ($mtime === false) {
            return $url;
        }

        $version = dechex($mtime);

        if (str_contains($url, '?')) {
            $newUrl = $url . '&v=' . $version;
        } else {
            $newUrl = $url . '?v=' . $version;
        }

        $this->debugLog[] = [
            'original' => $url,
            'modified' => $newUrl,
        ];

        return $newUrl;
    }

    /**
     * If a URL points to the same domain as this site, strip it down to a relative path.
     */
    private function normalizeSameDomainUrl(string $url): string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $siteUrl  = Uri::root();
        $siteHost = Uri::getInstance($siteUrl)->getHost();
        $urlHost  = Uri::getInstance($url)->getHost();

        if (strtolower($siteHost) !== strtolower($urlHost)) {
            return $url;
        }

        $root = Uri::root(true);
        $path = Uri::getInstance($url)->getPath();

        if (!empty($root) && str_starts_with($path, $root)) {
            $path = substr($path, strlen($root));
        }

        return ltrim($path, '/');
    }

    /**
     * Check if a URL path matches any exclusion pattern.
     */
    private function isExcluded(string $url): bool
    {
        if ($this->excludePatterns === null) {
            $this->excludePatterns = [];
            $excludeText = trim((string) $this->params->get('exclude_paths', ''));

            if (!empty($excludeText)) {
                $lines = array_filter(array_map('trim', explode("\n", $excludeText)));
                foreach ($lines as $line) {
                    if (str_starts_with($line, '#') || str_starts_with($line, ';')) {
                        continue;
                    }
                    $this->excludePatterns[] = $line;
                }
            }
        }

        $cleanUrl = strtok($url, '?#');

        foreach ($this->excludePatterns as $pattern) {
            if (str_contains($cleanUrl, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a relative URL to an absolute file path on disk.
     */
    private function resolveFilePath(string $url): ?string
    {
        $cleanUrl = strtok($url, '?#');

        if (empty($cleanUrl)) {
            return null;
        }

        $relativePath = ltrim($cleanUrl, '/');
        $absolutePath = JPATH_ROOT . '/' . $relativePath;
        $absolutePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absolutePath);

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
