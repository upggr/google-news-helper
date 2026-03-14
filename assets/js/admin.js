/* Google News Helper – Admin JS */
/* global gnhData, jQuery */

(function ($) {
    'use strict';

    // ── Enable / disable toggle ──────────────────────────────────────────────

    var $toggle = $('#gnh-enable-toggle');
    var $label  = $('.gnh-toggle-label');
    var $notice = $('.gnh-save-notice');
    var timer;

    $toggle.on('change', function () {
        var enabled = this.checked;

        $.post(gnhData.ajax_url, {
            action:  'gnh_toggle_enabled',
            enabled: enabled ? '1' : '0',
            nonce:   gnhData.nonce
        })
        .done(function () {
            if (enabled) {
                $label.html('<span class="gnh-status-on">Active</span>');
            } else {
                $label.html('<span class="gnh-status-off">Inactive</span>');
            }
            clearTimeout(timer);
            $notice.stop(true, true).fadeIn(150);
            timer = setTimeout(function () { $notice.fadeOut(300); }, 2000);
        })
        .fail(function () {
            $toggle.prop('checked', !enabled);
            alert('Could not save the setting. Please try again.');
        });
    });

    // ── Tag tester ───────────────────────────────────────────────────────────

    $('#gnh-run-test').on('click', function () {
        var postId = $('#gnh-test-post-select').val();
        if (!postId) {
            alert('Please select a post first.');
            return;
        }

        var $btn     = $(this);
        var $spinner = $('.gnh-test-spinner');
        var $results = $('#gnh-test-results');

        $btn.prop('disabled', true);
        $spinner.css('visibility', 'visible');
        $results.hide().empty();

        $.post(gnhData.ajax_url, {
            action:  'gnh_test_tags',
            post_id: postId,
            nonce:   gnhData.nonce
        })
        .done(function (response) {
            if (!response.success) {
                $results.html('<div class="notice notice-error inline"><p>' + escHtml(response.data.message) + '</p></div>').show();
                return;
            }

            var data = response.data;
            var html = '';

            // ── Tested URL
            html += '<p><strong>Tested URL:</strong> <a href="' + escHtml(data.url) + '" target="_blank">' + escHtml(data.url) + '</a></p>';

            // ── Conflict warnings
            var conflicts = (data.conflict_plugins || []).concat(data.mu_conflict_plugins || []);
            if (conflicts.length) {
                html += '<div class="notice notice-warning inline"><p><strong>⚠ Potential conflicts detected:</strong></p><ul style="margin-left:16px;list-style:disc;">';
                conflicts.forEach(function (c) {
                    html += '<li><strong>' + escHtml(c.name) + '</strong> <span style="color:#888;">(' + escHtml(c.type) + ': ' + escHtml(c.file) + ')</span> — this plugin may output its own og: / structured-data tags.</li>';
                });
                html += '</ul><p>Duplicate og: tags are skipped automatically by Google News Helper when Yoast, Rank Math, or AIOSEO is detected. Check for duplicates in the tag list below.</p></div>';
            }

            // ── SEO plugin badges (detected from HTML)
            if (data.seo_plugins && data.seo_plugins.length) {
                html += '<p><strong>Detected from page HTML:</strong> ';
                data.seo_plugins.forEach(function (name) {
                    html += '<span class="gnh-badge gnh-badge-info">' + escHtml(name) + '</span> ';
                });
                html += '</p>';
            }

            // ── Tags table
            html += '<table class="gnh-tags-table widefat striped"><thead><tr>';
            html += '<th>Tag</th><th>Type</th><th>Required</th><th>Status</th>';
            html += '</tr></thead><tbody>';

            var tags = data.tags || {};
            Object.keys(tags).forEach(function (key) {
                var t = tags[key];
                var statusClass, statusText;

                if (!t.found && t.required) {
                    statusClass = 'gnh-tag-missing';
                    statusText  = '✗ Missing (required)';
                } else if (!t.found) {
                    statusClass = 'gnh-tag-optional';
                    statusText  = '— Not found (optional)';
                } else if (t.duplicate) {
                    statusClass = 'gnh-tag-duplicate';
                    statusText  = '⚠ Duplicate (' + t.count + ' occurrences) — likely a plugin conflict';
                } else {
                    statusClass = 'gnh-tag-ok';
                    statusText  = '✓ Present';
                }

                html += '<tr class="' + statusClass + '">';
                html += '<td><code>' + escHtml(t.label) + '</code></td>';
                html += '<td>' + escHtml(t.type) + '</td>';
                html += '<td>' + (t.required ? 'Yes' : 'No') + '</td>';
                html += '<td>' + statusText + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table>';

            // ── JSON-LD
            html += '<div style="margin-top:12px;">';
            if (data.json_ld) {
                html += '<span class="gnh-badge gnh-badge-ok">✓ JSON-LD found: ' + escHtml(data.json_ld_type) + '</span>';
                if (data.json_ld_type && data.json_ld_type.indexOf('NewsArticle') === -1) {
                    html += ' <span class="gnh-badge gnh-badge-warn">⚠ Not NewsArticle type — enable Google News Helper to add it</span>';
                }
            } else {
                html += '<span class="gnh-badge gnh-badge-missing">✗ No JSON-LD structured data found</span>';
            }
            html += '</div>';

            $results.html(html).show();
        })
        .fail(function () {
            $results.html('<div class="notice notice-error inline"><p>Request failed. Please try again.</p></div>').show();
        })
        .always(function () {
            $btn.prop('disabled', false);
            $spinner.css('visibility', 'hidden');
        });
    });

    function escHtml(str) {
        if (typeof str !== 'string') { return str; }
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

}(jQuery));
