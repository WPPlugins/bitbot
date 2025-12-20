/**
 * BitBot Admin JavaScript
 */
(function($) {
    'use strict';

    const config = window.bitbotAdmin || {};

    /**
     * Initialize admin functionality
     */
    function init() {
        bindSyncButton();
        bindGenerateForm();
        bindDiscoverTopicsButton();
        bindDraftActions();
        bindSourceTypeToggle();
        bindSubscriptionActions();
        bindReactivateButton();
        bindReconnectButton();
    }

    /**
     * Reconnect button functionality
     */
    function bindReconnectButton() {
        const $reconnectBtn = $('#bitbot-reconnect-btn');
        console.log('[BitBot] Binding reconnect button, found:', $reconnectBtn.length);

        $reconnectBtn.on('click', function(e) {
            e.preventDefault();
            console.log('[BitBot] Reconnect clicked');

            const $btn = $(this);
            const originalText = $btn.text();

            $btn.text('Reconnecting...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_reconnect',
                    nonce: config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || 'Site reconnected successfully!');
                        location.reload();
                    } else {
                        alert('Reconnect failed: ' + (response.data?.error || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    alert('Failed to reconnect: ' + error);
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        });
    }

    /**
     * Sync button functionality
     */
    function bindSyncButton() {
        $('#bitbot-sync-btn').on('click', function(e) {
            e.preventDefault();

            const $btn = $(this);
            const originalText = $btn.text();

            $btn.text('Syncing... please wait').addClass('syncing').prop('disabled', true);

            // Show progress indicator
            const $status = $('<div id="sync-progress" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-radius: 4px;"><span class="spinner is-active" style="float: none; margin: 0 8px 0 0;"></span>Syncing content... This may take a few minutes for large sites.</div>');
            $btn.after($status);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                timeout: 600000, // 10 minute timeout (batches are 5 min each)
                data: {
                    action: 'bitbot_sync',
                    nonce: config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        const msg = data.message || `Sync complete! ${data.pagesSaved || 0} pages synced.`;
                        alert(msg);
                        location.reload();
                    } else {
                        alert('Sync failed: ' + (response.data?.error || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    if (status === 'timeout') {
                        alert('Sync timed out. Please try again or check if you have a large number of posts.');
                    } else {
                        alert('Failed to connect to server: ' + error);
                    }
                },
                complete: function() {
                    $btn.text(originalText).removeClass('syncing').prop('disabled', false);
                    $('#sync-progress').remove();
                }
            });
        });
    }

    /**
     * Content generation form
     */
    function bindGenerateForm() {
        $('#bitbot-generate-form').on('submit', function(e) {
            e.preventDefault();

            const $form = $(this);
            const $spinner = $('#generate-spinner');
            const $submit = $form.find('button[type="submit"]');

            const sourceType = $('#source_type').val();
            let sourceValue = '';

            if (sourceType === 'prompt') {
                sourceValue = $('#prompt_input').val();
            } else if (sourceType === 'upload') {
                const uploadMethod = $('input[name="upload_method"]:checked').val();

                if (uploadMethod === 'file') {
                    // Handle file upload via presigned URL
                    const file = $('#file_input')[0].files[0];
                    if (file) {
                        handleFileUpload(file, $('#instructions').val(), $spinner, $submit);
                        return;
                    }
                } else {
                    // Handle document URL - generate directly from URL
                    const docUrl = $('#doc_url_input').val();
                    if (docUrl) {
                        handleDocumentUrl(docUrl, $('#instructions').val(), $spinner, $submit);
                        return;
                    }
                }
            }

            if (!sourceValue) {
                alert('Please provide source content');
                return;
            }

            submitGeneration(sourceType, sourceValue, $('#instructions').val(), $spinner, $submit);
        });

        /**
         * Handle file upload via presigned URL (bypasses Vercel limit)
         */
        async function handleFileUpload(file, instructions, $spinner, $submit) {
            // Validate file size (10MB limit)
            const maxSize = 10 * 1024 * 1024; // 10MB in bytes
            if (file.size > maxSize) {
                alert('File size exceeds 10MB limit. Please select a smaller file.');
                return;
            }

            $spinner.addClass('is-active');
            $submit.prop('disabled', true);

            try {
                // Step 1: Get presigned upload URL
                const urlResponse = await $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'bitbot_get_upload_url',
                        nonce: config.nonce,
                        file_name: file.name
                    }
                });

                if (!urlResponse.success) {
                    throw new Error(urlResponse.data?.error || 'Failed to get upload URL');
                }

                const { uploadUrl, storagePath } = urlResponse.data;

                // Step 2: Upload file directly to Supabase Storage
                const uploadResponse = await fetch(uploadUrl, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': file.type
                    },
                    body: file
                });

                if (!uploadResponse.ok) {
                    throw new Error('Failed to upload file to storage');
                }

                // Step 3: Generate post from uploaded file
                const generateResponse = await $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'bitbot_generate_from_storage',
                        nonce: config.nonce,
                        storage_path: storagePath,
                        file_name: file.name,
                        instructions: instructions
                    }
                });

                if (generateResponse.success) {
                    alert('Post generated successfully!');
                    location.reload();
                } else {
                    throw new Error(generateResponse.data?.error || 'Generation failed');
                }
            } catch (error) {
                alert('Generation failed: ' + error.message);
            } finally {
                $spinner.removeClass('is-active');
                $submit.prop('disabled', false);
            }
        }

        /**
         * Handle document URL - generate post directly from a public URL
         */
        async function handleDocumentUrl(docUrl, instructions, $spinner, $submit) {
            $spinner.addClass('is-active');
            $submit.prop('disabled', true);

            try {
                // Extract filename from URL for file type detection
                const urlPath = new URL(docUrl).pathname;
                const fileName = urlPath.split('/').pop() || 'document.pdf';

                const response = await $.ajax({
                    url: config.ajaxUrl,
                    method: 'POST',
                    data: {
                        action: 'bitbot_generate_from_doc_url',
                        nonce: config.nonce,
                        doc_url: docUrl,
                        file_name: fileName,
                        instructions: instructions
                    }
                });

                if (response.success) {
                    alert('Post generated successfully!');
                    location.reload();
                } else {
                    throw new Error(response.data?.error || 'Generation failed');
                }
            } catch (error) {
                alert('Generation failed: ' + error.message);
            } finally {
                $spinner.removeClass('is-active');
                $submit.prop('disabled', false);
            }
        }

        function submitGeneration(type, value, instructions, $spinner, $submit) {
            $spinner.addClass('is-active');
            $submit.prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_generate',
                    nonce: config.nonce,
                    source_type: type,
                    source_value: value,
                    instructions: instructions
                },
                success: function(response) {
                    if (response.success) {
                        alert('Post generated successfully!');
                        location.reload();
                    } else {
                        alert('Generation failed: ' + (response.data?.error || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                },
                complete: function() {
                    $spinner.removeClass('is-active');
                    $submit.prop('disabled', false);
                }
            });
        }
    }

    /**
     * Source type toggle
     */
    function bindSourceTypeToggle() {
        $('#source_type').on('change', function() {
            const type = $(this).val();

            $('#prompt-row, #upload-row').hide();

            if (type === 'prompt') {
                $('#prompt-row').show();
            } else if (type === 'upload') {
                $('#upload-row').show();
            }
        });

        // Toggle between file upload and document URL
        $('input[name="upload_method"]').on('change', function() {
            const method = $(this).val();
            if (method === 'file') {
                $('#file-upload-section').show();
                $('#doc-url-section').hide();
            } else {
                $('#file-upload-section').hide();
                $('#doc-url-section').show();
            }
        });
    }

    /**
     * Discover topics button functionality
     */
    function bindDiscoverTopicsButton() {
        $('#bitbot-discover-topics-btn').on('click', function(e) {
            e.preventDefault();

            const $btn = $(this);
            const $spinner = $('#discover-spinner');
            const $container = $('#bitbot-topics-container');
            const originalText = $btn.text();

            $btn.text('Discovering...').prop('disabled', true);
            $spinner.addClass('is-active');

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                timeout: 60000,
                data: {
                    action: 'bitbot_discover_topics',
                    nonce: config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        renderTopicSuggestions(data.suggestions, data.contentGaps, data.sources);
                        $container.show();
                    } else {
                        alert('Topic discovery failed: ' + (response.data?.error || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    alert('Failed to discover topics: ' + error);
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });
    }

    /**
     * Render topic suggestions
     */
    function renderTopicSuggestions(suggestions, contentGaps, sources) {
        const $gaps = $('#bitbot-content-gaps');
        const $list = $('#bitbot-topics-list');
        $gaps.empty();
        $list.empty();

        // Show content gaps ABOVE the Suggested Topics header
        if (contentGaps && contentGaps.length > 0) {
            let gapsHtml = '<div style="background: rgba(255,255,255,0.2); padding: 10px 12px; border-radius: 6px; margin-bottom: 15px;">';
            gapsHtml += '<strong style="font-size: 13px;">Content gaps identified:</strong>';
            gapsHtml += '<p style="margin: 8px 0 0 0; font-size: 12px; opacity: 0.9;">' + contentGaps.map(escapeHtml).join(' • ') + '</p>';
            gapsHtml += '</div>';
            $gaps.html(gapsHtml);
        }

        if (!suggestions || suggestions.length === 0) {
            $list.html('<p>No topic suggestions available.</p>');
            return;
        }

        // Show topic suggestions
        let html = '<ul style="margin: 0; padding: 0; list-style: none;">';
        suggestions.forEach(function(topic) {
            html += `
                <li style="background: rgba(255,255,255,0.15); padding: 12px; margin-bottom: 10px; border-radius: 6px;">
                    <strong style="font-size: 14px;">${escapeHtml(topic.topic)}</strong>
                    <span style="opacity: 0.8; font-size: 12px; margin-left: 10px;">(${escapeHtml(topic.difficulty)})</span>
                    <p style="margin: 8px 0 10px 0; font-size: 13px; opacity: 0.9;">${escapeHtml(topic.reasoning)}</p>
                    <button class="button generate-from-topic" data-topic="${escapeHtml(topic.topic)}" data-reasoning="${escapeHtml(topic.reasoning)}" style="background: white; color: #667eea; border: none;">
                        Generate This Topic
                    </button>
                </li>
            `;
        });
        html += '</ul>';

        $list.html(html);

        // Bind click events for topic buttons
        $('.generate-from-topic').on('click', function() {
            const topic = $(this).data('topic');
            const reasoning = $(this).data('reasoning');

            // Fill in the prompt field and switch to manual mode
            $('#prompt_input').val('Write about: ' + topic + '\n\n' + reasoning);
            $('#source_type').val('prompt').trigger('change');

            // Scroll to the form
            $('html, body').animate({
                scrollTop: $('#bitbot-generate-form').offset().top - 50
            }, 500);

            // Optionally auto-submit
            if (confirm('Topic loaded into the form. Generate now?')) {
                $('#bitbot-generate-form').submit();
            }
        });
    }

    /**
     * Draft actions (preview, publish, delete)
     */
    function bindDraftActions() {
        // Preview draft
        $('.preview-draft').on('click', function() {
            const draftId = $(this).data('id');
            showPreviewModal(draftId);
        });

        // Publish draft
        $('.publish-draft').on('click', function() {
            const draftId = $(this).data('id');
            const $btn = $(this);

            if (!confirm('Create this as a WordPress draft post?')) {
                return;
            }

            $btn.text('Creating...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_publish_draft',
                    nonce: config.nonce,
                    draft_id: draftId
                },
                success: function(response) {
                    if (response.success) {
                        alert('WordPress draft created! Redirecting to editor...');
                        if (response.data?.edit_url) {
                            window.location.href = response.data.edit_url;
                        } else {
                            location.reload();
                        }
                    } else {
                        alert('Failed: ' + (response.data?.error || 'Unknown error'));
                        $btn.text('Create WP Draft').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                    $btn.text('Create WP Draft').prop('disabled', false);
                }
            });
        });

        // Delete draft
        $('.delete-draft').on('click', function() {
            const draftId = $(this).data('id');
            const $btn = $(this);
            const $row = $btn.closest('tr');

            if (!confirm('Are you sure you want to delete this draft? This cannot be undone.')) {
                return;
            }

            $btn.text('Deleting...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_delete_draft',
                    nonce: config.nonce,
                    draft_id: draftId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Failed: ' + (response.data?.error || 'Unknown error'));
                        $btn.text('Delete').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                    $btn.text('Delete').prop('disabled', false);
                }
            });
        });
    }

    /**
     * Show preview modal
     */
    function showPreviewModal(draftId) {
        // Fetch draft content
        $.ajax({
            url: config.ajaxUrl,
            method: 'GET',
            data: {
                action: 'bitbot_get_draft',
                nonce: config.nonce,
                draft_id: draftId
            },
            success: function(response) {
                if (response.success) {
                    const draft = response.data;
                    renderPreviewModal(draft);
                } else {
                    alert('Failed to load draft');
                }
            },
            error: function() {
                alert('Failed to connect to server');
            }
        });
    }

    /**
     * Render preview modal
     */
    function renderPreviewModal(draft) {
        const seo = draft.seo_meta || {};

        const modalHtml = `
            <div class="bitbot-modal-overlay" id="bitbot-preview-modal">
                <div class="bitbot-modal">
                    <div class="bitbot-modal-header">
                        <h3>${escapeHtml(draft.title)}</h3>
                        <button class="bitbot-modal-close">&times;</button>
                    </div>
                    <div class="bitbot-modal-content">
                        <div class="bitbot-preview-content">
                            ${draft.content}
                        </div>
                        <div class="bitbot-seo-preview">
                            <h4>SEO Preview</h4>
                            <div class="bitbot-seo-title">${escapeHtml(seo.metaTitle || draft.title)}</div>
                            <div class="bitbot-seo-url">example.com/${escapeHtml(draft.slug || 'post')}</div>
                            <div class="bitbot-seo-description">${escapeHtml(seo.metaDescription || '')}</div>
                            ${seo.keywords ? '<p><strong>Keywords:</strong> ' + escapeHtml(seo.keywords.join(', ')) + '</p>' : ''}
                        </div>
                    </div>
                    <div class="bitbot-modal-footer">
                        <button class="button bitbot-modal-close-btn">Close</button>
                        <button class="button button-primary publish-from-modal" data-id="${draft.id}">Create WP Draft</button>
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);

        // Bind close events
        $('#bitbot-preview-modal .bitbot-modal-close, #bitbot-preview-modal .bitbot-modal-close-btn').on('click', function() {
            $('#bitbot-preview-modal').remove();
        });

        $('#bitbot-preview-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).remove();
            }
        });

        // Bind publish from modal
        $('.publish-from-modal').on('click', function() {
            const draftId = $(this).data('id');
            $('#bitbot-preview-modal').remove();
            $(`.publish-draft[data-id="${draftId}"]`).trigger('click');
        });
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Subscription button actions
     */
    function bindSubscriptionActions() {
        // Subscribe button - redirect to Stripe checkout
        $('#bitbot-subscribe-btn').on('click', function(e) {
            e.preventDefault();

            const $btn = $(this);
            const originalText = $btn.text();

            $btn.text('Please wait...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_subscribe',
                    nonce: config.nonce
                },
                success: function(response) {
                    if (response.success && response.data?.checkoutUrl) {
                        // Redirect to Stripe checkout
                        window.location.href = response.data.checkoutUrl;
                    } else {
                        alert('Failed to start checkout: ' + (response.data?.error || 'Unknown error'));
                        $btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        });

        // Cancel subscription button
        $('#bitbot-cancel-btn').on('click', function(e) {
            e.preventDefault();

            if (!confirm('Are you sure you want to cancel your subscription? You will lose access to BitBot features at the end of your billing period.')) {
                return;
            }

            const $btn = $(this);
            const originalText = $btn.text();

            $btn.text('Cancelling...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_cancel_subscription',
                    nonce: config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Subscription cancelled. You will retain access until the end of your billing period.');
                        location.reload();
                    } else {
                        alert('Failed to cancel: ' + (response.data?.error || 'Unknown error'));
                        $btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        });

        // Billing portal button - view invoices and manage billing
        $('#bitbot-billing-btn').on('click', function(e) {
            e.preventDefault();

            const $btn = $(this);
            const originalText = $btn.text();

            $btn.text('Loading...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_billing_portal',
                    nonce: config.nonce
                },
                success: function(response) {
                    if (response.success && response.data?.url) {
                        // Open Stripe billing portal in new tab
                        window.open(response.data.url, '_blank');
                        $btn.text(originalText).prop('disabled', false);
                    } else {
                        alert('Failed to open billing portal: ' + (response.data?.error || 'Unknown error'));
                        $btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        });
    }

    /**
     * Reactivate subscription button (undo cancellation)
     */
    function bindReactivateButton() {
        $('#bitbot-reactivate-btn').on('click', function(e) {
            e.preventDefault();

            if (!confirm('Reactivate your subscription? This will undo the cancellation and your subscription will continue as normal.')) {
                return;
            }

            const $btn = $(this);
            const originalText = $btn.text();

            $btn.text('Reactivating...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_reactivate_subscription',
                    nonce: config.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('Subscription reactivated! Your subscription will continue as normal.');
                        location.reload();
                    } else {
                        alert('Failed to reactivate: ' + (response.data?.error || 'Unknown error'));
                        $btn.text(originalText).prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        });
    }

    /**
     * Conversations functionality with pagination
     */
    let conversationsPage = 1;
    const conversationsPerPage = 10;
    let allConversations = [];

    function loadConversations() {
        const $container = $('#bitbot-conversations-container');
        const $list = $('#bitbot-conversations-list');
        const $tbody = $('#bitbot-conversations-tbody');
        const $btn = $('#bitbot-load-conversations-btn');

        $container.find('.bitbot-loading').remove();
        $container.prepend('<p class="bitbot-loading" style="color: #666;"><span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Loading conversations...</p>');
        $btn.hide();

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'bitbot_get_conversations',
                nonce: config.nonce
            },
            success: function(response) {
                $container.find('.bitbot-loading').remove();

                if (response.success) {
                    allConversations = response.data || [];
                    conversationsPage = 1;
                    renderConversationsPage();
                    $list.show();
                } else {
                    $container.append('<p class="error" style="color: #dc3232;">Failed to load: ' + (response.data?.error || 'Unknown error') + '</p>');
                    $btn.show();
                }
            },
            error: function() {
                $container.find('.bitbot-loading').remove();
                $container.append('<p class="error" style="color: #dc3232;">Failed to connect to server</p>');
                $btn.show();
            }
        });
    }

    function renderConversationsPage() {
        const $tbody = $('#bitbot-conversations-tbody');
        const $pagination = $('#bitbot-conversations-pagination');
        $tbody.empty();

        if (allConversations.length === 0) {
            $tbody.append('<tr><td colspan="5" style="text-align: center; color: #666;">No conversations yet. Chat messages will appear here.</td></tr>');
            $pagination.hide();
            return;
        }

        const start = (conversationsPage - 1) * conversationsPerPage;
        const end = start + conversationsPerPage;
        const pageConversations = allConversations.slice(start, end);

        pageConversations.forEach(function(conv) {
            const sessionShort = conv.sessionId.substring(0, 8);
            const ipDisplay = conv.visitorIp || '-';
            const lastActive = new Date(conv.lastMessageAt).toLocaleString();

            $tbody.append(`
                <tr>
                    <td><code style="font-size: 11px;">${escapeHtml(sessionShort)}</code></td>
                    <td style="font-size: 12px;">${escapeHtml(ipDisplay)}</td>
                    <td style="text-align: center;">${conv.messageCount}</td>
                    <td style="font-size: 12px;">${lastActive}</td>
                    <td><button class="button button-small view-conversation" data-id="${conv.id}">View</button></td>
                </tr>
            `);
        });

        // Render pagination
        const totalPages = Math.ceil(allConversations.length / conversationsPerPage);
        if (totalPages > 1) {
            let paginationHtml = '<div style="margin-top: 15px; display: flex; align-items: center; gap: 10px;">';
            paginationHtml += '<span style="color: #666; font-size: 13px;">Page ' + conversationsPage + ' of ' + totalPages + '</span>';
            paginationHtml += '<div style="display: flex; gap: 5px;">';

            if (conversationsPage > 1) {
                paginationHtml += '<button class="button button-small conversations-prev">&laquo; Prev</button>';
            }
            if (conversationsPage < totalPages) {
                paginationHtml += '<button class="button button-small conversations-next">Next &raquo;</button>';
            }

            paginationHtml += '</div></div>';
            $pagination.html(paginationHtml).show();
        } else {
            $pagination.hide();
        }
    }

    function bindConversationsActions() {
        // Auto-load conversations on page load if the container exists
        if ($('#bitbot-conversations-container').length) {
            loadConversations();
        }

        // Manual refresh button
        $('#bitbot-load-conversations-btn').on('click', function(e) {
            e.preventDefault();
            loadConversations();
        });

        // Pagination
        $(document).on('click', '.conversations-prev', function() {
            if (conversationsPage > 1) {
                conversationsPage--;
                renderConversationsPage();
            }
        });

        $(document).on('click', '.conversations-next', function() {
            const totalPages = Math.ceil(allConversations.length / conversationsPerPage);
            if (conversationsPage < totalPages) {
                conversationsPage++;
                renderConversationsPage();
            }
        });

        // View conversation messages
        $(document).on('click', '.view-conversation', function(e) {
            e.preventDefault();

            const conversationId = $(this).data('id');
            const $btn = $(this);

            $btn.text('...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_get_conversation_messages',
                    nonce: config.nonce,
                    conversation_id: conversationId
                },
                success: function(response) {
                    $btn.text('View').prop('disabled', false);

                    if (response.success) {
                        const messages = response.data;
                        const $container = $('#bitbot-conversation-messages');
                        $container.empty();

                        if (messages.length === 0) {
                            $container.html('<p style="color: #666; text-align: center;">No messages in this conversation.</p>');
                        } else {
                            messages.forEach(function(msg) {
                                const isUser = msg.role === 'user';
                                const bgColor = isUser ? '#e3f2fd' : '#f5f5f5';
                                const label = isUser ? 'Visitor' : 'Bot';
                                const time = new Date(msg.createdAt).toLocaleTimeString();

                                $container.append(`
                                    <div style="background: ${bgColor}; padding: 12px; margin-bottom: 10px; border-radius: 6px;">
                                        <div style="font-size: 11px; color: #666; margin-bottom: 6px;">
                                            <strong>${label}</strong> &middot; ${time}
                                        </div>
                                        <div style="line-height: 1.5;">${escapeHtml(msg.content)}</div>
                                    </div>
                                `);
                            });
                        }

                        $('#bitbot-conversation-modal').show();
                    } else {
                        alert('Failed to load messages: ' + (response.data?.error || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                    $btn.text('View').prop('disabled', false);
                }
            });
        });

        // Close modal
        $('#bitbot-close-modal').on('click', function() {
            $('#bitbot-conversation-modal').hide();
        });

        // Close modal on backdrop click
        $('#bitbot-conversation-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Close on escape
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('#bitbot-conversation-modal').hide();
            }
        });
    }

    /**
     * Pages management functionality
     */
    let allPages = [];

    function loadPages() {
        const $container = $('#bitbot-pages-container');
        const $list = $('#bitbot-pages-list');
        const $tbody = $('#bitbot-pages-tbody');
        const $btn = $('#bitbot-load-pages-btn');

        $container.find('.bitbot-loading').remove();
        $container.prepend('<p class="bitbot-loading" style="color: #666;"><span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span> Loading pages...</p>');
        $btn.hide();

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'bitbot_get_pages',
                nonce: config.nonce
            },
            success: function(response) {
                $container.find('.bitbot-loading').remove();

                if (response.success) {
                    allPages = response.data.pages || [];
                    renderPagesTable();
                    $list.show();
                    $('#bitbot-pages-total').text('Total: ' + response.data.total + ' pages indexed');
                } else {
                    $container.append('<p class="error" style="color: #dc3232;">Failed to load: ' + (response.data?.error || 'Unknown error') + '</p>');
                    $btn.show();
                }
            },
            error: function() {
                $container.find('.bitbot-loading').remove();
                $container.append('<p class="error" style="color: #dc3232;">Failed to connect to server</p>');
                $btn.show();
            }
        });
    }

    function renderPagesTable() {
        const $tbody = $('#bitbot-pages-tbody');
        $tbody.empty();

        if (allPages.length === 0) {
            $tbody.append('<tr><td colspan="5" style="text-align: center; color: #666;">No pages indexed yet. Click "Sync Now" to crawl your site.</td></tr>');
            return;
        }

        allPages.forEach(function(page) {
            const enabledChecked = page.enabled ? 'checked' : '';
            const urlShort = page.url.length > 50 ? page.url.substring(0, 50) + '...' : page.url;
            const title = page.title || '-';
            const summary = page.summary ? (page.summary.length > 80 ? page.summary.substring(0, 80) + '...' : page.summary) : '-';
            const disabledStyle = page.enabled ? '' : 'opacity: 0.5; background: #f9f9f9;';
            const disabledTextStyle = page.enabled ? '' : 'text-decoration: line-through; color: #999;';
            // Only show remove button for enabled pages
            const removeBtn = page.enabled
                ? `<button class="button button-small remove-page" data-id="${page.id}" style="color: #a00;" title="Disable this page">×</button>`
                : '';

            $tbody.append(`
                <tr data-page-id="${page.id}" style="${disabledStyle}">
                    <td style="text-align: center;">
                        <input type="checkbox" class="toggle-page-enabled" data-id="${page.id}" ${enabledChecked} title="${page.enabled ? 'Click to disable' : 'Click to enable'}">
                    </td>
                    <td>
                        <a href="${escapeHtml(page.url)}" target="_blank" title="${escapeHtml(page.url)}" style="font-size: 12px; ${disabledTextStyle}">
                            ${escapeHtml(urlShort)}
                        </a>
                    </td>
                    <td style="font-size: 12px; ${disabledTextStyle}">${escapeHtml(title)}</td>
                    <td style="font-size: 11px; color: #666; ${disabledTextStyle}" title="${escapeHtml(page.summary || '')}">${escapeHtml(summary)}</td>
                    <td>
                        <button class="button button-small resync-page" data-id="${page.id}" data-url="${escapeHtml(page.url)}" title="${page.enabled ? 'Re-sync this page' : 'Re-sync and re-enable'}">↻</button>
                        ${removeBtn}
                    </td>
                </tr>
            `);
        });
    }

    function bindPagesActions() {
        // Auto-load pages on page load if the container exists
        if ($('#bitbot-pages-container').length) {
            loadPages();
        }

        // Manual refresh button
        $('#bitbot-load-pages-btn').on('click', function(e) {
            e.preventDefault();
            loadPages();
        });

        // Add page form
        $('#bitbot-add-page-form').on('submit', function(e) {
            e.preventDefault();

            const $form = $(this);
            const $input = $('#bitbot-new-url');
            const url = $input.val().trim();

            if (!url) {
                alert('Please enter a URL');
                return;
            }

            const $btn = $form.find('button[type="submit"]');
            const originalText = $btn.text();
            $btn.text('Adding...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_add_page',
                    nonce: config.nonce,
                    url: url
                },
                success: function(response) {
                    if (response.success) {
                        $input.val('');
                        alert(response.data.message || 'Page added!');
                        loadPages(); // Refresh the list
                    } else {
                        alert('Failed: ' + (response.data?.error || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                },
                complete: function() {
                    $btn.text(originalText).prop('disabled', false);
                }
            });
        });

        // Toggle page enabled/disabled
        $(document).on('change', '.toggle-page-enabled', function() {
            const $checkbox = $(this);
            const pageId = $checkbox.data('id');
            const enabled = $checkbox.is(':checked');

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_toggle_page',
                    nonce: config.nonce,
                    page_id: pageId,
                    enabled: enabled
                },
                success: function(response) {
                    if (!response.success) {
                        // Revert checkbox on error
                        $checkbox.prop('checked', !enabled);
                        alert('Failed: ' + (response.data?.error || 'Unknown error'));
                    }
                },
                error: function() {
                    $checkbox.prop('checked', !enabled);
                    alert('Failed to connect to server');
                }
            });
        });

        // Resync page
        $(document).on('click', '.resync-page', function(e) {
            e.preventDefault();

            const $btn = $(this);
            const pageUrl = $btn.data('url');

            $btn.text('...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_resync_page',
                    nonce: config.nonce,
                    page_url: pageUrl
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message || 'Re-syncing page...');
                    } else {
                        alert('Failed: ' + (response.data?.error || 'Unknown error'));
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                },
                complete: function() {
                    $btn.text('↻').prop('disabled', false);
                }
            });
        });

        // Remove page
        $(document).on('click', '.remove-page', function(e) {
            e.preventDefault();

            if (!confirm('Remove this page from the index? The chatbot will no longer reference it.')) {
                return;
            }

            const $btn = $(this);
            const pageId = $btn.data('id');
            const $row = $btn.closest('tr');

            $btn.text('...').prop('disabled', true);

            $.ajax({
                url: config.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'bitbot_remove_page',
                    nonce: config.nonce,
                    page_id: pageId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            // Update total count
                            allPages = allPages.filter(p => p.id !== pageId);
                            $('#bitbot-pages-total').text('Total: ' + allPages.length + ' pages indexed');
                        });
                    } else {
                        alert('Failed: ' + (response.data?.error || 'Unknown error'));
                        $btn.text('×').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Failed to connect to server');
                    $btn.text('×').prop('disabled', false);
                }
            });
        });
    }

    // Initialize when ready
    $(document).ready(function() {
        init();
        bindConversationsActions();
        bindPagesActions();
    });

})(jQuery);
