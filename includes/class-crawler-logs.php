<?php
/**
 * Crawler Access Log Viewer
 *
 * Provides admin dashboard for viewing crawler access logs and
 * identifying IP ranges that should be whitelisted for clean page serving.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_Crawler_Logs {

    public function __construct() {
        if ( is_admin() && current_user_can( 'manage_options' ) ) {
            add_action( 'admin_menu', [ $this, 'add_log_menu' ] );
        }
    }

    public function add_log_menu(): void {
        add_submenu_page(
            'google-news-helper',
            'Crawler Logs',
            'Crawler Logs',
            'manage_options',
            'gnh-crawler-logs',
            [ $this, 'render_logs_page' ]
        );
    }

    public function render_logs_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        ?>
        <div class="wrap">
            <h1>Google News Helper - Crawler Access Logs</h1>
            <p>Real-time log of crawlers accessing your site. Use this to identify IP ranges that should be whitelisted.</p>

            <h2>Latest Crawler Requests</h2>
            <p>
                <button class="button" onclick="location.reload()">Refresh</button>
                <button class="button" onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'">Show Analysis</button>
            </p>
            <div style="display:none; background:#f1f1f1; padding:10px; margin:10px 0; border-radius:5px;">
                <h3>IP Analysis</h3>
                <p>This shows which IPs accessed your site and whether they were detected as crawlers:</p>
                <ul>
                    <li><strong>Google IPs:</strong> Look for pattern "66.249.x.x" and "66.102.x.x" - use for whitelisting</li>
                    <li><strong>Bing IPs:</strong> Look for pattern "40.77.x.x" and "207.46.x.x"</li>
                    <li><strong>Non-Greek IPs:</strong> Any IP not in the 195.x.x.x Greek ranges</li>
                </ul>
            </div>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>IP Address</th>
                        <th>Detected Bot</th>
                        <th>Greek IP</th>
                        <th>User Agent (truncated)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $this->render_log_rows(); ?>
                </tbody>
            </table>

            <h3>Copy this Python code to update is_greek_ip() with real Google IPs:</h3>
            <pre style="background:#f1f1f1; padding:10px; border-radius:5px; overflow-x:auto;">
# Extract unique Google/Bing IPs from logs:
import re
with open('crawler-access.log') as f:
    lines = f.readlines()

google_ips = set()
for line in lines:
    match = re.search(r'(66\.\d+\.\d+\.\d+|40\.77\.\d+\.\d+|207\.46\.\d+\.\d+)', line)
    if match:
        ip = match.group(1)
        network = '.'.join(ip.split('.')[:3]) + '.'
        google_ips.add(network)

for ip in sorted(google_ips):
    print(f"'{ip}',  // Google/Bing IP range")
            </pre>
        </div>
        <?php
    }

    private function render_log_rows(): void {
        $log_file = GNH_PLUGIN_DIR . 'logs/crawler-access.log';

        if ( ! file_exists( $log_file ) ) {
            echo '<tr><td colspan="5">No logs yet. They will appear as crawlers access your site.</td></tr>';
            return;
        }

        $lines = array_reverse( file( $log_file, FILE_IGNORE_NEW_LINES ) );
        $count = 0;

        foreach ( $lines as $line ) {
            if ( $count >= 50 ) { // Show last 50 entries
                break;
            }

            // Parse log line format: [TIME] IP: x.x.x.x | Bot: YES/NO | Greek: YES/NO | UA: ...
            if ( preg_match( '/\[([^\]]+)\] IP: ([^ ]+) \| Bot: ([^ ]+) \| Greek: ([^ ]+) \| UA: (.+?) \| URL:/', $line, $matches ) ) {
                $timestamp = $matches[1];
                $ip = $matches[2];
                $is_bot = $matches[3];
                $is_greek = $matches[4];
                $ua = $matches[5];

                // Highlight Google/Bing IPs
                $ip_class = '';
                if ( preg_match( '/^(66\.249|66\.102|40\.77|207\.46)/', $ip ) ) {
                    $ip_class = ' style="background-color: #ffffcc; font-weight: bold;"';
                }

                printf(
                    '<tr><td>%s</td><td%s>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html( $timestamp ),
                    $ip_class,
                    esc_html( $ip ),
                    esc_html( $is_bot ),
                    esc_html( $is_greek ),
                    esc_html( substr( $ua, 0, 70 ) ) . ( strlen( $ua ) > 70 ? '...' : '' )
                );

                $count++;
            }
        }
    }
}
