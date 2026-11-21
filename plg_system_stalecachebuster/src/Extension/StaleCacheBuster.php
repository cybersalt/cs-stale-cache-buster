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

use Cybersalt\Plugin\System\StaleCacheBuster\Helper\SupportHelper;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\SubscriberInterface;

class StaleCacheBuster extends CMSPlugin implements SubscriberInterface
{
    /**
     * Log file names. They end in .php and start with a die() line, the way
     * Joomla's own logs do, so the web server never hands them out even
     * though the log folder sits inside the site.
     */
    public const LOG_NAME = 'stalecachebuster.log.php';

    public const LOG_OLD_NAME = 'stalecachebuster.old.log.php';

    public const LOG_GUARD = "#<?php die('Forbidden.'); ?>\n";

    /** Longest URL kept in the log; longer ones are cut. */
    private const LOG_MAX_URL = 2048;

    /** Log size that triggers rotation, and the most the viewer will read. */
    private const LOG_MAX_BYTES = 2 * 1024 * 1024;

    private const LOG_MAX_READ = 4 * 1024 * 1024;

    /**
     * Debug log of modifications made during this request.
     */
    private array $debugLog = [];

    /**
     * Cached list of template CSS paths to bust.
     */
    private ?array $templateCssPaths = null;

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

        if (!$app->isClient('site')) {
            return;
        }

        $document = $app->getDocument();
        if ($document->getType() !== 'html') {
            return;
        }

        $body = $app->getBody();

        if (empty($body)) {
            return;
        }

        $debugMode = (int) $this->params->get('debug_mode', 0);
        $enableLog = (int) $this->params->get('enable_logging', 0);
        // Path and query only: the host comes from the visitor's request.
        $pageUrl   = Uri::getInstance()->toString(['path', 'query']);

        // Only bust template CSS files
        $body = $this->bustTemplateCssUrls($body);

        // Debug comment
        if ($debugMode && !empty($this->debugLog)) {
            $comment = "\n<!-- CS Stale Cache Buster Debug Log\n";
            $comment .= "     Modified " . count($this->debugLog) . " template CSS URL(s):\n";
            foreach ($this->debugLog as $entry) {
                $comment .= "     " . $entry['original'] . "\n";
                $comment .= "       => " . $entry['modified'] . "\n";
            }
            $comment .= "-->\n";
            $body = str_replace('</body>', $comment . '</body>', $body);
        } elseif ($debugMode) {
            $body = str_replace('</body>', "\n<!-- CS Stale Cache Buster: No template CSS URLs modified -->\n</body>", $body);
        }

        if ($enableLog && !empty($this->debugLog)) {
            $this->writeLog($pageUrl, $this->debugLog);
        }

