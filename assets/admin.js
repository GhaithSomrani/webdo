
(function($) {
    'use strict';
    
    let isImporting = false;
    let isPaused = false;
    let currentOffset = 0;
    let totalArticles = 0;
    let skipLines = 0;
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        // Bind button events
        $('#start-import').on('click', startImport);
        $('#pause-import').on('click', pauseImport);
        $('#reset-import').on('click', resetImport);
        $('#create-table').on('click', createTable);
        
        // Update start line display when skip lines changes
        $('#skip-lines').on('input', function() {
            skipLines = parseInt($(this).val()) || 0;
            $('#start-line').text(skipLines + 1);
        });
        
        // Initialize skip lines value from input
        skipLines = parseInt($('#skip-lines').val()) || 0;
        $('#start-line').text(skipLines + 1);
        
        // Check status on page load
        checkImportStatus();
    });
    
    function createTable() {
        if (confirm('This will create the necessary database table. Continue?')) {
            $.ajax({
                url: wai_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wai_create_table',
                    nonce: wai_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Table created successfully. Please reload the page.');
                        location.reload();
                    } else {
                        alert('Error creating table: ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                    alert('Error creating table: ' + error);
                }
            });
        }
    }
    
    function startImport() {
        if (isImporting) {
            return;
        }
        
        // Get form values
        const jsonPath = $('#json-path').val();
        const skipImages = $('#skip-images').is(':checked');
        const testMode = $('#test-mode').is(':checked');
        const preserveAuthors = $('#preserve-authors').is(':checked');
        const batchSize = parseInt($('#batch-size').val()) || 10;
        skipLines = parseInt($('#skip-lines').val()) || 0;
        
        if (!jsonPath) {
            alert('Please specify the JSON file path');
            return;
        }
        
        // Validate batch size
        if (batchSize < 1 || batchSize > 50) {
            alert('Batch size must be between 1 and 50');
            return;
        }
        
        // Update UI
        isImporting = true;
        isPaused = false;
        
        // If resuming, use the current offset, otherwise start from the skip lines
        if ($('#start-import').text() === 'Resume Import') {
            // Keep the current offset for resuming
        } else {
            currentOffset = skipLines; // Start from skip lines
        }
        
        $('#start-import').prop('disabled', true);
        $('#pause-import').prop('disabled', false);
        $('#import-progress').show();
        $('#status-text').text('Importing...');
        
        // Start import process
        importBatch({
            json_path: jsonPath,
            skip_images: skipImages,
            test_mode: testMode,
            preserve_authors: preserveAuthors,
            batch_size: batchSize,
            skip_lines: skipLines,
            offset: currentOffset
        });
    }
    
    function pauseImport() {
        isPaused = true;
        isImporting = false;
        $('#pause-import').prop('disabled', true);
        $('#start-import').prop('disabled', false).text('Resume Import');
        $('#status-text').text('Paused');
    }
    
    function resetImport() {
        if (isImporting) {
            if (!confirm('Are you sure you want to reset the import? This will stop the current import process.')) {
                return;
            }
        }
        
        if (!confirm('This will clear all import logs. Are you sure?')) {
            return;
        }
        
        $.ajax({
            url: wai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wai_reset_import',
                nonce: wai_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Reset variables
                    isImporting = false;
                    isPaused = false;
                    currentOffset = 0;
                    totalArticles = 0;
                    
                    // Update UI
                    $('#start-import').prop('disabled', false).text('Start Import');
                    $('#pause-import').prop('disabled', true);
                    $('#import-progress').hide();
                    $('.progress-bar').css('width', '0%');
                    $('#progress-current').text('0');
                    $('#progress-total').text('0');
                    $('#log-entries').html('<tr><td colspan="5">No import logs yet.</td></tr>');
                    
                    updateStats({
                        total: 0,
                        success: 0,
                        failed: 0
                    });
                    
                    alert('Import reset successfully');
                }
            },
            error: function(xhr, status, error) {
                console.error('Reset error:', error);
                alert('Error resetting import: ' + error);
            }
        });
    }
    
    function importBatch(params) {
        if (isPaused || !isImporting) {
            return;
        }
        
        // Show loading indicator if it exists
        if ($('#import-status-indicator').length) {
            $('#import-status-indicator').show();
        }
        
        $.ajax({
            url: wai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wai_import_batch',
                nonce: wai_ajax.nonce,
                ...params
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update total if this is the first batch (adjusting for skip lines)
                    if (currentOffset === skipLines) {
                        totalArticles = data.total + skipLines; // Add back the skipped lines for accurate total
                        $('#progress-total').text(totalArticles);
                    }
                    
                    // Update progress
                    currentOffset += params.batch_size;
                    
                    // Calculate accurate progress percentage
                    const progress = Math.min(((currentOffset - skipLines) / data.total) * 100, 100);
                    $('.progress-bar').css('width', progress + '%');
                    $('#progress-current').text(Math.min(currentOffset, totalArticles));
                    
                    // Update log entries
                    updateLogEntries(data.processed);
                    
                    // Continue with next batch if there are more
                    if (data.has_more && isImporting && !isPaused) {
                        setTimeout(function() {
                            importBatch({
                                ...params,
                                offset: currentOffset
                            });
                        }, 1000); // 1 second delay between batches
                    } else {
                        // Import completed
                        importCompleted();
                    }
                    
                    // Update statistics
                    checkImportStatus();
                } else {
                    console.error('Import error:', response.data.message);
                    alert('Error: ' + response.data.message);
                    pauseImport();
                }
                
                // Hide loading indicator
                if ($('#import-status-indicator').length) {
                    $('#import-status-indicator').hide();
                }
            },
            error: function(xhr, status, error) {
                console.error('Import error:', error);
                alert('Import error: ' + error);
                pauseImport();
                
                // Hide loading indicator
                if ($('#import-status-indicator').length) {
                    $('#import-status-indicator').hide();
                }
            }
        });
    }
    
    function importCompleted() {
        isImporting = false;
        $('#start-import').prop('disabled', false).text('Start Import');
        $('#pause-import').prop('disabled', true);
        $('#status-text').text('Completed');
        currentOffset = 0;
        
        // Show completion message
        alert('Import completed successfully!');
    }
    
    function updateLogEntries(processed) {
        if (!processed || processed.length === 0) {
            return;
        }
        
        // Build HTML for new log entries
        let html = '';
        processed.forEach(function(item) {
            let statusClass = item.status === 'success' ? 'success' : 'error';
            if (item.message && item.message.includes('changing to ID')) {
                statusClass = 'updated';
            }
            const timestamp = new Date().toLocaleString();
            
            html += '<tr>';
            html += '<td>' + escapeHtml(item.id) + '</td>';
            html += '<td>' + escapeHtml(item.title) + '</td>';
            html += '<td><span class="status-' + statusClass + '">' + escapeHtml(item.status) + '</span></td>';
            html += '<td>' + escapeHtml(item.message) + '</td>';
            html += '<td>' + timestamp + '</td>';
            html += '</tr>';
        });
        
        // Prepend new entries to the log
        const $logEntries = $('#log-entries');
        if ($logEntries.find('td[colspan="5"]').length > 0) {
            $logEntries.empty();
        }
        $logEntries.prepend(html);
        
        // Keep only the most recent 20 entries
        $logEntries.find('tr').slice(20).remove();
    }
    
    function checkImportStatus() {
        $.ajax({
            url: wai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wai_check_status',
                nonce: wai_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    const stats = response.data;
                    updateStats(stats);
                    
                    // Update recent logs if available
                    if (stats.recent_logs && stats.recent_logs.length > 0) {
                        updateRecentLogs(stats.recent_logs);
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Status check error:', error);
            }
        });
    }
    
    function updateStats(stats) {
        const $statsGrid = $('.stats-grid');
        
        if ($statsGrid.length === 0) {
            return;
        }
        
        // Update stat numbers
        $statsGrid.find('.stat-item:eq(0) .stat-number').text(stats.total || 0);
        $statsGrid.find('.stat-item:eq(1) .stat-number').text(stats.success || 0);
        $statsGrid.find('.stat-item:eq(2) .stat-number').text(stats.failed || 0);
    }
    
    function updateRecentLogs(logs) {
        let html = '';
        
        if (logs.length === 0) {
            html = '<tr><td colspan="5">No import logs yet.</td></tr>';
        } else {
            logs.forEach(function(log) {
                let statusClass = log.status === 'success' ? 'success' : 'error';
                if (log.message && log.message.includes('changing to ID')) {
                    statusClass = 'updated';
                }
                
                html += '<tr>';
                html += '<td>' + escapeHtml(log.article_id) + '</td>';
                html += '<td>' + escapeHtml(log.title) + '</td>';
                html += '<td><span class="status-' + statusClass + '">' + escapeHtml(log.status) + '</span></td>';
                html += '<td>' + escapeHtml(log.message) + '</td>';
                html += '<td>' + escapeHtml(log.imported_at) + '</td>';
                html += '</tr>';
            });
        }
        
        $('#log-entries').html(html);
    }
    
    function escapeHtml(text) {
        if (text === undefined || text === null) {
            return '';
        }
        
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        
        return String(text).replace(/[&<>"']/g, function(m) {
            return map[m];
        });
    }
    
})(jQuery);