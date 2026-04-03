/**
 * BitBot Chat Widget
 *
 * Frontend chat widget for visitor interactions.
 *
 * @package BitBot
 * @since   1.0.0
 * @author  youami.ai
 * @license GPL-2.0-or-later
 */
(function() {
    'use strict';

    // Configuration from WordPress
    const config = window.bitbotConfig || {};

    // DOM Elements
    let widget, toggle, chat, messages, form, input;
    let chatHistory = [];
    let isOpen = false;
    let isLoading = false;
    let sessionId = null;
    let leadCapturedEmail = null; // Track if a lead was already captured this session

    /**
     * Generate or retrieve session ID
     */
    function getSessionId() {
        if (sessionId) return sessionId;

        // Try to get from localStorage
        const storageKey = 'bitbot_session_id';
        try {
            sessionId = localStorage.getItem(storageKey);

            if (!sessionId) {
                // Generate new UUID
                sessionId = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
                    const r = Math.random() * 16 | 0;
                    const v = c === 'x' ? r : (r & 0x3 | 0x8);
                    return v.toString(16);
                });
                localStorage.setItem(storageKey, sessionId);
            }
        } catch (e) {
            // localStorage not available, generate temporary ID
            sessionId = 'temp-' + Date.now();
        }

        return sessionId;
    }

    /**
     * Initialize the widget
     */
    function init() {
        widget = document.getElementById('bitbot-widget');
        toggle = document.getElementById('bitbot-toggle');
        chat = document.getElementById('bitbot-chat');
        messages = document.getElementById('bitbot-messages');
        form = document.getElementById('bitbot-form');
        input = document.getElementById('bitbot-input');

        if (!widget || !toggle || !chat) {
            return;
        }

        // Event listeners
        toggle.addEventListener('click', toggleChat);

        const minimize = document.getElementById('bitbot-minimize');
        if (minimize) {
            minimize.addEventListener('click', toggleChat);
        }

        if (form) {
            form.addEventListener('submit', handleSubmit);
        }

        // Close on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && isOpen) {
                toggleChat();
            }
        });
    }

    /**
     * Toggle chat window
     */
    function toggleChat() {
        isOpen = !isOpen;

        const iconChat = toggle.querySelector('.bitbot-icon-chat');
        const iconClose = toggle.querySelector('.bitbot-icon-close');

        if (isOpen) {
            chat.style.display = 'flex';
            if (iconChat) iconChat.style.display = 'none';
            if (iconClose) iconClose.style.display = 'block';
            if (input) input.focus();
        } else {
            chat.style.display = 'none';
            if (iconChat) iconChat.style.display = 'block';
            if (iconClose) iconClose.style.display = 'none';
        }
    }

    /**
     * Handle form submission
     */
    function handleSubmit(e) {
        e.preventDefault();

        const message = input.value.trim();
        if (!message || isLoading) return;

        // Add user message
        addMessage(message, 'user');
        chatHistory.push({ role: 'user', content: message });

        // Clear input
        input.value = '';

        // Send to API
        sendMessage(message);
    }

    /**
     * Send message to API
     */
    async function sendMessage(message) {
        isLoading = true;
        showTypingIndicator();

        try {
            const response = await fetch(config.restUrl + '/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    message: message,
                    history: chatHistory.slice(-10), // Send last 10 messages for context
                    sessionId: getSessionId(),
                    siteLanguage: config.languageCode || 'en'  // Pass site language
                })
            });

            const data = await response.json();

            hideTypingIndicator();

            if (data.success) {
                addMessage(data.data.message, 'bot', data.data.citations);
                chatHistory.push({ role: 'assistant', content: data.data.message });

                // Show lead captured confirmation
                if (data.data.lead_captured && !leadCapturedEmail) {
                    var emailMatch = message.match(/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/);
                    leadCapturedEmail = emailMatch ? emailMatch[0] : true;
                    var confirmDiv = document.createElement('div');
                    confirmDiv.className = 'bitbot-lead-confirmed';
                    confirmDiv.innerHTML = '<span class="bitbot-lead-check">&#10003;</span> We\'ll follow up' +
                        (typeof leadCapturedEmail === 'string' ? ' at ' + escapeHtml(leadCapturedEmail) : '') + '.';
                    messages.appendChild(confirmDiv);
                    scrollToBottom();
                }
            } else {
                addMessage('Sorry, I encountered an error. Please try again.', 'bot');
            }
        } catch (error) {
            hideTypingIndicator();
            addMessage('Sorry, I couldn\'t connect to the server. Please try again later.', 'bot');
        }

        isLoading = false;
    }

    /**
     * Simple markdown to HTML conversion
     */
    function parseMarkdown(text) {
        return text
            // Escape HTML first
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            // Bold **text**
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            // Italic *text*
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            // Line breaks
            .replace(/\n/g, '<br>')
            // Lists - lines starting with -
            .replace(/^- (.+)$/gm, '<li>$1</li>')
            .replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>')
            // Clean up multiple <ul> tags
            .replace(/<\/ul>\s*<ul>/g, '');
    }

    /**
     * Add message to chat
     */
    function addMessage(content, sender, citations) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'bitbot-message bitbot-' + sender;

        // Use markdown parsing for bot messages, escape for user
        const formattedContent = sender === 'bot' ? parseMarkdown(content) : escapeHtml(content);
        let html = '<div class="bitbot-message-content">' + formattedContent + '</div>';

        // Add citations if present
        if (citations && citations.length > 0) {
            html += '<div class="bitbot-citations">';
            html += '<div class="bitbot-citations-title">Related pages:</div>';

            citations.forEach(function(citation) {
                let url = citation.url;
                if (citation.anchorId) {
                    url += '#' + citation.anchorId;
                }
                html += '<a href="' + escapeHtml(url) + '" class="bitbot-citation-link" target="_blank" rel="noopener noreferrer">';
                html += escapeHtml(citation.title || citation.url);
                html += '</a>';
            });

            html += '</div>';
        }

        messageDiv.innerHTML = html;
        messages.appendChild(messageDiv);
        scrollToBottom();
    }

    /**
     * Show typing indicator
     */
    function showTypingIndicator() {
        const typingDiv = document.createElement('div');
        typingDiv.id = 'bitbot-typing-indicator';
        typingDiv.className = 'bitbot-message bitbot-bot';
        typingDiv.innerHTML = '<div class="bitbot-typing"><span></span><span></span><span></span></div>';
        messages.appendChild(typingDiv);
        scrollToBottom();
    }

    /**
     * Hide typing indicator
     */
    function hideTypingIndicator() {
        const typing = document.getElementById('bitbot-typing-indicator');
        if (typing) {
            typing.remove();
        }
    }

    /**
     * Scroll messages to bottom
     */
    function scrollToBottom() {
        if (messages) {
            messages.scrollTop = messages.scrollHeight;
        }
    }

    /**
     * Escape HTML to prevent XSS
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
