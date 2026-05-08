/**
 * Disk Usage Sunburst - Admin JavaScript
 * Modern ES6+ implementation with D3.js v7
 */

(function($) {
    'use strict';

    /**
     * Main DiskUsage class
     */
    class DiskUsageAnalyzer {
        constructor() {
            this.svg = null;
            this.tooltip = null;
            this.data = null;
            this.currentNode = null;
            this.analysisData = null;
            this.currentSnapshotId = null;
            this.currentView = 'chart'; // 'chart' or 'analysis'
            this.width = 960;
            this.height = 720;
            this.radius = Math.min(this.width, this.height) / 2;
            
            // Pagination state for each section
            this.paginationState = {
                'wordpress-breakdown': { page: 1, pageSize: 12, viewMode: 'table' },
                'largest-files': { page: 1, pageSize: 12, viewMode: 'table' },
                'largest-folders': { page: 1, pageSize: 12, viewMode: 'table' },
                'folders-most-files': { page: 1, pageSize: 12, viewMode: 'table' },
                'largest-plugin-folders': { page: 1, pageSize: 12, viewMode: 'table' },
                'largest-theme-folders': { page: 1, pageSize: 12, viewMode: 'table' },
                'uploads-summary': { page: 1, pageSize: 12, viewMode: 'table' },
                'file-types': { page: 1, pageSize: 12, viewMode: 'table' }
            };
            
            // Color scale - D3.js v7 compatible color scheme
            try {
                // Try using available color schemes in D3.js v7
                let colors = [];
                if (d3.schemeCategory10) colors = colors.concat(d3.schemeCategory10);
                if (d3.schemeSet3) colors = colors.concat(d3.schemeSet3);
                
                // Add additional colors for better variety
                const additionalColors = [
                    '#ff6b6b', '#4ecdc4', '#45b7d1', '#96ceb4', '#feca57', '#ff9ff3',
                    '#54a0ff', '#5f27cd', '#c44569', '#f0932b', '#eb4d4b', '#6ab04c',
                    '#f9ca24', '#686de0', '#4834d4', '#dda0dd', '#98d8c8', '#06a77d'
                ];
                colors = colors.concat(additionalColors);
                
                this.color = d3.scaleOrdinal(colors);
            } catch (error) {
                console.error('Color scale error:', error);
                // Fallback to simple colors
                this.color = d3.scaleOrdinal([
                    '#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd', '#8c564b',
                    '#e377c2', '#7f7f7f', '#bcbd22', '#17becf', '#aec7e8', '#ffbb78'
                ]);
            }
            
            // Initialize when document is ready
            this.init();
        }

        /**
         * Initialize the analyzer
         */
        init() {
            console.log('DiskUsageAnalyzer initializing...');
            
            if (!this.checkSVGSupport()) {
                console.error('SVG not supported');
                return;
            }
            
            this.bindEvents();
            this.setupTooltip();
            this.loadSnapshots();
            
            console.log('DiskUsageAnalyzer initialized successfully');
        }

        /**
         * Check SVG support
         */
        checkSVGSupport() {
            if (!document.createElementNS || !document.createElementNS('http://www.w3.org/2000/svg', 'svg').createSVGRect) {
                $('#rbdusb-svg-not-supported').show().html(
                    '<p>' + (rbdusbAjax.strings.svg_not_supported || 'SVG not supported') + '</p>'
                );
                console.error('SVG not supported by browser');
                return false;
            }
            console.log('SVG support confirmed');
            return true;
        }

        /**
         * Bind event handlers
         */
        bindEvents() {
            $('#rbdusb-start-scan').on('click', () => this.startScan());
            $('#rbdusb-clear-cache').on('click', () => this.clearCache());
            $('#rbdusb-toggle-settings').on('click', () => this.toggleSettings());
            $('#rbdusb-export-png').on('click', () => this.exportPNG());
            $('#rbdusb-export-svg').on('click', () => this.exportSVG());
            $('#rbdusb-export-json').on('click', () => this.exportJSON());
            
            // View toggle handlers
            $('#rbdusb-show-chart').on('click', () => this.switchToChartView());
            $('#rbdusb-show-analysis').on('click', () => this.switchToAnalysisView());
            
            // View mode toggle handlers (table vs cards)
            $(document).on('click', '.rbdusb-view-mode-btn', (e) => this.switchViewMode(e));
            
            // Pagination handlers
            $(document).on('click', '.rbdusb-pagination-btn', (e) => this.handlePagination(e));
            $(document).on('change', '[id$="-page-size"]', (e) => this.handlePageSizeChange(e));
            
            // Keyboard navigation
            $(document).on('keydown', (e) => this.handleKeydown(e));
        }

        /**
         * Setup tooltip
         */
        setupTooltip() {
            this.tooltip = d3.select('#rbdusb-chart-container')
                .append('div')
                .attr('id', 'rbdusb-tooltip')
                .style('opacity', 0);
        }

        /**
         * Start scanning process
         */
        async startScan() {
            const path = rbdusbAjax.abspath; // Always use WordPress root directory
            const useCache = $('#rbdusb-use-cache').is(':checked');
            
            try {
                this.showProgress();
                this.hideInstructions();
                this.clearMessages();
                
                console.log('Starting scan for path:', path);
                
                const response = await this.performScan(path, useCache);
                
                console.log('Scan response received:', response);
                
                // Handle both new and legacy response formats
                if (response.success === true) {
                    // New format
                    this.data = response.data;
                    this.currentSnapshotId = response.data.snapshot_id || null;
                    this.renderChart();
                    this.showStats(response.data.metadata);
                    this.showExportOptions();
                    this.showViewToggle();
                    this.showSuccess(rbdusbAjax.strings.complete);
                    
                    // Start background analysis for better performance
                    this.preloadAnalysis();
                    
                    // Reload snapshots if a new one was created
                    if (response.data.snapshot_id) {
                        this.loadSnapshots();
                    }
                } else if (response.error) {
                    // Legacy error format
                    this.showError(response.error);
                } else if (response.name && response.size !== undefined) {
                    // Legacy success format - direct scan data
                    this.data = response;
                    this.currentSnapshotId = null; // Legacy doesn't have snapshot ID
                    this.renderChart();
                    this.showStats(response.metadata);
                    this.showExportOptions();
                    this.showViewToggle();
                    this.showSuccess(rbdusbAjax.strings.complete);
                    
                    // Start background analysis for better performance
                    this.preloadAnalysis();
                    
                    // For legacy format, always reload snapshots
                    this.loadSnapshots();
                } else {
                    this.showError(rbdusbAjax.strings.error);
                }
            } catch (error) {
                console.error('Scan error:', error);
                this.showError(error.message || rbdusbAjax.strings.error);
            } finally {
                this.hideProgress();
            }
        }

        /**
         * Perform the actual scan via AJAX
         */
        async performScan(path, useCache) {
            return new Promise((resolve, reject) => {
                if (rbdusbAjax.debug) {
                    console.log('Starting AJAX request with data:', {
                        action: 'rbdusb_data',
                        path: path,
                        use_cache: useCache
                    });
                }

                $.ajax({
                    url: rbdusbAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'rbdusb_data',
                        nonce: rbdusbAjax.nonce,
                        path: path,
                        use_cache: useCache
                    },
                    timeout: 120000, // 2 minute timeout
                    dataType: 'text', // Get as text first to handle both formats
                })
                .done((response, textStatus, xhr) => {
                    if (rbdusbAjax.debug) {
                        console.log('AJAX Response received:', response);
                        console.log('Response type:', typeof response);
                        console.log('Status:', textStatus);
                    }

                    // Handle both old and new response formats
                    let parsedResponse;
                    try {
                        parsedResponse = JSON.parse(response);
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        reject(new Error('Invalid JSON response: ' + response.substring(0, 100)));
                        return;
                    }

                    resolve(parsedResponse);
                })
                .fail((xhr, status, error) => {
                    console.error('AJAX Error Details:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                    
                    let errorMessage = `Request failed: ${status}`;
                    if (xhr.responseText) {
                        errorMessage += ` - ${xhr.responseText}`;
                    }
                    
                    reject(new Error(errorMessage));
                });
            });
        }

        /**
         * Clear scan cache
         */
        async clearCache() {
            if (!confirm(rbdusbAjax.strings.confirm_clear_cache)) {
                return;
            }

            try {
                const response = await $.post(rbdusbAjax.ajaxurl, {
                    action: 'rbdusb_clear_cache',
                    nonce: rbdusbAjax.nonce
                });

                if (response.success) {
                    this.showSuccess(rbdusbAjax.strings.cache_cleared);
                } else {
                    this.showError(response.data.message);
                }
            } catch (error) {
                this.showError(error.message);
            }
        }

        /**
         * Render the sunburst chart
         */
        renderChart() {
            // Clear previous chart
            d3.select('#rbdusb-svg').remove();
            
            // Create SVG
            this.svg = d3.select('#rbdusb-chart-container')
                .append('svg')
                .attr('id', 'rbdusb-svg')
                .attr('width', this.width)
                .attr('height', this.height)
                .attr('viewBox', `0 0 ${this.width} ${this.height}`)
                .style('max-width', '100%')
                .style('height', 'auto');

            const g = this.svg.append('g')
                .attr('transform', `translate(${this.width / 2},${this.height / 2})`);

            // Create partition layout
            const partition = d3.partition()
                .size([2 * Math.PI, this.radius]);

            // Create hierarchy
            const root = d3.hierarchy(this.data)
                .sum(d => d.size)
                .sort((a, b) => b.value - a.value);

            // Generate the arcs
            partition(root);

            // Create arc generator
            const arc = d3.arc()
                .startAngle(d => d.x0)
                .endAngle(d => d.x1)
                .innerRadius(d => d.y0)
                .outerRadius(d => d.y1);

            // Add arcs
            const paths = g.selectAll('path')
                .data(root.descendants().slice(1))
                .enter()
                .append('path')
                .attr('d', arc)
                .style('fill', d => this.color((d.children ? d : d.parent).data.name))
                .style('cursor', 'pointer')
                .on('click', (event, d) => this.arcClick(event, d))
                .on('mouseover', (event, d) => this.showTooltip(event, d))
                .on('mousemove', (event, d) => this.moveTooltip(event, d))
                .on('mouseout', () => this.hideTooltip());

            // Store current node
            this.currentNode = root;

            // Add center circle for zooming out
            g.append('circle')
                .attr('r', 40)
                .style('fill', '#fff')
                .style('stroke', '#000')
                .style('stroke-width', 2)
                .style('cursor', 'pointer')
                .on('click', () => this.zoomOut());

            // Add center text
            g.append('text')
                .attr('text-anchor', 'middle')
                .attr('dy', '0.35em')
                .style('font-size', '12px')
                .style('font-weight', 'bold')
                .style('pointer-events', 'none')
                .text('↶ Zoom Out');
        }

        /**
         * Handle arc click - zoom in
         */
        arcClick(event, d) {
            if (d === this.currentNode) return;

            this.currentNode = d;
            
            const paths = this.svg.selectAll('path');
            
            // Safer transition without recursion
            paths.transition()
                .duration(750)
                .attrTween('d', (arcData) => {
                    // Store original values
                    const startAngle = arcData.x0;
                    const endAngle = arcData.x1;
                    const innerRadius = arcData.y0;
                    const outerRadius = arcData.y1;
                    
                    // Create interpolators for the zoom transition
                    const xScale = d3.scaleLinear()
                        .domain([d.x0, d.x1])
                        .range([0, 2 * Math.PI]);
                    
                    const yScale = d3.scaleLinear()
                        .domain([d.y0, this.radius])
                        .range([d.y0 ? 40 : 0, this.radius]);
                    
                    return (t) => {
                        const arc = d3.arc()
                            .startAngle(xScale(Math.max(0, Math.min(2 * Math.PI, startAngle))))
                            .endAngle(xScale(Math.max(0, Math.min(2 * Math.PI, endAngle))))
                            .innerRadius(yScale(Math.max(0, innerRadius)))
                            .outerRadius(yScale(Math.max(0, outerRadius)));
                        
                        return arc(arcData);
                    };
                });
        }

        /**
         * Zoom out to parent
         */
        zoomOut() {
            if (!this.currentNode.parent) return;
            
            this.arcClick(null, this.currentNode.parent);
        }

        /**
         * Create arc tween for smooth transitions
         */
        createArcTween(d) {
            const xScale = d3.scaleLinear()
                .domain([d.x0, d.x1])
                .range([0, 2 * Math.PI]);
            
            const yScale = d3.scaleLinear()
                .domain([d.y0, this.radius])
                .range([d.y0 ? 40 : 0, this.radius]);

            return data => {
                return d3.arc()
                    .startAngle(xScale(Math.max(0, Math.min(2 * Math.PI, data.x0))))
                    .endAngle(xScale(Math.max(0, Math.min(2 * Math.PI, data.x1))))
                    .innerRadius(yScale(Math.max(0, data.y0)))
                    .outerRadius(yScale(Math.max(0, data.y1)))(data);
            };
        }

        /**
         * Show tooltip
         */
        showTooltip(event, d) {
            const content = this.getTooltipContent(d);
            
            this.tooltip
                .html(content)
                .style('opacity', 1)
                .classed('visible', true);
                
            this.moveTooltip(event, d);
        }

        /**
         * Move tooltip
         */
        moveTooltip(event) {
            const [x, y] = d3.pointer(event, document.body);
            
            this.tooltip
                .style('left', (x + 15) + 'px')
                .style('top', (y - 10) + 'px');
        }

        /**
         * Hide tooltip
         */
        hideTooltip() {
            this.tooltip
                .style('opacity', 0)
                .classed('visible', false);
        }

        /**
         * Get tooltip content
         */
        getTooltipContent(d) {
            const data = d.data;
            let content = `<strong>${data.name}</strong><br>`;
            content += `Size: ${data.human_size || this.formatBytes(data.size)}<br>`;
            content += `Type: ${data.type}`;
            
            if (data.extension) {
                content += `<br>Extension: .${data.extension}`;
            }
            
            if (data.children) {
                content += `<br>Items: ${data.children.length}`;
            }
            
            return content;
        }

        /**
         * Format bytes to human readable
         */
        formatBytes(bytes, decimals = 2) {
            if (bytes === 0) return '0 Bytes';
            
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
            
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
        }

        /**
         * Show scan statistics
         */
        showStats(metadata) {
            if (!metadata) return;

            $('#rbdusb-stat-size').text(this.formatBytes(metadata.total_size));
            $('#rbdusb-stat-files').text(metadata.files_count.toLocaleString());
            $('#rbdusb-stat-dirs').text(metadata.directories_count.toLocaleString());
            $('#rbdusb-stat-time').text(metadata.scan_time.toFixed(2) + 's');
            
            $('#rbdusb-stats').show();
        }

        /**
         * Show/hide progress indicator
         */
        showProgress() {
            console.log('Showing progress indicator');
            $('#rbdusb-progress').show();
            $('#rbdusb-start-scan').prop('disabled', true).addClass('disabled');
            $('.rbdusb-progress-text').text(rbdusbAjax.strings.scanning || 'Scanning files and directories...');
            
            // Animate progress bar
            const progressBar = $('.rbdusb-progress-bar');
            progressBar.addClass('indeterminate');
        }

        hideProgress() {
            console.log('Hiding progress indicator');
            $('#rbdusb-progress').hide();
            $('#rbdusb-start-scan').prop('disabled', false).removeClass('disabled');
            
            const progressBar = $('.rbdusb-progress-bar');
            progressBar.removeClass('indeterminate');
        }

        /**
         * Clear all messages
         */
        clearMessages() {
            $('#rbdusb-messages').empty();
        }

        /**
         * Show/hide UI elements
         */
        showInstructions() {
            $('#rbdusb-instructions').show();
        }

        hideInstructions() {
            $('#rbdusb-instructions').hide();
        }

        showExportOptions() {
            $('#rbdusb-export').show();
        }

        showViewToggle() {
            $('#rbdusb-view-toggle').show();
        }

        /**
         * Message display methods
         */
        showError(message) {
            this.showMessage(message, 'error');
        }

        showSuccess(message) {
            this.showMessage(message, 'success');
        }

        showMessage(message, type = 'info') {
            const messageDiv = $(`
                <div class="notice notice-${type} is-dismissible">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `);

            $('#rbdusb-messages').html(messageDiv);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                messageDiv.fadeOut(() => messageDiv.remove());
            }, 5000);

            // Handle dismiss button
            messageDiv.find('.notice-dismiss').on('click', () => {
                messageDiv.fadeOut(() => messageDiv.remove());
            });
        }

        /**
         * Toggle settings panel
         */
        toggleSettings() {
            const settings = $('#rbdusb-settings');
            const button = $('#rbdusb-toggle-settings');
            
            if (settings.is(':visible')) {
                settings.slideUp();
                button.text(rbdusbAjax.strings.show_settings);
            } else {
                settings.slideDown();
                button.text(rbdusbAjax.strings.hide_settings);
            }
        }

        /**
         * Export functions
         */
        exportPNG() {
            if (!this.svg) {
                this.showError('No chart data to export');
                return;
            }

            try {
                this.showMessage('Generating PNG export...', 'info');
                
                const svgNode = this.svg.node();
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');
                const img = new Image();

                canvas.width = this.width;
                canvas.height = this.height;

                const svgString = new XMLSerializer().serializeToString(svgNode);
                const svgBlob = new Blob([svgString], { type: 'image/svg+xml' });
                const url = URL.createObjectURL(svgBlob);

                img.onload = () => {
                    try {
                        context.fillStyle = '#ffffff';
                        context.fillRect(0, 0, canvas.width, canvas.height);
                        context.drawImage(img, 0, 0);
                        
                        canvas.toBlob(blob => {
                            if (blob) {
                                this.downloadFile(blob, 'disk-usage-sunburst.png');
                                this.showSuccess('PNG export completed successfully');
                            } else {
                                this.showError('Failed to generate PNG export');
                            }
                        }, 'image/png');
                        
                        URL.revokeObjectURL(url);
                    } catch (error) {
                        console.error('PNG export error:', error);
                        this.showError('Failed to generate PNG: ' + error.message);
                        URL.revokeObjectURL(url);
                    }
                };

                img.onerror = () => {
                    this.showError('Failed to load SVG for PNG conversion');
                    URL.revokeObjectURL(url);
                };

                img.src = url;
                
            } catch (error) {
                console.error('PNG export initialization error:', error);
                this.showError('Failed to initialize PNG export: ' + error.message);
            }
        }

        exportSVG() {
            if (!this.svg) {
                this.showError('No chart data to export');
                return;
            }

            try {
                this.showMessage('Generating SVG export...', 'info');
                
                const svgNode = this.svg.node();
                const svgString = new XMLSerializer().serializeToString(svgNode);
                const svgBlob = new Blob([svgString], { type: 'image/svg+xml;charset=utf-8' });
                
                this.downloadFile(svgBlob, 'disk-usage-sunburst.svg');
                this.showSuccess('SVG export completed successfully');
                
            } catch (error) {
                console.error('SVG export error:', error);
                this.showError('Failed to export SVG: ' + error.message);
            }
        }

        exportJSON() {
            if (!this.data) {
                this.showError('No scan data to export');
                return;
            }

            try {
                this.showMessage('Generating JSON export...', 'info');
                
                const jsonString = JSON.stringify(this.data, null, 2);
                const jsonBlob = new Blob([jsonString], { type: 'application/json;charset=utf-8' });
                
                this.downloadFile(jsonBlob, 'disk-usage-data.json');
                this.showSuccess('JSON export completed successfully');
                
            } catch (error) {
                console.error('JSON export error:', error);
                this.showError('Failed to export JSON: ' + error.message);
            }
        }

        /**
         * Download file helper
         */
        downloadFile(blob, filename) {
            try {
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                a.style.display = 'none';
                
                document.body.appendChild(a);
                
                // Use a timeout to ensure proper cleanup
                setTimeout(() => {
                    a.click();
                    
                    // Clean up after a short delay
                    setTimeout(() => {
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    }, 100);
                }, 0);
                
            } catch (error) {
                console.error('Download error:', error);
                this.showError('Failed to download file: ' + error.message);
            }
        }

        /**
         * Keyboard navigation
         */
        handleKeydown(event) {
            if (!this.svg || !this.currentNode) return;

            switch (event.key) {
                case 'Escape':
                    this.zoomOut();
                    break;
                case 'Home':
                    if (this.data) {
                        this.arcClick(null, d3.hierarchy(this.data));
                    }
                    break;
            }
        }

        /**
         * Load and display snapshots
         */
        async loadSnapshots() {
            try {
                $('#rbdusb-snapshots-loading').show();
                $('#rbdusb-snapshots-empty').hide();
                $('#rbdusb-snapshots-table').hide();

                const response = await $.post(rbdusbAjax.ajaxurl, {
                    action: 'rbdusb_get_snapshots',
                    nonce: rbdusbAjax.nonce
                });

                if (response.success) {
                    this.renderSnapshots(response.data.snapshots);
                } else {
                    this.showError('Failed to load snapshots: ' + response.data.message);
                }
            } catch (error) {
                console.error('Error loading snapshots:', error);
                this.showError('Failed to load snapshots');
            } finally {
                $('#rbdusb-snapshots-loading').hide();
            }
        }

        /**
         * Render snapshots table
         */
        renderSnapshots(snapshots) {
            const $list = $('#rbdusb-snapshots-list');
            $list.empty();

            if (snapshots.length === 0) {
                $('#rbdusb-snapshots-empty').show();
                return;
            }

            snapshots.forEach(snapshot => {
                const row = $(`
                    <tr>
                        <td>
                            <strong>${this.escapeHtml(snapshot.filename)}</strong>
                        </td>
                        <td>${this.escapeHtml(snapshot.human_size)}</td>
                        <td>${snapshot.files_count.toLocaleString()}</td>
                        <td>${this.formatDate(snapshot.created_at)}</td>
                        <td>
                            <button type="button" class="button button-small rbdusb-load-snapshot" 
                                    data-snapshot-id="${this.escapeHtml(snapshot.id)}">
                                Load
                            </button>
                            <button type="button" class="button button-small button-link-delete rbdusb-delete-snapshot" 
                                    data-snapshot-id="${this.escapeHtml(snapshot.id)}">
                                Delete
                            </button>
                        </td>
                    </tr>
                `);
                $list.append(row);
            });

            // Bind snapshot actions
            $('.rbdusb-load-snapshot').on('click', (e) => {
                const snapshotId = $(e.target).data('snapshot-id');
                this.loadSnapshot(snapshotId);
            });

            $('.rbdusb-delete-snapshot').on('click', (e) => {
                const snapshotId = $(e.target).data('snapshot-id');
                if (confirm('Are you sure you want to delete this snapshot?')) {
                    this.deleteSnapshot(snapshotId);
                }
            });

            $('#rbdusb-snapshots-table').show();
        }

        /**
         * Load a specific snapshot
         */
        async loadSnapshot(snapshotId) {
            try {
                this.showMessage('Loading snapshot...', 'info');

                const response = await $.post(rbdusbAjax.ajaxurl, {
                    action: 'rbdusb_load_snapshot',
                    nonce: rbdusbAjax.nonce,
                    snapshot_id: snapshotId
                });

                if (response.success) {
                    this.data = response.data.data;
                    this.currentSnapshotId = snapshotId;
                    this.analysisData = null; // Reset analysis cache
                    this.renderChart();
                    this.showStats(response.data.metadata);
                    this.showExportOptions();
                    this.showViewToggle();
                    this.hideInstructions();
                    this.showSuccess(`Snapshot loaded from ${response.data.created_at}`);
                    
                    // Preload analysis for this snapshot
                    this.preloadAnalysis();
                } else {
                    this.showError('Failed to load snapshot: ' + response.data.message);
                }
            } catch (error) {
                console.error('Error loading snapshot:', error);
                this.showError('Failed to load snapshot');
            }
        }

        /**
         * Delete a snapshot
         */
        async deleteSnapshot(snapshotId) {
            try {
                const response = await $.post(rbdusbAjax.ajaxurl, {
                    action: 'rbdusb_delete_snapshot',
                    nonce: rbdusbAjax.nonce,
                    snapshot_id: snapshotId
                });

                if (response.success) {
                    this.showSuccess('Snapshot deleted successfully');
                    this.loadSnapshots(); // Reload the list
                } else {
                    this.showError('Failed to delete snapshot: ' + response.data.message);
                }
            } catch (error) {
                console.error('Error deleting snapshot:', error);
                this.showError('Failed to delete snapshot');
            }
        }

        /**
         * Escape HTML entities
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Format date for display
         */
        formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        }

        /**
         * Switch to chart view
         */
        switchToChartView() {
            this.currentView = 'chart';
            $('#rbdusb-chart-container').show();
            $('#rbdusb-analysis-container').hide();
            $('#rbdusb-show-chart').addClass('button-primary').removeClass('button-secondary');
            $('#rbdusb-show-analysis').removeClass('button-primary').addClass('button-secondary');
        }

        /**
         * Switch to analysis view
         */
        switchToAnalysisView() {
            this.currentView = 'analysis';
            $('#rbdusb-chart-container').hide();
            $('#rbdusb-analysis-container').show();
            $('#rbdusb-show-analysis').addClass('button-primary').removeClass('button-secondary');
            $('#rbdusb-show-chart').removeClass('button-primary').addClass('button-secondary');

            // Load analysis if not already loaded
            if (!this.analysisData) {
                this.loadAnalysis();
            } else {
                this.renderAnalysis(this.analysisData);
            }
        }

        /**
         * Preload analysis in background for better performance
         */
        async preloadAnalysis() {
            if (!this.currentSnapshotId && !this.data) {
                console.log('No snapshot ID or data for preload analysis');
                return;
            }

            try {
                // Show background progress
                this.showBackgroundProgress('Analyzing data...');
                
                // Use setTimeout to make it truly asynchronous and non-blocking
                setTimeout(async () => {
                    console.log('Preloading analysis in background...', {
                        snapshotId: this.currentSnapshotId,
                        hasData: !!this.data
                    });
                    
                    // Always call backend analysis (with or without snapshot ID)
                    const requestData = {
                        action: 'rbdusb_analyze_data',
                        nonce: rbdusbAjax.nonce
                    };
                    
                    // Add snapshot ID if available
                    if (this.currentSnapshotId) {
                        requestData.snapshot_id = this.currentSnapshotId;
                    }
                    
                    const response = await $.post(rbdusbAjax.ajaxurl, requestData);

                    console.log('Analysis response:', response);

                    if (response.success) {
                        this.analysisData = response.data;
                        console.log('Analysis preloaded successfully', this.analysisData);
                        
                        // Update button to show analysis is ready
                        $('#rbdusb-show-analysis').text('Detailed Analysis ✓');
                    } else {
                        console.error('Analysis failed:', response);
                        // Fallback to local analysis only if backend fails
                        if (this.data) {
                            this.analysisData = this.analyzeDataLocally(this.data);
                            console.log('Fallback local analysis completed', this.analysisData);
                            $('#rbdusb-show-analysis').text('Detailed Analysis ✓');
                        }
                    }
                    
                    // Hide background progress
                    this.hideBackgroundProgress();
                }, 100); // Small delay to ensure UI responsiveness
                
            } catch (error) {
                console.error('Background analysis failed:', error);
                this.hideBackgroundProgress();
                // Don't show error to user since this is background operation
            }
        }

        /**
         * Load analysis data
         */
        async loadAnalysis() {
            if (this.analysisData) {
                this.renderAnalysis(this.analysisData);
                return;
            }

            try {
                $('#rbdusb-analysis-loading').show();
                
                // Always call backend analysis (with or without snapshot ID)
                const requestData = {
                    action: 'rbdusb_analyze_data',
                    nonce: rbdusbAjax.nonce
                };
                
                // Add snapshot ID if available
                if (this.currentSnapshotId) {
                    requestData.snapshot_id = this.currentSnapshotId;
                }
                
                const response = await $.post(rbdusbAjax.ajaxurl, requestData);

                if (response.success) {
                    this.analysisData = response.data;
                    this.renderAnalysis(this.analysisData);
                } else {
                    console.error('Backend analysis failed:', response);
                    this.showError('Failed to analyze data: ' + (response.data?.message || 'Unknown error'));
                    
                    // Fallback to local analysis only if backend fails
                    if (this.data) {
                        console.log('Falling back to local analysis...');
                        this.analysisData = this.analyzeDataLocally(this.data);
                        this.renderAnalysis(this.analysisData);
                    }
                }
                
            } catch (error) {
                console.error('Analysis error:', error);
                this.showError('Failed to load analysis');
            } finally {
                $('#rbdusb-analysis-loading').hide();
            }
        }

        /**
         * Render analysis tables
         */
        renderAnalysis(analysis) {
            console.log('Rendering analysis with', analysis);
            
            // Debug: Check if the new sections have data
            console.log('Analysis data keys:', Object.keys(analysis));
            console.log('WordPress breakdown:', analysis.wordpress_breakdown?.length || 0);
            console.log('Largest files:', analysis.largest_files?.length || 0);
            console.log('Largest folders:', analysis.largest_folders?.length || 0);
            console.log('Folders most files:', analysis.folders_most_files?.length || 0);
            console.log('Largest plugin folders:', analysis.largest_plugin_folders?.length || 0);
            console.log('Largest theme folders:', analysis.largest_theme_folders?.length || 0);
            console.log('Uploads summary:', analysis.uploads_summary?.length || 0);

            // Render WordPress breakdown
            this.renderWordPressBreakdown(analysis.wordpress_breakdown || []);
            
            // Render largest files
            this.renderLargestFiles(analysis.largest_files || []);
            
            // Render largest folders  
            this.renderLargestFolders(analysis.largest_folders || []);
            
            // Render folders with most files
            this.renderFoldersWithMostFiles(analysis.folders_most_files || []);
            
            // Render largest plugin folders
            this.renderLargestPluginFolders(analysis.largest_plugin_folders || []);
            
            // Render largest theme folders
            this.renderLargestThemeFolders(analysis.largest_theme_folders || []);
            
            // Render uploads summary
            this.renderUploadsSummary(analysis.uploads_summary || []);
            
            // Render file types
            this.renderFileTypes(analysis.file_types || []);
        }

        /**
         * Render WordPress breakdown table
         */
        renderWordPressBreakdown(breakdown) {
            this.renderWordPressBreakdownPaginated(breakdown, 'wordpress-breakdown');
        }
        
        /**
         * Render WordPress breakdown with pagination
         */
        renderWordPressBreakdownPaginated(breakdown, section) {
            const state = this.paginationState[section];
            const totalItems = breakdown.length;
            const startIndex = (state.page - 1) * state.pageSize;
            const endIndex = startIndex + state.pageSize;
            const pageData = breakdown.slice(startIndex, endIndex);
            
            // Render table
            const $list = $(`#rbdusb-${section}-list`);
            $list.empty();

            console.log('WordPress breakdown data:', breakdown, 'Length:', breakdown.length, 'PageData:', pageData);
            
            if (breakdown.length === 0) {
                $list.append('<tr><td colspan="4" style="text-align: center; color: #646970; font-style: italic; padding: 40px;">No WordPress breakdown data available</td></tr>');
                return;
            }

            pageData.forEach(item => {
                const row = $(`
                    <tr>
                        <td><strong>${this.escapeHtml(item.category)}</strong></td>
                        <td>${this.escapeHtml(item.human_size)}</td>
                        <td>${item.percentage}%</td>
                        <td><code>${this.escapeHtml(item.path)}</code></td>
                    </tr>
                `);
                $list.append(row);
            });
            
            // Render cards
            if (state.viewMode === 'cards') {
                this.renderCards(`#rbdusb-${section}-cards`, pageData, this.createWordPressCard.bind(this));
            }
            
            // Render pagination
            this.renderPaginationControls(section, totalItems, state.page, state.pageSize);
        }

        /**
         * Render largest files table
         */
        renderLargestFiles(files) {
            this.renderLargestFilesPaginated(files, 'largest-files');
        }
        
        /**
         * Render largest files with pagination
         */
        renderLargestFilesPaginated(files, section) {
            const state = this.paginationState[section];
            const totalItems = files.length;
            const startIndex = (state.page - 1) * state.pageSize;
            const endIndex = startIndex + state.pageSize;
            const pageData = files.slice(startIndex, endIndex);
            
            // Render table
            const $list = $(`#rbdusb-${section}-list`);
            $list.empty();

            console.log('Largest files data:', files, 'Length:', files.length, 'PageData:', pageData);
            
            if (files.length === 0) {
                $list.append('<tr><td colspan="5" style="text-align: center; color: #646970; font-style: italic; padding: 40px;">No files data available</td></tr>');
                return;
            }

            pageData.forEach(file => {
                const row = $(`
                    <tr>
                        <td><strong>${this.escapeHtml(file.name)}</strong></td>
                        <td>${this.escapeHtml(file.human_size)}</td>
                        <td>${file.percentage}%</td>
                        <td><span class="file-ext">.${this.escapeHtml(file.extension)}</span></td>
                        <td><code>${this.escapeHtml(file.relative_path)}</code></td>
                    </tr>
                `);
                $list.append(row);
            });
            
            // Render cards
            if (state.viewMode === 'cards') {
                this.renderCards(`#rbdusb-${section}-cards`, pageData, this.createFileCard.bind(this));
            }
            
            // Render pagination
            this.renderPaginationControls(section, totalItems, state.page, state.pageSize);
        }

        /**
         * Render largest folders table
         */
        renderLargestFolders(folders) {
            this.renderLargestFoldersPaginated(folders, 'largest-folders');
        }
        
        /**
         * Render largest folders with pagination
         */
        renderLargestFoldersPaginated(folders, section) {
            const state = this.paginationState[section];
            const totalItems = folders.length;
            const startIndex = (state.page - 1) * state.pageSize;
            const endIndex = startIndex + state.pageSize;
            const pageData = folders.slice(startIndex, endIndex);
            
            // Render table
            const $list = $(`#rbdusb-${section}-list`);
            $list.empty();

            if (folders.length === 0) {
                $list.append('<tr><td colspan="5" style="text-align: center; color: #646970; font-style: italic; padding: 40px;">No folders data available</td></tr>');
                return;
            }

            pageData.forEach(folder => {
                const row = $(`
                    <tr>
                        <td><strong>${this.escapeHtml(folder.name)}</strong></td>
                        <td>${this.escapeHtml(folder.human_size)}</td>
                        <td>${folder.percentage}%</td>
                        <td>${folder.files_count.toLocaleString()}</td>
                        <td><code>${this.escapeHtml(folder.relative_path)}</code></td>
                    </tr>
                `);
                $list.append(row);
            });
            
            // Render cards
            if (state.viewMode === 'cards') {
                this.renderCards(`#rbdusb-${section}-cards`, pageData, this.createFolderCard.bind(this));
            }
            
            // Render pagination
            this.renderPaginationControls(section, totalItems, state.page, state.pageSize);
        }

        /**
         * Render plugins analysis table
         */
        renderPluginsAnalysis(plugins) {
            const $list = $('#rbdusb-plugins-analysis-list');
            $list.empty();

            if (plugins.length === 0) {
                $list.append('<tr><td colspan="4">No plugins detected</td></tr>');
                return;
            }

            plugins.forEach(plugin => {
                const row = $(`
                    <tr>
                        <td><strong>${this.escapeHtml(plugin.name)}</strong></td>
                        <td>${this.formatBytes(plugin.size)}</td>
                        <td>${plugin.files_count.toLocaleString()}</td>
                        <td><code>${this.escapeHtml(plugin.path)}</code></td>
                    </tr>
                `);
                $list.append(row);
            });
        }

        /**
         * Render folders with most files table
         */
        renderFoldersWithMostFiles(folders) {
            this.renderFoldersWithMostFilesPaginated(folders, 'folders-most-files');
        }
        
        /**
         * Render folders with most files with pagination
         */
        renderFoldersWithMostFilesPaginated(folders, section) {
            const state = this.paginationState[section];
            const totalItems = folders.length;
            const startIndex = (state.page - 1) * state.pageSize;
            const endIndex = startIndex + state.pageSize;
            const pageData = folders.slice(startIndex, endIndex);
            
            // Render table
            const $list = $(`#rbdusb-${section}-list`);
            $list.empty();

            if (folders.length === 0) {
                $list.append('<tr><td colspan="5" style="text-align: center; color: #646970; font-style: italic; padding: 40px;">No folders with most files data available</td></tr>');
                return;
            }

            pageData.forEach(folder => {
                const row = $(`
                    <tr>
                        <td><strong>${this.escapeHtml(folder.name)}</strong></td>
                        <td>${folder.files_count.toLocaleString()}</td>
                        <td>${this.escapeHtml(folder.human_size)}</td>
                        <td>${this.escapeHtml(folder.average_file_size_human)}</td>
                        <td>${folder.percentage}%</td>
                    </tr>
                `);
                $list.append(row);
            });
            
            // Render cards
            if (state.viewMode === 'cards') {
                this.renderCards(`#rbdusb-${section}-cards`, pageData, this.createFolderMostFilesCard.bind(this));
            }
            
            // Render pagination
            this.renderPaginationControls(section, totalItems, state.page, state.pageSize);
        }

        /**
         * Render largest plugin folders table
         */
        renderLargestPluginFolders(plugins) {
            this.renderLargestPluginFoldersPaginated(plugins, 'largest-plugin-folders');
        }
        
        /**
         * Render largest plugin folders with pagination
         */
        renderLargestPluginFoldersPaginated(plugins, section) {
            const state = this.paginationState[section];
            const totalItems = plugins.length;
            const startIndex = (state.page - 1) * state.pageSize;
            const endIndex = startIndex + state.pageSize;
            const pageData = plugins.slice(startIndex, endIndex);
            
            // Render table
            const $list = $(`#rbdusb-${section}-list`);
            $list.empty();

            if (plugins.length === 0) {
                $list.append('<tr><td colspan="5" style="text-align: center; color: #646970; font-style: italic; padding: 40px;">No plugin folders data available</td></tr>');
                return;
            }

            pageData.forEach(plugin => {
                const row = $(`
                    <tr>
                        <td><strong>${this.escapeHtml(plugin.name)}</strong></td>
                        <td>${this.escapeHtml(plugin.human_size)}</td>
                        <td>${plugin.files_count.toLocaleString()}</td>
                        <td>${this.escapeHtml(plugin.human_size)}</td>
                        <td>${this.escapeHtml(plugin.average_file_size_human)}</td>
                    </tr>
                `);
                $list.append(row);
            });
            
            // Render cards
            if (state.viewMode === 'cards') {
                this.renderCards(`#rbdusb-${section}-cards`, pageData, this.createPluginFolderCard.bind(this));
            }
            
            // Render pagination
            this.renderPaginationControls(section, totalItems, state.page, state.pageSize);
        }

        /**
         * Render largest theme folders table
         */
        renderLargestThemeFolders(themes) {
            this.renderLargestThemeFoldersPaginated(themes, 'largest-theme-folders');
        }
        
        /**
         * Render largest theme folders with pagination
         */
        renderLargestThemeFoldersPaginated(themes, section) {
            const state = this.paginationState[section];
            const totalItems = themes.length;
            const startIndex = (state.page - 1) * state.pageSize;
            const endIndex = startIndex + state.pageSize;
            const pageData = themes.slice(startIndex, endIndex);
            
            // Render table
            const $list = $(`#rbdusb-${section}-list`);
            $list.empty();

            if (themes.length === 0) {
                $list.append('<tr><td colspan="5" style="text-align: center; color: #646970; font-style: italic; padding: 40px;">No theme folders data available</td></tr>');
                return;
            }

            pageData.forEach(theme => {
                const row = $(`
                    <tr>
                        <td><strong>${this.escapeHtml(theme.name)}</strong></td>
                        <td>${this.escapeHtml(theme.human_size)}</td>
                        <td>${theme.files_count.toLocaleString()}</td>
                        <td>${this.escapeHtml(theme.human_size)}</td>
                        <td>${this.escapeHtml(theme.average_file_size_human)}</td>
                    </tr>
                `);
                $list.append(row);
            });
            
            // Render cards
            if (state.viewMode === 'cards') {
                this.renderCards(`#rbdusb-${section}-cards`, pageData, this.createThemeFolderCard.bind(this));
            }
            
            // Render pagination
            this.renderPaginationControls(section, totalItems, state.page, state.pageSize);
        }

        /**
         * Render uploads summary table
         */
        renderUploadsSummary(uploads) {
            this.renderUploadsSummaryPaginated(uploads, 'uploads-summary');
        }
        
        /**
         * Render uploads summary with pagination
         */
        renderUploadsSummaryPaginated(uploads, section) {
            const state = this.paginationState[section];
            const totalItems = uploads.length;
            const startIndex = (state.page - 1) * state.pageSize;
            const endIndex = startIndex + state.pageSize;
            const pageData = uploads.slice(startIndex, endIndex);
            
            // Render table
            const $list = $(`#rbdusb-${section}-list`);
            $list.empty();

            if (uploads.length === 0) {
                $list.append('<tr><td colspan="5" style="text-align: center; color: #646970; font-style: italic; padding: 40px;">No uploads data available</td></tr>');
                return;
            }

            pageData.forEach(upload => {
                const row = $(`
                    <tr>
                        <td><strong>${this.escapeHtml(upload.period)}</strong></td>
                        <td>${this.escapeHtml(upload.human_size)}</td>
                        <td>${upload.files_count.toLocaleString()}</td>
                        <td>${this.escapeHtml(upload.human_size)}</td>
                        <td>${this.escapeHtml(upload.average_file_size_human)}</td>
                    </tr>
                `);
                $list.append(row);
            });
            
            // Render cards
            if (state.viewMode === 'cards') {
                this.renderCards(`#rbdusb-${section}-cards`, pageData, this.createUploadsSummaryCard.bind(this));
            }
            
            // Render pagination
            this.renderPaginationControls(section, totalItems, state.page, state.pageSize);
        }

        /**
         * Render file types table
         */
        renderFileTypes(fileTypes) {
            this.renderFileTypesPaginated(fileTypes, 'file-types');
        }
        
        /**
         * Render file types with pagination
         */
        renderFileTypesPaginated(fileTypes, section) {
            const state = this.paginationState[section];
            const totalItems = fileTypes.length;
            const startIndex = (state.page - 1) * state.pageSize;
            const endIndex = startIndex + state.pageSize;
            const pageData = fileTypes.slice(startIndex, endIndex);
            
            // Render table
            const $list = $(`#rbdusb-${section}-list`);
            $list.empty();

            if (fileTypes.length === 0) {
                $list.append('<tr><td colspan="4" style="text-align: center; color: #646970; font-style: italic; padding: 40px;">No file types data available</td></tr>');
                return;
            }

            pageData.forEach(type => {
                const row = $(`
                    <tr>
                        <td><span class="file-ext">${this.escapeHtml(type.extension)}</span></td>
                        <td>${this.escapeHtml(type.human_size)}</td>
                        <td>${type.percentage}%</td>
                        <td>${this.getFileTypeDescription(type.extension)}</td>
                    </tr>
                `);
                $list.append(row);
            });
            
            // Render cards
            if (state.viewMode === 'cards') {
                this.renderCards(`#rbdusb-${section}-cards`, pageData, this.createFileTypeCard.bind(this));
            }
            
            // Render pagination
            this.renderPaginationControls(section, totalItems, state.page, state.pageSize);
        }

        /**
         * Local data analysis fallback for better performance
         */
        analyzeDataLocally(data) {
            // Simple local analysis for immediate results
            return {
                largest_files: [],
                largest_folders: [],
                file_types: [],
                wordpress_breakdown: [],
                plugins_analysis: [],
                summary: {
                    total_size: data.size || 0,
                    files_count: 0,
                    directories_count: 0
                }
            };
        }

        /**
         * Get file type description
         */
        getFileTypeDescription(extension) {
            const types = {
                'jpg': 'Image',
                'jpeg': 'Image',
                'png': 'Image',
                'gif': 'Image',
                'webp': 'Image',
                'pdf': 'Document',
                'doc': 'Document',
                'docx': 'Document',
                'js': 'JavaScript',
                'css': 'Stylesheet',
                'php': 'PHP Script',
                'html': 'HTML',
                'mp4': 'Video',
                'avi': 'Video',
                'mov': 'Video',
                'mp3': 'Audio',
                'wav': 'Audio',
                'zip': 'Archive',
                'tar': 'Archive',
                'gz': 'Archive',
                'sql': 'Database',
                'txt': 'Text',
                'log': 'Log File',
                '': 'No extension'
            };
            return types[extension.toLowerCase()] || 'Unknown';
        }
        
        /**
         * Switch view mode between table and cards
         */
        switchViewMode(event) {
            const $btn = $(event.target);
            const viewMode = $btn.data('view');
            const section = $btn.data('section');
            
            // Update button states
            $btn.siblings().removeClass('active');
            $btn.addClass('active');
            
            // Update section class
            const $section = $(`#rbdusb-${section}`);
            $section.removeClass('table-view card-view').addClass(`${viewMode}-view`);
            
            // Update pagination state
            this.paginationState[section].viewMode = viewMode;
            this.paginationState[section].page = 1; // Reset to first page
            
            // Re-render current data
            if (this.analysisData) {
                this.renderSectionData(section, this.analysisData);
            }
        }
        
        /**
         * Handle pagination clicks
         */
        handlePagination(event) {
            const $btn = $(event.target);
            const $pagination = $btn.closest('.rbdusb-pagination');
            const section = $pagination.attr('id').replace('rbdusb-', '').replace('-pagination', '');
            const page = $btn.data('page');
            
            const state = this.paginationState[section];
            
            if (page === 'prev' && state.page > 1) {
                state.page--;
            } else if (page === 'next') {
                // We'll check max pages in renderSectionData
                state.page++;
            } else if (typeof page === 'number') {
                state.page = page;
            }
            
            // Re-render current data
            if (this.analysisData) {
                this.renderSectionData(section, this.analysisData);
            }
        }
        
        /**
         * Handle page size change
         */
        handlePageSizeChange(event) {
            const $select = $(event.target);
            const section = $select.attr('id').replace('rbdusb-', '').replace('-page-size', '');
            const pageSize = parseInt($select.val());
            
            this.paginationState[section].pageSize = pageSize;
            this.paginationState[section].page = 1; // Reset to first page
            
            // Re-render current data
            if (this.analysisData) {
                this.renderSectionData(section, this.analysisData);
            }
        }
        
        /**
         * Render data for a specific section with pagination
         */
        renderSectionData(section, analysisData) {
            const sectionMap = {
                'wordpress-breakdown': {
                    data: analysisData.wordpress_breakdown || [],
                    renderer: this.renderWordPressBreakdownPaginated.bind(this)
                },
                'largest-files': {
                    data: analysisData.largest_files || [],
                    renderer: this.renderLargestFilesPaginated.bind(this)
                },
                'largest-folders': {
                    data: analysisData.largest_folders || [],
                    renderer: this.renderLargestFoldersPaginated.bind(this)
                },
                'folders-most-files': {
                    data: analysisData.folders_most_files || [],
                    renderer: this.renderFoldersWithMostFilesPaginated.bind(this)
                },
                'largest-plugin-folders': {
                    data: analysisData.largest_plugin_folders || [],
                    renderer: this.renderLargestPluginFoldersPaginated.bind(this)
                },
                'largest-theme-folders': {
                    data: analysisData.largest_theme_folders || [],
                    renderer: this.renderLargestThemeFoldersPaginated.bind(this)
                },
                'uploads-summary': {
                    data: analysisData.uploads_summary || [],
                    renderer: this.renderUploadsSummaryPaginated.bind(this)
                },
                'file-types': {
                    data: analysisData.file_types || [],
                    renderer: this.renderFileTypesPaginated.bind(this)
                }
            };
            
            const sectionInfo = sectionMap[section];
            if (sectionInfo) {
                sectionInfo.renderer(sectionInfo.data, section);
            }
        }
        
        /**
         * Show background progress indicator
         */
        showBackgroundProgress(text = 'Processing data...') {
            const $progress = $('#rbdusb-background-progress');
            $progress.find('.rbdusb-background-progress-text').text(text);
            $progress.addClass('visible');
            $progress.find('.rbdusb-background-progress-fill').addClass('indeterminate');
        }
        
        /**
         * Hide background progress indicator
         */
        hideBackgroundProgress() {
            const $progress = $('#rbdusb-background-progress');
            $progress.removeClass('visible');
            $progress.find('.rbdusb-background-progress-fill').removeClass('indeterminate');
        }
        
        /**
         * Render pagination controls
         */
        renderPaginationControls(section, totalItems, currentPage, pageSize) {
            console.log('Pagination for', section, '- totalItems:', totalItems, 'currentPage:', currentPage, 'pageSize:', pageSize);
            const totalPages = Math.ceil(totalItems / pageSize);
            const $pagination = $(`#rbdusb-${section}-pagination`);
            const $info = $(`#rbdusb-${section}-info`);
            const $pages = $(`#rbdusb-${section}-pages`);
            
            if (totalPages <= 1) {
                $pagination.hide();
                return;
            }
            
            // Update info text
            const startItem = (currentPage - 1) * pageSize + 1;
            const endItem = Math.min(currentPage * pageSize, totalItems);
            $info.text(`Showing ${startItem}-${endItem} of ${totalItems} items`);
            
            // Generate page buttons
            $pages.empty();
            const maxVisiblePages = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
            let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
            
            // Adjust start page if we're near the end
            if (endPage - startPage + 1 < maxVisiblePages) {
                startPage = Math.max(1, endPage - maxVisiblePages + 1);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const $pageBtn = $(`
                    <button type="button" class="rbdusb-pagination-btn ${i === currentPage ? 'current' : ''}" 
                            data-page="${i}">${i}</button>
                `);
                $pages.append($pageBtn);
            }
            
            // Update prev/next button states
            $pagination.find('[data-page="prev"]').toggleClass('disabled', currentPage <= 1);
            $pagination.find('[data-page="next"]').toggleClass('disabled', currentPage >= totalPages);
            
            $pagination.show();
        }
        
        /**
         * Render cards for any data type
         */
        renderCards(containerId, data, cardRenderer) {
            const $container = $(containerId);
            $container.empty();
            
            if (data.length === 0) {
                $container.html('<div class="rbdusb-cards-empty">No data available</div>');
                return;
            }
            
            data.forEach(item => {
                const card = cardRenderer(item);
                $container.append(card);
            });
        }
        
        /**
         * Create a file card
         */
        createFileCard(file) {
            return $(`
                <div class="rbdusb-analysis-card">
                    <div class="rbdusb-card-header">
                        <div class="rbdusb-card-title">${this.escapeHtml(file.name)}</div>
                        <div class="rbdusb-card-badge rbdusb-card-item-ext">${this.escapeHtml(file.extension || 'no ext')}</div>
                    </div>
                    <div class="rbdusb-card-content">
                        <div class="rbdusb-card-item">
                            <div class="rbdusb-card-item-info">
                                <div class="rbdusb-card-item-name">Size</div>
                                <div class="rbdusb-card-item-path">${this.escapeHtml(file.relative_path || file.path || '')}</div>
                            </div>
                            <div class="rbdusb-card-item-meta">
                                <div class="rbdusb-card-item-size">${this.escapeHtml(file.human_size)}</div>
                                <div class="rbdusb-card-item-percentage">${file.percentage}%</div>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }
        
        /**
         * Create a folder card
         */
        createFolderCard(folder) {
            return $(`
                <div class="rbdusb-analysis-card">
                    <div class="rbdusb-card-header">
                        <div class="rbdusb-card-title">${this.escapeHtml(folder.name)}</div>
                        <div class="rbdusb-card-badge">${folder.files_count.toLocaleString()} files</div>
                    </div>
                    <div class="rbdusb-card-content">
                        <div class="rbdusb-card-item">
                            <div class="rbdusb-card-item-info">
                                <div class="rbdusb-card-item-name">Size</div>
                                <div class="rbdusb-card-item-path">${this.escapeHtml(folder.relative_path || folder.path || '')}</div>
                            </div>
                            <div class="rbdusb-card-item-meta">
                                <div class="rbdusb-card-item-size">${this.escapeHtml(folder.human_size)}</div>
                                <div class="rbdusb-card-item-percentage">${folder.percentage}%</div>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }
        
        /**
         * Create a WordPress breakdown card
         */
        createWordPressCard(item) {
            return $(`
                <div class="rbdusb-analysis-card">
                    <div class="rbdusb-card-header">
                        <div class="rbdusb-card-title">${this.escapeHtml(item.category)}</div>
                        <div class="rbdusb-card-badge">${item.percentage}%</div>
                    </div>
                    <div class="rbdusb-card-content">
                        <div class="rbdusb-card-item">
                            <div class="rbdusb-card-item-info">
                                <div class="rbdusb-card-item-name">Size</div>
                                <div class="rbdusb-card-item-path">${this.escapeHtml(item.path)}</div>
                            </div>
                            <div class="rbdusb-card-item-meta">
                                <div class="rbdusb-card-item-size">${this.escapeHtml(item.human_size)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }
        
        /**
         * Create a folder with most files card
         */
        createFolderMostFilesCard(folder) {
            return $(`
                <div class="rbdusb-analysis-card">
                    <div class="rbdusb-card-header">
                        <div class="rbdusb-card-title">${this.escapeHtml(folder.name)}</div>
                        <div class="rbdusb-card-badge">${folder.files_count.toLocaleString()} files</div>
                    </div>
                    <div class="rbdusb-card-content">
                        <div class="rbdusb-card-item">
                            <div class="rbdusb-card-item-info">
                                <div class="rbdusb-card-item-name">Size</div>
                                <div class="rbdusb-card-item-path">${this.escapeHtml(folder.relative_path || folder.path || '')}</div>
                            </div>
                            <div class="rbdusb-card-item-meta">
                                <div class="rbdusb-card-item-size">${this.escapeHtml(folder.human_size)}</div>
                                <div class="rbdusb-card-item-percentage">${folder.percentage}%</div>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }
        
        /**
         * Create a plugin folder card
         */
        createPluginFolderCard(plugin) {
            return $(`
                <div class="rbdusb-analysis-card">
                    <div class="rbdusb-card-header">
                        <div class="rbdusb-card-title">${this.escapeHtml(plugin.name)}</div>
                        <div class="rbdusb-card-badge">${plugin.files_count.toLocaleString()} files</div>
                    </div>
                    <div class="rbdusb-card-content">
                        <div class="rbdusb-card-item">
                            <div class="rbdusb-card-item-info">
                                <div class="rbdusb-card-item-name">Size</div>
                                <div class="rbdusb-card-item-path">${this.escapeHtml(plugin.path)}</div>
                            </div>
                            <div class="rbdusb-card-item-meta">
                                <div class="rbdusb-card-item-size">${this.escapeHtml(plugin.human_size)}</div>
                                <div class="rbdusb-card-item-percentage">Avg: ${this.escapeHtml(plugin.average_file_size_human)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }
        
        /**
         * Create a theme folder card
         */
        createThemeFolderCard(theme) {
            return $(`
                <div class="rbdusb-analysis-card">
                    <div class="rbdusb-card-header">
                        <div class="rbdusb-card-title">${this.escapeHtml(theme.name)}</div>
                        <div class="rbdusb-card-badge">${theme.files_count.toLocaleString()} files</div>
                    </div>
                    <div class="rbdusb-card-content">
                        <div class="rbdusb-card-item">
                            <div class="rbdusb-card-item-info">
                                <div class="rbdusb-card-item-name">Size</div>
                                <div class="rbdusb-card-item-path">${this.escapeHtml(theme.path)}</div>
                            </div>
                            <div class="rbdusb-card-item-meta">
                                <div class="rbdusb-card-item-size">${this.escapeHtml(theme.human_size)}</div>
                                <div class="rbdusb-card-item-percentage">Avg: ${this.escapeHtml(theme.average_file_size_human)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }
        
        /**
         * Create an uploads summary card
         */
        createUploadsSummaryCard(upload) {
            return $(`
                <div class="rbdusb-analysis-card">
                    <div class="rbdusb-card-header">
                        <div class="rbdusb-card-title">${this.escapeHtml(upload.period)}</div>
                        <div class="rbdusb-card-badge">${upload.files_count.toLocaleString()} files</div>
                    </div>
                    <div class="rbdusb-card-content">
                        <div class="rbdusb-card-item">
                            <div class="rbdusb-card-item-info">
                                <div class="rbdusb-card-item-name">Size</div>
                                <div class="rbdusb-card-item-path">wp-content/uploads/${this.escapeHtml(upload.period)}</div>
                            </div>
                            <div class="rbdusb-card-item-meta">
                                <div class="rbdusb-card-item-size">${this.escapeHtml(upload.human_size)}</div>
                                <div class="rbdusb-card-item-percentage">Avg: ${this.escapeHtml(upload.average_file_size_human)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }
        
        /**
         * Create a file type card
         */
        createFileTypeCard(type) {
            return $(`
                <div class="rbdusb-analysis-card">
                    <div class="rbdusb-card-header">
                        <div class="rbdusb-card-title">${this.escapeHtml(type.extension || 'No extension')}</div>
                        <div class="rbdusb-card-badge">${type.percentage}%</div>
                    </div>
                    <div class="rbdusb-card-content">
                        <div class="rbdusb-card-item">
                            <div class="rbdusb-card-item-info">
                                <div class="rbdusb-card-item-name">Type</div>
                                <div class="rbdusb-card-item-path">${this.getFileTypeDescription(type.extension)}</div>
                            </div>
                            <div class="rbdusb-card-item-meta">
                                <div class="rbdusb-card-item-size">${this.escapeHtml(type.human_size)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `);
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        // Check if we're on the plugin page
        if ($('#rbdusb-start-scan').length) {
            new DiskUsageAnalyzer();
        }
    });

})(jQuery);