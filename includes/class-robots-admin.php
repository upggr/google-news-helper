<?php
/**
 * Admin screen: robots.txt health and optimization hints.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GNH_Robots_Admin {

    public static function render_static(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $report = GNH_Robots::get_health_report();
        ?>
        <div class="wrap gnh-wrap gnh-robots-wrap">
            <h1>
                <span class="dashicons dashicons-shield" style="font-size:28px;line-height:1.1;vertical-align:middle;margin-right:6px;color:#1d2327;"></span>
                <?php esc_html_e( 'robots.txt', 'google-news-helper' ); ?>
                <span class="gnh-version"><?php esc_html_e( 'Health & optimization', 'google-news-helper' ); ?> — Google News Helper v<?php echo esc_html( GNH_VERSION ); ?></span>
            </h1>

            <div class="gnh-card gnh-robots-summary">
                <h2><?php esc_html_e( 'Overall status', 'google-news-helper' ); ?></h2>
                <?php
                $badge_class = 'gnh-robots-badge--warn';
                if ( $report['overall'] === 'good' ) {
                    $badge_class = 'gnh-robots-badge--good';
                } elseif ( $report['overall'] === 'bad' ) {
                    $badge_class = 'gnh-robots-badge--bad';
                }
                ?>
                <p class="gnh-robots-overall">
                    <span class="gnh-robots-badge <?php echo esc_attr( $badge_class ); ?>">
                        <?php echo esc_html( $report['overall_label'] ); ?>
                    </span>
                </p>
                <p class="description">
                    <?php esc_html_e( 'Checks the URL crawlers request (usually WordPress’s virtual robots.txt plus this plugin’s rules). A static robots.txt file in your site root can override WordPress entirely.', 'google-news-helper' ); ?>
                </p>
                <p>
                    <a href="<?php echo esc_url( $report['robots_url'] ); ?>" class="button" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open live robots.txt', 'google-news-helper' ); ?></a>
                    <a href="<?php echo esc_url( admin_url( 'options-reading.php' ) ); ?>" class="button"><?php esc_html_e( 'Search engine visibility', 'google-news-helper' ); ?></a>
                </p>
            </div>

            <div class="gnh-card">
                <h2><?php esc_html_e( 'Health checks', 'google-news-helper' ); ?></h2>
                <table class="widefat striped gnh-robots-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Check', 'google-news-helper' ); ?></th>
                            <th><?php esc_html_e( 'Result', 'google-news-helper' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $report['checks'] as $check ) : ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $check['label'] ); ?></strong>
                                <?php if ( ! empty( $check['hint'] ) ) : ?>
                                    <br><span class="description"><?php echo esc_html( $check['hint'] ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="gnh-robots-status gnh-robots-status--<?php echo esc_attr( $check['status'] ); ?>">
                                    <?php echo esc_html( $check['result'] ); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="gnh-card">
                <h2><?php esc_html_e( 'Optimization', 'google-news-helper' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Google News Helper extends WordPress’s virtual robots.txt (no extra files needed). It adds Google News sitemap discovery, explicit allows for news and social crawlers, and works with your cache plugin’s crawler bypass.', 'google-news-helper' ); ?></p>
                <ul class="gnh-robots-opt-list">
                    <?php foreach ( $report['optimizations'] as $item ) : ?>
                    <li>
                        <strong><?php echo esc_html( $item['title'] ); ?></strong>
                        <?php if ( ! empty( $item['detail'] ) ) : ?>
                            — <?php echo esc_html( $item['detail'] ); ?>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="gnh-card">
                <h2><?php esc_html_e( 'Live robots.txt (preview)', 'google-news-helper' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Fetched from your site over HTTP. Cached plugins or a CDN may serve a different body until purged.', 'google-news-helper' ); ?></p>
                <?php if ( ! empty( $report['fetch_error'] ) ) : ?>
                    <p class="gnh-robots-fetch-error"><?php echo esc_html( $report['fetch_error'] ); ?></p>
                <?php else : ?>
                    <textarea class="gnh-robots-preview" readonly rows="18" cols="80"><?php echo esc_textarea( $report['body'] ); ?></textarea>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
