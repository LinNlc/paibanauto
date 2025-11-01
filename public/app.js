(() => {
  const bus = document.createElement('span');
  const App = {
    on(eventName, listener) {
      bus.addEventListener(eventName, listener);
      return () => bus.removeEventListener(eventName, listener);
    },
    off(eventName, listener) {
      bus.removeEventListener(eventName, listener);
    },
    emit(eventName, detail) {
      bus.dispatchEvent(new CustomEvent(eventName, { detail }));
    },
    ready(callback) {
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', callback, { once: true });
      } else {
        callback();
      }
    },
  };

  const APP_META = {
    version: '2024.06.10',
    releasedAt: '2024-06-10 12:00',
    changelog: [
      {
        version: '2024.06.10',
        releasedAt: '2024-06-10 12:00',
        items: [
          '新增“播单专辑排班”视图，支持白班/中班维护、自动排班与 Excel 导入导出能力。',
          '数据统计页增加播单专辑排班分析，仅在具有播单权限时展示。',
          '侧边栏排班菜单支持悬停展开播单专辑入口，并依据权限动态显示。',
        ],
      },
      {
        version: '2024.06.05',
        releasedAt: '2024-06-05 18:00',
        items: [
          '修复排班页面的接口路径，恢复用户信息与班次数据的正常加载。',
          '全站统一提供退出登录入口，支持一键安全登出。',
          '权限设置页新增团队管理，支持新增、重命名和删除可切换的团队。',
        ],
      },
      {
        version: '2024.06.02',
        releasedAt: '2024-06-02 09:00',
        items: [
          '重新调整排班日历结构，按日期展示行以保证姓名与班次严格对应。',
          '新增“权限设置”页面，集中管理账号角色与权限，仅高级管理员可见。',
          '全站静态资源版本号更新至 20240602，确保浏览器获取最新界面。',
        ],
      },
      {
        version: '2024.06.01',
        releasedAt: '2024-06-01 10:00',
        items: [
          '修复排班表中姓名列与班次列的错位问题。',
          '仅在首页提供团队切换，其它页面自动跟随所选团队。',
          '新增侧边栏版本信息与更新日志，并通过版本号刷新静态资源缓存。',
        ],
      },
    ],
  };

  App.meta = APP_META;

  const TEAM_STORAGE_KEY = 'paiban:selected-team-id';

  function normalizeTeamId(value) {
    const num = Number.parseInt(value, 10);
    return Number.isFinite(num) && num > 0 ? num : null;
  }

  App.getPreferredTeamId = function getPreferredTeamId() {
    try {
      const raw = window.localStorage.getItem(TEAM_STORAGE_KEY);
      return normalizeTeamId(raw);
    } catch (error) {
      return null;
    }
  };

  App.setPreferredTeamId = function setPreferredTeamId(teamId) {
    const normalized = normalizeTeamId(teamId);
    try {
      if (normalized === null) {
        window.localStorage.removeItem(TEAM_STORAGE_KEY);
      } else {
        window.localStorage.setItem(TEAM_STORAGE_KEY, String(normalized));
      }
    } catch (error) {
      /* 忽略本地存储异常 */
    }
    App.emit('team:change', { teamId: normalized });
  };

  window.addEventListener('storage', (event) => {
    if (event.key !== TEAM_STORAGE_KEY) {
      return;
    }
    App.emit('team:change', { teamId: App.getPreferredTeamId() });
  });

  async function performLogout() {
    try {
      await fetch('/api/auth.php?action=logout', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
        },
        body: '{}',
      });
    } catch (error) {
      console.warn('Failed to logout', error);
    } finally {
      window.location.href = './login.html';
    }
  }

  App.logout = performLogout;

  App.bindLogoutButtons = function bindLogoutButtons(root = document) {
    const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
    scope.querySelectorAll('[data-action="logout"]').forEach((button) => {
      if (button.dataset.logoutBound === '1') {
        return;
      }
      button.addEventListener('click', (event) => {
        event.preventDefault();
        performLogout();
      });
      button.dataset.logoutBound = '1';
    });
  };

  let toastContainer;
  let toastHideTimer;

  function ensureToastContainer() {
    if (!toastContainer) {
      toastContainer = document.createElement('div');
      toastContainer.className = 'toast-container';
      document.body.appendChild(toastContainer);
    }
    return toastContainer;
  }

  App.toast = function toast(message, options = {}) {
    const { type = 'error', duration = 3200 } = options;
    const container = ensureToastContainer();
    container.innerHTML = '';
    const el = document.createElement('div');
    el.className = 'toast-message';
    el.dataset.type = type;
    el.textContent = message;
    container.appendChild(el);

    requestAnimationFrame(() => {
      el.classList.add('show');
    });

    if (toastHideTimer) {
      clearTimeout(toastHideTimer);
    }

    toastHideTimer = window.setTimeout(() => {
      el.classList.remove('show');
      window.setTimeout(() => {
        if (el.parentElement === container) {
          container.removeChild(el);
        }
      }, 260);
    }, duration);
  };

  let overlayEl;

  function ensureOverlay() {
    if (!overlayEl) {
      overlayEl = document.createElement('div');
      overlayEl.className = 'loading-mask';
      overlayEl.innerHTML = `
        <div class="mask-content">
          <div class="mask-spinner" aria-hidden="true"></div>
          <div class="mask-text">加载中...</div>
        </div>
      `;
      document.body.appendChild(overlayEl);
    }
    return overlayEl;
  }

  App.showLoading = function showLoading(message = '加载中...') {
    const overlay = ensureOverlay();
    overlay.querySelector('.mask-text').textContent = message;
    overlay.classList.add('show');
    document.body.classList.add('has-overlay');
  };

  App.hideLoading = function hideLoading() {
    if (!overlayEl) return;
    overlayEl.classList.remove('show');
    document.body.classList.remove('has-overlay');
  };

  App.flash = function flashElement(el) {
    if (!el) return;
    el.classList.remove('cell-flash');
    void el.offsetWidth;
    el.classList.add('cell-flash');
  };

  App.initSidebar = function initSidebar(options = {}) {
    const { activeView, onNavigate } = options;
    const sidebar = document.querySelector('.app-sidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');

    if (toggle) {
      toggle.addEventListener('click', () => {
        document.body.classList.toggle('sidebar-open');
      });
    }

    if (sidebar) {
      const navLinks = sidebar.querySelectorAll('nav a[data-view]');
      navLinks.forEach((link) => {
        const view = link.dataset.view;
        if (view && activeView && view === activeView) {
          link.classList.add('is-active');
        } else {
          link.classList.remove('is-active');
        }
        link.addEventListener('click', (event) => {
          if (typeof onNavigate === 'function') {
            const shouldPrevent = onNavigate({ event, view });
            if (shouldPrevent === true) {
              event.preventDefault();
              return;
            }
          }
          document.body.classList.remove('sidebar-open');
        });
      });

      populateSidebarMeta(sidebar);
    }

    document.addEventListener('keyup', (event) => {
      if (event.key === 'Escape') {
        document.body.classList.remove('sidebar-open');
      }
    });

    App.bindLogoutButtons();
  };

  function populateSidebarMeta(sidebar) {
    if (!sidebar) {
      return;
    }
    const versionTarget = sidebar.querySelector('[data-app-version]');
    const releaseTarget = sidebar.querySelector('[data-app-release]');
    const changelogTarget = sidebar.querySelector('[data-app-changelog]');

    if (versionTarget) {
      versionTarget.textContent = APP_META.version;
    }

    if (releaseTarget) {
      releaseTarget.textContent = APP_META.releasedAt;
    }

    if (changelogTarget) {
      changelogTarget.innerHTML = '';
      if (Array.isArray(APP_META.changelog) && APP_META.changelog.length) {
        APP_META.changelog.forEach((entry) => {
          const item = document.createElement('li');
          item.className = 'sidebar-changelog-item';

          const header = document.createElement('div');
          header.className = 'sidebar-changelog-item-header';
          const versionSpan = document.createElement('span');
          versionSpan.className = 'sidebar-changelog-version';
          versionSpan.textContent = `v${entry.version}`;
          const dateSpan = document.createElement('span');
          dateSpan.className = 'sidebar-changelog-date';
          dateSpan.textContent = entry.releasedAt || '';
          header.appendChild(versionSpan);
          header.appendChild(dateSpan);

          const list = document.createElement('ul');
          list.className = 'sidebar-changelog-item-list';
          if (Array.isArray(entry.items)) {
            entry.items.forEach((text) => {
              if (!text) return;
              const li = document.createElement('li');
              li.textContent = text;
              list.appendChild(li);
            });
          }

          item.appendChild(header);
          item.appendChild(list);
          changelogTarget.appendChild(item);
        });
      } else {
        const empty = document.createElement('li');
        empty.className = 'sidebar-changelog-empty';
        empty.textContent = '暂无更新记录';
        changelogTarget.appendChild(empty);
      }
    }
  }

  App.registerGlobalShortcuts = function registerGlobalShortcuts(map) {
    document.addEventListener('keydown', (event) => {
      if (event.target && ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName)) {
        return;
      }
      const handler = map[event.key?.toLowerCase?.()] || map[event.key];
      if (typeof handler === 'function') {
        handler(event);
      }
    });
  };

  App.emit('app:ready');
  window.App = App;
})();
