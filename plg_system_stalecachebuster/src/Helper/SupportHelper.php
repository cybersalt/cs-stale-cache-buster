<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 3
 */

namespace Cybersalt\Plugin\System\StaleCacheBuster\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Builds the "please contact support" sentence used wherever this plugin
 * needs to point a site admin at human help (error messages, the post-install
 * card).
 *
 * Reads the support_email / support_url / support_label params off the
 * plugin row, so a reseller can swap the defaults without forking the code.
 * The params are read straight from the extensions table, not through
 * PluginHelper, so this also works while the plugin is still disabled (for
 * example in the install card on a fresh install).
 */
final class SupportHelper
{
    public const DEFAULT_EMAIL = 'support@cybersalt.com';

    public const DEFAULT_URL = 'https://www.cybersalt.com/services/support-request-form';

    public const DEFAULT_LABEL = 'Cybersalt support';

    private static ?Registry $params = null;

    /**
     * Fully formed contact sentence, for example "Please contact Cybersalt
     * support (support@cybersalt.com / https://...) for assistance."
     */
    public static function getContactSentence(): string
    {
        $label = self::getLabel();
        $parts = array_values(array_filter([self::getEmail(), self::getUrl()], 'strlen'));

        if (empty($parts)) {
            return Text::sprintf('PLG_SYSTEM_STALECACHEBUSTER_SUPPORT_CONTACT_PLAIN', $label);
        }

        return Text::sprintf(
            'PLG_SYSTEM_STALECACHEBUSTER_SUPPORT_CONTACT_WITH_DETAILS',
            $label,
            implode(' / ', $parts)
        );
    }

    /**
     * Configured email, or the default when the param was never saved.
     */
    public static function getEmail(): string
    {
        return trim((string) self::getParams()->get('support_email', self::DEFAULT_EMAIL));
    }

    /**
     * Configured URL, or the default when the param was never saved. Only
     * http(s) URLs are returned, so the value is safe to use as a link.
     */
    public static function getUrl(): string
    {
        $url = trim((string) self::getParams()->get('support_url', self::DEFAULT_URL));

        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    /**
     * Configured label, or the translated fallback.
     */
    public static function getLabel(): string
    {
        $configured = trim((string) self::getParams()->get('support_label', self::DEFAULT_LABEL));

        return $configured !== ''
            ? $configured
            : Text::_('PLG_SYSTEM_STALECACHEBUSTER_SUPPORT_FALLBACK_LABEL');
    }

    private static function getParams(): Registry
    {
        if (self::$params !== null) {
            return self::$params;
        }

        self::$params = new Registry();

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query
                ->select($db->quoteName('params'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('stalecachebuster'));

            self::$params = new Registry((string) $db->setQuery($query)->loadResult());
        } catch (\Throwable $e) {
            // Fall back to the defaults: a support sentence must never break the page it is on.
        }

        return self::$params;
    }
}
