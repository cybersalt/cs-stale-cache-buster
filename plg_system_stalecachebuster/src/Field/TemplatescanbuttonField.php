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

class TemplatescanbuttonField extends FormField
{
    protected $type = 'Templatescanbutton';

    protected function getInput(): string
    {
        $token = Session::getFormToken();
        $scanUrl = Uri::base() . 'index.php?option=com_ajax&plugin=stalecachebuster&group=system&format=raw&action=scan&' . $token . '=1';

        $html = '<div style="margin-bottom: 12px;">';

        $html .= '<button type="button" class="btn btn-info" style="display: inline-flex; align-items: center; gap: 6px;" onclick="scanTemplates(\'' . $scanUrl . '\')">';
        $html .= '<span class="icon-search" aria-hidden="true"></span>';
        $html .= Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_TEMPLATES');
        $html .= '</button>';

        $html .= '</div>';

        $html .= '<div id="template-scan-results"></div>';

        $html .= '<script>
        function scanTemplates(url) {
            var resultsDiv = document.getElementById("template-scan-results");
            resultsDiv.innerHTML = "<p><em>' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCANNING', true) . '</em></p>";

            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success || !data.templates || data.templates.length === 0) {
                        resultsDiv.innerHTML = "<div class=\"alert alert-warning\">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_NO_TEMPLATES', true) . '</div>";
                        return;
                    }

                    var html = "";
                    data.templates.forEach(function(tpl) {
                        html += "<div class=\"card mb-3\"><div class=\"card-body\">";
                        html += "<h4 class=\"card-title\">" + tpl.name + " <span class=\"badge bg-info\">" + tpl.files.length + " CSS</span></h4>";
                        html += "<table class=\"table table-sm table-striped\"><thead><tr><th>File</th><th>Modified</th><th>Version Hash</th></tr></thead><tbody>";
                        tpl.files.forEach(function(f) {
                            html += "<tr><td><code>" + f.path + "</code></td><td>" + f.modified + "</td><td><code>?v=" + f.version + "</code></td></tr>";
                        });
                        html += "</tbody></table></div></div>";
                    });

                    resultsDiv.innerHTML = html;
                })
                .catch(function(err) {
                    resultsDiv.innerHTML = "<div class=\"alert alert-danger\">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_ERROR', true) . ' " + err.message + "</div>";
                });
        }
        </script>';

        return $html;
    }

    protected function getLabel(): string
    {
        return '<label class="form-label">' . Text::_('PLG_SYSTEM_STALECACHEBUSTER_SCAN_TEMPLATES_LABEL') . '</label>';
    }
}
