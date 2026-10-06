    </div>
<footer>
    <p>&copy; 2024 Service Portal. All rights reserved.</p> 
</footer>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var siteNav = document.querySelector('.site-nav');
        var navMenuToggle = document.querySelector('.nav-menu-toggle');
        var profileMenu = document.querySelector('.nav-profile-menu');
        var profileButton = document.querySelector('.nav-profile-button');
        var notificationMenu = document.querySelector('.nav-notification-menu');
        var notificationButton = document.querySelector('.nav-notification-button');
        if (siteNav && navMenuToggle) {
            navMenuToggle.addEventListener('click', function (event) {
                event.stopPropagation();
            if (profileMenu) profileMenu.classList.remove('is-open');
            if (profileButton) profileButton.setAttribute('aria-expanded', 'false');
            if (notificationMenu) notificationMenu.classList.remove('is-open');
            if (notificationButton) notificationButton.setAttribute('aria-expanded', 'false');
                var isOpen = siteNav.classList.toggle('is-mobile-menu-open');
                navMenuToggle.setAttribute('aria-expanded', String(isOpen));
                navMenuToggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
            });
            siteNav.querySelectorAll('.nav-main-links a').forEach(function (link) {
                link.addEventListener('click', function () {
                    siteNav.classList.remove('is-mobile-menu-open');
                    navMenuToggle.setAttribute('aria-expanded', 'false');
                    navMenuToggle.setAttribute('aria-label', 'Open navigation menu');
                });
            });
            document.addEventListener('click', function (event) {
                if (!siteNav.contains(event.target)) {
                    siteNav.classList.remove('is-mobile-menu-open');
                    navMenuToggle.setAttribute('aria-expanded', 'false');
                    navMenuToggle.setAttribute('aria-label', 'Open navigation menu');
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && siteNav.classList.contains('is-mobile-menu-open')) {
                    siteNav.classList.remove('is-mobile-menu-open');
                    navMenuToggle.setAttribute('aria-expanded', 'false');
                    navMenuToggle.setAttribute('aria-label', 'Open navigation menu');
                    navMenuToggle.focus();
                }
            });
        }

        if (profileMenu && profileButton) {
            profileButton.addEventListener('click', function (event) {
                event.stopPropagation();
                if (notificationMenu) notificationMenu.classList.remove('is-open');
                if (notificationButton) notificationButton.setAttribute('aria-expanded', 'false');
                if (siteNav) siteNav.classList.remove('is-mobile-menu-open');
                if (navMenuToggle) {
                    navMenuToggle.setAttribute('aria-expanded', 'false');
                    navMenuToggle.setAttribute('aria-label', 'Open navigation menu');
                }
                var isOpen = profileMenu.classList.contains('is-open');
                profileMenu.classList.toggle('is-open', !isOpen);
                profileButton.setAttribute('aria-expanded', String(!isOpen));
            });

            document.addEventListener('click', function (event) {
                if (!profileMenu.contains(event.target)) {
                    profileMenu.classList.remove('is-open');
                    profileButton.setAttribute('aria-expanded', 'false');
                }
            });
        }

        var notificationItems = document.querySelector('.nav-notification-items');
        var notificationCount = document.querySelector('.nav-notification-count');
        var notificationCsrf = <?php echo json_encode((string)($_SESSION['csrf_token'] ?? '')); ?>;
        function positionNotificationBell() {
            if (!notificationMenu || !notificationButton) return;
            var navbar = document.querySelector('nav');
            var profileControl = document.querySelector('.nav-profile-button');
            if (!navbar || !profileControl) return;
            var navbarRect = navbar.getBoundingClientRect();
            var profileRect = profileControl.getBoundingClientRect();
            var bellLeft = profileRect.left - notificationButton.offsetWidth - 15;
            notificationMenu.style.left = Math.max(8, bellLeft) + 'px';
            var bellTop = window.matchMedia('(max-width: 900px)').matches
                ? profileRect.top + (profileRect.height - notificationButton.offsetHeight) / 2
                : navbarRect.top + (navbarRect.height - notificationButton.offsetHeight) / 2;
            notificationMenu.style.top = bellTop + 'px';
            notificationMenu.style.visibility = 'visible';
            var dropdown = notificationMenu.querySelector('.nav-notification-dropdown');
            if (dropdown && window.matchMedia('(max-width: 900px)').matches) {
                var dropdownWidth = Math.min(320, window.innerWidth - 20);
                var dropdownLeft = Math.max(10, Math.min(profileRect.right - dropdownWidth, window.innerWidth - dropdownWidth - 10));
                dropdown.style.width = dropdownWidth + 'px';
                dropdown.style.left = (dropdownLeft - parseFloat(notificationMenu.style.left || '0')) + 'px';
                dropdown.style.right = 'auto';
                dropdown.style.transform = 'none';
            } else if (dropdown) {
                dropdown.style.width = '';
                dropdown.style.left = '';
                dropdown.style.right = '';
                dropdown.style.transform = '';
            }
        }
        function refreshPortalNotifications() {
            if (!notificationMenu || !notificationItems || !notificationCount) return;
            fetch('/sps/pages/notifications_feed.php', { credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'} })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (data) {
                    if (!data) return;
                    var unread = Number(data.unread_count || 0);
                    notificationCount.textContent = unread > 99 ? '99+' : String(unread);
                    notificationCount.hidden = unread < 1;
                    notificationItems.replaceChildren();
                    if (!Array.isArray(data.notifications) || data.notifications.length === 0) {
                        var empty = document.createElement('div');
                        empty.className = 'nav-notification-empty';
                        empty.textContent = 'No notifications yet.';
                        notificationItems.appendChild(empty);
                        return;
                    }
                    data.notifications.forEach(function (notification) {
                        var item = document.createElement('a');
                        item.className = 'nav-notification-entry' + (notification.read_at ? '' : ' is-unread');
                        item.href = '/sps/pages/notifications.php?id=' + encodeURIComponent(notification.id);
                        var subject = document.createElement('strong');
                        subject.textContent = notification.subject || 'Notification';
                        var message = document.createElement('span');
                        message.textContent = notification.message || '';
                        var time = document.createElement('time');
                        var parsedDate = new Date(String(notification.created_at || '').replace(' ', 'T'));
                        time.textContent = isNaN(parsedDate.getTime()) ? '' : parsedDate.toLocaleString();
                        item.appendChild(subject);
                        item.appendChild(message);
                        item.appendChild(time);
                        item.addEventListener('click', function (event) {
                            if (notification.read_at) return;
                            event.preventDefault();
                            var form = new URLSearchParams();
                            form.set('action', 'read');
                            form.set('notification_id', String(notification.id));
                            form.set('csrf_token', notificationCsrf);
                            fetch('/sps/pages/notifications_feed.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body:form.toString() })
                                .catch(function () {})
                                .then(function () { window.location.href = item.href; });
                        });
                        notificationItems.appendChild(item);
                    });
                }).catch(function () {});
        }
        if (notificationMenu && notificationButton) {
            positionNotificationBell();
            window.addEventListener('resize', positionNotificationBell);
            if ('ResizeObserver' in window) {
                var notificationNavbar = document.querySelector('nav');
                if (notificationNavbar) new ResizeObserver(positionNotificationBell).observe(notificationNavbar);
            }
            notificationButton.addEventListener('click', function (event) {
                event.stopPropagation();
                if (profileMenu) profileMenu.classList.remove('is-open');
                if (profileButton) profileButton.setAttribute('aria-expanded', 'false');
                if (siteNav) siteNav.classList.remove('is-mobile-menu-open');
                if (navMenuToggle) {
                    navMenuToggle.setAttribute('aria-expanded', 'false');
                    navMenuToggle.setAttribute('aria-label', 'Open navigation menu');
                }
                var isOpen = notificationMenu.classList.toggle('is-open');
                notificationButton.setAttribute('aria-expanded', String(isOpen));
                if (isOpen) refreshPortalNotifications();
            });
            document.addEventListener('click', function (event) {
                if (!notificationMenu.contains(event.target)) {
                    notificationMenu.classList.remove('is-open');
                    notificationButton.setAttribute('aria-expanded', 'false');
                }
            });
            refreshPortalNotifications();
            window.setInterval(refreshPortalNotifications, 30000);
        }

        document.querySelectorAll('input[type="password"]').forEach(function (input) {
            if (input.closest('.password-field')) {
                return;
            }

            var wrapper = document.createElement('div');
            wrapper.className = 'password-field';

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'password-toggle';
            toggle.setAttribute('aria-label', 'Show password');
            toggle.textContent = '👁';
            toggle.title = 'Show password';

            toggle.addEventListener('click', function () {
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                toggle.textContent = isPassword ? '🙈' : '👁';
                toggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                toggle.title = isPassword ? 'Hide password' : 'Show password';
            });

            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            wrapper.appendChild(toggle);
        });
    });

    <?php if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true): ?>
    (function () {
        var role = <?php echo json_encode(strtolower((string)($_SESSION['role'] ?? ''))); ?>;
        var soundSettingKey = 'spsLiveNotificationSound:' + role + ':' + <?php echo json_encode((string)($_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? '')); ?>;
        var knownVersion = null;
        var hasUnsavedFormChanges = false;
        var refreshTimer = null;
        var updateNotice = null;
        var updateNoticeDismissTimer = null;
        var soundEnabled = false;
        var audioContext = null;
        var liveBaselineKey = 'spsLiveBaseline:' + role + ':' + <?php echo json_encode((string)($_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? '')); ?>;
        var pendingReloadNoticeKey = 'spsPendingLiveNotice:' + role + ':' + <?php echo json_encode((string)($_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? '')); ?>;
        var liveSnapshotKey = 'spsLiveSnapshot:' + role + ':' + <?php echo json_encode((string)($_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? '')); ?>;
        var pendingReloadMessageKey = 'spsPendingLiveMessage:' + role + ':' + <?php echo json_encode((string)($_SESSION['customer_id'] ?? $_SESSION['user_id'] ?? '')); ?>;
        var knownActivity = null;
        try {
            soundEnabled = window.localStorage.getItem(soundSettingKey) === 'on';
            knownVersion = window.sessionStorage.getItem(liveBaselineKey);
            var storedActivity = window.sessionStorage.getItem(liveSnapshotKey);
            knownActivity = storedActivity ? JSON.parse(storedActivity) : null;
        } catch (ignore) {}

        document.addEventListener('input', function (event) {
            if (event.target && event.target.closest('form')) hasUnsavedFormChanges = true;
        });
        document.addEventListener('change', function (event) {
            if (event.target && event.target.closest('form')) hasUnsavedFormChanges = true;
        });

        function playNotificationSound(testSound) {
            if (!soundEnabled && !testSound) return;
            try {
                var AudioContextClass = window.AudioContext || window.webkitAudioContext;
                if (!AudioContextClass) return;
                if (!audioContext) audioContext = new AudioContextClass();
                if (audioContext.state === 'suspended') audioContext.resume();
                var startAt = audioContext.currentTime;
                [880, 1175, 1480].forEach(function (frequency, index) {
                    var noteStart = startAt + index * 0.19;
                    var oscillator = audioContext.createOscillator();
                    var gain = audioContext.createGain();
                    oscillator.type = 'triangle';
                    oscillator.frequency.setValueAtTime(frequency, noteStart);
                    gain.gain.setValueAtTime(0.0001, noteStart);
                    gain.gain.exponentialRampToValueAtTime(0.65, noteStart + 0.025);
                    gain.gain.setValueAtTime(0.65, noteStart + 0.12);
                    gain.gain.exponentialRampToValueAtTime(0.0001, noteStart + 0.18);
                    oscillator.connect(gain);
                    gain.connect(audioContext.destination);
                    oscillator.start(noteStart);
                    oscillator.stop(noteStart + 0.19);
                });
            } catch (ignore) {}
        }

        var soundButton = document.createElement('button');
        soundButton.type = 'button';
        soundButton.textContent = soundEnabled ? '🔔 Sound alerts: On' : '🔕 Sound alerts: Off';
        soundButton.setAttribute('aria-pressed', soundEnabled ? 'true' : 'false');
        soundButton.setAttribute('aria-label', soundEnabled ? 'Turn notification sound off' : 'Turn notification sound on');
        soundButton.title = 'Toggle sound alerts (saved for this account on this browser)';
        soundButton.style.cssText = 'width:100%;box-sizing:border-box;padding:11px 14px;text-align:left;border:0;border-bottom:1px solid #eef2f7;background:rgba(255,255,255,.95);color:#1f2937;font:700 .76rem Arial,sans-serif;letter-spacing:.02em;cursor:pointer;';
        soundButton.addEventListener('click', function () {
            soundEnabled = !soundEnabled;
            try { window.localStorage.setItem(soundSettingKey, soundEnabled ? 'on' : 'off'); } catch (ignore) {}
            soundButton.textContent = soundEnabled ? '🔔 Sound alerts: On' : '🔕 Sound alerts: Off';
            soundButton.setAttribute('aria-pressed', soundEnabled ? 'true' : 'false');
            soundButton.setAttribute('aria-label', soundEnabled ? 'Turn notification sound off' : 'Turn notification sound on');
            if (soundEnabled) playNotificationSound(true);
        });
        var profileDropdown = document.querySelector('.nav-profile-dropdown');
        if (profileDropdown) {
            profileDropdown.insertBefore(soundButton, profileDropdown.firstChild);
        } else {
            document.body.appendChild(soundButton);
        }
        document.addEventListener('pointerdown', function () {
            if (soundEnabled && audioContext && audioContext.state === 'suspended') audioContext.resume();
        }, { once: true });

        function showUpdateNotice(updates) {
            if (updateNoticeDismissTimer) {
                window.clearTimeout(updateNoticeDismissTimer);
                updateNoticeDismissTimer = null;
            }
            if (updateNotice) updateNotice.remove();
            updateNotice = document.createElement('div');
            updateNotice.setAttribute('role', 'status');
            updateNotice.setAttribute('aria-live', 'polite');
            updateNotice.style.cssText = 'position:fixed;right:16px;bottom:62px;z-index:9999;width:min(440px,calc(100vw - 32px));max-height:45vh;overflow:auto;padding:38px 16px 14px;border-radius:10px;background:#0f172a;color:#fff;box-shadow:0 8px 30px rgba(15,23,42,.25);font:600 14px/1.4 Arial,sans-serif;';
            var closeNoticeButton = document.createElement('button');
            closeNoticeButton.type = 'button';
            closeNoticeButton.textContent = '×';
            closeNoticeButton.setAttribute('aria-label', 'Close update notification');
            closeNoticeButton.title = 'Close notification';
            closeNoticeButton.style.cssText = 'position:absolute;top:7px;right:8px;width:28px;height:28px;min-width:28px;margin:0;padding:0;border:1px solid rgba(255,255,255,.35);border-radius:6px;background:rgba(255,255,255,.1);color:#fff;font:700 20px/1 Arial,sans-serif;cursor:pointer;';
            closeNoticeButton.addEventListener('click', function () {
                if (updateNoticeDismissTimer) {
                    window.clearTimeout(updateNoticeDismissTimer);
                    updateNoticeDismissTimer = null;
                }
                if (updateNotice && updateNotice.isConnected) updateNotice.remove();
                updateNotice = null;
            });
            updateNotice.appendChild(closeNoticeButton);
            var heading = document.createElement('div');
            heading.textContent = 'Updates';
            heading.style.cssText = 'font-weight:800;margin-bottom:6px;';
            updateNotice.appendChild(heading);
            if (!Array.isArray(updates)) updates = [{ text:String(updates || 'New activity.'), link:'' }];
            var list = document.createElement('ul');
            list.style.cssText = 'margin:0;padding-left:18px;';
            updates.forEach(function (update) {
                var item = document.createElement('li');
                item.style.cssText = 'margin:4px 0;';
                if (update && update.link) {
                    var link = document.createElement('a');
                    link.href = update.link;
                    link.textContent = update.text || 'View update';
                    link.style.cssText = 'color:#bfdbfe;text-decoration:underline;font-weight:700;';
                    item.appendChild(link);
                } else {
                    item.textContent = update && update.text ? update.text : String(update || '');
                }
                list.appendChild(item);
            });
            updateNotice.appendChild(list);
            document.body.appendChild(updateNotice);

            function scheduleDismissal() {
                if (updateNoticeDismissTimer) window.clearTimeout(updateNoticeDismissTimer);
                updateNoticeDismissTimer = window.setTimeout(function () {
                    if (updateNotice && updateNotice.isConnected && !updateNotice.matches(':hover') && !updateNotice.contains(document.activeElement)) {
                        updateNotice.remove();
                        updateNotice = null;
                    }
                    updateNoticeDismissTimer = null;
                }, 30000);
            }
            updateNotice.addEventListener('mouseenter', function () {
                if (updateNoticeDismissTimer) window.clearTimeout(updateNoticeDismissTimer);
                updateNoticeDismissTimer = null;
            });
            updateNotice.addEventListener('mouseleave', scheduleDismissal);
            updateNotice.addEventListener('focusin', function () {
                if (updateNoticeDismissTimer) window.clearTimeout(updateNoticeDismissTimer);
                updateNoticeDismissTimer = null;
            });
            updateNotice.addEventListener('focusout', function (event) {
                if (!updateNotice.contains(event.relatedTarget)) scheduleDismissal();
            });
            scheduleDismissal();
        }

        try {
            if (window.sessionStorage.getItem(pendingReloadNoticeKey) === '1') {
                window.sessionStorage.removeItem(pendingReloadNoticeKey);
                var refreshedMessage = window.sessionStorage.getItem(pendingReloadMessageKey) || '[]';
                window.sessionStorage.removeItem(pendingReloadMessageKey);
                try { showUpdateNotice(JSON.parse(refreshedMessage)); } catch (ignore) { showUpdateNotice(refreshedMessage); }
            }
        } catch (ignore) {}

        function orderLabel(order) {
            if (!order) return 'Work order';
            return order.order_number || ('WO' + String(order.id || '').padStart(4, '0'));
        }

        function describeActivityChanges(previous, current) {
            if (!previous || !current) return [];
            var messages = [];
            var workOrderById = {};
            (current.workorders || []).forEach(function (order) {
                workOrderById[String(order.id)] = order;
            });
            function compareRows(key, labelForRow, linkForRow) {
                var before = {};
                (previous[key] || []).forEach(function (row) { before[String(row.id)] = row; });
                (current[key] || []).forEach(function (row) {
                    var rowId = String(row.id);
                    if (!before[rowId] || JSON.stringify(before[rowId]) !== JSON.stringify(row)) {
                        messages.push({ text:labelForRow(row, !before[rowId]), link:linkForRow ? linkForRow(row) : '' });
                    }
                });
            }

            compareRows('workorders', function (order, isNew) {
                var label = orderLabel(order);
                var client = String(order.client_name || '').trim();
                return (isNew ? 'New work order ' : 'Work order ') + label + (client ? ' (' + client + ')' : '') + (isNew ? ' was created.' : ' was updated.');
            }, function (order) { return '/sps/pages/view_workorder.php?id=' + encodeURIComponent(order.id); });
            compareRows('service_requests', function (request, isNew) {
                var label = request.request_number || ('SR-' + String(request.id || '').padStart(5, '0'));
                var topic = String(request.problem_summary || request.service_type || '').trim();
                return (isNew ? 'New service request ' : 'Service request ') + label + (topic ? ' — ' + topic : '') + (isNew ? ' was submitted.' : ' was updated.');
            }, function (request) { return '/sps/pages/view_service_request.php?id=' + encodeURIComponent(request.id); });

            ['property_visits', 'visits', 'work_performed'].forEach(function (key) {
                var beforeRows = {};
                (previous[key] || []).forEach(function (row) { beforeRows[String(row.id)] = row; });
                (current[key] || []).forEach(function (row) {
                    var rowId = String(row.id);
                    if (!beforeRows[rowId] || JSON.stringify(beforeRows[rowId]) !== JSON.stringify(row)) {
                        var parentOrder = workOrderById[String(row.workorder_id)] || { id: row.workorder_id };
                        var item = key === 'work_performed' ? 'Work note' : 'Property visit';
                        messages.push({ text:item + ' updated on ' + orderLabel(parentOrder) + '.', link:'/sps/pages/view_workorder.php?id=' + encodeURIComponent(row.workorder_id) });
                    }
                });
            });

            compareRows('workorder_edits', function (edit) {
                var parentOrder = workOrderById[String(edit.workorder_id)] || { id: edit.workorder_id };
                var fieldNames = {
                    status: 'status', priority: 'priority', work_performed_by: 'assignment',
                    additional_technician: 'technician assignment', vessel_hours: 'vessel hours',
                    parts_cost: 'parts cost', permission_date: 'property access', permission_time: 'property access'
                };
                var changeName = fieldNames[edit.field_name] || 'details';
                return 'Work order ' + orderLabel(parentOrder) + ' ' + changeName + ' changed.';
            }, function (edit) { return '/sps/pages/view_workorder.php?id=' + encodeURIComponent(edit.workorder_id); });

            var uniqueMessages = [];
            messages.forEach(function (message) {
                if (!uniqueMessages.some(function (existing) { return existing.text === message.text && existing.link === message.link; })) uniqueMessages.push(message);
            });
            if (uniqueMessages.length > 3) {
                return uniqueMessages.slice(0, 3).concat([{ text:'And ' + (uniqueMessages.length - 3) + ' more updates.', link:'' }]);
            }
            return uniqueMessages;
        }

        function pollLiveActivity() {
            if (document.hidden) return;
            fetch('/sps/pages/dashboard_live_check.php', { credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'} })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (data) {
                    if (!data || !data.version) return;
                    if (knownVersion === null) {
                        knownVersion = data.version;
                        knownActivity = data.activity || {};
                        try {
                            window.sessionStorage.setItem(liveBaselineKey, knownVersion);
                            window.sessionStorage.setItem(liveSnapshotKey, JSON.stringify(knownActivity));
                        } catch (ignore) {}
                        return;
                    }
                    if (knownVersion === data.version) return;
                    var updateMessages = describeActivityChanges(knownActivity, data.activity || {});
                    knownVersion = data.version;
                    knownActivity = data.activity || {};
                    try {
                        window.sessionStorage.setItem(liveBaselineKey, knownVersion);
                        window.sessionStorage.setItem(liveSnapshotKey, JSON.stringify(knownActivity));
                    } catch (ignore) {}
                    if (!updateMessages.length) return;
                    var updateSummary = updateMessages.map(function (update) { return update.text; }).join(' ');
                    playNotificationSound(false);
                    if (hasUnsavedFormChanges) {
                        showUpdateNotice(updateMessages.concat([{ text:'Save your changes to load the updated page.', link:'' }]));
                        return;
                    }
                    try {
                        window.sessionStorage.setItem(pendingReloadNoticeKey, '1');
                        window.sessionStorage.setItem(pendingReloadMessageKey, JSON.stringify(updateMessages));
                    } catch (ignore) {}
                        window.clearTimeout(refreshTimer);
                        refreshTimer = window.setTimeout(function () {
                            if (!document.hidden && !hasUnsavedFormChanges) window.location.reload();
                            else {
                                try {
                                    window.sessionStorage.removeItem(pendingReloadNoticeKey);
                                    window.sessionStorage.removeItem(pendingReloadMessageKey);
                                } catch (ignore) {}
                                showUpdateNotice(updateMessages.concat([{ text:'Save your changes to load the updated page.', link:'' }]));
                            }
                        }, 700);
                }).catch(function () {});
        }

        pollLiveActivity();
        window.setInterval(pollLiveActivity, 12000);
    })();
    <?php endif; ?>
</script>
</body>
</html>