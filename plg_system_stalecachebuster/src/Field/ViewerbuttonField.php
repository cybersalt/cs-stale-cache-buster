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

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * Log viewer buttons on the plugin settings page: open the viewer, download
 * the raw log, and clear the log (confirmed in an in-page Bootstrap modal, then
 * sent as a POST with the CSRF token).
 */
class ViewerbuttonField extends FormField
{
    protected $type = 'Viewerbutton';

    protected function getInput(): string
    {
        $token = Session::getFormToken();
        $base  = Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system';

        $viewerUrl   = $base . '&format=html&tmpl=component&action=viewer&' . $token . '=1';
        $downloadUrl = $base . '&format=raw&action=download&' . $token . '=1';
        $clearUrl    = $base . '&format=raw&action=clear';

        $document = Factory::getApplication()->getDocument();
        $wa       = $document->getWebAssetManager();
        $wa->useScript('bootstrap.modal');
        $document->addScriptOptions('plg_system_stalecachebuster.viewer', [
            'clearUrl' => $clearUrl,
            'token'    => $token,
            'success'  => Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_SUCCESS'),
            'failed'   => Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_FAILED'),
        ]);
        $wa->addInlineScript(<<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    var options = Joomla.getOptions('plg_system_stalecachebuster.viewer');
    var modal = document.getElementById('scb-clear-modal');
    var button = document.getElementById('scb-clear-confirm');

    if (!options || !modal || !button) {
        return;
    }

    // Move the modal out of the form so it is not positioned inside a tab pane.
    document.body.appendChild(modal);

    button.addEventListener('click', function () {
        var body = new FormData();
        body.append(options.token, '1');
        button.disabled = true;

        fetch(options.clearUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                bootstrap.Modal.getOrCreateInstance(modal).hide();
                Joomla.renderMessages(data && data.success
                    ? { success: [options.success] }
                    : { error: [(data && data.error) || options.failed] });
            })
            .catch(function () {
                bootstrap.Modal.getOrCreateInstance(modal).hide();
                Joomla.renderMessages({ error: [options.failed] });
            })
            .finally(function () { button.disabled = false; });
    });
});
JS);

        $html = '<div class="d-flex flex-wrap gap-2">';

        $html .= '<a href="' . htmlspecialchars($viewerUrl) . '" target="_blank" rel="noopener" class="btn btn-primary">'
            . '<span class="icon-eye" aria-hidden="true"></span> '
            . Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEW_LOG') . '</a>';

        $html .= '<a href="' . htmlspecialchars($downloadUrl) . '" class="btn btn-success">'
            . '<span class="icon-download" aria-hidden="true"></span> '
            . Text::_('PLG_SYSTEM_STALECACHEBUSTER_DOWNLOAD_LOG') . '</a>';

        $html .= '<button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#scb-clear-modal">'
            . '<span class="icon-trash" aria-hidden="true"></span> '
            . Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_LOG') . '</button>';

        $html .= '</div>';

        $html .= '<div class="small text-muted mt-2">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_VIEWER_INFO') . '</div>';

        $html .= '<div class="modal fade" id="scb-clear-modal" tabindex="-1" aria-labelledby="scb-clear-title" aria-hidden="true">'
            . '<div class="modal-dialog"><div class="modal-content">'
            . '<div class="modal-header">'
            . '<h2 class="modal-title h5" id="scb-clear-title">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_CONFIRM_TITLE') . '</h2>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . htmlspecialchars(Text::_('PLG_SYSTEM_STALECACHEBUSTER_CANCEL')) . '"></button>'
            . '</div>'
            . '<div class="modal-body p-3">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_CONFIRM') . '</div>'
            . '<div class="modal-footer">'
            . '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_CANCEL') . '</button>'
            . '<button type="button" class="btn btn-danger" id="scb-clear-confirm">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_CLEAR_CONFIRM_YES') . '</button>'
            . '</div></div></div></div>';

        return $html;
    }

    protected function getLabel(): string
    {
        return '<label class="form-label">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_LOG_VIEWER_LABEL') . '</label>';
    }
}
