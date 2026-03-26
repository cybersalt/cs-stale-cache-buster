<?php

/**
 * @package     Cybersalt.Plugin
 * @subpackage  System.StaleCacheBuster
 *
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 3
 */

namespace Cybersalt\Plugin\System\StaleCacheBuster\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

class ViewerbuttonField extends FormField
{
    protected $type = 'Viewerbutton';

    protected function getInput(): string
    {
        $token = Session::getFormToken();

        $viewerUrl   = Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system&format=raw&action=viewer&' . $token . '=1';
        $downloadUrl = Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system&format=raw&action=download&' . $token . '=1';
        $clearUrl    = Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system&format=raw&action=clear&' . $token . '=1';

        $html = '<div style="display: flex; gap: 10px; flex-wrap: wrap;">';

        // View Log button
        $html .= '<a href="' . $viewerUrl . '" target="_blank" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px;">';
        $html .= '<span class="icon-eye" aria-hidden="true"></span>';
        $html .= Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEW_LOG');
        $html .= '</a>';

        // Download Log button
        $html .= '<a href="' . $downloadUrl . '" class="btn btn-success" style="display: inline-flex; align-items: center; gap: 6px;">';
        $html .= '<span class="icon-download" aria-hidden="true"></span>';
        $html .= Text::_('PLG_SYSTEM_STALECACHEBUSTER_DOWNLOAD_LOG');
        $html .= '</a>';

        // Clear Log button
        $html .= '<button type="button" class="btn btn-danger" style="display: inline-flex; align-items: center; gap: 6px;" onclick="clearStaleCacheBusterLog(\'' . $clearUrl . '\', \'' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_CONFIRM', true) . '\')">';
        $html .= '<span class="icon-trash" aria-hidden="true"></span>';
        $html .= Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_LOG');
        $html .= '</button>';

        $html .= '<script>
        function clearStaleCacheBusterLog(url, confirmMsg) {
            if (!confirm(confirmMsg)) return;
            fetch(url)
                .then(r => r.text())
                .then(text => {
                    if (!text) throw new Error("Empty response from server");
                    return JSON.parse(text);
                })
                .then(data => {
                    if (data.success) {
                        alert("' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_SUCCESS', true) . '");
                    } else {
                        alert("Error: " + (data.error || "Unknown error"));
                    }
                })
                .catch(err => alert("Failed: " + err.message));
        }
        </script>';

        $html .= '</div>';

        $html .= '<div class="small text-muted" style="margin-top: 8px;">';
        $html .= Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEWER_INFO');
        $html .= '</div>';

        return $html;
    }

    protected function getLabel(): string
    {
        return '<label class="form-label">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_LOG_VIEWER_LABEL') . '</label>';
    }
}
