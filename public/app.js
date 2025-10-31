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
    }

    document.addEventListener('keyup', (event) => {
      if (event.key === 'Escape') {
        document.body.classList.remove('sidebar-open');
      }
    });
  };

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
