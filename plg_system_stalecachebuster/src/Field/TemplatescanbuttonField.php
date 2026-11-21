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
 * "Scan Templates" button on the plugin settings page. Lists the CSS files of
 * every installed site template and the version tag each one would get.
 */
class TemplatescanbuttonField extends FormField
{
    protected $type = 'Templatescanbutton';

    protected function getInput(): string
    {
        $token   = Session::getFormToken();
        $scanUrl = Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system&format=raw&action=scan&' . $token . '=1';

        $document = Factory::getApplication()->getDocument();
        $document->addScriptOptions('plg_system_stalecachebuster.scan', [
            'url'      => $scanUrl,
            'scanning' => Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCANNING'),
            'none'     => Text::_('PLG_SYSTEM_STALECACHEBUSTER_NO_TEMPLATES'),
            'error'    => Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_ERROR'),
            'count'    => Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_FILES_COUNT'),
            'colFile'  => Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_COL_FILE'),
            'colMod'   => Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_COL_MODIFIED'),
            'colVer'   => Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_COL_VERSION'),
        ]);
        $document->getWebAssetManager()->addInlineScript(<<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    var options = Joomla.getOptions('plg_system_stalecachebuster.scan');
    var button = document.getElementById('scb-scan-button');
    var results = document.getElementById('template-scan-results');

    if (!options || !button || !results) {
        return;
    }

    function esc(value) {
        var div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    }

    button.addEventListener('click', function () {
        results.innerHTML = '<p><em>' + esc(options.scanning) + '</em></p>';

        fetch(options.url, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) {
                    results.innerHTML = '<div class="alert alert-danger">' + esc(options.error) + ' ' + esc(data.error || '') + '</div>';
                    return;
                }

                if (!data.templates || data.templates.length === 0) {
                    results.innerHTML = '<div class="alert alert-warning">' + esc(options.none) + '</div>';
                    return;
                }

                var html = '';
                data.templates.forEach(function (tpl) {
                    html += '<div class="card mb-3"><div class="card-body">';
                    html += '<h4 class="card-title">' + esc(tpl.name) + ' <span class="badge bg-info">' + esc(options.count.replace('%d', tpl.files.length)) + '</span></h4>';
                    html += '<table class="table table-sm table-striped"><thead><tr><th>' + esc(options.colFile) + '</th><th>' + esc(options.colMod) + '</th><th>' + esc(options.colVer) + '</th></tr></thead><tbody>';
                    tpl.files.forEach(function (f) {
                        html += '<tr><td><code>' + esc(f.path) + '</code></td><td>' + esc(f.modified) + '</td><td><code>?v=' + esc(f.version) + '</code></td></tr>';
                    });
                    html += '</tbody></table></div></div>';
                });

                results.innerHTML = html;
            })
            .catch(function (err) {
                results.innerHTML = '<div class="alert alert-danger">' + esc(options.error) + ' ' + esc(err.message) + '</div>';
            });
    });
});
JS);

        $html  = '<div class="mb-3">';
        $html .= '<button type="button" id="scb-scan-button" class="btn btn-info">';
        $html .= '<span class="icon-search" aria-hidden="true"></span> ';
        $html .= Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_TEMPLATES');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '<div id="template-scan-results"></div>';

        return $html;
    }

    protected function getLabel(): string
    {
        return '<label class="form-label">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_TEMPLATES_LABEL') . '</label>';
    }
}
