<?php
/**
 * Admin page template
 *
 * @package RaidBoxes\DiskUsageSunburst
 * @var array $settings Plugin settings
 */

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}
?>

<div class="wrap">
    <h1><?php esc_html_e( 'Disk Usage Analysis', 'disk-usage-sunburst' ); ?></h1>
    
    <div class="rbdusb-container">
        <!-- Control Panel -->
        <div class="rbdusb-controls">
            <p class="description">
                <?php printf( esc_html__( 'Analyzing WordPress installation at: %s', 'disk-usage-sunburst' ), '<code>' . esc_html( ABSPATH ) . '</code>' ); ?>
            </p>
            
            <div class="rbdusb-control-group">
                <button type="button" id="rbdusb-start-scan" class="button button-primary">
                    <?php esc_html_e( 'Start Scan', 'disk-usage-sunburst' ); ?>
                </button>
                <button type="button" id="rbdusb-clear-cache" class="button button-secondary">
                    <?php esc_html_e( 'Clear Cache', 'disk-usage-sunburst' ); ?>
                </button>
                <label>
                    <input type="checkbox" id="rbdusb-use-cache" checked />
                    <?php esc_html_e( 'Use cached results', 'disk-usage-sunburst' ); ?>
                </label>
            </div>
        </div>

        <!-- Progress Indicator -->
        <div id="rbdusb-progress" class="rbdusb-progress" style="display: none;">
            <div class="rbdusb-progress-bar">
                <div class="rbdusb-progress-fill"></div>
            </div>
            <div class="rbdusb-progress-text">
                <?php esc_html_e( 'Initializing scan...', 'disk-usage-sunburst' ); ?>
            </div>
        </div>

        <!-- Error/Success Messages -->
        <div id="rbdusb-messages"></div>

        <!-- Snapshots Section -->
        <div id="rbdusb-snapshots" class="rbdusb-snapshots">
            <h3><?php esc_html_e( 'Previous Snapshots', 'disk-usage-sunburst' ); ?></h3>
            <p class="description">
                <?php esc_html_e( 'Each scan creates a snapshot that you can view later. Snapshots are automatically saved when you start a new analysis.', 'disk-usage-sunburst' ); ?>
            </p>
            
            <div id="rbdusb-snapshots-loading" style="display: none;">
                <p><?php esc_html_e( 'Loading snapshots...', 'disk-usage-sunburst' ); ?></p>
            </div>
            
            <div id="rbdusb-snapshots-empty" style="display: none;">
                <p><?php esc_html_e( 'No snapshots available. Run your first scan to create one.', 'disk-usage-sunburst' ); ?></p>
            </div>
            
            <div id="rbdusb-snapshots-table" style="display: none;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Snapshot', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Files', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'disk-usage-sunburst' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rbdusb-snapshots-list">
                        <!-- Snapshots will be loaded here -->
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Usage Instructions -->
        <div id="rbdusb-instructions" class="rbdusb-instructions">
            <h3><?php esc_html_e( 'How to Use', 'disk-usage-sunburst' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Click "Start Scan" to analyze your WordPress installation', 'disk-usage-sunburst' ); ?></li>
                <li><?php esc_html_e( 'Click on any arc in the chart to zoom in and explore that directory', 'disk-usage-sunburst' ); ?></li>
                <li><?php esc_html_e( 'Click the center circle to zoom back out', 'disk-usage-sunburst' ); ?></li>
                <li><?php esc_html_e( 'Hover over arcs to see detailed size information', 'disk-usage-sunburst' ); ?></li>
                <li><?php esc_html_e( 'Load previous snapshots to compare disk usage over time', 'disk-usage-sunburst' ); ?></li>
            </ul>
        </div>

        <!-- Scan Statistics -->
        <div id="rbdusb-stats" class="rbdusb-stats" style="display: none;">
            <h3><?php esc_html_e( 'Scan Statistics', 'disk-usage-sunburst' ); ?></h3>
            <div class="rbdusb-stats-grid">
                <div class="rbdusb-stat">
                    <span class="rbdusb-stat-label"><?php esc_html_e( 'Total Size:', 'disk-usage-sunburst' ); ?></span>
                    <span class="rbdusb-stat-value" id="rbdusb-stat-size">-</span>
                </div>
                <div class="rbdusb-stat">
                    <span class="rbdusb-stat-label"><?php esc_html_e( 'Files Scanned:', 'disk-usage-sunburst' ); ?></span>
                    <span class="rbdusb-stat-value" id="rbdusb-stat-files">-</span>
                </div>
                <div class="rbdusb-stat">
                    <span class="rbdusb-stat-label"><?php esc_html_e( 'Directories Scanned:', 'disk-usage-sunburst' ); ?></span>
                    <span class="rbdusb-stat-value" id="rbdusb-stat-dirs">-</span>
                </div>
                <div class="rbdusb-stat">
                    <span class="rbdusb-stat-label"><?php esc_html_e( 'Scan Time:', 'disk-usage-sunburst' ); ?></span>
                    <span class="rbdusb-stat-value" id="rbdusb-stat-time">-</span>
                </div>
            </div>
        </div>

        <!-- Chart/Analysis Toggle -->
        <div id="rbdusb-view-toggle" class="rbdusb-view-toggle" style="display: none;">
            <button type="button" id="rbdusb-show-chart" class="button button-primary">
                <?php esc_html_e( 'Chart View', 'disk-usage-sunburst' ); ?>
            </button>
            <button type="button" id="rbdusb-show-analysis" class="button">
                <?php esc_html_e( 'Detailed Analysis', 'disk-usage-sunburst' ); ?>
            </button>
        </div>

        <!-- SVG Container -->
        <div id="rbdusb-chart-container" class="rbdusb-chart-container">
            <div id="rbdusb-svg-not-supported" class="notice notice-error" style="display: none;">
                <p><?php esc_html_e( 'This plugin requires SVG support. Please update to a modern browser.', 'disk-usage-sunburst' ); ?></p>
            </div>
        </div>

        <!-- Detailed Analysis Container -->
        <div id="rbdusb-analysis-container" class="rbdusb-analysis-container" style="display: none;">
            <div id="rbdusb-analysis-loading" style="display: none;">
                <p><?php esc_html_e( 'Analyzing data...', 'disk-usage-sunburst' ); ?></p>
            </div>

            <!-- WordPress Breakdown -->
            <div id="rbdusb-wordpress-breakdown" class="rbdusb-analysis-section table-view">
                <div class="rbdusb-analysis-section-header">
                    <h3><?php esc_html_e( 'WordPress Breakdown', 'disk-usage-sunburst' ); ?></h3>
                    <div class="rbdusb-view-mode-toggle">
                        <button type="button" class="rbdusb-view-mode-btn active" data-view="table" data-section="wordpress-breakdown">
                            <?php esc_html_e( 'Table', 'disk-usage-sunburst' ); ?>
                        </button>
                        <button type="button" class="rbdusb-view-mode-btn" data-view="cards" data-section="wordpress-breakdown">
                            <?php esc_html_e( 'Cards', 'disk-usage-sunburst' ); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Table View -->
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Category', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Percentage', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Path', 'disk-usage-sunburst' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rbdusb-wordpress-breakdown-list">
                        <!-- WordPress breakdown will be loaded here -->
                    </tbody>
                </table>
                
                <!-- Card View -->
                <div id="rbdusb-wordpress-breakdown-cards" class="rbdusb-analysis-cards">
                    <!-- Cards will be loaded here -->
                </div>
                
                <!-- Pagination -->
                <div id="rbdusb-wordpress-breakdown-pagination" class="rbdusb-pagination" style="display: none;">
                    <div class="rbdusb-pagination-info">
                        <span id="rbdusb-wordpress-breakdown-info"></span>
                    </div>
                    <div class="rbdusb-pagination-controls">
                        <button type="button" class="rbdusb-pagination-btn" data-page="prev">&laquo; <?php esc_html_e( 'Previous', 'disk-usage-sunburst' ); ?></button>
                        <div id="rbdusb-wordpress-breakdown-pages"></div>
                        <button type="button" class="rbdusb-pagination-btn" data-page="next"><?php esc_html_e( 'Next', 'disk-usage-sunburst' ); ?> &raquo;</button>
                        <div class="rbdusb-page-size-selector">
                            <label><?php esc_html_e( 'Show:', 'disk-usage-sunburst' ); ?></label>
                            <select id="rbdusb-wordpress-breakdown-page-size">
                                <option value="6">6</option>
                                <option value="12" selected>12</option>
                                <option value="24">24</option>
                                <option value="48">48</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Largest Files -->
            <div id="rbdusb-largest-files" class="rbdusb-analysis-section table-view">
                <div class="rbdusb-analysis-section-header">
                    <h3><?php esc_html_e( 'Largest Files', 'disk-usage-sunburst' ); ?></h3>
                    <div class="rbdusb-view-mode-toggle">
                        <button type="button" class="rbdusb-view-mode-btn active" data-view="table" data-section="largest-files">
                            <?php esc_html_e( 'Table', 'disk-usage-sunburst' ); ?>
                        </button>
                        <button type="button" class="rbdusb-view-mode-btn" data-view="cards" data-section="largest-files">
                            <?php esc_html_e( 'Cards', 'disk-usage-sunburst' ); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Table View -->
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Filename', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( '%', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Path', 'disk-usage-sunburst' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rbdusb-largest-files-list">
                        <!-- Largest files will be loaded here -->
                    </tbody>
                </table>
                
                <!-- Card View -->
                <div id="rbdusb-largest-files-cards" class="rbdusb-analysis-cards">
                    <!-- Cards will be loaded here -->
                </div>
                
                <!-- Pagination -->
                <div id="rbdusb-largest-files-pagination" class="rbdusb-pagination" style="display: none;">
                    <div class="rbdusb-pagination-info">
                        <span id="rbdusb-largest-files-info"></span>
                    </div>
                    <div class="rbdusb-pagination-controls">
                        <button type="button" class="rbdusb-pagination-btn" data-page="prev">&laquo; <?php esc_html_e( 'Previous', 'disk-usage-sunburst' ); ?></button>
                        <div id="rbdusb-largest-files-pages"></div>
                        <button type="button" class="rbdusb-pagination-btn" data-page="next"><?php esc_html_e( 'Next', 'disk-usage-sunburst' ); ?> &raquo;</button>
                        <div class="rbdusb-page-size-selector">
                            <label><?php esc_html_e( 'Show:', 'disk-usage-sunburst' ); ?></label>
                            <select id="rbdusb-largest-files-page-size">
                                <option value="6">6</option>
                                <option value="12" selected>12</option>
                                <option value="24">24</option>
                                <option value="48">48</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Largest Folders -->
            <div id="rbdusb-largest-folders" class="rbdusb-analysis-section table-view">
                <div class="rbdusb-analysis-section-header">
                    <h3><?php esc_html_e( 'Largest Folders', 'disk-usage-sunburst' ); ?></h3>
                    <div class="rbdusb-view-mode-toggle">
                        <button type="button" class="rbdusb-view-mode-btn active" data-view="table" data-section="largest-folders">
                            <?php esc_html_e( 'Table', 'disk-usage-sunburst' ); ?>
                        </button>
                        <button type="button" class="rbdusb-view-mode-btn" data-view="cards" data-section="largest-folders">
                            <?php esc_html_e( 'Cards', 'disk-usage-sunburst' ); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Table View -->
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Folder', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( '%', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Files', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Path', 'disk-usage-sunburst' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rbdusb-largest-folders-list">
                        <!-- Largest folders will be loaded here -->
                    </tbody>
                </table>
                
                <!-- Card View -->
                <div id="rbdusb-largest-folders-cards" class="rbdusb-analysis-cards">
                    <!-- Cards will be loaded here -->
                </div>
                
                <!-- Pagination -->
                <div id="rbdusb-largest-folders-pagination" class="rbdusb-pagination" style="display: none;">
                    <div class="rbdusb-pagination-info">
                        <span id="rbdusb-largest-folders-info"></span>
                    </div>
                    <div class="rbdusb-pagination-controls">
                        <button type="button" class="rbdusb-pagination-btn" data-page="prev">&laquo; <?php esc_html_e( 'Previous', 'disk-usage-sunburst' ); ?></button>
                        <div id="rbdusb-largest-folders-pages"></div>
                        <button type="button" class="rbdusb-pagination-btn" data-page="next"><?php esc_html_e( 'Next', 'disk-usage-sunburst' ); ?> &raquo;</button>
                        <div class="rbdusb-page-size-selector">
                            <label><?php esc_html_e( 'Show:', 'disk-usage-sunburst' ); ?></label>
                            <select id="rbdusb-largest-folders-page-size">
                                <option value="6">6</option>
                                <option value="12" selected>12</option>
                                <option value="24">24</option>
                                <option value="48">48</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Folders with Most Files -->
            <div id="rbdusb-folders-most-files" class="rbdusb-analysis-section table-view">
                <div class="rbdusb-analysis-section-header">
                    <h3><?php esc_html_e( 'Folders with most files', 'disk-usage-sunburst' ); ?></h3>
                    <div class="rbdusb-view-mode-toggle">
                        <button type="button" class="rbdusb-view-mode-btn active" data-view="table" data-section="folders-most-files">
                            <?php esc_html_e( 'Table', 'disk-usage-sunburst' ); ?>
                        </button>
                        <button type="button" class="rbdusb-view-mode-btn" data-view="cards" data-section="folders-most-files">
                            <?php esc_html_e( 'Cards', 'disk-usage-sunburst' ); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Table View -->
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Folder', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'File Count', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'File Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Avg File Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( '%', 'disk-usage-sunburst' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rbdusb-folders-most-files-list">
                        <!-- Folders with most files will be loaded here -->
                    </tbody>
                </table>
                
                <!-- Card View -->
                <div id="rbdusb-folders-most-files-cards" class="rbdusb-analysis-cards">
                    <!-- Cards will be loaded here -->
                </div>
                
                <!-- Pagination -->
                <div id="rbdusb-folders-most-files-pagination" class="rbdusb-pagination" style="display: none;">
                    <div class="rbdusb-pagination-info">
                        <span id="rbdusb-folders-most-files-info"></span>
                    </div>
                    <div class="rbdusb-pagination-controls">
                        <button type="button" class="rbdusb-pagination-btn" data-page="prev">&laquo; <?php esc_html_e( 'Previous', 'disk-usage-sunburst' ); ?></button>
                        <div id="rbdusb-folders-most-files-pages"></div>
                        <button type="button" class="rbdusb-pagination-btn" data-page="next"><?php esc_html_e( 'Next', 'disk-usage-sunburst' ); ?> &raquo;</button>
                        <div class="rbdusb-page-size-selector">
                            <label><?php esc_html_e( 'Show:', 'disk-usage-sunburst' ); ?></label>
                            <select id="rbdusb-folders-most-files-page-size">
                                <option value="6">6</option>
                                <option value="12" selected>12</option>
                                <option value="24">24</option>
                                <option value="48">48</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Largest Plugin Folders -->
            <div id="rbdusb-largest-plugin-folders" class="rbdusb-analysis-section table-view">
                <div class="rbdusb-analysis-section-header">
                    <h3><?php esc_html_e( 'Largest Plugin Folders', 'disk-usage-sunburst' ); ?></h3>
                    <div class="rbdusb-view-mode-toggle">
                        <button type="button" class="rbdusb-view-mode-btn active" data-view="table" data-section="largest-plugin-folders">
                            <?php esc_html_e( 'Table', 'disk-usage-sunburst' ); ?>
                        </button>
                        <button type="button" class="rbdusb-view-mode-btn" data-view="cards" data-section="largest-plugin-folders">
                            <?php esc_html_e( 'Cards', 'disk-usage-sunburst' ); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Table View -->
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Folder', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Total Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'File Count', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'File Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Avg File Size', 'disk-usage-sunburst' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rbdusb-largest-plugin-folders-list">
                        <!-- Largest plugin folders will be loaded here -->
                    </tbody>
                </table>
                
                <!-- Card View -->
                <div id="rbdusb-largest-plugin-folders-cards" class="rbdusb-analysis-cards">
                    <!-- Cards will be loaded here -->
                </div>
                
                <!-- Pagination -->
                <div id="rbdusb-largest-plugin-folders-pagination" class="rbdusb-pagination" style="display: none;">
                    <div class="rbdusb-pagination-info">
                        <span id="rbdusb-largest-plugin-folders-info"></span>
                    </div>
                    <div class="rbdusb-pagination-controls">
                        <button type="button" class="rbdusb-pagination-btn" data-page="prev">&laquo; <?php esc_html_e( 'Previous', 'disk-usage-sunburst' ); ?></button>
                        <div id="rbdusb-largest-plugin-folders-pages"></div>
                        <button type="button" class="rbdusb-pagination-btn" data-page="next"><?php esc_html_e( 'Next', 'disk-usage-sunburst' ); ?> &raquo;</button>
                        <div class="rbdusb-page-size-selector">
                            <label><?php esc_html_e( 'Show:', 'disk-usage-sunburst' ); ?></label>
                            <select id="rbdusb-largest-plugin-folders-page-size">
                                <option value="6">6</option>
                                <option value="12" selected>12</option>
                                <option value="24">24</option>
                                <option value="48">48</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Largest Theme Folders -->
            <div id="rbdusb-largest-theme-folders" class="rbdusb-analysis-section table-view">
                <div class="rbdusb-analysis-section-header">
                    <h3><?php esc_html_e( 'Largest Theme Folders', 'disk-usage-sunburst' ); ?></h3>
                    <div class="rbdusb-view-mode-toggle">
                        <button type="button" class="rbdusb-view-mode-btn active" data-view="table" data-section="largest-theme-folders">
                            <?php esc_html_e( 'Table', 'disk-usage-sunburst' ); ?>
                        </button>
                        <button type="button" class="rbdusb-view-mode-btn" data-view="cards" data-section="largest-theme-folders">
                            <?php esc_html_e( 'Cards', 'disk-usage-sunburst' ); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Table View -->
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Folder', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Total Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'File Count', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'File Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Avg File Size', 'disk-usage-sunburst' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rbdusb-largest-theme-folders-list">
                        <!-- Largest theme folders will be loaded here -->
                    </tbody>
                </table>
                
                <!-- Card View -->
                <div id="rbdusb-largest-theme-folders-cards" class="rbdusb-analysis-cards">
                    <!-- Cards will be loaded here -->
                </div>
                
                <!-- Pagination -->
                <div id="rbdusb-largest-theme-folders-pagination" class="rbdusb-pagination" style="display: none;">
                    <div class="rbdusb-pagination-info">
                        <span id="rbdusb-largest-theme-folders-info"></span>
                    </div>
                    <div class="rbdusb-pagination-controls">
                        <button type="button" class="rbdusb-pagination-btn" data-page="prev">&laquo; <?php esc_html_e( 'Previous', 'disk-usage-sunburst' ); ?></button>
                        <div id="rbdusb-largest-theme-folders-pages"></div>
                        <button type="button" class="rbdusb-pagination-btn" data-page="next"><?php esc_html_e( 'Next', 'disk-usage-sunburst' ); ?> &raquo;</button>
                        <div class="rbdusb-page-size-selector">
                            <label><?php esc_html_e( 'Show:', 'disk-usage-sunburst' ); ?></label>
                            <select id="rbdusb-largest-theme-folders-page-size">
                                <option value="6">6</option>
                                <option value="12" selected>12</option>
                                <option value="24">24</option>
                                <option value="48">48</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- WP-Content/Uploads Summary -->
            <div id="rbdusb-uploads-summary" class="rbdusb-analysis-section table-view">
                <div class="rbdusb-analysis-section-header">
                    <h3><?php esc_html_e( 'WP-Content/Uploads Summary', 'disk-usage-sunburst' ); ?></h3>
                    <div class="rbdusb-view-mode-toggle">
                        <button type="button" class="rbdusb-view-mode-btn active" data-view="table" data-section="uploads-summary">
                            <?php esc_html_e( 'Table', 'disk-usage-sunburst' ); ?>
                        </button>
                        <button type="button" class="rbdusb-view-mode-btn" data-view="cards" data-section="uploads-summary">
                            <?php esc_html_e( 'Cards', 'disk-usage-sunburst' ); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Table View -->
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Year/Month', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Total Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'File Count', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'File Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Avg File Size', 'disk-usage-sunburst' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rbdusb-uploads-summary-list">
                        <!-- WP-Content/Uploads summary will be loaded here -->
                    </tbody>
                </table>
                
                <!-- Card View -->
                <div id="rbdusb-uploads-summary-cards" class="rbdusb-analysis-cards">
                    <!-- Cards will be loaded here -->
                </div>
                
                <!-- Pagination -->
                <div id="rbdusb-uploads-summary-pagination" class="rbdusb-pagination" style="display: none;">
                    <div class="rbdusb-pagination-info">
                        <span id="rbdusb-uploads-summary-info"></span>
                    </div>
                    <div class="rbdusb-pagination-controls">
                        <button type="button" class="rbdusb-pagination-btn" data-page="prev">&laquo; <?php esc_html_e( 'Previous', 'disk-usage-sunburst' ); ?></button>
                        <div id="rbdusb-uploads-summary-pages"></div>
                        <button type="button" class="rbdusb-pagination-btn" data-page="next"><?php esc_html_e( 'Next', 'disk-usage-sunburst' ); ?> &raquo;</button>
                        <div class="rbdusb-page-size-selector">
                            <label><?php esc_html_e( 'Show:', 'disk-usage-sunburst' ); ?></label>
                            <select id="rbdusb-uploads-summary-page-size">
                                <option value="6">6</option>
                                <option value="12" selected>12</option>
                                <option value="24">24</option>
                                <option value="48">48</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- File Types -->
            <div id="rbdusb-file-types" class="rbdusb-analysis-section table-view">
                <div class="rbdusb-analysis-section-header">
                    <h3><?php esc_html_e( 'File Types Breakdown', 'disk-usage-sunburst' ); ?></h3>
                    <div class="rbdusb-view-mode-toggle">
                        <button type="button" class="rbdusb-view-mode-btn active" data-view="table" data-section="file-types">
                            <?php esc_html_e( 'Table', 'disk-usage-sunburst' ); ?>
                        </button>
                        <button type="button" class="rbdusb-view-mode-btn" data-view="cards" data-section="file-types">
                            <?php esc_html_e( 'Cards', 'disk-usage-sunburst' ); ?>
                        </button>
                    </div>
                </div>
                
                <!-- Table View -->
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'File Extension', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Size', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Percentage', 'disk-usage-sunburst' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'disk-usage-sunburst' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="rbdusb-file-types-list">
                        <!-- File types will be loaded here -->
                    </tbody>
                </table>
                
                <!-- Card View -->
                <div id="rbdusb-file-types-cards" class="rbdusb-analysis-cards">
                    <!-- Cards will be loaded here -->
                </div>
                
                <!-- Pagination -->
                <div id="rbdusb-file-types-pagination" class="rbdusb-pagination" style="display: none;">
                    <div class="rbdusb-pagination-info">
                        <span id="rbdusb-file-types-info"></span>
                    </div>
                    <div class="rbdusb-pagination-controls">
                        <button type="button" class="rbdusb-pagination-btn" data-page="prev">&laquo; <?php esc_html_e( 'Previous', 'disk-usage-sunburst' ); ?></button>
                        <div id="rbdusb-file-types-pages"></div>
                        <button type="button" class="rbdusb-pagination-btn" data-page="next"><?php esc_html_e( 'Next', 'disk-usage-sunburst' ); ?> &raquo;</button>
                        <div class="rbdusb-page-size-selector">
                            <label><?php esc_html_e( 'Show:', 'disk-usage-sunburst' ); ?></label>
                            <select id="rbdusb-file-types-page-size">
                                <option value="6">6</option>
                                <option value="12" selected>12</option>
                                <option value="24">24</option>
                                <option value="48">48</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Export Options -->
        <div id="rbdusb-export" class="rbdusb-export" style="display: none;">
            <h3><?php esc_html_e( 'Export Options', 'disk-usage-sunburst' ); ?></h3>
            <button type="button" id="rbdusb-export-png" class="button">
                <?php esc_html_e( 'Export as PNG', 'disk-usage-sunburst' ); ?>
            </button>
            <button type="button" id="rbdusb-export-svg" class="button">
                <?php esc_html_e( 'Export as SVG', 'disk-usage-sunburst' ); ?>
            </button>
            <button type="button" id="rbdusb-export-json" class="button">
                <?php esc_html_e( 'Export as JSON', 'disk-usage-sunburst' ); ?>
            </button>
        </div>
    </div>

    <!-- Settings Section -->
    <div id="rbdusb-settings" class="rbdusb-settings" style="display: none;">
        <h2><?php esc_html_e( 'Performance Settings', 'disk-usage-sunburst' ); ?></h2>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'rbdusb_settings' );
            do_settings_sections( 'rbdusb_settings' );
            submit_button();
            ?>
        </form>
    </div>

    <!-- Settings Toggle -->
    <p>
        <button type="button" id="rbdusb-toggle-settings" class="button button-link">
            <?php esc_html_e( 'Show Advanced Settings', 'disk-usage-sunburst' ); ?>
        </button>
    </p>
    
    <!-- Background Progress Indicator -->
    <div id="rbdusb-background-progress" class="rbdusb-background-progress">
        <div class="rbdusb-background-progress-text"><?php esc_html_e( 'Processing data...', 'disk-usage-sunburst' ); ?></div>
        <div class="rbdusb-background-progress-bar">
            <div class="rbdusb-background-progress-fill"></div>
        </div>
    </div>
</div>