/**
 * FeatherLift Media Admin JavaScript
 */
(function($) {
    'use strict';

    window.enhancedS3 = {
        
        /**
         * Initialize the admin interface
         */
        init: function() {
            this.bindEvents();
            this.loadLogs();
            this.startStatusPolling();
            this.initFeatherliteScan();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Setup AWS resources
            $(document).on('click', '#setup-aws-resources', this.setupAWSResources);
            
            // Refresh logs
            $(document).on('click', '#refresh-logs', this.loadLogs);
            
            // Filter logs
            $(document).on('change', '#status-filter, #operation-filter', this.loadLogs);
            
            // Media modal events
            $(document).on('click', '.enhanced-s3-upload-btn', function(e) {
                e.preventDefault();
                var attachmentId = $(this).data('attachment-id');
                enhancedS3.queueUpload(attachmentId);
            });
            
            $(document).on('click', '.enhanced-s3-download-btn', function(e) {
                e.preventDefault();
                var attachmentId = $(this).data('attachment-id');
                enhancedS3.queueDownload(attachmentId);
            });

            $(document).on('click', '.enhanced-s3-generate-alt', function(e) {
                e.preventDefault();
                var attachmentId = $(this).data('attachment-id');
                enhancedS3.generateAltTag(attachmentId, $(this));
            });
        },

        initFeatherliteScan: function() {
            var self = this;
            var $boxes = $('.featherlite-scan');

            if (!$boxes.length) {
                return;
            }

            $boxes.each(function() {
                var $box = $(this);
                var $selectAll = $box.find('.featherlite-select-all-toggle');
                var $bulkBtn = $box.find('.featherlite-optimize-selected');
                var $statusArea = $box.find('.featherlite-bulk-status');

                var syncState = function() {
                    var $eligible = $box.find('.featherlite-row-select:enabled');
                    var $checked = $eligible.filter(':checked');
                    var hasSelection = $checked.length > 0;
                    $bulkBtn.prop('disabled', !hasSelection);
                    $bulkBtn.toggleClass('is-hidden', !hasSelection);
                    if ($eligible.length) {
                        $selectAll.prop('checked', $checked.length === $eligible.length);
                    } else {
                        $selectAll.prop('checked', false);
                    }
                };

                $box.on('change', '.featherlite-row-select', function() {
                    if (!this.checked) {
                        $selectAll.prop('checked', false);
                    } else {
                        var $eligible = $box.find('.featherlite-row-select:enabled');
                        var $checked = $eligible.filter(':checked');
                        if ($eligible.length && $checked.length === $eligible.length) {
                            $selectAll.prop('checked', true);
                        }
                    }
                    syncState();
                });

                $selectAll.on('change', function() {
                    var checked = $(this).is(':checked');
                    $box.find('.featherlite-row-select:enabled').prop('checked', checked);
                    syncState();
                });

                $bulkBtn.on('click', function(e) {
                    e.preventDefault();
                    var ids = $box.find('.featherlite-row-select:enabled:checked').map(function() {
                        return $(this).val();
                    }).get();

                    if (!ids.length) {
                        return;
                    }

                    self.bulkOptimizeSelection(ids, $bulkBtn, $statusArea, syncState);
                });

                syncState();
            });
        },

        bulkOptimizeSelection: function(attachmentIds, $button, $statusArea, afterCallback) {
            if (!attachmentIds.length) {
                return;
            }

            var originalText = $button.text();
            $button.prop('disabled', true).text('Queuing...');

            if ($statusArea && $statusArea.length) {
                $statusArea.html('<div class="notice notice-info inline"><p>Queuing ' + attachmentIds.length + ' file(s)...</p></div>');
            }

            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'bulk_s3_upload',
                    attachment_ids: attachmentIds,
                    nonce: enhancedS3Ajax.nonce
                }
            }).done(function(response) {
                if ($statusArea && $statusArea.length) {
                    if (response.success) {
                        var summary = response.data;
                        var message = 'Optimization queued for ' + summary.success + ' file(s)';
                        if (summary.failed) {
                            message += ' • ' + summary.failed + ' failed';
                        }
                        $statusArea.html('<div class="notice notice-success inline"><p>' + message + '</p></div>');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        var err = typeof response.data === 'string' ? response.data : 'Unable to queue files.';
                        $statusArea.html('<div class="notice notice-error inline"><p>' + err + '</p></div>');
                    }
                }
            }).fail(function() {
                if ($statusArea && $statusArea.length) {
                    $statusArea.html('<div class="notice notice-error inline"><p>Network error while queueing files.</p></div>');
                }
            }).always(function() {
                $button.prop('disabled', false).text(originalText);
                if (typeof afterCallback === 'function') {
                    afterCallback();
                }
            });
        },

        /**
         * Setup AWS resources automatically
         */
        setupAWSResources: function() {
            var $button = $('#setup-aws-resources');
            var $status = $('#setup-status');
            
            $button.prop('disabled', true).text('Setting up...');
            $status.html('<div class="notice notice-info"><p>Setting up AWS resources...</p></div>');
            
            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'setup_aws_resources',
                    nonce: enhancedS3Ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<div class="notice notice-success"><p>AWS resources created successfully!</p><ul>' +
                            '<li><strong>Bucket:</strong> ' + response.data.bucket_name + '</li>' +
                            '<li><strong>Queue:</strong> ' + response.data.queue_url + '</li>' +
                            (response.data.cloudfront_domain ? '<li><strong>CloudFront:</strong> ' + response.data.cloudfront_domain + '</li>' : '') +
                            '</ul></div>');
                        
                        // Reload the page to show updated config
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $status.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $status.html('<div class="notice notice-error"><p>Network error occurred</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Setup AWS Resources');
                }
            });
        },

        /**
         * Queue an upload operation
         */
        queueUpload: function(attachmentId) {
            var $statusDiv = $('#status-' + attachmentId);
            
            $statusDiv.html('<div class="notice notice-info inline"><p>Queuing upload...</p></div>');
            
            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'queue_s3_upload',
                    attachment_id: attachmentId,
                    nonce: enhancedS3Ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $statusDiv.html('<div class="notice notice-success inline"><p>Upload queued successfully!</p></div>');
                        
                        // Start polling for status updates
                        enhancedS3.pollOperationStatus(response.data.log_id, $statusDiv);
                    } else {
                        $statusDiv.html('<div class="notice notice-error inline"><p>Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $statusDiv.html('<div class="notice notice-error inline"><p>Network error occurred</p></div>');
                }
            });
        },

        /**
         * Queue a download operation
         */
        queueDownload: function(attachmentId) {
            var $statusDiv = $('#status-' + attachmentId);
            
            $statusDiv.html('<div class="notice notice-info inline"><p>Queuing download...</p></div>');
            
            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'queue_s3_download',
                    attachment_id: attachmentId,
                    nonce: enhancedS3Ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $statusDiv.html('<div class="notice notice-success inline"><p>Download queued successfully!</p></div>');
                        
                        // Start polling for status updates
                        enhancedS3.pollOperationStatus(response.data.log_id, $statusDiv);
                    } else {
                        $statusDiv.html('<div class="notice notice-error inline"><p>Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $statusDiv.html('<div class="notice notice-error inline"><p>Network error occurred</p></div>');
                }
            });
        },

        /**
         * Poll operation status
         */
        pollOperationStatus: function(logId, $statusDiv) {
            var pollInterval = setInterval(function() {
                $.ajax({
                    url: enhancedS3Ajax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_operation_status',
                        log_id: logId,
                        nonce: enhancedS3Ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var log = response.data;
                            var statusClass = 'notice-info';
                            var statusText = log.status.charAt(0).toUpperCase() + log.status.slice(1);
                            
                            if (log.status === 'completed') {
                                statusClass = 'notice-success';
                                clearInterval(pollInterval);
                                
                                // Refresh the page to show updated status
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else if (log.status === 'failed') {
                                statusClass = 'notice-error';
                                statusText += ': ' + (log.error_message || 'Unknown error');
                                clearInterval(pollInterval);
                            }
                            
                            $statusDiv.html('<div class="notice ' + statusClass + ' inline"><p>' + statusText + '</p></div>');
                        }
                    }
                });
            }, 2000); // Poll every 2 seconds
            
            // Stop polling after 5 minutes
            setTimeout(function() {
                clearInterval(pollInterval);
            }, 300000);
        },

        /**
         * Load and display logs
         */
        loadLogs: function() {
            if (!$('#logs-tbody').length) {
                return; // Not on logs page
            }
            
            var statusFilter = $('#status-filter').val();
            var operationFilter = $('#operation-filter').val();
            
            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_logs',
                    status: statusFilter,
                    operation: operationFilter,
                    limit: 50,
                    offset: 0,
                    nonce: enhancedS3Ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        enhancedS3.renderLogs(response.data);
                    }
                }
            });

            enhancedS3.loadQueueOverview();
        },

        loadQueueOverview: function() {
            if (!$('#queue-overview').length) {
                return;
            }

            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'get_log_stats',
                    nonce: enhancedS3Ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        enhancedS3.renderQueueOverview(response.data);
                    }
                }
            });
        },

        /**
         * Render logs in the table
         */
        renderLogs: function(logs) {
            var $tbody = $('#logs-tbody');
            $tbody.empty();
            
            if (logs.length === 0) {
                $tbody.append('<tr><td colspan="8">No logs found</td></tr>');
                return;
            }
            
            $.each(logs, function(index, log) {
                var statusBadge = enhancedS3.getStatusBadge(log.status);
                var fileSize = log.file_size ? enhancedS3.formatFileSize(log.file_size) : '-';
                var startedAt = log.started_at ? new Date(log.started_at).toLocaleString() : '-';
                var completedAt = log.completed_at ? new Date(log.completed_at).toLocaleString() : '-';
                var notes = enhancedS3.formatJobMeta(log.job_meta);
                var labelMap = {
                    'upload': 'Upload',
                    'download': 'Download',
                    'alt': 'Alt (AI)'
                };
                var opLabel = labelMap[log.operation_type] || log.operation_type;
                
                var actions = '';
                if (log.status === 'failed' && log.error_message) {
                    actions = '<button type="button" class="button button-small" onclick="alert(\'' + 
                              log.error_message.replace(/'/g, "\\'") + '\')">View Error</button>';
                }
                
                var row = '<tr>' +
                    '<td>' + log.id + '</td>' +
                    '<td>' + log.file_name + '</td>' +
                    '<td>' + opLabel + '</td>' +
                    '<td>' + statusBadge + '</td>' +
                    '<td>' + fileSize + '</td>' +
                    '<td>' + startedAt + '</td>' +
                    '<td>' + completedAt + '</td>' +
                    '<td>' + notes + '</td>' +
                    '<td>' + actions + '</td>' +
                    '</tr>';
                
                $tbody.append(row);
            });
        },

        /**
         * Get status badge HTML
         */
        getStatusBadge: function(status) {
            var badges = {
                'requested': '<span class="status-badge status-requested">Requested</span>',
                'in_progress': '<span class="status-badge status-in-progress">In Progress</span>',
                'completed': '<span class="status-badge status-completed">Completed</span>',
                'failed': '<span class="status-badge status-failed">Failed</span>',
                'skipped': '<span class="status-badge status-skipped">Skipped</span>'
            };
            
            return badges[status] || status;
        },

        /**
         * Format file size
         */
        formatFileSize: function(bytes) {
            if (!bytes || bytes <= 0) return '0 Bytes';
            
            var k = 1024;
            var sizes = ['Bytes', 'KB', 'MB', 'GB'];
            var i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        },

        renderQueueOverview: function(data) {
            if (!data) {
                return;
            }

            var overview = data.overview || {};
            var totals = data.totals || {};

            $('#queue-overview .queue-card').each(function() {
                var $card = $(this);
                var type = $card.data('type');
                var counts = overview[type] || {};
                $card.find('[data-field="pending"]').text(counts.requested || 0);
                $card.find('[data-field="in_progress"]').text(counts.in_progress || 0);
                $card.find('[data-field="completed"]').text(counts.completed || 0);
                if ($card.find('[data-field="skipped"]').length) {
                    $card.find('[data-field="skipped"]').text(counts.skipped || 0);
                }

                var totalRow = totals[type] || {};
                if ($card.find('[data-field="completed_size"]').length) {
                    $card.find('[data-field="completed_size"]').text(
                        enhancedS3.formatFileSize(totalRow.completed_size || 0)
                    );
                }
            });
        },

        formatJobMeta: function(meta) {
            if (!meta || ($.isPlainObject(meta) && $.isEmptyObject(meta))) {
                return '-';
            }

            if (typeof meta === 'string') {
                return enhancedS3.escapeHtml(meta);
            }

            var parts = [];
            if (meta.source) {
                parts.push('Source: ' + enhancedS3.escapeHtml(meta.source));
            }
            if (meta.post_id) {
                parts.push('Post ID: ' + meta.post_id);
            }
            if (meta.batch) {
                parts.push('Batch: ' + enhancedS3.escapeHtml(meta.batch));
            }
            if (meta.notes) {
                parts.push(enhancedS3.escapeHtml(meta.notes));
            }
            if (meta.alt_text) {
                parts.push('Alt: ' + enhancedS3.escapeHtml(meta.alt_text));
            }
            if (meta.message) {
                parts.push(enhancedS3.escapeHtml(meta.message));
            }
            if (!parts.length) {
                return '-';
            }
            return parts.join('<br>');
        },

        escapeHtml: function(value) {
            if (value === null || value === undefined) {
                return '';
            }
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        /**
         * Start polling for status updates
         */
        startStatusPolling: function() {
            // Poll for in-progress operations every 30 seconds
            setInterval(function() {
                if ($('#logs-tbody').length) {
                    enhancedS3.loadLogs();
                }
            }, 30000);
        },

        generateAltTag: function(attachmentId, $trigger) {
            if (!attachmentId) {
                return;
            }

            var $status = $('#alt-status-' + attachmentId);
            var $preview = $('#alt-preview-' + attachmentId);

            if ($status.length) {
                $status.html('<div class="notice notice-info inline"><p>' + enhancedS3Ajax.strings.alt_generating + '</p></div>');
            }

            if ($trigger && $trigger.length) {
                $trigger.prop('disabled', true);
            }

            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'generate_ai_alt_tag',
                    attachment_id: attachmentId,
                    nonce: enhancedS3Ajax.nonce
                }
            }).done(function(response) {
                if (response.success && response.data && response.data.alt_text) {
                    if ($preview.length) {
                        $preview.text(response.data.alt_text);
                    }
                    if ($status.length) {
                        var note = response.data.skipped ? (response.data.message || enhancedS3Ajax.strings.alt_skip) : enhancedS3Ajax.strings.alt_success;
                        var klass = response.data.skipped ? 'notice-warning' : 'notice-success';
                        $status.html('<div class="notice ' + klass + ' inline"><p>' + note + '</p></div>');
                    }
                } else {
                    var msg = response.data && response.data.message ? response.data.message : enhancedS3Ajax.strings.alt_error;
                    if ($status.length) {
                        $status.html('<div class="notice notice-error inline"><p>' + msg + '</p></div>');
                    }
                }
            }).fail(function() {
                if ($status.length) {
                    $status.html('<div class="notice notice-error inline"><p>' + enhancedS3Ajax.strings.alt_error + '</p></div>');
                }
            }).always(function() {
                if ($trigger && $trigger.length) {
                    $trigger.prop('disabled', false);
                }
            });
        }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        enhancedS3.init();
        
        // Initialize bulk operations if on bulk page
        if ($('#bulk-operations-form').length) {
            initBulkOperations();
        }
        
        // Manual upload all button
        $('#manual-upload-all').on('click', function() {
            if (confirm('This will queue all local files for S3 upload. Continue?')) {
                manualUploadAll();
            }
        });
        
        // Retry failed operations
        $('#retry-failed').on('click', function() {
            retryFailedOperations();
        });
        
        // Handle compression service changes
        $('#compression-service-select').on('change', function() {
            const selectedService = $(this).val();
            const apiKeyField = $('#tinypng-api-key-field');
            const passwordInput = apiKeyField.find('input[type="password"]');
            const hint = apiKeyField.find('[data-tinypng-hint]');

            if (selectedService === 'tinypng') {
                apiKeyField.attr('data-active', '1');
                passwordInput.prop('disabled', false).focus();
                hint.stop(true, true).slideUp(120);
            } else {
                apiKeyField.attr('data-active', '0');
                passwordInput.prop('disabled', true);
                hint.stop(true, true).slideDown(120);
            }
        });
        
        // Initial state for compression service
        $('#compression-service-select').trigger('change');

        initAISettingsPanel();
        
        $(document).on('click', '.enhanced-s3-clear-secret', function(e) {
            e.preventDefault();
            var field = $(this).data('field');
            if (!field || !confirm('Remove the stored value for this key?')) {
                return;
            }
            $('input[name="enhanced_s3_settings[' + field + '_clear]"]').val('1');
            $('input[name="enhanced_s3_settings[' + field + ']"]').val('');
            $(this).replaceWith('<span class="description">Value will be removed when you save.</span>');
        });

        var resizeToggle = $('#enhanced-s3-auto-resize');
        var resizeInputs = $("input[name='enhanced_s3_settings[resize_max_width]'], input[name='enhanced_s3_settings[resize_max_height]']");
        function toggleResizeInputs() {
            var enabled = resizeToggle.is(':checked');
            resizeInputs.prop('disabled', !enabled);
        }
        if (resizeToggle.length) {
            resizeToggle.on('change', toggleResizeInputs);
            toggleResizeInputs();
        }

        // Reset AWS resources button
        $('#reset-aws-resources').on('click', function(e) {
            e.preventDefault();
            
            var downloadFiles = $('#download-s3-files').is(':checked');
            var deleteAWS = $('#delete-aws-resources').is(':checked');
            
            var confirmMsg = 'This will reset your AWS configuration.\n\n';
            if (downloadFiles) confirmMsg += '✓ Download all S3 files to local storage\n';
            if (deleteAWS) confirmMsg += '✓ Delete all AWS resources (bucket, queue, CloudFront)\n';
            confirmMsg += '\nContinue?';
            
            if (!confirm(confirmMsg)) return;
            
            var $button = $(this);
            var $status = $('#setup-status');
            
            $button.prop('disabled', true).text('Processing...');
            $status.html('<div class="notice notice-info"><p>Processing reset...</p></div>');
            
            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'reset_aws_resources',
                    download_files: downloadFiles,
                    delete_aws_resources: deleteAWS,
                    nonce: enhancedS3Ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        $status.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $status.html('<div class="notice notice-error"><p>Network error occurred</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Reset AWS Configuration');
                }
            });
        });

        // Download all S3 files button
        // Update the download all files JavaScript in admin.js
        $('#download-all-files').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Queue all S3 files for download to local storage?\n\nThis will process in the background and may take time for large libraries.')) return;
            
            var $button = $(this);
            var $status = $('#setup-status');
            
            $button.prop('disabled', true).text('Queueing Downloads...');
            $status.html('<div class="notice notice-info"><p>Queueing all S3 files for download...</p></div>');
            
            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'download_all_s3_files',
                    nonce: enhancedS3Ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    } else {
                        $status.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $status.html('<div class="notice notice-error"><p>Network error occurred</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Download All S3 Files to Local Storage');
                }
            });
        });

        // Add simple reset handler
        $('#simple-reset').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('This will reset plugin configuration but leave all S3 files intact.\n\nYour files will remain on S3 but the plugin will lose track of them.\n\nContinue?')) return;
            
            var $button = $(this);
            var $status = $('#setup-status');
            
            $button.prop('disabled', true).text('Resetting...');
            $status.html('<div class="notice notice-info"><p>Resetting configuration...</p></div>');
            
            $.ajax({
                url: enhancedS3Ajax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'simple_reset',
                    nonce: enhancedS3Ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $status.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $status.html('<div class="notice notice-error"><p>Error: ' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    $status.html('<div class="notice notice-error"><p>Reset failed</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Reset Plugin Configuration Only');
                }
            });
        });
    });

    // Handle media modal
    $(document).on('DOMNodeInserted', function(e) {
        if ($(e.target).hasClass('media-modal')) {
            // Media modal opened, enhance it
            setTimeout(function() {
                enhancedS3.enhanceMediaModal();
            }, 500);
        }
    });

    // Enhance media modal with S3 controls
    enhancedS3.enhanceMediaModal = function() {
        $('.attachment-details').each(function() {
            var $details = $(this);
            var attachmentId = $details.find('[data-setting="id"]').val();
            
            if (!attachmentId || $details.find('.enhanced-s3-controls').length) {
                return; // Already enhanced or no attachment ID
            }
            
            // Add S3 controls
            var controls = '<div class="enhanced-s3-controls" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">' +
                '<h3>S3 Management</h3>' +
                '<button type="button" class="button button-primary enhanced-s3-upload-btn" data-attachment-id="' + attachmentId + '">Upload to S3</button> ' +
                '<button type="button" class="button enhanced-s3-download-btn" data-attachment-id="' + attachmentId + '">Download from S3</button>' +
                '<div id="status-' + attachmentId + '" style="margin-top: 10px;"></div>' +
                '</div>';
            
            $details.find('.settings').append(controls);
        });
    };

    // Bulk Operations JavaScript
    function initBulkOperations() {
        // Checkbox selection handlers
        $('#select-all').on('change', function() {
            $('input[name="attachment_ids[]"]').prop('checked', this.checked);
            updateButtonStates();
        });
        
        $(document).on('change', 'input[name="attachment_ids[]"]', updateButtonStates);
        
        // Selection helper buttons
        $('#select-all-local').on('click', function() {
            $('input[name="attachment_ids[]"][data-local-exists="1"][data-is-s3="0"]').prop('checked', true);
            updateButtonStates();
        });
        
        $('#select-all-s3').on('click', function() {
            $('input[name="attachment_ids[]"][data-is-s3="1"]').prop('checked', true);
            updateButtonStates();
        });
        
        $('#clear-selection').on('click', function() {
            $('input[name="attachment_ids[]"]').prop('checked', false);
            updateButtonStates();
        });
        
        // Bulk operation buttons
        $('#bulk-upload').on('click', performBulkUpload);
        $('#bulk-download').on('click', performBulkDownload);
        $('#bulk-generate-alt').on('click', performBulkAltGeneration);
    }

    function updateButtonStates() {
        var selectedBoxes = $('input[name="attachment_ids[]"]:checked');
        var uploadCount = 0;
        var downloadCount = 0;
        var altCount = 0;
        
        selectedBoxes.each(function() {
            var $this = $(this);
            var isS3 = $this.data('is-s3') == '1';
            var localExists = $this.data('local-exists') == '1';
            var isImage = $this.data('is-image') == '1';
            
            if (localExists && !isS3) {
                uploadCount++;
            }
            if (isS3) {
                downloadCount++;
            }
            if (isImage) {
                altCount++;
            }
        });
        
        $('#upload-count').text(uploadCount);
        $('#download-count').text(downloadCount);
        
        $('#bulk-upload').prop('disabled', uploadCount === 0);
        $('#bulk-download').prop('disabled', downloadCount === 0);
        $('#bulk-generate-alt').prop('disabled', altCount === 0);
    }

    function performBulkUpload() {
        var selectedIds = [];
        $('input[name="attachment_ids[]"]:checked').each(function() {
            var $this = $(this);
            if ($this.data('local-exists') == '1' && $this.data('is-s3') != '1') {
                selectedIds.push($(this).val());
            }
        });
        
        if (selectedIds.length === 0) {
            alert('No local files selected for upload');
            return;
        }
        
        if (!confirm('Upload ' + selectedIds.length + ' files to S3?')) {
            return;
        }
        
        showProgress('Uploading files to S3...');
        
        $.ajax({
            url: enhancedS3Ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bulk_s3_upload',
                attachment_ids: selectedIds,
                nonce: enhancedS3Ajax.nonce
            },
            success: function(response) {
                hideProgress();
                if (response.success) {
                    var result = response.data;
                    var message = 'Upload queued: ' + result.success + ' successful';
                    if (result.failed > 0) {
                        message += ', ' + result.failed + ' failed';
                    }
                    alert(message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                hideProgress();
                alert('Network error occurred');
            }
        });
    }

    function performBulkDownload() {
        var selectedIds = [];
        $('input[name="attachment_ids[]"]:checked').each(function() {
            if ($(this).data('is-s3') == '1') {
                selectedIds.push($(this).val());
            }
        });
        
        if (selectedIds.length === 0) {
            alert('No S3 files selected for download');
            return;
        }
        
        if (!confirm('Download ' + selectedIds.length + ' files from S3?')) {
            return;
        }
        
        showProgress('Downloading files from S3...');
        
        $.ajax({
            url: enhancedS3Ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bulk_s3_download',
                attachment_ids: selectedIds,
                nonce: enhancedS3Ajax.nonce
            },
            success: function(response) {
                hideProgress();
                if (response.success) {
                    var result = response.data;
                    var message = 'Download queued: ' + result.success + ' successful';
                    if (result.failed > 0) {
                        message += ', ' + result.failed + ' failed';
                    }
                    alert(message);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                hideProgress();
                alert('Network error occurred');
            }
        });
    }

    function performBulkAltGeneration() {
        var selectedIds = [];
        $('input[name="attachment_ids[]"]:checked').each(function() {
            selectedIds.push($(this).val());
        });

        if (selectedIds.length === 0) {
            alert('Select at least one media item.');
            return;
        }

        showProgress('Generating alt tags via AI...');

        $.ajax({
            url: enhancedS3Ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'bulk_generate_ai_alt_tags',
                attachment_ids: selectedIds,
                nonce: enhancedS3Ajax.nonce
            }
        }).done(function(response) {
            hideProgress();
            if (response.success && response.data) {
                var data = response.data;
                alert('Alt tags complete. Created: ' + data.success + '\nSkipped: ' + data.skipped + '\nFailed: ' + data.failed);
            } else {
                alert('Unable to generate alt tags for selection.');
            }
        }).fail(function() {
            hideProgress();
            alert('Network error while generating alt tags.');
        });
    }

    function showProgress(message) {
        $('#bulk-progress').show();
        $('#progress-text').text(message);
        $('#progress-fill').css('width', '0%').animate({width: '100%'}, 2000);
    }

    function hideProgress() {
        $('#bulk-progress').hide();
    }

    function manualUploadAll() {
        showProgress('Queuing all local files for upload...');
        
        $.ajax({
            url: enhancedS3Ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'manual_upload_all',
                nonce: enhancedS3Ajax.nonce
            },
            success: function(response) {
                hideProgress();
                if (response.success) {
                    alert('Queued ' + response.data.count + ' files for upload');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                hideProgress();
                alert('Network error occurred');
            }
        });
    }

    function retryFailedOperations() {
        $.ajax({
            url: enhancedS3Ajax.ajaxurl,
            type: 'POST',
            data: {
                action: 'retry_failed_operations',
                nonce: enhancedS3Ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert('Retried ' + response.data.count + ' failed operations');
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                }
            }
        });
    }

    function initAISettingsPanel() {
        if (!enhancedS3Ajax.ai) {
            return;
        }

        var $agent = $('#enhanced-s3-ai-agent');
        var $model = $('#enhanced-s3-ai-model');
        var $credentials = $('.enhanced-s3-ai-credential');

        if (!$agent.length) {
            return;
        }

        var renderModels = function(agent, selected) {
            if (!$model.length) {
                return;
            }
            var options = enhancedS3Ajax.ai.models[agent] || {};
            var html = '';
            $.each(options, function(value, label) {
                var sel = selected ? selected === value : false;
                html += '<option value="' + value + '"' + (sel ? ' selected' : '') + '>' + label + '</option>';
            });
            $model.html(html);
        };

        var toggleCredentials = function(agent) {
            $credentials.each(function() {
                var $block = $(this);
                $block.toggle($block.data('agent') === agent);
            });
        };

        $agent.on('change', function() {
            var agent = $(this).val();
            renderModels(agent, null);
            toggleCredentials(agent);
        });

        toggleCredentials($agent.val());
    }

})(jQuery);