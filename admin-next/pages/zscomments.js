const tagName = window.__GRAV_PAGE_TAG;

if (!tagName) {
  throw new Error('Missing __GRAV_PAGE_TAG for ZSComments admin page.');
}

class GravZscommentsAdminPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.state = {
      loading: true,
      error: '',
      actionKey: '',
      data: {
        comments: [],
        page: 0,
        totalAvailable: 0,
        totalRetrieved: 0,
        pages: [],
      },
      labels: {
        plugin_title: 'ZSComments',
        summary_loaded: '%retrieved% of %total% comments loaded',
        refresh: 'Refresh',
        comments_title: 'Recent comments',
        loading_comments: 'Loading comments …',
        no_comments: 'No comments found.',
        author_unknown: 'Unknown',
        status_pending: 'Pending',
        status_approved: 'Approved',
        approve: 'Approve',
        approve_running: 'Approving …',
        delete: 'Delete',
        delete_running: 'Deleting …',
        quickreply_placeholder: 'Optional quick reply for approval',
        load_more: 'Load more',
        pages_title: 'Recently commented pages',
        no_pages: 'No commented pages yet.',
        column_author: 'Author',
        column_page: 'Page',
        column_date: 'Date',
        column_status: 'Status',
        column_actions: 'Actions',
        column_route: 'Route',
        column_comments: 'Comments',
        column_last_comment: 'Last comment',
        filter_range: 'Time range',
        filter_range_7d: 'last 7 days',
        filter_range_30d: 'last 30 days',
        filter_range_all: 'all',
        filter_pending_only: 'only pending',
        filter_route: 'Route',
        filter_route_placeholder: 'e.g. /site/about',
        filter_search: 'Text search',
        filter_search_placeholder: 'Search comment text',
        confirm_delete: 'Do you really want to delete this comment?',
      },
      filters: {
        range: '7d',
        pendingOnly: false,
        route: '',
        search: '',
      },
      filterTimer: null,
      focusedFilter: null,
    };

    this.handlePageAction = this.handlePageAction.bind(this);
  }

  connectedCallback() {
    this.addEventListener('page-action', this.handlePageAction);
    this.updateDocumentTitle();
    this.loadData();
  }

  disconnectedCallback() {
    this.removeEventListener('page-action', this.handlePageAction);

    if (this.state.filterTimer) {
      clearTimeout(this.state.filterTimer);
      this.state.filterTimer = null;
    }
  }

  handlePageAction(event) {
    if (event.detail && event.detail.id === 'refresh') {
      this.loadData();
    }
  }

  get apiBase() {
    const serverUrl = window.__GRAV_API_SERVER_URL || '';
    const apiPrefix = window.__GRAV_API_PREFIX || '/api/v1';

    return `${serverUrl}${apiPrefix}`;
  }

  get headers() {
    const headers = {
      'Accept': 'application/json',
    };

    if (window.__GRAV_API_TOKEN) {
      headers['X-API-Token'] = window.__GRAV_API_TOKEN;
    }

    return headers;
  }

  escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  formatCommentText(value) {
    return this.escapeHtml(value).replace(/\n/g, '<br>');
  }

  async request(path, options = {}) {
    const response = await fetch(`${this.apiBase}${path}`, {
      credentials: 'same-origin',
      ...options,
      headers: {
        ...this.headers,
        ...(options.headers || {}),
      },
    });

    let payload = null;

    try {
      payload = await response.json();
    } catch (error) {
      payload = null;
    }

    if (!response.ok) {
      const message = payload?.detail || payload?.title || payload?.error?.detail || payload?.error?.message || payload?.message || response.statusText || 'Request failed';
      throw new Error(message);
    }

    return payload?.data ?? payload ?? {};
  }

  async loadData(page = 0, append = false) {
    this.state.loading = true;
    this.state.error = '';
    this.render();

    try {
      const params = new URLSearchParams({
        page: String(page),
        range: this.state.filters.range,
      });

      if (this.state.filters.pendingOnly) {
        params.set('pending_only', '1');
      }

      if (this.state.filters.route) {
        params.set('route', this.state.filters.route);
      }

      if (this.state.filters.search) {
        params.set('search', this.state.filters.search);
      }

      const data = await this.request(`/zscomments-admin?${params.toString()}`);

      if (append) {
        const comments = [...this.state.data.comments, ...(data.comments || [])];

        this.state.data = {
          comments,
          page: data.page || 0,
          totalAvailable: data.totalAvailable || 0,
          totalRetrieved: comments.length,
          pages: data.pages || [],
        };
      } else {
        this.state.data = {
          comments: data.comments || [],
          page: data.page || 0,
          totalAvailable: data.totalAvailable || 0,
          totalRetrieved: data.totalRetrieved || 0,
          pages: data.pages || [],
        };
      }

      if (data.filters) {
        this.state.filters = {
          range: data.filters.range || this.state.filters.range,
          pendingOnly: Boolean(data.filters.pending_only),
          route: data.filters.route || '',
          search: data.filters.search || '',
        };
      }

      if (data.labels) {
        this.state.labels = {
          ...this.state.labels,
          ...data.labels,
        };
      }
    } catch (error) {
      this.state.error = error.message || 'Failed to load comments.';
    } finally {
      this.state.loading = false;
      this.render();
    }
  }

  async moderateComment(action, payload) {
    const actionKey = `${action}:${payload.id}`;
    this.state.actionKey = actionKey;
    this.state.error = '';
    this.render();

    try {
      await this.request(`/zscomments-admin/${action}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload),
      });

      await this.loadData();
    } catch (error) {
      this.state.error = error.message || 'Action failed.';
      this.state.loading = false;
      this.render();
    } finally {
      this.state.actionKey = '';
      this.render();
    }
  }

  bindActions() {
    const root = this.shadowRoot;

    root.querySelector('[data-action="refresh"]')?.addEventListener('click', () => {
      this.state.focusedFilter = null;
      this.loadData();
    });
    root.querySelector('[data-action="load-more"]')?.addEventListener('click', () => {
      this.state.focusedFilter = null;
      this.loadData(this.state.data.page + 1, true);
    });
    root.querySelector('[data-filter="range"]')?.addEventListener('change', (event) => {
      this.state.focusedFilter = null;
      this.state.filters.range = event.target.value;
      this.loadData(0, false);
    });
    root.querySelector('[data-filter="pending-only"]')?.addEventListener('change', (event) => {
      this.state.focusedFilter = null;
      this.state.filters.pendingOnly = Boolean(event.target.checked);
      this.loadData(0, false);
    });
    root.querySelector('[data-filter="route"]')?.addEventListener('input', (event) => {
      this.rememberFilterFocus('route', event.target);
      this.state.filters.route = event.target.value;
      this.queueFilterReload();
    });
    root.querySelector('[data-filter="search"]')?.addEventListener('input', (event) => {
      this.rememberFilterFocus('search', event.target);
      this.state.filters.search = event.target.value;
      this.queueFilterReload();
    });

    root.querySelectorAll('[data-approve]').forEach((button) => {
      button.addEventListener('click', () => {
        this.state.focusedFilter = null;
        const id = button.getAttribute('data-id');
        const url = button.getAttribute('data-url');
        const lang = button.getAttribute('data-lang') || '';
        const textarea = root.querySelector(`[data-quickreply="${CSS.escape(id)}"]`);
        const quickreply = textarea ? textarea.value.trim() : '';

        this.moderateComment('approve', { id, url, lang, quickreply });
      });
    });

    root.querySelectorAll('[data-delete]').forEach((button) => {
      button.addEventListener('click', () => {
        this.state.focusedFilter = null;
        const id = button.getAttribute('data-id');
        const url = button.getAttribute('data-url');
        const lang = button.getAttribute('data-lang') || '';

        if (!window.confirm(this.label('confirm_delete', 'Do you really want to delete this comment?'))) {
          return;
        }

        this.moderateComment('delete', { id, url, lang });
      });
    });
  }

  rememberFilterFocus(name, input) {
    this.state.focusedFilter = {
      name,
      start: typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length,
      end: typeof input.selectionEnd === 'number' ? input.selectionEnd : input.value.length,
    };
  }

  restoreFilterFocus() {
    if (!this.state.focusedFilter) {
      return;
    }

    const { name, start, end } = this.state.focusedFilter;
    const input = this.shadowRoot.querySelector(`[data-filter="${name}"]`);

    if (!input) {
      return;
    }

    input.focus();

    if (typeof input.setSelectionRange === 'function') {
      const nextStart = Math.min(start, input.value.length);
      const nextEnd = Math.min(end, input.value.length);
      input.setSelectionRange(nextStart, nextEnd);
    }
  }

  queueFilterReload() {
    if (this.state.filterTimer) {
      clearTimeout(this.state.filterTimer);
    }

    this.state.filterTimer = setTimeout(() => {
      this.state.filterTimer = null;
      this.loadData(0, false);
    }, 300);
  }

  label(key, fallback = '') {
    return this.state.labels[key] || fallback || key;
  }

  formatLabel(key, replacements = {}, fallback = '') {
    let text = this.label(key, fallback);

    Object.entries(replacements).forEach(([name, value]) => {
      text = text.replaceAll(`%${name}%`, String(value));
    });

    return text;
  }

  updateDocumentTitle() {
    document.title = `${this.label('plugin_title', 'ZSComments')} — Grav Admin`;
  }

  render() {
    const { loading, error, data, actionKey } = this.state;
    const hasComments = data.comments && data.comments.length > 0;
    const canLoadMore = data.totalRetrieved < data.totalAvailable;

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: block;
          color: hsl(240 10% 3.9%);
          font-family: ui-sans-serif, system-ui, sans-serif;
        }
        .wrap {
          display: grid;
          gap: 1.5rem;
        }
        .toolbar {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 1rem;
          flex-wrap: wrap;
        }
        .toolbar-left,
        .toolbar-right,
        .filters {
          display: flex;
          align-items: center;
          gap: 0.75rem;
          flex-wrap: wrap;
        }
        .filter-group {
          display: inline-flex;
          align-items: center;
          gap: 0.45rem;
          color: hsl(240 3.8% 46.1%);
          font-size: 0.92rem;
        }
        .filter-group select {
          border: 1px solid hsl(240 5.9% 90%);
          border-radius: 0.55rem;
          background: white;
          padding: 0.45rem 0.6rem;
          font: inherit;
          color: inherit;
        }
        .filter-group.checkbox {
          gap: 0.5rem;
        }
        .filter-group input[type="checkbox"] {
          margin: 0;
        }
        .filter-group input[type="text"] {
          border: 1px solid hsl(240 5.9% 90%);
          border-radius: 0.55rem;
          background: white;
          padding: 0.45rem 0.6rem;
          font: inherit;
          color: inherit;
          min-width: 12rem;
        }
        .muted {
          color: hsl(240 3.8% 46.1%);
          font-size: 0.95rem;
        }
        .button {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 0.5rem;
          border: 1px solid hsl(240 5.9% 90%);
          border-radius: 0.6rem;
          background: white;
          color: inherit;
          padding: 0.6rem 0.9rem;
          cursor: pointer;
          font: inherit;
        }
        .button:hover {
          background: hsl(240 4.8% 95.9%);
        }
        .button.primary {
          background: hsl(221 83% 53%);
          border-color: hsl(221 83% 53%);
          color: white;
        }
        .button.primary:hover {
          background: hsl(221 83% 45%);
        }
        .button.danger {
          color: hsl(0 72% 51%);
          border-color: hsl(0 84% 90%);
          background: hsl(0 86% 97%);
        }
        .button:disabled {
          opacity: 0.6;
          cursor: wait;
        }
        .panel {
          border: 1px solid hsl(240 5.9% 90%);
          border-radius: 1rem;
          background: white;
          overflow: hidden;
        }
        .panel-header {
          padding: 1rem 1.25rem;
          border-bottom: 1px solid hsl(240 5.9% 90%);
        }
        .panel-body {
          padding: 1.25rem;
          display: grid;
          gap: 1rem;
        }
        .alert {
          border: 1px solid hsl(0 84% 90%);
          background: hsl(0 86% 97%);
          color: hsl(0 72% 42%);
          border-radius: 0.8rem;
          padding: 0.9rem 1rem;
        }
        .comment-title {
          font-weight: 600;
        }
        .badge {
          display: inline-flex;
          align-items: center;
          border-radius: 999px;
          padding: 0.2rem 0.6rem;
          font-size: 0.8rem;
          font-weight: 600;
          white-space: nowrap;
        }
        .badge.pending {
          background: hsl(48 96% 89%);
          color: hsl(32 95% 32%);
        }
        .badge.approved {
          background: hsl(142 76% 90%);
          color: hsl(142 72% 24%);
        }
        table {
          width: 100%;
          border-collapse: collapse;
        }
        th, td {
          text-align: left;
          padding: 0.8rem 0.5rem;
          border-bottom: 1px solid hsl(240 5.9% 90%);
          vertical-align: top;
        }
        th {
          font-size: 0.85rem;
          color: hsl(240 3.8% 46.1%);
        }
        .comments-table th,
        .comments-table td {
          padding-top: 0.55rem;
          padding-bottom: 0.55rem;
        }
        .comment-row-details td {
          padding-top: 0.25rem;
          padding-bottom: 0.8rem;
          background: hsl(240 4.8% 98.5%);
        }
        .comment-cell-text {
          line-height: 1.5;
          white-space: normal;
        }
        .comment-meta-line {
          font-size: 0.85rem;
          color: hsl(240 3.8% 46.1%);
          margin-top: 0.2rem;
        }
        .comment-actions {
          display: flex;
          gap: 0.45rem;
          flex-wrap: wrap;
          justify-content: flex-end;
        }
        .button.small {
          padding: 0.3rem 0.55rem;
          border-radius: 0.45rem;
          font-size: 0.82rem;
          line-height: 1.2;
        }
        textarea.quickreply {
          width: 100%;
          min-height: 4.4rem;
          margin-top: 0.6rem;
          border: 1px solid hsl(240 5.9% 90%);
          border-radius: 0.65rem;
          padding: 0.65rem 0.75rem;
          font: inherit;
          resize: vertical;
          background: white;
        }
        .center {
          text-align: center;
        }
        .empty {
          padding: 2rem 1rem;
          text-align: center;
          color: hsl(240 3.8% 46.1%);
        }
      </style>
      <div class="wrap">
        <div class="toolbar">
          <div class="toolbar-left">
            <div class="muted">${this.escapeHtml(this.formatLabel('summary_loaded', { retrieved: data.totalRetrieved || 0, total: data.totalAvailable || 0 }, '%retrieved% of %total% comments loaded'))}</div>
            <div class="filters">
              <label class="filter-group">
                <span>${this.escapeHtml(this.label('filter_range', 'Time range'))}</span>
                <select data-filter="range" ${loading ? 'disabled' : ''}>
                  <option value="7d" ${this.state.filters.range === '7d' ? 'selected' : ''}>${this.escapeHtml(this.label('filter_range_7d', 'last 7 days'))}</option>
                  <option value="30d" ${this.state.filters.range === '30d' ? 'selected' : ''}>${this.escapeHtml(this.label('filter_range_30d', 'last 30 days'))}</option>
                  <option value="all" ${this.state.filters.range === 'all' ? 'selected' : ''}>${this.escapeHtml(this.label('filter_range_all', 'all'))}</option>
                </select>
              </label>
              <label class="filter-group checkbox">
                <input type="checkbox" data-filter="pending-only" ${this.state.filters.pendingOnly ? 'checked' : ''} ${loading ? 'disabled' : ''}>
                <span>${this.escapeHtml(this.label('filter_pending_only', 'only pending'))}</span>
              </label>
              <label class="filter-group">
                <span>${this.escapeHtml(this.label('filter_route', 'Route'))}</span>
                <input type="text" data-filter="route" value="${this.escapeHtml(this.state.filters.route)}" placeholder="${this.escapeHtml(this.label('filter_route_placeholder', 'e.g. /site/about'))}" ${loading ? 'disabled' : ''}>
              </label>
              <label class="filter-group">
                <span>${this.escapeHtml(this.label('filter_search', 'Text search'))}</span>
                <input type="text" data-filter="search" value="${this.escapeHtml(this.state.filters.search)}" placeholder="${this.escapeHtml(this.label('filter_search_placeholder', 'Search comment text'))}" ${loading ? 'disabled' : ''}>
              </label>
            </div>
          </div>
          <div class="toolbar-right">
            <button class="button" data-action="refresh" ${loading ? 'disabled' : ''}>${this.escapeHtml(this.label('refresh', 'Refresh'))}</button>
          </div>
        </div>
        ${error ? `<div class="alert">${this.escapeHtml(error)}</div>` : ''}
        <section class="panel">
          <div class="panel-header">
            <strong>${this.escapeHtml(this.label('comments_title', 'Recent comments'))}</strong>
          </div>
          <div class="panel-body">
            ${loading && !hasComments ? `<div class="empty">${this.escapeHtml(this.label('loading_comments', 'Loading comments …'))}</div>` : ''}
            ${!loading && !hasComments ? `<div class="empty">${this.escapeHtml(this.label('no_comments', 'No comments found.'))}</div>` : ''}
            ${hasComments ? `
              <table class="comments-table">
                <thead>
                  <tr>
                    <th>${this.escapeHtml(this.label('column_author', 'Author'))}</th>
                    <th>${this.escapeHtml(this.label('column_page', 'Page'))}</th>
                    <th>${this.escapeHtml(this.label('column_date', 'Date'))}</th>
                    <th>${this.escapeHtml(this.label('column_status', 'Status'))}</th>
                    <th style="text-align:right;">${this.escapeHtml(this.label('column_actions', 'Actions'))}</th>
                  </tr>
                </thead>
                <tbody>
                  ${data.comments.map((comment) => {
                    const commentKey = `${comment.id}`;
                    const approveKey = `approve:${commentKey}`;
                    const deleteKey = `delete:${commentKey}`;
                    const isPending = Number(comment.is_pending || 0) === 1;
                    const lang = comment.lang || '';
                    const url = comment.url || '/';

                    return `
                      <tr class="comment-row-main">
                        <td>
                          <div class="comment-title">${this.escapeHtml(comment.author || this.label('author_unknown', 'Unknown'))}</div>
                          <div class="comment-meta-line">${this.escapeHtml(comment.email || '')}</div>
                          <div class="comment-meta-line">${this.escapeHtml(comment.ip)}</div>
                        </td>
                        <td>
                          <div>${this.escapeHtml(comment.pageTitle || url)}</div>
                          <div class="comment-meta-line">${this.escapeHtml(url)}${lang ? ` · ${this.escapeHtml(lang)}` : ''}</div>
                        </td>
                        <td>${this.escapeHtml(comment.date || '')}</td>
                        <td><span class="badge ${isPending ? 'pending' : 'approved'}">${this.escapeHtml(isPending ? this.label('status_pending', 'Pending') : this.label('status_approved', 'Approved'))}</span></td>
                        <td>
                          <div class="comment-actions">
                            ${isPending ? `<button class="button primary small" data-approve data-id="${this.escapeHtml(commentKey)}" data-url="${this.escapeHtml(url)}" data-lang="${this.escapeHtml(lang)}" ${actionKey === approveKey ? 'disabled' : ''}>${this.escapeHtml(actionKey === approveKey ? this.label('approve_running', 'Approving …') : this.label('approve', 'Approve'))}</button>` : ''}
                            <button class="button danger small" data-delete data-id="${this.escapeHtml(commentKey)}" data-url="${this.escapeHtml(url)}" data-lang="${this.escapeHtml(lang)}" ${actionKey === deleteKey ? 'disabled' : ''}>${this.escapeHtml(actionKey === deleteKey ? this.label('delete_running', 'Deleting …') : this.label('delete', 'Delete'))}</button>
                          </div>
                        </td>
                      </tr>
                      <tr class="comment-row-details">
                        <td colspan="5">
                          <div class="comment-cell-text">${this.formatCommentText(comment.text || '')}</div>
                          ${isPending ? `<textarea class="quickreply" data-quickreply="${this.escapeHtml(commentKey)}" placeholder="${this.escapeHtml(this.label('quickreply_placeholder', 'Optional quick reply for approval'))}"></textarea>` : ''}
                        </td>
                      </tr>
                    `;
                  }).join('')}
                </tbody>
              </table>
            ` : ''}
            ${canLoadMore ? `<div class="center"><button class="button" data-action="load-more" ${loading ? 'disabled' : ''}>${this.escapeHtml(this.label('load_more', 'Load more'))}</button></div>` : ''}
          </div>
        </section>
        <section class="panel">
          <div class="panel-header">
            <strong>${this.escapeHtml(this.label('pages_title', 'Recently commented pages'))}</strong>
          </div>
          <div class="panel-body">
            ${data.pages && data.pages.length ? `
              <table>
                <thead>
                  <tr>
                    <th>${this.escapeHtml(this.label('column_page', 'Page'))}</th>
                    <th>${this.escapeHtml(this.label('column_route', 'Route'))}</th>
                    <th>${this.escapeHtml(this.label('column_comments', 'Comments'))}</th>
                    <th>${this.escapeHtml(this.label('column_last_comment', 'Last comment'))}</th>
                  </tr>
                </thead>
                <tbody>
                  ${data.pages.map((page) => `
                    <tr>
                      <td>${this.escapeHtml(page.title || '')}</td>
                      <td>${this.escapeHtml(page.route || '')}${page.lang ? ` <span class="muted">(${this.escapeHtml(page.lang)})</span>` : ''}</td>
                      <td>${this.escapeHtml(page.commentsCount || 0)}</td>
                      <td>${this.escapeHtml(page.lastCommentDate || '')}</td>
                    </tr>
                  `).join('')}
                </tbody>
              </table>
            ` : `<div class="empty">${this.escapeHtml(this.label('no_pages', 'No commented pages yet.'))}</div>`}
          </div>
        </section>
      </div>
    `;

    this.bindActions();
    this.restoreFilterFocus();
    this.updateDocumentTitle();
  }
}

if (!customElements.get(tagName)) {
  customElements.define(tagName, GravZscommentsAdminPage);
}
