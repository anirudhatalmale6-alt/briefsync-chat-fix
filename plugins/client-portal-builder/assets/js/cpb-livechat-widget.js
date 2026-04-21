/**
 * BriefSync Live Chat Widget
 * Self-executing IIFE — requires jQuery + cpbLiveChat localized object
 */
(function ($) {
    'use strict';

    if (typeof cpbLiveChat === 'undefined') {
        return;
    }

    /* ------------------------------------------------------------------ */
    /*  Config from WP-localized object                                   */
    /* ------------------------------------------------------------------ */
    var cfg = {
        ajaxUrl:   cpbLiveChat.ajax_url   || '',
        restUrl:   cpbLiveChat.rest_url   || '',
        nonce:     cpbLiveChat.nonce      || '',
        greeting:  cpbLiveChat.greeting   || 'Hi there! How can I help you today?',
        botName:   cpbLiveChat.bot_name   || 'BriefSync Bot',
        accent:    cpbLiveChat.accent     || '#1E78CD',
        userName:  cpbLiveChat.user_name  || '',
        userEmail: cpbLiveChat.user_email || ''
    };

    /* ------------------------------------------------------------------ */
    /*  State                                                             */
    /* ------------------------------------------------------------------ */
    var state = {
        open:          false,
        sessionId:     localStorage.getItem('cpb_chat_session') || '',
        lastMsgId:     0,
        unread:        0,
        polling:       null,          // interval handle
        started:       false,         // session started?
        status:        'ai',          // 'ai' | 'live'
        visitorName:   '',
        visitorEmail:  '',
        prechatDone:   false
    };

    /* ------------------------------------------------------------------ */
    /*  SVG Icons                                                         */
    /* ------------------------------------------------------------------ */
    var icons = {
        chat:    (cpbLiveChat.plugin_url ? '<img class="cpb-livechat-icon-chat" src="' + cpbLiveChat.plugin_url + 'assets/icons/bubblecons/chat.png" alt="" style="width:28px;height:28px;object-fit:contain;">' : '<svg class="cpb-livechat-icon-chat" viewBox="0 0 24 24"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm0 14H5.17L4 17.17V4h16z"/><path d="M7 9h10v2H7zm0-3h10v2H7zm0 6h7v2H7z"/></svg>'),
        close:   '<svg class="cpb-livechat-icon-close" viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
        closeH:  '<svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
        send:    '<svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>',
        bot:     '<svg viewBox="0 0 24 24"><path d="M12 2a2 2 0 0 1 2 2c0 .74-.4 1.39-1 1.73V7h1a7 7 0 0 1 7 7v1H3v-1a7 7 0 0 1 7-7h1V5.73A2 2 0 0 1 12 2zm-4 16a1 1 0 0 0-1 1v1h10v-1a1 1 0 0 0-1-1H8zm1-7a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm6 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z"/></svg>',
        person:  '<svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>'
    };

    /* ------------------------------------------------------------------ */
    /*  Helper: format time                                               */
    /* ------------------------------------------------------------------ */
    function fmtTime(dateStr) {
        var d = dateStr ? new Date(dateStr) : new Date();
        var h = d.getHours();
        var m = d.getMinutes();
        var ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        m = m < 10 ? '0' + m : m;
        return h + ':' + m + ' ' + ampm;
    }

    /* ------------------------------------------------------------------ */
    /*  Build & Inject Widget HTML                                        */
    /* ------------------------------------------------------------------ */
    function buildWidget() {
        var html =
            /* -- Bubble -- */
            '<div class="cpb-livechat-widget">' +
                '<div class="cpb-livechat-bubble cpb-livechat--pulse" id="cpbChatBubble">' +
                    icons.chat + icons.close +
                    '<span class="cpb-livechat-badge" id="cpbChatBadge">0</span>' +
                '</div>' +

                /* -- Panel -- */
                '<div class="cpb-livechat-panel" id="cpbChatPanel">' +

                    /* Header */
                    '<div class="cpb-livechat-header">' +
                        '<div class="cpb-livechat-header-avatar">' + icons.bot + '</div>' +
                        '<div class="cpb-livechat-header-info">' +
                            '<div class="cpb-livechat-header-name">' + escHtml(cfg.botName) + '</div>' +
                            '<div class="cpb-livechat-header-status" id="cpbChatStatus">' +
                                '<span class="cpb-livechat-status-dot"></span> <span id="cpbChatStatusText">AI Assistant</span>' +
                            '</div>' +
                        '</div>' +
                        '<button class="cpb-livechat-header-close" id="cpbChatClose">' + icons.closeH + '</button>' +
                    '</div>' +

                    /* Pre-chat form */
                    '<div class="cpb-livechat-prechat" id="cpbPrechat">' +
                        '<div class="cpb-livechat-prechat-title">Welcome!</div>' +
                        '<div class="cpb-livechat-prechat-subtitle">Let us know who you are so we can help you better.</div>' +
                        '<div class="cpb-livechat-prechat-field">' +
                            '<label for="cpbPrechatName">Name</label>' +
                            '<input type="text" id="cpbPrechatName" placeholder="Your name">' +
                        '</div>' +
                        '<div class="cpb-livechat-prechat-field">' +
                            '<label for="cpbPrechatEmail">Email</label>' +
                            '<input type="email" id="cpbPrechatEmail" placeholder="you@example.com">' +
                        '</div>' +
                        '<button class="cpb-livechat-prechat-submit" id="cpbPrechatSubmit">Start Chat</button>' +
                        '<button class="cpb-livechat-prechat-skip" id="cpbPrechatSkip">Skip — chat anonymously</button>' +
                    '</div>' +

                    /* Messages */
                    '<div class="cpb-livechat-messages" id="cpbChatMessages" style="display:none;"></div>' +

                    /* Typing indicator (lives inside messages conceptually but outside for layout) */
                    '<div class="cpb-livechat-typing" id="cpbChatTyping" style="display:none;">' +
                        '<div class="cpb-livechat-msg-avatar">' + icons.bot + '</div>' +
                        '<div class="cpb-livechat-typing-dots">' +
                            '<span class="cpb-livechat-typing-dot"></span>' +
                            '<span class="cpb-livechat-typing-dot"></span>' +
                            '<span class="cpb-livechat-typing-dot"></span>' +
                        '</div>' +
                    '</div>' +

                    /* Input */
                    '<div class="cpb-livechat-input" id="cpbChatInput" style="display:none;">' +
                        '<textarea id="cpbChatTextarea" rows="1" placeholder="Type a message..."></textarea>' +
                        (cfg.userName ? '<button class="cpb-livechat-capture" id="cpbChatCapture" title="Capture page screenshot"><svg viewBox="0 0 24 24"><path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4z"/><path d="M9 2 7.17 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-3.17L15 2H9zm3 15a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/></svg></button>' : '') +
                        '<button class="cpb-livechat-send" id="cpbChatSend">' + icons.send + '</button>' +
                    '</div>' +

                '</div>' +
            '</div>';

        $(document.body).append(html);
    }

    /* ------------------------------------------------------------------ */
    /*  Escape HTML                                                       */
    /* ------------------------------------------------------------------ */
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /* ------------------------------------------------------------------ */
    /*  Append a message to the messages area                             */
    /* ------------------------------------------------------------------ */
    function appendMessage(senderType, text, time, msgId) {
        var $msgs = $('#cpbChatMessages');

        var avatarIcon = senderType === 'ai' ? icons.bot : icons.person;
        var avatarHtml = senderType !== 'visitor'
            ? '<div class="cpb-livechat-msg-avatar">' + avatarIcon + '</div>'
            : '';

        var html =
            '<div class="cpb-livechat-msg cpb-livechat-msg--' + senderType + '"' +
                (msgId ? ' data-msg-id="' + msgId + '"' : '') + '>' +
                avatarHtml +
                '<div class="cpb-livechat-msg-body">' +
                    '<div class="cpb-livechat-msg-bubble">' + escHtml(text) + '</div>' +
                    '<div class="cpb-livechat-msg-time">' + (time || fmtTime()) + '</div>' +
                '</div>' +
            '</div>';

        $msgs.append(html);
        scrollToBottom();

        if (msgId && msgId > state.lastMsgId) {
            state.lastMsgId = msgId;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Append a system / status message                                  */
    /* ------------------------------------------------------------------ */
    function appendSystem(text) {
        $('#cpbChatMessages').append(
            '<div class="cpb-livechat-system">' + escHtml(text) + '</div>'
        );
        scrollToBottom();
    }

    /* ------------------------------------------------------------------ */
    /*  Scroll to bottom of messages                                      */
    /* ------------------------------------------------------------------ */
    function scrollToBottom() {
        var el = document.getElementById('cpbChatMessages');
        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Show / hide typing indicator                                      */
    /* ------------------------------------------------------------------ */
    function showTyping() {
        var $t = $('#cpbChatTyping');
        $t.css('display', 'flex').addClass('cpb-livechat--visible');
        scrollToBottom();
    }

    function hideTyping() {
        $('#cpbChatTyping').css('display', 'none').removeClass('cpb-livechat--visible');
    }

    /* ------------------------------------------------------------------ */
    /*  Unread badge                                                      */
    /* ------------------------------------------------------------------ */
    function updateBadge() {
        var $badge = $('#cpbChatBadge');
        if (state.unread > 0) {
            $badge.text(state.unread > 99 ? '99+' : state.unread);
            $badge.addClass('cpb-livechat--visible');
        } else {
            $badge.removeClass('cpb-livechat--visible');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Toggle panel                                                      */
    /* ------------------------------------------------------------------ */
    function togglePanel() {
        state.open = !state.open;
        var $panel  = $('#cpbChatPanel');
        var $bubble = $('#cpbChatBubble');

        $bubble.removeClass('cpb-livechat--pulse');

        if (state.open) {
            $panel.addClass('cpb-livechat--open');
            $bubble.addClass('cpb-livechat--open');

            // Reset unread
            state.unread = 0;
            updateBadge();

            // Start session on first open if prechat is done
            if (state.prechatDone && !state.started) {
                startSession();
            }

            // Start polling
            startPolling();

            // Focus input
            setTimeout(function () {
                $('#cpbChatTextarea').focus();
            }, 350);

        } else {
            $panel.removeClass('cpb-livechat--open');
            $bubble.removeClass('cpb-livechat--open');
            stopPolling();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Pre-chat form handling                                            */
    /* ------------------------------------------------------------------ */
    function completePrechat(name, email) {
        state.visitorName  = (name || '').trim();
        state.visitorEmail = (email || '').trim();
        state.prechatDone  = true;

        // Hide form, show messages + input
        $('#cpbPrechat').hide();
        $('#cpbChatMessages').show();
        $('#cpbChatInput').show();

        if (!state.started) {
            startSession();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  AJAX helper                                                       */
    /* ------------------------------------------------------------------ */
    /* REST API routing map for chat actions */
    var chatRestMap = {
        'cpb_livechat_start': { method: 'PUT',  path: '/start' },
        'cpb_livechat_send':  { method: 'PUT',  path: '/send'  },
        'cpb_livechat_poll':  { method: 'GET',  path: '/poll'  }
    };

    function ajaxPost(action, data, onSuccess, onError) {
        data = data || {};
        var restBase = (cfg.restUrl || (cfg.ajaxUrl.replace('/wp-admin/admin-ajax.php', '/wp-json/cpp-chat/v1')));
        var route = chatRestMap[action];

        if (route) {
            var url = restBase + route.path;
            var opts = { dataType: 'json' };

            if (route.method === 'GET') {
                opts.method = 'GET';
                opts.url = url + '?' + $.param(data);
            } else {
                opts.method = route.method;
                opts.url = url;
                opts.contentType = 'application/json';
                opts.data = JSON.stringify(data);
            }

            opts.success = function (res) {
                if (res && res.success && typeof onSuccess === 'function') {
                    onSuccess(res.data);
                } else if (typeof onError === 'function') {
                    onError(res);
                }
            };
            opts.error = function (xhr) {
                if (typeof onError === 'function') onError(xhr);
            };

            $.ajax(opts);
        } else {
            data.action = action;
            data._ajax_nonce = cfg.nonce;
            $.ajax({
                url:      cfg.ajaxUrl,
                method:   'POST',
                data:     data,
                dataType: 'json',
                success:  function (res) {
                    if (res && res.success && typeof onSuccess === 'function') {
                        onSuccess(res.data);
                    } else if (typeof onError === 'function') {
                        onError(res);
                    }
                },
                error: function (xhr) {
                    if (typeof onError === 'function') onError(xhr);
                }
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Start / Resume Session                                            */
    /* ------------------------------------------------------------------ */
    function startSession() {
        if (state.started) return;
        state.started = true; // Set synchronously to prevent race conditions

        var payload = {
            session_id: state.sessionId
        };
        if (state.visitorName)  payload.visitor_name  = state.visitorName;
        if (state.visitorEmail) payload.visitor_email  = state.visitorEmail;

        ajaxPost('cpb_livechat_start', payload, function (data) {
            state.sessionId = data.session_id || '';

            // Persist session
            localStorage.setItem('cpb_chat_session', state.sessionId);

            // Render existing messages if resuming
            if (data.messages && data.messages.length) {
                $.each(data.messages, function (_, m) {
                    appendMessage(m.sender_type, m.message, fmtTime(m.created_at), m.id);
                });
            } else if (data.greeting) {
                // New session: show greeting and sync lastMsgId via poll to prevent duplicate
                appendMessage('ai', data.greeting, fmtTime(), 0);
                // Immediate silent poll to get the real message ID so regular polling won't duplicate
                ajaxPost('cpb_livechat_poll', { session_id: state.sessionId, after_id: 0 }, function (pollData) {
                    if (pollData.messages && pollData.messages.length) {
                        // Just update lastMsgId, don't render (already shown above)
                        $.each(pollData.messages, function (_, m) {
                            if (m.id > state.lastMsgId) state.lastMsgId = m.id;
                        });
                    }
                });
            }

            // Update status
            if (data.status) {
                updateStatus(data.status, data.operator_name || '');
            }
        }, function () {
            state.started = false; // Allow retry on failure
            appendSystem('Unable to connect. Please try again.');
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Send Message                                                      */
    /* ------------------------------------------------------------------ */
    function sendMessage() {
        var $ta  = $('#cpbChatTextarea');
        var text = $ta.val().trim();
        if (!text) return;

        // Show visitor message immediately
        appendMessage('visitor', text, fmtTime());
        $ta.val('').css('height', 'auto').focus();

        // Show typing
        showTyping();

        ajaxPost('cpb_livechat_send', {
            session_id: state.sessionId,
            message:    text
        }, function (data) {
            hideTyping();

            if (data.reply) {
                appendMessage(
                    data.sender_type || 'ai',
                    data.reply,
                    fmtTime(data.created_at),
                    data.id || 0
                );
            }

            if (data.status && data.status !== state.status) {
                updateStatus(data.status, data.operator_name || '');
            }
        }, function () {
            hideTyping();
            appendSystem('Message failed to send. Please try again.');
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Poll for new messages                                             */
    /* ------------------------------------------------------------------ */
    function poll() {
        if (!state.sessionId || !state.started) return;

        ajaxPost('cpb_livechat_poll', {
            session_id: state.sessionId,
            after_id:   state.lastMsgId
        }, function (data) {
            if (data.messages && data.messages.length) {
                $.each(data.messages, function (_, m) {
                    // Avoid duplicates
                    if (m.id <= state.lastMsgId) return;

                    appendMessage(m.sender_type, m.message, fmtTime(m.created_at), m.id);

                    // Track unread when panel closed
                    if (!state.open && m.sender_type !== 'visitor') {
                        state.unread++;
                        updateBadge();
                    }
                });
            }

            if (data.status && data.status !== state.status) {
                updateStatus(data.status, data.operator_name || '');
            }
        });
    }

    function startPolling() {
        stopPolling();
        state.polling = setInterval(poll, 4000);
    }

    function stopPolling() {
        if (state.polling) {
            clearInterval(state.polling);
            state.polling = null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Update AI / Live status                                           */
    /* ------------------------------------------------------------------ */
    function updateStatus(newStatus, operatorName) {
        var prev = state.status;
        state.status = newStatus;

        var $text = $('#cpbChatStatusText');

        if (newStatus === 'live') {
            $text.text(operatorName ? 'Connected to ' + operatorName : 'Live Agent');
            if (prev === 'ai') {
                appendSystem('Connected to ' + (operatorName || 'a live agent'));
            }
        } else {
            $text.text('AI Assistant');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Textarea auto-resize                                              */
    /* ------------------------------------------------------------------ */
    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 100) + 'px';
    }

    /* ------------------------------------------------------------------ */
    /*  Init                                                              */
    /* ------------------------------------------------------------------ */
    function init() {
        buildWidget();

        // Auto-skip prechat for logged-in users
        if (cfg.userName) {
            completePrechat(cfg.userName, cfg.userEmail);
        }

        // Bubble click
        $(document).on('click', '#cpbChatBubble', function () {
            togglePanel();
        });

        // Header close
        $(document).on('click', '#cpbChatClose', function (e) {
            e.stopPropagation();
            if (state.open) togglePanel();
        });

        // Pre-chat submit
        $(document).on('click', '#cpbPrechatSubmit', function () {
            var name = $('#cpbPrechatName').val();
            var email = $('#cpbPrechatEmail').val();
            if (!name || !name.trim()) {
                $('#cpbPrechatName').focus();
                return;
            }
            if (!email || !email.trim() || email.indexOf('@') === -1) {
                $('#cpbPrechatEmail').focus();
                return;
            }
            completePrechat(name, email);
        });

        // Pre-chat skip
        $(document).on('click', '#cpbPrechatSkip', function () {
            completePrechat('', '');
        });

        // Pre-chat enter key
        $(document).on('keydown', '#cpbPrechatName, #cpbPrechatEmail', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#cpbPrechatSubmit').trigger('click');
            }
        });

        // Capture button — save scroll position, close chat, then trigger page capture
        $(document).on('click', '#cpbChatCapture', function () {
            // Save scroll position NOW before anything changes
            window._cppSavedScrollX = window.scrollX || window.pageXOffset || 0;
            window._cppSavedScrollY = window.scrollY || window.pageYOffset || 0;
            // Close chat panel
            if (state.open) togglePanel();
            // Trigger capture after chat closes (allow animation to finish)
            setTimeout(function () {
                if (typeof window.cppShowCapture === 'function') {
                    window.cppShowCapture();
                }
            }, 400);
        });

        // Send button
        $(document).on('click', '#cpbChatSend', function () {
            sendMessage();
        });

        // Textarea enter (Shift+Enter = newline)
        $(document).on('keydown', '#cpbChatTextarea', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Textarea auto-resize
        $(document).on('input', '#cpbChatTextarea', function () {
            autoResize(this);
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Boot                                                              */
    /* ------------------------------------------------------------------ */
    $(document).ready(init);

})(window.jQuery);
