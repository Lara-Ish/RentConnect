/**
 * RentConnect - Real-time Notifications Client Handler
 * Manages notification badge, panel toggle, AJAX retrieval, and mark-as-read actions.
 */
(function() {
  let isFetching = false;

  function getNotificationIcon(type) {
    switch (type) {
      case 'payment':
        return '<div class="notif-icon-circle notif-icon-payment">💳</div>';
      case 'maintenance':
        return '<div class="notif-icon-circle notif-icon-maintenance">🔧</div>';
      case 'announcement':
        return '<div class="notif-icon-circle notif-icon-announcement">📢</div>';
      case 'account':
      default:
        return '<div class="notif-icon-circle notif-icon-account">👤</div>';
    }
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  window.loadNotifications = function(silent = false) {
    if (isFetching) return;
    isFetching = true;

    fetch('api/notification_api.php?action=list')
      .then(res => res.json())
      .then(data => {
        isFetching = false;
        if (data.status === 'success') {
          renderNotifications(data);
        }
      })
      .catch(err => {
        isFetching = false;
        if (!silent) console.error('Failed to load notifications:', err);
      });
  };

  function renderNotifications(data) {
    const unreadCount = data.unread_count || 0;
    const badge = document.getElementById('notificationBadge');
    const unreadPill = document.getElementById('notificationUnreadPill');
    const listContainer = document.getElementById('notificationListContainer');

    // Update unread badge on bell button
    if (badge) {
      if (unreadCount > 0) {
        badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
        badge.style.display = 'flex';
      } else {
        badge.style.display = 'none';
      }
    }

    // Update unread counter pill inside dropdown header
    if (unreadPill) {
      if (unreadCount > 0) {
        unreadPill.textContent = `${unreadCount} new`;
        unreadPill.style.display = 'inline-block';
      } else {
        unreadPill.style.display = 'none';
      }
    }

    // Render notification list items
    if (listContainer) {
      const items = data.notifications || [];
      if (items.length === 0) {
        listContainer.innerHTML = `
          <div class="notification-empty-state">
            <div class="empty-icon">🔔</div>
            <h4>No Notifications Yet</h4>
            <p>You're all caught up! New updates will appear here.</p>
          </div>
        `;
        return;
      }

      let html = '';
      items.forEach(n => {
        const isUnread = parseInt(n.is_read, 10) === 0;
        const iconHtml = getNotificationIcon(n.type);
        html += `
          <div class="notification-item ${isUnread ? 'unread' : 'read'}" 
               data-id="${n.id}" 
               data-link="${escapeHtml(n.link || '')}"
               onclick="handleNotificationClick(event, ${n.id}, '${escapeHtml(n.link || '')}')"
               role="button"
               tabindex="0">
            ${iconHtml}
            <div class="notif-content">
              <div class="notif-title">${escapeHtml(n.title)}</div>
              <div class="notif-message">${escapeHtml(n.message)}</div>
              <div class="notif-time">${escapeHtml(n.time_ago)} • ${escapeHtml(n.formatted_date || '')}</div>
            </div>
            ${isUnread ? '<span class="notification-unread-dot" title="Unread"></span>' : ''}
          </div>
        `;
      });
      listContainer.innerHTML = html;
    }
  }

  window.toggleNotificationPanel = function(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    const notifPanel = document.getElementById('notificationDropdown');
    const settingsPanel = document.getElementById('settingsDropdown');

    if (settingsPanel) {
      settingsPanel.classList.remove('show');
    }

    if (notifPanel) {
      const isShowing = notifPanel.classList.toggle('show');
      if (isShowing) {
        window.loadNotifications();
      }
    }
  };

  window.closeNotificationPanel = function() {
    const notifPanel = document.getElementById('notificationDropdown');
    if (notifPanel) {
      notifPanel.classList.remove('show');
    }
  };

  window.handleNotificationClick = function(e, id, link) {
    if (e) e.stopPropagation();

    // Mark as read via API
    fetch('api/notification_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_read', id: id })
    })
    .then(res => res.json())
    .then(data => {
      if (data.status === 'success') {
        const badge = document.getElementById('notificationBadge');
        if (badge) {
          if (data.unread_count > 0) {
            badge.textContent = data.unread_count;
            badge.style.display = 'flex';
          } else {
            badge.style.display = 'none';
          }
        }
        const unreadPill = document.getElementById('notificationUnreadPill');
        if (unreadPill) {
          if (data.unread_count > 0) {
            unreadPill.textContent = `${data.unread_count} new`;
            unreadPill.style.display = 'inline-block';
          } else {
            unreadPill.style.display = 'none';
          }
        }
      }
    })
    .catch(() => {});

    // Update item visual in DOM immediately
    const itemEl = document.querySelector(`.notification-item[data-id="${id}"]`);
    if (itemEl && itemEl.classList.contains('unread')) {
      itemEl.classList.remove('unread');
      itemEl.classList.add('read');
      const dot = itemEl.querySelector('.notification-unread-dot');
      if (dot) dot.remove();

      // Decrement unread count badge in DOM immediately
      const badge = document.getElementById('notificationBadge');
      if (badge && badge.textContent) {
        let currentCount = parseInt(badge.textContent, 10) || 0;
        let newCount = Math.max(0, currentCount - 1);
        if (newCount > 0) {
          badge.textContent = newCount > 99 ? '99+' : newCount;
          badge.style.display = 'flex';
        } else {
          badge.style.display = 'none';
        }
        const unreadPill = document.getElementById('notificationUnreadPill');
        if (unreadPill) {
          if (newCount > 0) {
            unreadPill.textContent = `${newCount} new`;
            unreadPill.style.display = 'inline-block';
          } else {
            unreadPill.style.display = 'none';
          }
        }
      }
    }

    window.closeNotificationPanel();

    // Navigate to relevant section if link is provided
    if (link) {
      // Landlord navigation
      if (typeof switchSection === 'function') {
        if (link === 'sectionPayments') switchSection('sectionPayments');
        else if (link === 'sectionTickets') switchSection('sectionTickets');
        else if (link === 'sectionDirectory') switchSection('sectionDirectory');
        else if (link === 'sectionProfile' && typeof openProfileView === 'function') openProfileView('accountInfo');
        else switchSection(link);
      }
      // Tenant navigation
      else if (typeof switchTenantSection === 'function') {
        if (link === 'tenantProfile' && typeof openProfileView === 'function') {
          openProfileView('accountInfo');
        } else {
          switchTenantSection(link);
        }
      } else if (typeof openProfileView === 'function') {
        openProfileView('accountInfo');
      }
    }
  };

  window.markAllNotificationsAsRead = function(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }

    fetch('api/notification_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_all_read' })
    })
    .then(res => res.json())
    .then(data => {
      if (data.status === 'success') {
        const badge = document.getElementById('notificationBadge');
        if (badge) badge.style.display = 'none';
        const unreadPill = document.getElementById('notificationUnreadPill');
        if (unreadPill) unreadPill.style.display = 'none';
        document.querySelectorAll('.notification-item.unread').forEach(el => {
          el.classList.remove('unread');
          el.classList.add('read');
          const dot = el.querySelector('.notification-unread-dot');
          if (dot) dot.remove();
        });
      }
    })
    .catch(err => console.error('Failed to mark all as read:', err));
  };

  // Close notifications when clicking outside
  document.addEventListener('click', function(e) {
    if (!e.target.closest('#headerNotificationBtn') && !e.target.closest('#notificationDropdown')) {
      window.closeNotificationPanel();
    }
  });

  // Initial load and periodic polling every 20 seconds
  document.addEventListener('DOMContentLoaded', function() {
    window.loadNotifications();
    setInterval(() => window.loadNotifications(true), 20000);
  });
})();