        $app->setBody($body);
    }

    // ---------------------------------------------------------------
    // Template CSS scanning and busting
    // ---------------------------------------------------------------

    /**
     * Recursively list the .css files below a directory, as relative paths
     * from the site root with forward slashes (templates/cassiopeia/css/x.css).
     * Plain PHP, so it does not depend on any Joomla filesystem class.
     *
     * @return  string[]
     */
    private function listCssFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $root  = rtrim(str_replace('\\', '/', JPATH_ROOT), '/') . '/';
        $files = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.css')) {
                    continue;
                }

                $path = str_replace('\\', '/', $file->getPathname());

                $files[] = str_starts_with($path, $root) ? substr($path, \strlen($root)) : $path;
            }
        } catch (\Throwable $e) {
            // An unreadable folder just means no CSS files from it.
        }

        sort($files);

        return $files;
    }

    /**
     * Get the list of CSS file paths from the active template that should be busted.
     * Returns relative paths like: templates/cybersalt/css/template.css
     */
    private function getTemplateCssPaths(): array
    {
        if ($this->templateCssPaths !== null) {
            return $this->templateCssPaths;
        }

        $template = $this->getApplication()->getTemplate();

        // The active template's own css folder, and the Joomla 4+ media location
        $this->templateCssPaths = array_merge(
            $this->listCssFiles(JPATH_ROOT . '/templates/' . $template . '/css'),
            $this->listCssFiles(JPATH_ROOT . '/media/templates/site/' . $template . '/css')
        );

        return $this->templateCssPaths;
    }

    /**
     * Bust only template CSS URLs in the rendered HTML.
     */
    private function bustTemplateCssUrls(string $body): string
    {
        $cssPaths = $this->getTemplateCssPaths();

        if (empty($cssPaths)) {
            return $body;
        }

        $pattern = '/<link\b[^>]*\brel=["\']stylesheet["\'][^>]*>/i';

        return preg_replace_callback($pattern, function ($match) use ($cssPaths) {
            $tag = $match[0];

            if (!preg_match('/\bhref=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                return $tag;
            }

            $url = $hrefMatch[1];

            // Normalize to relative path for comparison
            $workingUrl = $this->normalizeSameDomainUrl($url);

            // Strip any existing query string for path comparison
            $cleanUrl = strtok($workingUrl, '?#');
            $cleanUrl = ltrim($cleanUrl, '/');

            // Check if this URL matches any template CSS file
            $isTemplateCss = false;
            foreach ($cssPaths as $cssPath) {
                if ($cleanUrl === $cssPath) {
                    $isTemplateCss = true;
                    break;
                }
            }

            if (!$isTemplateCss) {
                return $tag;
            }

            // Resolve file and get modification time
            $filePath = JPATH_ROOT . '/' . $cleanUrl;
            $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);

            if (!is_file($filePath)) {
                return $tag;
            }

            $mtime = @filemtime($filePath);
            if ($mtime === false) {
                return $tag;
            }

            $version = dechex($mtime);

            // Replace or append version parameter
            if (str_contains($url, '?')) {
                $newUrl = $url . '&v=' . $version;
            } else {
                $newUrl = $url . '?v=' . $version;
            }

            $this->debugLog[] = [
                'original' => $url,
                'modified' => $newUrl,
            ];

            return str_replace($hrefMatch[1], $newUrl, $tag);
        }, $body) ?? $body;
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

    // ---------------------------------------------------------------
    // AJAX handler for template scan and log viewer
    // ---------------------------------------------------------------

    /**
     * Single entry point for the settings-page buttons and the log viewer
     * (index.php?option=com_ajax&plugin=stalecachebuster&group=system).
     *
     * Every action needs an administrator who can manage plugins and a valid
     * CSRF token. Actions that change anything (clear, delete) also need the
     * right to edit plugins, and must arrive as a POST.
     */
    public function onAjaxStalecachebuster(AjaxEvent $event): void
    {
        $app   = $this->getApplication();
        $input = $app->getInput();

        $this->loadLanguage();

        $action = $input->get('action', 'viewer', 'cmd');
        $isHtml = $action === 'viewer';

        if (!$app->isClient('administrator')) {
            $this->deny('PLG_SYSTEM_STALECACHEBUSTER_ERROR_ACCESS', 403, $isHtml, $event);
            return;
        }

        $user = $app->getIdentity();

        if (!$user || $user->guest || !$user->authorise('core.manage', 'com_plugins')) {
            $this->deny('PLG_SYSTEM_STALECACHEBUSTER_ERROR_ACCESS', 403, $isHtml, $event);
            return;
        }

        $changes = \in_array($action, ['clear', 'delete'], true);

        if ($changes) {
            if (strtoupper((string) $input->getMethod()) !== 'POST') {
                $this->deny('PLG_SYSTEM_STALECACHEBUSTER_ERROR_POST_ONLY', 405, false, $event);
                return;
            }

            if (!$user->authorise('core.edit', 'com_plugins')) {
                $this->deny('PLG_SYSTEM_STALECACHEBUSTER_ERROR_ACCESS', 403, false, $event);
                return;
            }

            if (!Session::checkToken('post')) {
                $this->deny('PLG_SYSTEM_STALECACHEBUSTER_ERROR_TOKEN', 403, false, $event);
                return;
            }
        } elseif (!Session::checkToken('get')) {
            $this->deny('PLG_SYSTEM_STALECACHEBUSTER_ERROR_TOKEN', 403, $isHtml, $event);
            return;
        }

        switch ($action) {
            case 'viewer':
                $event->addResult($this->renderViewer());
                return;

            case 'scan':
                $this->sendJson($this->scanTemplates());
                return;

            case 'dump':
                $this->sendText($this->buildDump(), 'text/plain; charset=utf-8');
                return;

            case 'csv':
                $this->sendText(
                    $this->buildCsv(),
                    'text/csv; charset=utf-8',
                    'stalecachebuster-log-' . date('Ymd-His') . '.csv'
                );
                return;

            case 'download':
                $this->downloadLog();
                return;

            case 'clear':
                $this->clearLog();
                return;

            case 'delete':
                $this->deleteEntries();
                return;
        }

        $this->deny('PLG_SYSTEM_STALECACHEBUSTER_ERROR_UNKNOWN_ACTION', 400, $isHtml, $event);
    }

    /**
     * Refuse a request with a translated message that ends with the support
     * contact sentence. JSON for the AJAX buttons, an alert box for the viewer.
     */
    private function deny(string $key, int $status, bool $html, AjaxEvent $event): void
    {
        $message = Text::sprintf($key, SupportHelper::getContactSentence());

        if ($html) {
            $this->getApplication()->setHeader('status', (string) $status, true);
            $event->addResult('<div class="alert alert-danger" role="alert">'
                . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>');

            return;
        }

        $this->sendJson(['success' => false, 'error' => $message], $status);
    }

    private function sendJson(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data);
        $this->getApplication()->close();
    }

    private function sendText(string $body, string $contentType, string $filename = ''): void
    {
        header('Content-Type: ' . $contentType);
        header('X-Content-Type-Options: nosniff');

        if ($filename !== '') {
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }

        echo $body;
        $this->getApplication()->close();
    }

    /**
     * Scan all installed site templates and return their CSS files.
     */
    private function scanTemplates(): array
    {
        $templates = [];

        // Scan /templates/ directory for site templates
        $templateDirs = array_filter(
            scandir(JPATH_ROOT . '/templates') ?: [],
            static fn (string $name): bool => $name[0] !== '.' && is_dir(JPATH_ROOT . '/templates/' . $name)
        );

        foreach ($templateDirs as $tplName) {
            // Skip system template
            if ($tplName === 'system') {
                continue;
            }

            $cssFiles = [];

            // /templates/{name}/css/ and /media/templates/site/{name}/css/
            $paths = array_merge(
                $this->listCssFiles(JPATH_ROOT . '/templates/' . $tplName . '/css'),
                $this->listCssFiles(JPATH_ROOT . '/media/templates/site/' . $tplName . '/css')
            );

            foreach ($paths as $relative) {
                $file  = JPATH_ROOT . '/' . $relative;
                $mtime = @filemtime($file);

                $cssFiles[] = [
                    'path'     => $relative,
                    'modified' => $mtime ? date('Y-m-d H:i:s', $mtime) : 'unknown',
                    'size'     => (int) @filesize($file),
                    'version'  => $mtime ? dechex($mtime) : '',
                ];
            }

            if (!empty($cssFiles)) {
                $templates[] = [
                    'name'  => $tplName,
                    'files' => $cssFiles,
                ];
            }
        }

        return [
            'success'   => true,
            'templates' => $templates,
        ];
    }

    // ---------------------------------------------------------------
    // Logging
    // ---------------------------------------------------------------

    /**
     * The log lives in Joomla's configured log folder (administrator/logs by
     * default) under a .php name with a die() first line, so it can't be
     * downloaded even though that folder is inside the site. Logs left by
     * older versions (plain .log files, some in the public /logs folder) are
     * moved across once, by the installer script on update.
     */
    private function getLogFile(bool $old = false): string
    {
        $dir = (string) $this->getApplication()->get('log_path', '');

        if ($dir === '') {
            $dir = JPATH_ADMINISTRATOR . '/logs';
        }

        return rtrim($dir, '/\\') . '/' . ($old ? self::LOG_OLD_NAME : self::LOG_NAME);
    }

    private function writeLog(string $pageUrl, array $modifications): void
    {
        $logFile = $this->getLogFile();
        $logDir  = \dirname($logFile);

        // Logging must never break a page: skip quietly when we can't write.
        if (!is_dir($logDir) && !@mkdir($logDir, 0755, true)) {
            return;
        }

        clearstatcache(true, $logFile);

        if (is_file($logFile) && filesize($logFile) > self::LOG_MAX_BYTES) {
            $backup = $this->getLogFile(true);
            @unlink($backup);

            // Locked or not allowed to rename: start again rather than let
            // the file grow without end.
            if (!@rename($logFile, $backup)) {
                @file_put_contents($logFile, '', LOCK_EX);
            }

            clearstatcache(true, $logFile);
        }

        $clip  = static fn ($url): string => substr((string) $url, 0, self::LOG_MAX_URL);
        $entry = [
            'timestamp'     => date('Y-m-d H:i:s'),
            'page'          => $clip($pageUrl),
            'modifications' => array_map(
                static fn (array $mod): array => [
                    'original' => $clip($mod['original'] ?? ''),
                    'modified' => $clip($mod['modified'] ?? ''),
                ],
                $modifications
            ),
        ];

        $logLine = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        $isNew   = !is_file($logFile) || filesize($logFile) === 0;

        if ($isNew ? !is_writable($logDir) : !is_writable($logFile)) {
            return;
        }

        @file_put_contents($logFile, ($isNew ? self::LOG_GUARD : '') . $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * Read the whole log, newest entry first. Every entry gets an id (a short
     * hash of its line) so rows can be selected and deleted.
     *
     * @return  array[]
     */
    private function readEntries(): array
    {
        $logFile = $this->getLogFile();

        if (!is_file($logFile)) {
            return [];
        }

        $entries = [];

        // Read at most the newest LOG_MAX_READ bytes; a cut first line is
        // simply not valid JSON and is skipped like the die() line.
        $size     = (int) filesize($logFile);
        $contents = (string) @file_get_contents($logFile, false, null, max(0, $size - self::LOG_MAX_READ));

        foreach (explode("\n", $contents) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $entry = json_decode($line, true);

            if (!\is_array($entry)) {
                continue;
            }

            $entry['id']            = substr(sha1($line), 0, 12);
            $entry['time']          = (int) strtotime((string) ($entry['timestamp'] ?? ''));
            $entry['modifications'] = \is_array($entry['modifications'] ?? null) ? $entry['modifications'] : [];
            $entries[]              = $entry;
        }

        return array_reverse($entries);
    }

    /**
     * Path part of a CSS URL without host and query string, used for the CSS file filter.
     */
    private function cssFileOf(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path !== '' ? $path : $url;
    }

    /**
     * Number of seconds covered by a time-range filter value (0 = any time).
     */
    private function rangeSeconds(string $range): int
    {
        return ['1h' => 3600, '24h' => 86400, '7d' => 604800, '30d' => 2592000][$range] ?? 0;
    }

    /**
     * Apply the viewer filters (free text, time range, CSS file).
     *
     * @param   array[]  $entries  Entries from readEntries()
     *
     * @return  array[]
     */
    private function filterEntries(array $entries, string $search, string $range, string $file): array
    {
        $seconds = $this->rangeSeconds($range);
        $cutoff  = $seconds ? time() - $seconds : 0;
        $needle  = mb_strtolower($search);

        return array_values(array_filter($entries, function (array $entry) use ($cutoff, $needle, $file): bool {
            if ($cutoff && $entry['time'] < $cutoff) {
                return false;
            }

            if ($file !== '') {
                $found = false;

                foreach ($entry['modifications'] as $mod) {
                    if ($this->cssFileOf((string) ($mod['original'] ?? '')) === $file) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    return false;
                }
            }

            if ($needle !== '') {
                $haystack = (string) ($entry['page'] ?? '');

                foreach ($entry['modifications'] as $mod) {
                    $haystack .= ' ' . ($mod['original'] ?? '') . ' ' . ($mod['modified'] ?? '');
                }

                if (!str_contains(mb_strtolower($haystack), $needle)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Filter values from the request, cleaned.
     *
     * @return  array{q: string, range: string, file: string, limit: int, start: int}
     */
    private function getFilters(): array
    {
        $input = $this->getApplication()->getInput();
        $range = $input->get('range', '', 'cmd');
        $limit = $input->get('limit', 50, 'int');

        return [
            'q'     => trim((string) $input->get('q', '', 'string')),
            'range' => \in_array($range, ['1h', '24h', '7d', '30d'], true) ? $range : '',
            'file'  => trim((string) $input->get('file', '', 'string')),
            'limit' => \in_array($limit, [10, 25, 50, 100, 200], true) ? $limit : 50,
            'start' => max(0, $input->get('start', 0, 'int')),
        ];
    }

    private function buildDump(): string
    {
        $filters = $this->getFilters();
        $entries = $this->filterEntries($this->readEntries(), $filters['q'], $filters['range'], $filters['file']);
        $out     = '';

        foreach ($entries as $entry) {
            $out .= '[' . ($entry['timestamp'] ?? '') . '] ' . ($entry['page'] ?? '') . "\n";

            foreach ($entry['modifications'] as $mod) {
                $out .= '    ' . ($mod['original'] ?? '') . ' => ' . ($mod['modified'] ?? '') . "\n";
            }

            $out .= "\n";
        }

        return $out;
    }

    private function buildCsv(): string
    {
        $filters = $this->getFilters();
        $entries = $this->filterEntries($this->readEntries(), $filters['q'], $filters['range'], $filters['file']);

        // A cell that starts with = + - @ would be run as a formula by a spreadsheet.
        $safe = static function (string $value): string {
            return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
        };

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['timestamp', 'page', 'original_url', 'versioned_url'], ',', '"', '');

        foreach ($entries as $entry) {
            foreach ($entry['modifications'] as $mod) {
                fputcsv($handle, [
                    $safe((string) ($entry['timestamp'] ?? '')),
                    $safe((string) ($entry['page'] ?? '')),
                    $safe((string) ($mod['original'] ?? '')),
                    $safe((string) ($mod['modified'] ?? '')),
                ], ',', '"', '');
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function downloadLog(): void
    {
        $logFile = $this->getLogFile();

        if (!is_file($logFile)) {
            $this->sendText('', 'application/octet-stream', 'stalecachebuster_' . date('Y-m-d_His') . '.log');
            return;
        }

        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="stalecachebuster_' . date('Y-m-d_His') . '.log"');
        header('Content-Length: ' . filesize($logFile));
        readfile($logFile);
        $this->getApplication()->close();
    }

    /**
     * Where to go after a clear or delete that was sent from the viewer.
     */
    private function viewerUrl(array $filters = []): string
    {
        $query = [
            'option'  => 'com_ajax',
            'plugin'  => 'stalecachebuster',
            'group'   => 'system',
            'format'  => 'html',
            'tmpl'    => 'component',
            'action'  => 'viewer',
            Session::getFormToken() => 1,
        ];

        foreach (['q', 'range', 'file'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $query[$key] = $filters[$key];
            }
        }

        if (($filters['limit'] ?? 50) !== 50) {
            $query['limit'] = $filters['limit'];
        }

        return Uri::base() . 'index.php?' . http_build_query($query);
    }

    private function clearLog(): void
    {
        $app      = $this->getApplication();
        $fromView = $app->getInput()->post->getInt('viewer', 0) === 1;
        $logFile  = $this->getLogFile();
        $failed   = false;

        foreach ([$logFile, $this->getLogFile(true)] as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (!@unlink($file) && @file_put_contents($file, '') === false) {
                $failed = true;
            }
        }

        if ($failed) {
            if ($fromView) {
                $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_STALECACHEBUSTER_ERROR_LOG_WRITE', SupportHelper::getContactSentence()), 'error');
                $app->redirect($this->viewerUrl());
                return;
            }

            $this->sendJson([
                'success' => false,
                'error'   => Text::sprintf('PLG_SYSTEM_STALECACHEBUSTER_ERROR_LOG_WRITE', SupportHelper::getContactSentence()),
            ], 500);
            return;
        }

        if ($fromView) {
            $app->enqueueMessage(Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_SUCCESS'), 'message');
            $app->redirect($this->viewerUrl());
            return;
        }

        $this->sendJson(['success' => true, 'message' => Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_SUCCESS')]);
    }

    /**
     * Delete the selected entries (POST ids[] = entry ids from the viewer).
     */
    private function deleteEntries(): void
    {
        $app     = $this->getApplication();
        $input   = $app->getInput();
        $filters = [
            'q'     => trim((string) $input->post->get('q', '', 'string')),
            'range' => (string) $input->post->get('range', '', 'cmd'),
            'file'  => trim((string) $input->post->get('file', '', 'string')),
            'limit' => $input->post->getInt('limit', 50),
        ];
        $ids = array_values(array_filter(
            (array) $input->post->get('ids', [], 'array'),
            static fn ($id): bool => \is_string($id) && preg_match('/^[a-f0-9]{12}$/', $id) === 1
        ));

        if (empty($ids)) {
            $app->enqueueMessage(Text::_('PLG_SYSTEM_STALECACHEBUSTER_DELETE_NONE'), 'warning');
            $app->redirect($this->viewerUrl($filters));
            return;
        }

        $logFile = $this->getLogFile();
        $deleted = 0;
        $handle  = is_file($logFile) ? @fopen($logFile, 'c+') : false;

        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (\is_resource($handle)) {
                fclose($handle);
            }

            $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_STALECACHEBUSTER_ERROR_LOG_WRITE', SupportHelper::getContactSentence()), 'error');
            $app->redirect($this->viewerUrl($filters));
            return;
        }

        $keep = [];

        foreach (explode("\n", (string) stream_get_contents($handle)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (\in_array(substr(sha1($line), 0, 12), $ids, true)) {
                $deleted++;
                continue;
            }

            $keep[] = $line;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $keep ? implode("\n", $keep) . "\n" : '');
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $app->enqueueMessage(Text::sprintf('PLG_SYSTEM_STALECACHEBUSTER_DELETE_SUCCESS', $deleted), 'message');
        $app->redirect($this->viewerUrl($filters));
    }

    // ---------------------------------------------------------------
    // Log viewer page
    // ---------------------------------------------------------------

    /**
     * Build the log viewer. It is rendered inside the Atum administrator
     * template (com_ajax with format=html&tmpl=component), so it gets
     * Atum's light and dark colours for free.
     */
    private function renderViewer(): string
    {
        $app     = $this->getApplication();
        $filters = $this->getFilters();
        $all     = $this->readEntries();
        $matches = $this->filterEntries($all, $filters['q'], $filters['range'], $filters['file']);
        $total   = \count($matches);
        $start   = $filters['start'] >= $total ? 0 : $filters['start'];
        $rows    = \array_slice($matches, $start, $filters['limit']);
        $token   = Session::getFormToken();
        $logFile = $this->getLogFile();

        // Statistics always cover the whole log, not the filtered set.
        $now   = time();
        $stats = [
            'all'   => \count($all),
            '1h'    => 0,
            '24h'   => 0,
            '7d'    => 0,
            'urls'  => 0,
            'size'  => is_file($logFile) ? (int) filesize($logFile) : 0,
            'files' => [],
        ];

        foreach ($all as $entry) {
            $age = $now - $entry['time'];

            $stats['1h']  += $age <= 3600 ? 1 : 0;
            $stats['24h'] += $age <= 86400 ? 1 : 0;
            $stats['7d']  += $age <= 604800 ? 1 : 0;
            $stats['urls'] += \count($entry['modifications']);

            foreach ($entry['modifications'] as $mod) {
                $stats['files'][$this->cssFileOf((string) ($mod['original'] ?? ''))] = true;
            }
        }

        $cssFiles = array_keys($stats['files']);
        sort($cssFiles);

        $base = [
            'option' => 'com_ajax',
            'plugin' => 'stalecachebuster',
            'group'  => 'system',
            'format' => 'html',
            'tmpl'   => 'component',
            'action' => 'viewer',
            $token   => 1,
        ];

        // Builds a viewer/dump/csv link that keeps the current filters unless overridden.
        $link = function (array $override = [], string $action = 'viewer', bool $keepFilters = true) use ($base, $filters): string {
            $query = $base;
            $query['action'] = $action;

            if ($action !== 'viewer') {
                $query['format'] = 'raw';
                unset($query['tmpl']);
            }

            $current = $keepFilters ? $filters : ['q' => '', 'range' => '', 'file' => '', 'limit' => 50, 'start' => 0];

            foreach (array_merge($current, $override) as $key => $value) {
                if ($value !== '' && $value !== 0 && !($key === 'limit' && (int) $value === 50)) {
                    $query[$key] = $value;
                }
            }

            return Uri::base() . 'index.php?' . http_build_query($query);
        };

        $document = $app->getDocument();
        $wa       = $document->getWebAssetManager();
        $wa->useScript('bootstrap.modal');

        $logging = (int) $this->params->get('enable_logging', 0) === 1;

        ob_start();
        include __DIR__ . '/../../tmpl/viewer.php';

        return (string) ob_get_clean();
    }
}
