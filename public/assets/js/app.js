/* ============================================================
 * PMD 个人物品管理数据库 前端单页应用
 * 依赖：/assets/css/style.css
 * 入口：boot() → public_status → 登录 → 外壳 → hash 路由
 * ============================================================ */
'use strict';

/* ---------------- 全局状态 ---------------- */
const S = {
  status: null,          // public_status 数据
  view: 'items',
  items: {
    q: '', location: '', main: '', sub: '', showScrapped: false, pendingOnly: false,
    page: 1, pageSize: 30, sort: 'last_modified', order: 'desc',
    rows: [], total: 0, selected: new Set(),
  },
  add: { mode: 'single', rows: 5, serialMode: 'auto' },
  exchange: { searchRows: [], searchSel: new Set(), newLoc: '', pending: [], token: null, draft: null },
  purchase: { scanLoc: '', candidates: [], searchRows: [], searchSel: new Set(), cart: [], token: null, draft: null },
  borrow: { tab: 'out', rows: [], history: [] },
};

/* ---------------- 基础工具 ---------------- */
const $ = (sel, root) => (root || document).querySelector(sel);
const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function t(key, params) {
  let s = (S.status && S.status.strings && S.status.strings[key]) || key;
  if (params) for (const k in params) s = s.split('{' + k + '}').join(params[k]);
  return s;
}

function el(tag, attrs, ...children) {
  const node = document.createElement(tag);
  if (attrs) {
    for (const k in attrs) {
      const v = attrs[k];
      if (k === 'class') node.className = v;
      else if (k === 'html') node.innerHTML = v;
      else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
      else if (k === 'dataset') Object.assign(node.dataset, v);
      // 布尔属性（selected/checked/disabled/readonly 等）：false 不设置（属性存在即生效），true 设为空属性
      else if (v === false) continue;
      else if (v === true) node.setAttribute(k, '');
      else node.setAttribute(k, v);
    }
  }
  for (const c of children.flat()) {
    if (c == null || c === false) continue;
    if (typeof c === 'object') node.appendChild(c);
    else node.appendChild(document.createTextNode(String(c)));
  }
  return node;
}

function qs(params) {
  const p = new URLSearchParams();
  for (const k in params) {
    const v = params[k];
    if (v !== '' && v != null) p.set(k, v);
  }
  return p.toString();
}

/* ---------------- API ---------------- */
async function api(action, params = {}, opt = {}) {
  let url = 'api.php?action=' + encodeURIComponent(action);
  const init = {};
  if (opt.get) {
    url += (Object.keys(params).length ? '&' + qs(params) : '');
    init.method = 'GET';
  } else {
    init.method = 'POST';
    init.headers = { 'Content-Type': 'application/json' };
    init.body = JSON.stringify(params || {});
  }
  const res = await fetch(url, init);
  if (res.status === 401) { location.reload(); throw new Error('unauthorized'); }
  let j = null;
  try { j = await res.json(); } catch (e) { throw new Error('服务器响应异常'); }
  if (!res.ok || !j.ok) throw new Error(j.error || '请求失败');
  return j.data;
}

async function apiForm(action, formData) {
  const res = await fetch('api.php?action=' + encodeURIComponent(action), { method: 'POST', body: formData });
  if (res.status === 401) { location.reload(); throw new Error('unauthorized'); }
  const j = await res.json();
  if (!res.ok || !j.ok) throw new Error(j.error || '请求失败');
  return j.data;
}

/* ---------------- UI 组件 ---------------- */
function toast(msg, type = 'ok', ms = 3200) {
  let box = $('#toasts');
  if (!box) { box = el('div', { id: 'toasts' }); document.body.appendChild(box); }
  const n = el('div', { class: 'toast ' + type }, msg);
  box.appendChild(n);
  setTimeout(() => { n.style.opacity = '0'; n.style.transition = 'opacity .3s'; setTimeout(() => n.remove(), 320); }, ms);
}

function modal({ title, body, foot, size = '' }) {
  const mask = el('div', { class: 'modal-mask' });
  const dlg = el('div', { class: 'modal ' + size });
  const head = el('div', { class: 'modal-head' },
    el('div', {}, title),
    el('button', { class: 'modal-x', type: 'button', onclick: () => mask.remove() }, '×')
  );
  dlg.appendChild(head);
  if (body) dlg.appendChild(el('div', { class: 'modal-body' }, body));
  if (foot) dlg.appendChild(el('div', { class: 'modal-foot' }, foot));
  mask.appendChild(dlg);
  mask.addEventListener('click', e => { if (e.target === mask) mask.remove(); });
  document.body.appendChild(mask);
  return { mask, dlg, close: () => mask.remove() };
}

function confirmDlg(message, okText) {
  return new Promise(resolve => {
    const m = modal({
      title: t('common.confirm'), size: 'sm',
      body: el('div', {}, message),
      foot: [
        el('button', { class: 'btn ghost', type: 'button', onclick: () => { m.close(); resolve(false); } }, t('common.cancel')),
        el('button', { class: 'btn', type: 'button', onclick: () => { m.close(); resolve(true); } }, okText || t('common.confirm')),
      ],
    });
  });
}

function btn(text, cls, onclick) {
  return el('button', { class: 'btn ' + (cls || ''), type: 'button', onclick }, text);
}

function locName(code) {
  if (!code) return '';
  const l = (S.status.locations || []).find(x => x.code === code);
  return l ? l.name : code;
}

function catName(main, sub) {
  const c = (S.status.categories || []).find(x => x.main_code === main && x.sub_code === sub);
  return c ? c.sub_name : sub;
}

function hygieneName(code) {
  if (!code) return '';
  const h = (S.status.hygiene_levels || []).find(x => x.code === code);
  return h ? h.name : '';
}

function hygieneSelect(attrs, selected) {
  const opts = [el('option', { value: '' }, t('common.select'))]
    .concat((S.status.hygiene_levels || []).map(h =>
      el('option', { value: h.code, selected: h.code === selected }, `${h.code} ${h.name}`)));
  if (selected && !(S.status.hygiene_levels || []).some(h => h.code === selected)) {
    opts.push(el('option', { value: selected, selected: true }, `${selected}（自定义）`));
  }
  return el('select', attrs, opts);
}

function depBadge(v) {
  v = +v;
  const cls = v < 20 ? 'dep-lo' : (v < 60 ? 'dep-mid' : 'dep-hi');
  return el('span', { class: 'badge ' + cls }, v + '%');
}

function applyTheme(accent) {
  const r = document.documentElement.style;
  r.setProperty('--accent', accent || '#2563eb');
  r.setProperty('--accent-dark', shade(accent || '#2563eb', -18));
  r.setProperty('--accent-soft', hexToRgba(accent || '#2563eb', 0.1));
}
function shade(hex, pct) {
  const n = parseInt(hex.slice(1), 16);
  const f = c => Math.max(0, Math.min(255, c + (pct / 100) * 255));
  const r = f(n >> 16), g = f((n >> 8) & 0xff), b = f(n & 0xff);
  return '#' + ((1 << 24) + (Math.round(r) << 16) + (Math.round(g) << 8) + Math.round(b)).toString(16).slice(1);
}
function hexToRgba(hex, a) {
  const n = parseInt(hex.slice(1), 16);
  return `rgba(${n >> 16}, ${(n >> 8) & 0xff}, ${n & 0xff}, ${a})`;
}

function locationSelect(attrs, selected, withCustom) {
  const opts = [el('option', { value: '' }, t('common.select'))]
    .concat((S.status.locations || []).map(l =>
      el('option', { value: l.code, selected: l.code === selected }, `${l.code}（${l.name}）`)));
  if (withCustom && selected && !(S.status.locations || []).some(l => l.code === selected)) {
    opts.push(el('option', { value: selected, selected: true }, `${selected}（自定义）`));
  }
  return el('select', attrs, opts);
}

/* ---------------- 启动 ---------------- */
async function boot() {
  try {
    S.status = await api('public_status', {}, { get: true });
  } catch (e) {
    toast('无法连接服务器：' + e.message, 'err', 6000);
    return;
  }
  applyTheme(S.status.theme_accent);
  document.title = S.status.site_title;
  if (!S.status.logged_in) { renderLogin(); return; }
  try {
    const s = await api('settings.get', {}, { get: true });
    if (s && s.rows_per_page) S.items.pageSize = s.rows_per_page;
  } catch (e) { /* 忽略：保持默认每页 30 条 */ }
  renderShell();
  route();
}

function renderLogin() {
  const app = $('#app');
  app.innerHTML = '';
  const logo = S.status.logo ? `<img class="logo" src="/uploads/logo/${esc(S.status.logo)}" alt="logo">` : '';
  const card = el('div', { class: 'login-wrap' },
    el('div', { class: 'login-card' },
      el('div', { html: logo }),
      el('h1', {}, S.status.site_title),
      el('div', { class: 'sub' }, t('app.subtitle')),
      el('input', { id: 'pin', type: 'password', placeholder: 'PIN', autocomplete: 'current-password', maxlength: '32' }),
      el('div', { class: 'mt' },
        el('button', { class: 'btn lg', id: 'loginBtn', type: 'button' }, t('common.login'))
      ),
      el('div', { class: 'hint', id: 'loginErr', style: 'color:#dc2626;min-height:18px;margin-top:10px' })
    )
  );
  app.appendChild(card);
  const doLogin = async () => {
    const pin = $('#pin').value;
    if (!pin) return;
    try {
      const data = await api('login', { pin });
      S.status = data;
      applyTheme(data.theme_accent);
      document.title = data.site_title;
      renderShell();
      route();
    } catch (e) {
      $('#loginErr').textContent = e.message;
    }
  };
  $('#loginBtn').addEventListener('click', doLogin);
  $('#pin').addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
  $('#pin').focus();
}

function renderShell() {
  const app = $('#app');
  app.innerHTML = '';
  const logo = S.status.logo
    ? el('img', { src: '/uploads/logo/' + esc(S.status.logo), alt: 'logo' })
    : el('div', { style: 'width:36px;height:36px;border-radius:8px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px' }, 'PMD');
  const nav = el('nav', { class: 'pmd-nav' }, [
    ['items', t('nav.items')], ['exchange', t('nav.exchange')], ['purchase', t('nav.purchase')],
    ['borrow', t('nav.borrow')], ['add', t('nav.add')], ['settings', t('nav.settings')],
  ].map(([v, label]) => el('a', { href: '#/' + v, dataset: { view: v }, onclick: () => setActiveNav(v) }, label)));

  const header = el('header', { class: 'pmd-header' },
    el('div', { class: 'pmd-header-inner' },
      el('div', { class: 'pmd-brand' }, logo, el('div', {}, el('div', { class: 'title' }, S.status.site_title), el('div', { class: 'sub' }, t('app.subtitle')))),
      nav,
      el('div', { class: 'pmd-header-right' },
        el('button', { class: 'btn ghost sm', type: 'button', onclick: async () => { await api('logout'); location.reload(); } }, t('common.logout'))
      )
    )
  );
  app.appendChild(header);
  app.appendChild(el('main', { class: 'pmd-main', id: 'main' }));
}

function setActiveNav(v) {
  $$('.pmd-nav a').forEach(a => a.classList.toggle('active', a.dataset.view === v));
}

/* ---------------- 路由 ---------------- */
function route() {
  const h = (location.hash.replace(/^#\/?/, '') || 'items').split('?')[0];
  S.view = ['items', 'exchange', 'purchase', 'borrow', 'add', 'settings'].includes(h) ? h : 'items';
  setActiveNav(S.view);
  const main = $('#main');
  if (!main) return;
  main.innerHTML = '';
  ({ items: renderItems, exchange: renderExchange, purchase: renderPurchase, borrow: renderBorrow, add: renderAdd, settings: renderSettings })[S.view]();
  window.scrollTo(0, 0);
}
window.addEventListener('hashchange', route);

/* ============================================================
 * 视图：物品总览
 * ============================================================ */
async function renderItems() {
  const main = $('#main');
  main.innerHTML = '';
  const st = S.items;

  const toolbar = el('div', { class: 'card' },
    el('h2', {}, t('items.title')),
    el('div', { class: 'row' },
      el('input', { class: 'flex1', id: 'itQ', type: 'text', placeholder: t('items.keyword'), value: st.q }),
      locationSelect({ id: 'itLoc', class: '', style: 'width:auto' }, st.location),
      el('select', { id: 'itMain', style: 'width:auto' },
        el('option', { value: '' }, t('field.mainCat') + '：' + t('common.all')),
        Object.entries(S.status.main_categories).map(([k, v]) => el('option', { value: k, selected: k === st.main }, `${k} ${v}`))
      ),
      el('select', { id: 'itSub', style: 'width:auto' }, subCatOptions(st.sub)),
      el('label', { class: 'nowrap' }, el('input', { type: 'checkbox', id: 'itScrap', checked: st.showScrapped }), ' ' + t('items.showScrapped')),
      el('label', { class: 'nowrap' }, el('input', { type: 'checkbox', id: 'itPending', checked: st.pendingOnly }), ' ' + t('items.pendingOnly')),
      btn(t('common.search'), 'sm', () => { st.q = $('#itQ').value.trim(); st.location = $('#itLoc').value; st.main = $('#itMain').value; st.sub = $('#itSub').value; st.showScrapped = $('#itScrap').checked; st.pendingOnly = $('#itPending').checked; st.page = 1; loadItems(); }),
      btn(t('common.reset'), 'ghost sm', () => { Object.assign(st, { q: '', location: '', main: '', sub: '', showScrapped: false, pendingOnly: false, page: 1 }); renderItems(); }),
    ),
    el('div', { class: 'row mt' },
      btn(t('items.batchEdit'), 'sm', batchEditModal),
      btn(t('items.exportXlsx'), 'ghost sm', () => exportItems('xlsx')),
      btn(t('items.exportCsv'), 'ghost sm', () => exportItems('csv')),
      btn(t('items.import'), 'ghost sm', importItemsModal),
      el('span', { class: 'muted', id: 'itCount' })
    )
  );
  main.appendChild(toolbar);
  main.appendChild(el('div', { id: 'itemsTable' }));
  await loadItems();
}

function subCatOptions(selected) {
  const main = S.items.main;
  const cats = main
    ? (S.status.categories || []).filter(c => c.main_code === main)
    : (S.status.categories || []);
  const seen = new Set();
  const opts = [el('option', { value: '' }, t('field.subCat') + '：' + t('common.all'))];
  for (const c of cats) {
    if (seen.has(c.sub_code)) continue;
    seen.add(c.sub_code);
    opts.push(el('option', { value: c.sub_code, selected: c.sub_code === selected }, `${c.sub_code} ${c.sub_name}`));
  }
  return opts;
}

async function loadItems() {
  const st = S.items;
  const data = await api('items.list', {
    q: st.q, location: st.location, main_category: st.main, sub_category: st.sub,
    show_scrapped: st.showScrapped ? 1 : '', pending_only: st.pendingOnly ? 1 : '',
    page: st.page, page_size: st.pageSize, sort: st.sort, order: st.order,
  }, { get: true });
  st.rows = data.rows;
  st.total = data.total;
  st.selected = new Set([...st.selected].filter(s => data.rows.some(r => r.serial_no === s)));
  $('#itCount').textContent = t('common.rows').replace('{n}', data.total);
  renderItemsTable();
}

function renderItemsTable() {
  const st = S.items;
  const box = $('#itemsTable');
  box.innerHTML = '';
  if (!st.rows.length) { box.appendChild(el('div', { class: 'card empty' }, t('items.noResult'))); return; }

  const headCells = [
    el('th', {}, el('input', { type: 'checkbox', id: 'selAll', onchange: e => {
      st.rows.forEach(r => e.target.checked ? st.selected.add(r.serial_no) : st.selected.delete(r.serial_no));
      renderItemsTable();
    } })),
    el('th', {}, t('field.serial')), el('th', {}, t('field.name')), el('th', {}, t('field.brand')),
    el('th', {}, t('field.location')), el('th', {}, t('field.newLocation')), el('th', {}, t('field.container')),
    el('th', { class: 'num' }, t('field.price')), el('th', { class: 'num' }, t('field.quantity')),
    el('th', { class: 'num' }, t('field.quarterly')), el('th', {}, t('field.unit')),
    el('th', {}, t('field.depreciation')), el('th', {}, t('field.mainCat') + '/' + t('field.subCat')),
    el('th', {}, t('field.hygiene')), el('th', {}, t('field.modified')), el('th', {}, t('common.actions')),
  ];
  const thead = el('thead', {}, el('tr', {}, headCells));

  const tbody = el('tbody', {});
  for (const r of st.rows) {
    const sel = st.selected.has(r.serial_no);
    const tr = el('tr', { class: sel ? 'selected' : '', ondblclick: () => editItemModal(r.serial_no) },
      el('td', {}, el('input', { type: 'checkbox', checked: sel, onchange: e => { e.target.checked ? st.selected.add(r.serial_no) : st.selected.delete(r.serial_no); tr.classList.toggle('selected', e.target.checked); } })),
      el('td', { class: 'mono' }, r.serial_no),
      el('td', {}, r.name),
      el('td', {}, r.brand || '—'),
      el('td', {}, r.location_code === 'LTO'
        ? el('span', { class: 'badge lto' }, 'LTO 借出及在途')
        : (r.location_code ? el('span', { class: 'badge loc' }, r.location_code + ' ' + locName(r.location_code)) : '—')),
      el('td', {}, r.new_location_code ? el('span', { class: 'badge warn', style: 'background:var(--warning-soft);color:var(--warning)' }, r.new_location_code) : '—'),
      el('td', { class: 'mono' }, r.container_serial || '—'),
      el('td', { class: 'num' }, r.purchase_price > 0 ? Number(r.purchase_price).toFixed(2) : '—'),
      el('td', { class: 'num' }, r.quantity),
      el('td', { class: 'num' }, r.quarterly_consumption),
      el('td', {}, r.unit || '—'),
      el('td', {}, depBadge(r.depreciation)),
      el('td', {}, el('span', { class: 'badge main' }, r.main_category), ' ', el('span', { class: 'badge sub' }, r.sub_category + ' ' + (r.sub_name || ''))),
      el('td', {}, r.hygiene_level ? el('span', { class: 'badge hy' }, r.hygiene_level + ' ' + (r.hygiene_name || '')) : '—'),
      el('td', { class: 'nowrap' }, r.last_modified),
      el('td', { class: 'nowrap' },
        btn(t('common.edit'), 'ghost sm', () => editItemModal(r.serial_no)),
        ' ',
        r.main_category !== 'B' ? btn(t('items.scrap'), 'ghost sm', () => scrapItem(r)) : null,
        ' ',
        btn('删', 'danger-ghost sm', () => deleteItem(r))
      )
    );
    tbody.appendChild(tr);
  }
  const table = el('div', { class: 'table-wrap' },
    el('table', { class: 'pmd-table' }, thead, tbody)
  );
  box.appendChild(table);
  box.appendChild(renderPager(st.total, st.page, st.pageSize, async p => { st.page = p; await loadItems(); }));
}

function renderPager(total, page, pageSize, onGo) {
  const pages = Math.max(1, Math.ceil(total / pageSize));
  return el('div', { class: 'pager' },
    btn('‹', 'ghost sm', () => page > 1 && onGo(page - 1)),
    el('span', {}, t('common.page').replace('{page}', page).replace('{total}', pages) + ' · ' + t('common.rows').replace('{n}', total)),
    btn('›', 'ghost sm', () => page < pages && onGo(page + 1))
  );
}

function exportItems(format) {
  const st = S.items;
  const p = {
    format,
    q: st.q, location: st.location, main_category: st.main, sub_category: st.sub,
    show_scrapped: st.showScrapped ? 1 : '', pending_only: st.pendingOnly ? 1 : '',
  };
  const a = el('a', { href: 'api.php?action=export.items&' + qs(p), style: 'display:none' });
  document.body.appendChild(a);
  a.click();
  a.remove();
}

/* ---------------- 物品编辑 / 批量修改 / 删除 ---------------- */
function itemFormFields(data, { serialReadonly = false, showSerial = true } = {}) {
  const d = data || {};
  const cats = S.status.categories || [];
  const mainOpts = [el('option', { value: '' }, t('common.select'))]
    .concat(Object.entries(S.status.main_categories).map(([k, v]) => el('option', { value: k, selected: k === d.main_category }, `${k} ${v}`)));
  const subOpts = cats
    .filter(c => !d.main_category || c.main_code === d.main_category)
    .map(c => el('option', { value: c.sub_code, selected: c.sub_code === d.sub_category && c.main_code === d.main_category }, `${c.sub_code} ${c.sub_name}`));
  const subSel = el('select', { id: 'fSub' }, [el('option', { value: '' }, t('common.select'))].concat(subOpts));
  const mainSel = el('select', { id: 'fMain', onchange: () => {
    const m = mainSel.value;
    subSel.innerHTML = '';
    subSel.appendChild(el('option', { value: '' }, t('common.select')));
    for (const c of cats.filter(c => c.main_code === m)) {
      subSel.appendChild(el('option', { value: c.sub_code }, `${c.sub_code} ${c.sub_name}`));
    }
  } }, mainOpts);

  const serialInput = el('input', { id: 'fSerial', type: 'text', placeholder: '如 NDZ121', value: d.serial_no || '', maxlength: '6', readonly: serialReadonly, style: 'text-transform:uppercase' });
  const f = el('div', { class: 'form-grid' },
    showSerial ? el('div', {}, el('label', { class: 'f' }, t('field.serial') + (serialReadonly ? '' : ' <span class="req">*</span>')), serialInput, el('div', { class: 'hint' }, t('add.serialManual') + ' / ' + t('add.serialAuto'))) : null,
    el('div', {}, el('label', { class: 'f' }, t('field.name') + ' <span class="req">*</span>'), el('input', { id: 'fName', type: 'text', value: d.name || '' })),
    el('div', {}, el('label', { class: 'f' }, t('field.brand')), el('input', { id: 'fBrand', type: 'text', value: d.brand || '' })),
    el('div', {}, el('label', { class: 'f' }, t('field.mainCat') + ' <span class="req">*</span>'), mainSel),
    el('div', {}, el('label', { class: 'f' }, t('field.subCat') + ' <span class="req">*</span>'), subSel),
    el('div', {}, el('label', { class: 'f' }, t('field.location')), locationSelect({ id: 'fLoc', withCustom: true }, d.location_code || '', true)),
    el('div', {}, el('label', { class: 'f' }, t('field.newLocation')), locationSelect({ id: 'fNewLoc', withCustom: true }, d.new_location_code || '', true)),
    el('div', {}, el('label', { class: 'f' }, t('field.container')), el('input', { id: 'fContainer', type: 'text', maxlength: '6', value: d.container_serial || '', style: 'text-transform:uppercase' })),
    el('div', {}, el('label', { class: 'f' }, t('field.price')), el('input', { id: 'fPrice', type: 'number', step: '0.01', min: '0', value: d.purchase_price ?? '' })),
    el('div', {}, el('label', { class: 'f' }, t('field.quantity')), el('input', { id: 'fQty', type: 'number', min: '0', step: 'any', value: d.quantity ?? 0 })),
    el('div', {}, el('label', { class: 'f' }, t('field.quarterly')), el('input', { id: 'fQuarterly', type: 'number', min: '0', step: 'any', value: d.quarterly_consumption ?? 0 })),
    el('div', {}, el('label', { class: 'f' }, t('field.unit')), el('input', { id: 'fUnit', type: 'text', value: d.unit || '' })),
    el('div', {}, el('label', { class: 'f' }, t('field.depreciation')), el('input', { id: 'fDep', type: 'number', min: '0', max: '100', step: '1', value: d.depreciation ?? 100 })),
    el('div', {}, el('label', { class: 'f' }, t('field.barcode')), el('input', { id: 'fBarcode', type: 'text', value: d.barcode || '' })),
    el('div', {}, el('label', { class: 'f' }, t('field.hygiene')), hygieneSelect({ id: 'fHyg' }, d.hygiene_level || '')),
    el('div', { class: 'wide' }, el('label', { class: 'f' }, t('field.notes')), el('textarea', { id: 'fNotes' }, d.notes || '')),
  );
  const collect = () => ({
    serial_no: serialInput.value.trim().toUpperCase(),
    name: $('#fName').value.trim(),
    brand: $('#fBrand').value.trim(),
    main_category: mainSel.value,
    sub_category: subSel.value,
    location_code: $('#fLoc').value,
    new_location_code: $('#fNewLoc').value,
    container_serial: $('#fContainer').value.trim().toUpperCase(),
    purchase_price: $('#fPrice').value,
    quantity: $('#fQty').value,
    quarterly_consumption: $('#fQuarterly').value,
    unit: $('#fUnit').value.trim(),
    depreciation: $('#fDep').value,
    barcode: $('#fBarcode').value.trim(),
    hygiene_level: $('#fHyg').value,
    notes: $('#fNotes').value,
  });
  return { f, collect };
}

async function editItemModal(serial) {
  const item = await api('items.get', { serial_no: serial }, { get: true });
  const { f, collect } = itemFormFields(item);
  const warn = el('div', { id: 'editWarn' });
  const m = modal({
    title: t('items.editItem') + ' · ' + item.serial_no, size: 'lg',
    body: el('div', {}, warn, f),
    foot: [
      btn(t('common.cancel'), 'ghost', () => m.close()),
      btn(t('common.save'), '', async () => {
        try {
          const data = collect();
          const r = await api('items.update', { serial_no: item.serial_no, ...data });
          warn.innerHTML = '';
          if (r.serial !== item.serial_no) {
            $('#editWarn') && (warn.className = 'alert warn');
            warn.textContent = t('items.serialChanged').replace('{old}', item.serial_no).replace('{new}', r.serial);
          }
          if (r.warnings && r.warnings.length) {
            warn.className = 'alert warn';
            warn.textContent = r.warnings.join('；');
          }
          toast(t('common.success'));
          m.close();
          await loadItems();
        } catch (e) {
          warn.className = 'alert err';
          warn.textContent = e.message;
        }
      }),
    ],
  });
}

function batchEditModal() {
  const st = S.items;
  if (!st.selected.size) { toast(t('items.selectFirst'), 'warn'); return; }
  const fieldSel = el('select', { id: 'beField' }, [
    ['location_code', t('field.location')], ['new_location_code', t('field.newLocation')],
    ['name', t('field.name')], ['brand', t('field.brand')],
    ['quantity', t('field.quantity')], ['quarterly_consumption', t('field.quarterly')],
    ['purchase_price', t('field.price')], ['depreciation', t('field.depreciation')],
    ['container_serial', t('field.container')], ['unit', t('field.unit')],
    ['barcode', t('field.barcode')], ['hygiene_level', t('field.hygiene')], ['notes', t('field.notes')],
    ['main_category', t('field.mainCat')], ['sub_category', t('field.subCat')],
  ].map(([v, label]) => el('option', { value: v }, label)));
  const valueWrap = el('div', {});
  const m = modal({
    title: t('items.batchEdit'), size: 'sm',
    body: el('div', {},
      el('div', {}, t('items.batchEditHint').replace('{n}', st.selected.size)),
      el('label', { class: 'f' }, t('items.chooseField')), fieldSel,
      valueWrap,
    ),
    foot: [
      btn(t('common.cancel'), 'ghost', () => m.close()),
      btn(t('common.apply'), '', async () => {
        try {
          const input = valueWrap.querySelector('input, select');
          const value = input ? input.value : '';
          if (value === '' && ['name', 'brand', 'notes', 'unit', 'barcode'].includes(fieldSel.value)) {
            toast(t('items.chooseValue'), 'warn'); return;
          }
          const r = await api('items.batch_update', { serials: [...st.selected], field: fieldSel.value, value });
          const msg = [];
          if (r.updated) msg.push(t('common.rows').replace('{n}', r.updated) + ' ' + t('common.success'));
          if (Object.keys(r.changed).length) msg.push(t('items.serialChanged').replace('{old}', Object.keys(r.changed)[0]).replace('{new}', Object.values(r.changed)[0]) + ' 等');
          if (r.errors.length) msg.push(r.errors.join('；'));
          m.close();
          toast(msg.join('；') || t('common.success'), r.errors.length ? 'warn' : 'ok');
          await loadItems();
        } catch (e) { toast(e.message, 'err'); }
      }),
    ],
  });
  const renderValue = () => {
    const field = fieldSel.value;
    valueWrap.innerHTML = '';
    const label = el('label', { class: 'f' }, t('items.chooseValue'));
    if (field === 'location_code' || field === 'new_location_code') {
      valueWrap.appendChild(label);
      valueWrap.appendChild(locationSelect({ id: 'beVal', withCustom: true }, '', true));
    } else if (field === 'main_category') {
      valueWrap.appendChild(label);
      valueWrap.appendChild(el('select', { id: 'beVal' },
        Object.entries(S.status.main_categories).map(([k, v]) => el('option', { value: k }, `${k} ${v}`))
      ));
    } else if (field === 'sub_category') {
      valueWrap.appendChild(label);
      valueWrap.appendChild(el('select', { id: 'beVal' }, [el('option', { value: '' }, t('common.select'))].concat(
        (S.status.categories || []).map(c => el('option', { value: c.sub_code }, `${c.sub_code} ${c.sub_name}`))
      )));
    } else if (field === 'depreciation') {
      valueWrap.appendChild(label);
      valueWrap.appendChild(el('input', { id: 'beVal', type: 'number', min: '0', max: '100', step: '1', value: '100' }));
    } else if (field === 'quantity' || field === 'quarterly_consumption') {
      valueWrap.appendChild(label);
      valueWrap.appendChild(el('input', { id: 'beVal', type: 'number', min: '0', step: '1', value: '0' }));
    } else if (field === 'purchase_price') {
      valueWrap.appendChild(label);
      valueWrap.appendChild(el('input', { id: 'beVal', type: 'number', min: '0', step: '0.01', value: '0' }));
    } else if (field === 'hygiene_level') {
      valueWrap.appendChild(label);
      valueWrap.appendChild(hygieneSelect({ id: 'beVal' }, ''));
    } else {
      valueWrap.appendChild(label);
      valueWrap.appendChild(el('input', { id: 'beVal', type: 'text', value: '' }));
    }
  };
  fieldSel.addEventListener('change', renderValue);
  renderValue();
}

async function deleteItem(r) {
  if (!await confirmDlg(t('items.deleteConfirm').replace('{serial}', r.serial_no).replace('{name}', r.name), t('common.delete'))) return;
  try {
    await api('items.delete', { serial_no: r.serial_no });
    toast(t('items.deleted'));
    await loadItems();
  } catch (e) { toast(e.message, 'err'); }
}

async function scrapItem(r) {
  if (!await confirmDlg(t('items.scrapConfirm').replace('{serial}', r.serial_no).replace('{name}', r.name), t('items.scrap'))) return;
  try {
    const res = await api('items.update', { serial_no: r.serial_no, main_category: 'B', sub_category: r.sub_category });
    toast(t('items.serialChanged').replace('{old}', r.serial_no).replace('{new}', res.serial));
    await loadItems();
  } catch (e) { toast(e.message, 'err'); }
}

/* ---------------- 导入物品 ---------------- */
function autoCatNotice(data) {
  let html = '';
  const list = (data && data.auto_categories) || [];
  if (list.length) {
    const names = list.map(c => `${c.main_code}-${c.sub_code}（${c.sub_name}）`).join('、');
    html += '<div class="alert ok mt">' + t('add.autoCatNotice').replace('{n}', list.length).replace('{list}', esc(names)) + '</div>';
  }
  const hy = (data && data.auto_hygiene) || [];
  if (hy.length) {
    const names = hy.map(c => `${c.code}（${c.name}）`).join('、');
    html += '<div class="alert ok mt">' + t('add.autoHygieneNotice').replace('{n}', hy.length).replace('{list}', esc(names)) + '</div>';
  }
  return html;
}

function importItemsModal() {
  const drop = el('div', { class: 'dropzone' }, t('add.importFile') + ' (.xlsx / .csv / .sql)');
  const fileInput = el('input', { type: 'file', accept: '.xlsx,.csv,.sql', style: 'display:none' });
  const dupRow = el('div', { class: 'row mt' },
    el('span', {}, t('add.importDup') + '：'),
    el('label', {}, el('input', { type: 'radio', name: 'dupMode', value: 'update', checked: true }), ' ' + t('add.importDupUpdate')),
    el('label', {}, el('input', { type: 'radio', name: 'dupMode', value: 'skip' }), ' ' + t('add.importDupSkip')),
  );
  const result = el('div', {});
  let token = null;
  const m = modal({
    title: t('add.modeImport'), size: 'lg',
    body: el('div', {},
      el('div', { class: 'row' },
        drop, fileInput,
        btn(t('add.downloadTemplate'), 'ghost sm', () => { const a = el('a', { href: 'api.php?action=export.template&type=items', style: 'display:none' }); document.body.appendChild(a); a.click(); a.remove(); }),
        btn(t('add.downloadTemplate') + ' (CSV)', 'ghost sm', () => { const a = el('a', { href: 'api.php?action=export.template&type=items&format=csv', style: 'display:none' }); document.body.appendChild(a); a.click(); a.remove(); }),
      ),
      dupRow,
      result,
    ),
    foot: [
      btn(t('common.cancel'), 'ghost', () => m.close()),
      btn(t('common.apply'), '', async () => {
        if (!token) { toast(t('val.noFile'), 'warn'); return; }
        const dupMode = $('input[name=dupMode]:checked').value;
        try {
          const r = await api('import.apply', { token, dup_mode: dupMode });
          toast(t('add.importResult').replace('{inserted}', r.inserted).replace('{updated}', r.updated).replace('{skipped}', r.skipped));
          if (r.errors.length) { result.className = 'alert err mt'; result.textContent = t('add.importErrors') + r.errors.slice(0, 8).join('；'); }
          m.close();
          S.items.page = 1;
          await loadItems();
        } catch (e) { toast(e.message, 'err'); }
      }),
    ],
  });
  const handleFile = async f => {
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    try {
      result.className = '';
      result.textContent = t('common.loading');
      const isSql = f.name.toLowerCase().endsWith('.sql');
      const data = await apiForm(isSql ? 'import.sql_preview' : 'import.preview', fd);
      token = data.token;
      dupRow.style.display = data.has_duplicates ? '' : 'none';
      let html = `<div class="alert ${data.invalid ? 'err' : 'ok'}">文件：${esc(data.file)}`
        + ` · 新增 ${data.inserted} · 重复 ${data.duplicates} · 无效 ${data.invalid}`
        + (data.ignored != null ? ` · 忽略非 INSERT 语句 ${data.ignored}` : '') + '</div>';
      const errRows = data.rows.filter(r => r.errors && r.errors.length);
      if (errRows.length) {
        html += '<div class="alert err">' + t('add.importErrors') + '<ul>' + errRows.slice(0, 10).map(r => `<li>第 ${r.row_no} 行 ${r.serial_no || ''}：${esc(r.errors.join('；'))}</li>`).join('') + '</ul></div>';
      }
      html += autoCatNotice(data);
      result.innerHTML = html;
    } catch (e) {
      result.className = 'alert err';
      result.textContent = e.message;
    }
  };
  drop.addEventListener('click', () => fileInput.click());
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('over'));
  drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); handleFile(e.dataTransfer.files[0]); });
  fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));
}

/* ============================================================
 * 视图：新增物品
 * ============================================================ */
function renderAdd() {
  const main = $('#main');
  main.innerHTML = '';
  main.appendChild(el('div', { class: 'card' },
    el('h2', {}, t('add.title')),
    el('div', { class: 'row' },
      btn(t('add.modeSingle'), S.add.mode === 'single' ? '' : 'ghost', () => { S.add.mode = 'single'; renderAdd(); }),
      btn(t('add.modeBatch'), S.add.mode === 'batch' ? '' : 'ghost', () => { S.add.mode = 'batch'; renderAdd(); }),
      btn(t('add.modeImport'), S.add.mode === 'import' ? '' : 'ghost', () => { S.add.mode = 'import'; renderAdd(); }),
    ),
    el('div', { class: 'mt' }, S.add.mode === 'single' ? addSingleForm() : (S.add.mode === 'batch' ? addBatchForm() : importItemsModalInline(main)))
  ));
}

function catSelects(dMain, dSub) {
  const cats = S.status.categories || [];
  const mainSel = el('select', { id: 'aMain' },
    [el('option', { value: '' }, t('common.select'))].concat(
      Object.entries(S.status.main_categories).map(([k, v]) => el('option', { value: k, selected: k === dMain }, `${k} ${v}`))
    ));
  const subSel = el('select', { id: 'aSub' },
    [el('option', { value: '' }, t('common.select'))].concat(
      cats.filter(c => !dMain || c.main_code === dMain)
        .map(c => el('option', { value: c.sub_code, selected: c.sub_code === dSub && c.main_code === dMain }, `${c.sub_code} ${c.sub_name}`))
    ));
  mainSel.addEventListener('change', () => {
    const m = mainSel.value;
    subSel.innerHTML = '';
    subSel.appendChild(el('option', { value: '' }, t('common.select')));
    for (const c of cats.filter(c => c.main_code === m)) subSel.appendChild(el('option', { value: c.sub_code }, `${c.sub_code} ${c.sub_name}`));
    suggestSerial();
  });
  subSel.addEventListener('change', suggestSerial);
  return { mainSel, subSel };
}

async function suggestSerial() {
  const main = $('#aMain').value, sub = $('#aSub').value;
  const box = $('#aSerial');
  if (!box) return;
  if (!main || !sub || S.add.serialMode !== 'auto') { box.value = ''; return; }
  try {
    const d = await api('serial.next', { main_category: main, sub_category: sub }, { get: true });
    box.value = d.serial_no;
  } catch (e) { box.value = ''; }
}

function addSingleForm() {
  const { mainSel, subSel } = catSelects('', '');
  const serialBox = el('div', {},
    el('label', { class: 'f' }, t('field.serial')),
    el('div', { class: 'row' },
      el('label', {}, el('input', { type: 'radio', name: 'serialMode', value: 'auto', checked: S.add.serialMode === 'auto', onchange: () => { S.add.serialMode = 'auto'; suggestSerial(); } }), ' ' + t('add.serialAuto')),
      el('label', {}, el('input', { type: 'radio', name: 'serialMode', value: 'manual', onchange: () => { S.add.serialMode = 'manual'; $('#aSerial').value = ''; $('#aSerial').readOnly = false; } }), ' ' + t('add.serialManual')),
    ),
    el('input', { id: 'aSerial', type: 'text', maxlength: '6', placeholder: t('add.serialAuto'), readonly: true, style: 'text-transform:uppercase' }),
    el('div', { class: 'hint' }, '如 NDZ121：首字母' + Object.entries(S.status.main_categories).map(([k, v]) => `${k}=${v}`).join('、') + '；后 2 位字母为子类别')
  );
  const f = el('div', { class: 'form-grid' },
    el('div', {}, el('label', { class: 'f' }, t('field.mainCat') + ' <span class="req">*</span>'), mainSel),
    el('div', {}, el('label', { class: 'f' }, t('field.subCat') + ' <span class="req">*</span>'), subSel),
    el('div', { class: 'wide' }, serialBox),
    el('div', {}, el('label', { class: 'f' }, t('field.name') + ' <span class="req">*</span>'), el('input', { id: 'aName', type: 'text' })),
    el('div', {}, el('label', { class: 'f' }, t('field.brand')), el('input', { id: 'aBrand', type: 'text' })),
    el('div', {}, el('label', { class: 'f' }, t('field.location')), locationSelect({ id: 'aLoc', withCustom: true }, '', true)),
    el('div', {}, el('label', { class: 'f' }, t('field.container')), el('input', { id: 'aContainer', type: 'text', maxlength: '6' })),
    el('div', {}, el('label', { class: 'f' }, t('field.price')), el('input', { id: 'aPrice', type: 'number', min: '0', step: '0.01' })),
    el('div', {}, el('label', { class: 'f' }, t('field.quantity')), el('input', { id: 'aQty', type: 'number', min: '0', step: 'any', value: '1' })),
    el('div', {}, el('label', { class: 'f' }, t('field.quarterly')), el('input', { id: 'aQuarterly', type: 'number', min: '0', step: 'any', value: '0' })),
    el('div', {}, el('label', { class: 'f' }, t('field.unit')), el('input', { id: 'aUnit', type: 'text' })),
    el('div', {}, el('label', { class: 'f' }, t('field.depreciation')), el('input', { id: 'aDep', type: 'number', min: '0', max: '100', value: '100' })),
    el('div', {}, el('label', { class: 'f' }, t('field.barcode')), el('input', { id: 'aBarcode', type: 'text' })),
    el('div', {}, el('label', { class: 'f' }, t('field.hygiene')), hygieneSelect({ id: 'aHyg' }, '')),
    el('div', { class: 'wide' }, el('label', { class: 'f' }, t('field.notes')), el('textarea', { id: 'aNotes' })),
  );
  const errBox = el('div', {});
  const wrap = el('div', {},
    f,
    errBox,
    el('div', { class: 'mt' }, btn(t('common.save'), 'lg', async () => {
      try {
        const data = {
          serial_no: S.add.serialMode === 'manual' ? $('#aSerial').value.trim().toUpperCase() : $('#aSerial').value.trim().toUpperCase(),
          name: $('#aName').value.trim(),
          brand: $('#aBrand').value.trim(),
          main_category: mainSel.value,
          sub_category: subSel.value,
          location_code: $('#aLoc').value,
          container_serial: $('#aContainer').value.trim().toUpperCase(),
          purchase_price: $('#aPrice').value,
          quantity: $('#aQty').value,
          quarterly_consumption: $('#aQuarterly').value,
          unit: $('#aUnit').value.trim(),
          depreciation: $('#aDep').value,
          barcode: $('#aBarcode').value.trim(),
          hygiene_level: $('#aHyg').value,
          notes: $('#aNotes').value.trim(),
        };
        const r = await api('items.create', data);
        errBox.innerHTML = '';
        if (r.warnings && r.warnings.length) { errBox.className = 'alert warn'; errBox.textContent = r.warnings.join('；'); }
        toast(t('common.success') + ' · ' + r.serial);
        renderAdd();
      } catch (e) {
        errBox.className = 'alert err mt';
        errBox.textContent = e.message;
      }
    }))
  );
  return wrap;
}

function addBatchForm() {
  const { mainSel, subSel } = catSelects('', '');
  const locSel = locationSelect({ id: 'bLoc', withCustom: true }, '', true);
  const hySel = hygieneSelect({ id: 'bHyg' }, '');
  const countSel = el('select', { id: 'bCount' }, [5, 10, 15, 20, 30, 50].map(n => el('option', { value: n }, n + ' 行')));
  const rowsBox = el('div', {}, el('div', { class: 'empty' }, '—'));
  const errBox = el('div', {});

  const renderRows = () => {
    const n = +countSel.value;
    rowsBox.innerHTML = '';
    rowsBox.appendChild(el('div', { class: 'hint mb' }, t('add.batchHint')));
    const table = el('table', { class: 'pmd-table', style: 'min-width:700px' });
    const thead = el('thead', {}, el('tr', {},
      ['#', t('field.name'), t('field.brand'), t('field.quantity'), t('field.unit'), t('field.price')].map(h => el('th', {}, h))
    ));
    const tbody = el('tbody', {});
    for (let i = 0; i < n; i++) {
      const tr = el('tr', {},
        el('td', {}, String(i + 1).padStart(2, '0')),
        el('td', {}, el('input', { class: 'bName', type: 'text', placeholder: t('field.name') })),
        el('td', {}, el('input', { class: 'bBrand', type: 'text' })),
        el('td', {}, el('input', { class: 'bQty', type: 'number', min: '0', step: '1', value: '1', style: 'width:80px' })),
        el('td', {}, el('input', { class: 'bUnit', type: 'text', style: 'width:70px' })),
        el('td', {}, el('input', { class: 'bPrice', type: 'number', min: '0', step: '0.01', style: 'width:100px' })),
      );
      tbody.appendChild(tr);
    }
    table.appendChild(thead);
    table.appendChild(tbody);
    rowsBox.appendChild(el('div', { class: 'table-wrap' }, table));
  };
  countSel.addEventListener('change', renderRows);
  renderRows();

  return el('div', {},
    el('div', { class: 'form-grid' },
      el('div', {}, el('label', { class: 'f' }, t('field.mainCat') + ' <span class="req">*</span>'), mainSel),
      el('div', {}, el('label', { class: 'f' }, t('field.subCat') + ' <span class="req">*</span>'), subSel),
      el('div', {}, el('label', { class: 'f' }, t('field.location') + ' <span class="req">*</span>'), locSel),
      el('div', {}, el('label', { class: 'f' }, t('field.hygiene')), hySel),
      el('div', {}, el('label', { class: 'f' }, t('add.batchRows')), countSel),
    ),
    rowsBox,
    errBox,
    el('div', { class: 'mt' }, btn(t('common.save'), 'lg', async () => {
      const main = mainSel.value, sub = subSel.value, loc = locSel.value;
      if (!main || !sub) { errBox.className = 'alert err'; errBox.textContent = t('val.categoryNeeded'); return; }
      const rows = [];
      $$('.bName').forEach((inp, i) => {
        const name = inp.value.trim();
        if (name) {
          rows.push({
            name, brand: $$('.bBrand')[i].value.trim(),
            quantity: $$('.bQty')[i].value || '1',
            unit: $$('.bUnit')[i].value.trim(),
            purchase_price: $$('.bPrice')[i].value || '',
          });
        }
      });
      if (!rows.length) { errBox.className = 'alert err'; errBox.textContent = t('val.nameRequired'); return; }
      errBox.className = '';
      errBox.textContent = t('common.loading');
      const created = [];
      const fails = [];
      for (const r of rows) {
        try {
          const res = await api('items.create', { ...r, main_category: main, sub_category: sub, location_code: loc, depreciation: 100, quantity: r.quantity, hygiene_level: hySel.value });
          created.push(res.serial);
        } catch (e) { fails.push(r.name + ': ' + e.message); }
      }
      if (fails.length) { errBox.className = 'alert err'; errBox.textContent = fails.join('；'); }
      else { errBox.className = 'alert ok'; errBox.textContent = t('common.rows').replace('{n}', created.length) + ' ' + t('common.success') + '：' + created.join(', '); }
    }))
  );
}

function importItemsModalInline(main) {
  const drop = el('div', { class: 'dropzone' }, t('add.importFile') + ' (.xlsx / .csv / .sql)');
  const fileInput = el('input', { type: 'file', accept: '.xlsx,.csv,.sql', style: 'display:none' });
  const dupRow = el('div', { class: 'row mt' },
    el('span', {}, t('add.importDup') + '：'),
    el('label', {}, el('input', { type: 'radio', name: 'dupMode2', value: 'update', checked: true }), ' ' + t('add.importDupUpdate')),
    el('label', {}, el('input', { type: 'radio', name: 'dupMode2', value: 'skip' }), ' ' + t('add.importDupSkip')),
  );
  const result = el('div', {});
  const applyBtn = btn(t('common.apply'), '', async () => {
    if (!token) { toast(t('val.noFile'), 'warn'); return; }
    const dupMode = $('input[name=dupMode2]:checked').value;
    try {
      const r = await api('import.apply', { token, dup_mode: dupMode });
      toast(t('add.importResult').replace('{inserted}', r.inserted).replace('{updated}', r.updated).replace('{skipped}', r.skipped));
      if (r.errors.length) { result.className = 'alert err mt'; result.textContent = t('add.importErrors') + r.errors.slice(0, 8).join('；'); }
    } catch (e) { toast(e.message, 'err'); }
  });
  let token = null;
  const handleFile = async f => {
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    try {
      result.className = '';
      result.textContent = t('common.loading');
      const isSql = f.name.toLowerCase().endsWith('.sql');
      const data = await apiForm(isSql ? 'import.sql_preview' : 'import.preview', fd);
      token = data.token;
      dupRow.style.display = data.has_duplicates ? '' : 'none';
      result.className = 'alert ' + (data.invalid ? 'err' : 'ok');
      result.textContent = `文件：${data.file} · 新增 ${data.inserted} · 重复 ${data.duplicates} · 无效 ${data.invalid}`;
      const errRows = data.rows.filter(r => r.errors && r.errors.length);
      if (errRows.length) {
        result.innerHTML += '<div class="mt">' + errRows.slice(0, 8).map(r => `第 ${r.row_no} 行 ${r.serial_no || ''}：${esc(r.errors.join('；'))}`).join('<br>') + '</div>';
      }
      result.innerHTML += autoCatNotice(data);
    } catch (e) {
      result.className = 'alert err';
      result.textContent = e.message;
    }
  };
  drop.addEventListener('click', () => fileInput.click());
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); handleFile(e.dataTransfer.files[0]); });
  fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));
  return el('div', {},
    el('div', { class: 'row' }, drop, fileInput,
      btn(t('add.downloadTemplate'), 'ghost sm', () => { const a = el('a', { href: 'api.php?action=export.template&type=items', style: 'display:none' }); document.body.appendChild(a); a.click(); a.remove(); }),
      btn(t('add.downloadTemplate') + ' (CSV)', 'ghost sm', () => { const a = el('a', { href: 'api.php?action=export.template&type=items&format=csv', style: 'display:none' }); document.body.appendChild(a); a.click(); a.remove(); }),
    ),
    dupRow,
    result,
    el('div', { class: 'mt' }, applyBtn)
  );
}

/* ============================================================
 * 视图：物资交换作业
 * ============================================================ */
async function renderExchange() {
  const main = $('#main');
  const st = S.exchange;
  main.appendChild(el('div', { class: 'card' },
    el('h2', {}, t('exchange.title')),
    el('div', { class: 'steps' },
      el('div', { class: 'step active' }, el('span', { class: 'dot' }, '1'), t('exchange.step1')),
      el('div', { class: 'step' }, el('span', { class: 'dot' }, '2'), t('exchange.step2')),
      el('div', { class: 'step' }, el('span', { class: 'dot' }, '3'), t('exchange.step3')),
      el('div', { class: 'step' }, el('span', { class: 'dot' }, '4'), t('exchange.step4')),
    )
  ));

  // 步骤1-2：查询并设置新位置
  const searchBox = el('div', { class: 'card' },
    el('h2', {}, t('exchange.step1') + ' / ' + t('exchange.step2')),
    el('div', { class: 'desc' }, t('exchange.step1Hint')),
    el('div', { class: 'row' },
      el('input', { id: 'exQ', class: 'flex1', type: 'text', placeholder: t('items.keyword') }),
      locationSelect({ id: 'exLoc', style: 'width:auto' }, ''),
      btn(t('common.search'), 'sm', async () => {
        const q = $('#exQ').value.trim(), loc = $('#exLoc').value;
        const d = await api('items.list', { q, location: loc, page_size: 200 }, { get: true });
        st.searchRows = d.rows;
        st.searchSel = new Set();
        renderExchangeSearch();
      }),
      btn(t('common.reset'), 'ghost sm', () => { $('#exQ').value = ''; $('#exLoc').value = ''; st.searchRows = []; renderExchangeSearch(); }),
    ),
    el('div', { class: 'mt', id: 'exSearchTable' }),
    el('div', { class: 'row mt' },
      el('label', { class: 'f', style: 'margin:0' }, t('field.newLocation') + '：'),
      locationSelect({ id: 'exNewLoc', withCustom: true }, '', true),
      btn(t('common.apply'), '', async () => {
        const newLoc = $('#exNewLoc').value;
        if (!st.searchSel.size) { toast(t('items.selectFirst'), 'warn'); return; }
        if (!newLoc) { toast(t('exchange.newLocInvalid', { loc: '' }), 'warn'); return; }
        try {
          const r = await api('exchange.prepare', { serials: [...st.searchSel], new_location: newLoc });
          toast(t('common.rows').replace('{n}', r.applied) + ' ' + t('common.success') + (r.errors.length ? '；' + r.errors.join('；') : ''));
          await loadExchangePending();
        } catch (e) { toast(e.message, 'err'); }
      }),
    )
  );
  main.appendChild(searchBox);

  // 步骤3-4：待交换物品 → 作业单 → 应用
  const pendingCard = el('div', { class: 'card' },
    el('h2', {}, t('exchange.step3') + ' / ' + t('exchange.step4')),
    el('div', { class: 'row' },
      el('div', { class: 'flex1' }, el('div', { class: 'desc' }, t('exchange.pending'))),
      btn(t('exchange.genWorksheet'), 'sm', async () => {
        try {
          const d = await api('exchange.generate');
          st.token = d.token;
          st.draft = d.rows;
          toast(t('exchange.worksheetDone'));
          const a = el('a', { href: 'api.php?action=exchange.worksheet&token=' + encodeURIComponent(d.token), style: 'display:none' });
          document.body.appendChild(a); a.click(); a.remove();
          renderExchangePending();
        } catch (e) { toast(e.message, 'err'); }
      }),
      btn(t('common.apply'), 'success sm', async () => {
        if (!st.token) { toast('请先生成作业单', 'warn'); return; }
        if (!await confirmDlg(t('exchange.applyConfirm'))) return;
        try {
          const r = await api('exchange.apply', { token: st.token });
          st.token = null; st.draft = null;
          toast(t('exchange.applied').replace('{n}', r.applied));
          await loadExchangePending();
        } catch (e) { toast(e.message, 'err'); }
      }),
    ),
    el('div', { class: 'mt', id: 'exPending' }),
  );
  main.appendChild(pendingCard);

  // 上传作业单直达应用
  main.appendChild(exchangeUploadCard());

  await loadExchangePending();
}

function renderExchangeSearch() {
  const st = S.exchange;
  const box = $('#exSearchTable');
  box.innerHTML = '';
  if (!st.searchRows.length) { box.appendChild(el('div', { class: 'empty' }, t('items.noResult'))); return; }
  const tbody = el('tbody', {});
  for (const r of st.searchRows) {
    const sel = st.searchSel.has(r.serial_no);
    const tr = el('tr', {},
      el('td', {}, el('input', { type: 'checkbox', checked: sel, onchange: e => { e.target.checked ? st.searchSel.add(r.serial_no) : st.searchSel.delete(r.serial_no); } })),
      el('td', { class: 'mono' }, r.serial_no),
      el('td', {}, r.name),
      el('td', {}, r.location_code || '—'),
      el('td', {}, r.new_location_code || '—'),
    );
    tbody.appendChild(tr);
  }
  box.appendChild(el('div', { class: 'table-wrap' },
    el('table', { class: 'pmd-table' },
      el('thead', {}, el('tr', {}, [t('common.select'), t('field.serial'), t('field.name'), t('field.location'), t('field.newLocation')].map(h => el('th', {}, h)))),
      tbody)
  ));
}

async function loadExchangePending() {
  const st = S.exchange;
  st.pending = await api('exchange.pending', {}, { get: true });
  renderExchangePending();
}

function renderExchangePending() {
  const st = S.exchange;
  const box = $('#exPending');
  if (!box) return;
  box.innerHTML = '';
  if (!st.pending.length) { box.appendChild(el('div', { class: 'empty' }, t('exchange.pendingEmpty'))); return; }
  const tbody = el('tbody', {});
  for (const r of st.pending) {
    tbody.appendChild(el('tr', {},
      el('td', { class: 'mono' }, r.serial_no),
      el('td', {}, r.name),
      el('td', {}, r.location_code || '—'),
      el('td', {}, el('span', { class: 'badge', style: 'background:var(--warning-soft);color:var(--warning)' }, r.new_location_code)),
    ));
  }
  box.appendChild(el('div', { class: 'table-wrap' },
    el('table', { class: 'pmd-table' },
      el('thead', {}, el('tr', {}, [t('field.serial'), t('field.name'), t('field.location'), t('field.newLocation')].map(h => el('th', {}, h)))),
      tbody)
  ));
}

function exchangeUploadCard() {
  const st = S.exchange;
  const drop = el('div', { class: 'dropzone' }, t('exchange.upload') + ' (.xlsx / .csv)');
  const fileInput = el('input', { type: 'file', accept: '.xlsx,.csv', style: 'display:none' });
  const result = el('div', {});
  const applyBtn = btn(t('common.apply'), '', async () => {
    if (!st.token) { toast(t('val.noFile'), 'warn'); return; }
    if (!await confirmDlg(t('exchange.applyConfirm'))) return;
    try {
      const r = await api('import.exchange_apply', { token: st.token });
      st.token = null;
      toast(t('exchange.applied').replace('{n}', r.applied));
      result.innerHTML = '';
      await loadExchangePending();
    } catch (e) { toast(e.message, 'err'); }
  });
  const handleFile = async f => {
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    try {
      result.className = '';
      result.textContent = t('common.loading');
      const data = await apiForm('import.exchange_preview', fd);
      st.token = data.token;
      let html = `<div class="alert ${data.rows.some(r => r.errors.length) ? 'err' : 'ok'}">${esc(data.file)} · 有效 ${data.valid} 行</div>`;
      const bad = data.rows.filter(r => r.errors.length);
      if (bad.length) html += '<div class="alert err mt">' + bad.slice(0, 8).map(r => `第 ${r.row_no} 行 ${r.serial_no}：${esc(r.errors.join('；'))}`).join('<br>') + '</div>';
      result.innerHTML = html;
    } catch (e) {
      result.className = 'alert err';
      result.textContent = e.message;
    }
  };
  drop.addEventListener('click', () => fileInput.click());
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); handleFile(e.dataTransfer.files[0]); });
  fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));
  return el('div', { class: 'card' },
    el('h2', {}, t('exchange.upload')),
    el('div', { class: 'desc' }, t('exchange.uploadHint')),
    el('div', { class: 'row' }, drop, fileInput,
      btn(t('add.downloadTemplate'), 'ghost sm', () => { const a = el('a', { href: 'api.php?action=export.template&type=exchange', style: 'display:none' }); document.body.appendChild(a); a.click(); a.remove(); })
    ),
    result,
    el('div', { class: 'mt' }, applyBtn)
  );
}

/* ============================================================
 * 视图：物资采购作业
 * ============================================================ */
async function renderPurchase() {
  const main = $('#main');
  const st = S.purchase;
  main.appendChild(el('div', { class: 'card' },
    el('h2', {}, t('purchase.title')),
    el('div', { class: 'steps' },
      el('div', { class: 'step active' }, el('span', { class: 'dot' }, '1'), t('purchase.step1')),
      el('div', { class: 'step' }, el('span', { class: 'dot' }, '2'), t('purchase.step2')),
      el('div', { class: 'step' }, el('span', { class: 'dot' }, '3'), t('purchase.step3')),
      el('div', { class: 'step' }, el('span', { class: 'dot' }, '4'), t('purchase.step4')),
      el('div', { class: 'step' }, el('span', { class: 'dot' }, '5'), t('purchase.step5')),
    )
  ));

  // 步骤1-2：扫描低库存
  const scanCard = el('div', { class: 'card' },
    el('h2', {}, t('purchase.step1') + ' / ' + t('purchase.step2')),
    el('div', { class: 'row' },
      el('label', { class: 'f', style: 'margin:0' }, t('purchase.step1') + '：'),
      el('select', { id: 'puLoc', style: 'width:auto' },
        [el('option', { value: '' }, t('purchase.scanAll'))].concat(
          (S.status.locations || []).filter(l => l.code !== 'LTO').map(l => el('option', { value: l.code }, `${l.code} ${l.name}`))
        )),
      btn(t('common.search'), 'sm', async () => {
        st.scanLoc = $('#puLoc').value;
        st.candidates = await api('purchase.scan', { location: st.scanLoc });
        renderCandidates();
      }),
    ),
    el('div', { class: 'mt', id: 'puCandidates' }),
  );
  main.appendChild(scanCard);

  // 步骤3：补充检索
  main.appendChild(el('div', { class: 'card' },
    el('h2', {}, t('purchase.step3')),
    el('div', { class: 'desc' }, t('purchase.addMoreHint')),
    el('div', { class: 'row' },
      el('input', { id: 'puQ', class: 'flex1', type: 'text', placeholder: t('items.keyword') }),
      btn(t('common.search'), 'sm', async () => {
        const d = await api('items.list', { q: $('#puQ').value.trim(), page_size: 100 }, { get: true });
        st.searchRows = d.rows.filter(r => !st.cart.some(c => c.serial_no === r.serial_no));
        renderSearchRows();
      }),
    ),
    el('div', { class: 'mt', id: 'puSearchRows' }),
  ));

  // 采购列表（购物车）
  const cartCard = el('div', { class: 'card' },
    el('h2', {}, t('purchase.addedList')),
    btn(t('purchase.addNewItem'), 'ghost sm', () => { st.cart.push(newCartRow()); renderCart(); }),
    el('div', { class: 'mt', id: 'puCart' }),
    el('div', { class: 'row mt' },
      btn(t('purchase.genList'), '', async () => {
        if (!st.cart.length) { toast(t('purchase.noItems'), 'warn'); return; }
        try {
          const d = await api('purchase.generate', { rows: st.cart.map(c => ({ ...c })) });
          if (!d.ok) {
            const bad = d.rows.filter(r => r.errors && r.errors.length);
            toast(t('purchase.purchaseQtyInvalid').replace('{serial}', bad[0] ? bad[0].serial_no : ''), 'err');
            return;
          }
          st.token = d.token;
          st.draft = d.rows;
          toast(t('purchase.listDone'));
          const a = el('a', { href: 'api.php?action=purchase.worksheet&token=' + encodeURIComponent(d.token), style: 'display:none' });
          document.body.appendChild(a); a.click(); a.remove();
        } catch (e) { toast(e.message, 'err'); }
      }),
      btn(t('common.apply'), 'success', async () => {
        if (!st.token) { toast(t('purchase.noItems'), 'warn'); return; }
        if (!await confirmDlg(t('purchase.applyConfirm'))) return;
        try {
          const r = await api('purchase.apply', { token: st.token });
          st.token = null; st.draft = null; st.cart = [];
          toast(t('purchase.applied').replace('{inserted}', r.inserted).replace('{updated}', r.updated));
          renderCart();
        } catch (e) { toast(e.message, 'err'); }
      }),
    )
  );
  main.appendChild(cartCard);

  // 上传欲购清单
  main.appendChild(purchaseUploadCard());

  renderCandidates();
  renderCart();
}

function newCartRow(item) {
  return {
    serial_no: item ? item.serial_no : '',
    name: item ? item.name : '',
    brand: item ? item.brand : '',
    main_category: item ? item.main_category : '',
    sub_category: item ? item.sub_category : '',
    location_code: item ? item.location_code : '',
    unit: item ? item.unit : '',
    quantity: item ? item.quantity : 0,
    purchase_qty: 1,
    purchase_price: item ? item.purchase_price : '',
    hygiene_level: item ? (item.hygiene_level || '') : '',
    notes: '',
    is_new: !item,
  };
}

function renderCandidates() {
  const st = S.purchase;
  const box = $('#puCandidates');
  box.innerHTML = '';
  if (!st.candidates.length) { box.appendChild(el('div', { class: 'empty' }, t('purchase.candidatesEmpty'))); return; }
  const tbody = el('tbody', {});
  for (const c of st.candidates) {
    const inCart = st.cart.some(x => x.serial_no === c.serial_no);
    const tr = el('tr', {},
      el('td', {}, el('input', { type: 'checkbox', checked: inCart, disabled: inCart, onchange: e => {
        if (e.target.checked) { st.cart.push(newCartRow(c)); renderCandidates(); renderCart(); }
      } })),
      el('td', { class: 'mono' }, c.serial_no),
      el('td', {}, c.name),
      el('td', {}, c.brand || '—'),
      el('td', {}, c.location_code || '—'),
      el('td', { class: 'num' }, c.quantity),
      el('td', {}, c.unit || '—'),
      el('td', {}, depBadge(c.depreciation)),
    );
    tbody.appendChild(tr);
  }
  box.appendChild(el('div', { class: 'table-wrap' },
    el('table', { class: 'pmd-table' },
      el('thead', {}, el('tr', {}, [t('common.select'), t('field.serial'), t('field.name'), t('field.brand'), t('field.location'), t('field.quantity'), t('field.unit'), t('field.depreciation')].map(h => el('th', {}, h)))),
      tbody)
  ));
}

function renderSearchRows() {
  const st = S.purchase;
  const box = $('#puSearchRows');
  box.innerHTML = '';
  if (!st.searchRows.length) { box.appendChild(el('div', { class: 'empty' }, t('items.noResult'))); return; }
  const tbody = el('tbody', {});
  for (const r of st.searchRows) {
    const tr = el('tr', {},
      el('td', {}, el('input', { type: 'checkbox', onchange: e => {
        if (e.target.checked) { st.cart.push(newCartRow(r)); st.searchRows = st.searchRows.filter(x => x.serial_no !== r.serial_no); renderSearchRows(); renderCart(); }
      } })),
      el('td', { class: 'mono' }, r.serial_no),
      el('td', {}, r.name),
      el('td', {}, r.location_code || '—'),
      el('td', { class: 'num' }, r.quantity),
      el('td', {}, depBadge(r.depreciation)),
    );
    tbody.appendChild(tr);
  }
  box.appendChild(el('div', { class: 'table-wrap' },
    el('table', { class: 'pmd-table' },
      el('thead', {}, el('tr', {}, [t('common.select'), t('field.serial'), t('field.name'), t('field.location'), t('field.quantity'), t('field.depreciation')].map(h => el('th', {}, h)))),
      tbody)
  ));
}

function renderCart() {
  const st = S.purchase;
  const box = $('#puCart');
  if (!box) return;
  box.innerHTML = '';
  if (!st.cart.length) { box.appendChild(el('div', { class: 'empty' }, t('purchase.noItems'))); return; }
  const tbody = el('tbody', {});
  st.cart.forEach((c, idx) => {
    const tr = el('tr', {},
      el('td', {}, c.is_new ? el('span', { class: 'badge new' }, t('purchase.newItem')) : el('span', { class: 'mono' }, c.serial_no)),
      c.is_new
        ? el('td', { colspan: '2' }, el('div', { class: 'row' },
            el('input', { class: 'cName', type: 'text', placeholder: t('field.name'), value: c.name, style: 'width:160px' }),
            el('select', { class: 'cMain', style: 'width:110px' }, [el('option', { value: '' }, t('field.mainCat'))].concat(Object.entries(S.status.main_categories).map(([k, v]) => el('option', { value: k, selected: k === c.main_category }, `${k} ${v}`)))),
            el('select', { class: 'cSub', style: 'width:130px' }, [el('option', { value: '' }, t('field.subCat'))].concat((S.status.categories || []).filter(x => !c.main_category || x.main_code === c.main_category).map(x => el('option', { value: x.sub_code, selected: x.sub_code === c.sub_category }, `${x.sub_code} ${x.sub_name}`)))),
            el('select', { class: 'cHyg', style: 'width:150px' }, [el('option', { value: '' }, t('field.hygiene'))].concat((S.status.hygiene_levels || []).map(x => el('option', { value: x.code, selected: x.code === c.hygiene_level }, `${x.code} ${x.name}`)))),
          ))
        : el('td', { colspan: '2' }, c.name),
      el('td', {}, c.brand || '—'),
      el('td', {}, el('input', { class: 'cQty', type: 'number', min: '1', step: '1', value: c.purchase_qty, style: 'width:70px', onchange: e => { st.cart[idx].purchase_qty = +e.target.value || 1; } })),
      el('td', {}, el('input', { class: 'cPrice', type: 'number', min: '0', step: '0.01', value: c.purchase_price ?? '', style: 'width:90px', onchange: e => { st.cart[idx].purchase_price = e.target.value; } })),
      el('td', {}, c.unit || '—'),
      el('td', {}, btn('✕', 'danger-ghost sm', () => { st.cart.splice(idx, 1); renderCart(); })),
    );
    tbody.appendChild(tr);
  });
  // 新增行输入变化时同步状态
  box.appendChild(el('div', { class: 'table-wrap' },
    el('table', { class: 'pmd-table' },
      el('thead', {}, el('tr', {}, [t('field.serial'), t('field.name') + '/' + t('field.subCat'), t('field.brand'), t('purchase.qty'), t('field.price'), t('field.unit'), ''].map(h => el('th', {}, h)))),
      tbody)
  ));
  box.onchange = e => {
    const tr = e.target.closest('tr');
    if (!tr) return;
    const idx = Array.from(tr.parentNode.children).indexOf(tr);
    if (idx < 0 || !st.cart[idx]) return;
    if (e.target.classList.contains('cName')) st.cart[idx].name = e.target.value;
    if (e.target.classList.contains('cMain')) { st.cart[idx].main_category = e.target.value; st.cart[idx].sub_category = ''; }
    if (e.target.classList.contains('cSub')) st.cart[idx].sub_category = e.target.value;
    if (e.target.classList.contains('cHyg')) st.cart[idx].hygiene_level = e.target.value;
  };
}

function purchaseUploadCard() {
  const st = S.purchase;
  const drop = el('div', { class: 'dropzone' }, t('purchase.upload') + ' (.xlsx / .csv)');
  const fileInput = el('input', { type: 'file', accept: '.xlsx,.csv', style: 'display:none' });
  const result = el('div', {});
  const applyBtn = btn(t('common.apply'), '', async () => {
    if (!st.token) { toast(t('val.noFile'), 'warn'); return; }
    if (!await confirmDlg(t('purchase.applyConfirm'))) return;
    try {
      const r = await api('import.purchase_apply', { token: st.token });
      st.token = null;
      toast(t('purchase.applied').replace('{inserted}', r.inserted).replace('{updated}', r.updated));
      result.innerHTML = '';
    } catch (e) { toast(e.message, 'err'); }
  });
  const handleFile = async f => {
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    try {
      result.className = '';
      result.textContent = t('common.loading');
      const data = await apiForm('import.purchase_preview', fd);
      st.token = data.token;
      let html = `<div class="alert ${data.rows.some(r => r.errors.length) ? 'err' : 'ok'}">${esc(data.file)} · 有效 ${data.valid} 行</div>`;
      const bad = data.rows.filter(r => r.errors.length);
      if (bad.length) html += '<div class="alert err mt">' + bad.slice(0, 8).map(r => `第 ${r.row_no} 行 ${r.serial_no || '(新增)'}：${esc(r.errors.join('；'))}`).join('<br>') + '</div>';
      html += autoCatNotice(data);
      result.innerHTML = html;
    } catch (e) {
      result.className = 'alert err';
      result.textContent = e.message;
    }
  };
  drop.addEventListener('click', () => fileInput.click());
  drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
  drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); handleFile(e.dataTransfer.files[0]); });
  fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));
  return el('div', { class: 'card' },
    el('h2', {}, t('purchase.upload')),
    el('div', { class: 'desc' }, t('purchase.uploadHint')),
    el('div', { class: 'row' }, drop, fileInput,
      btn(t('add.downloadTemplate'), 'ghost sm', () => { const a = el('a', { href: 'api.php?action=export.template&type=purchase', style: 'display:none' }); document.body.appendChild(a); a.click(); a.remove(); })
    ),
    result,
    el('div', { class: 'mt' }, applyBtn)
  );
}

/* ============================================================
 * 视图：物资借还作业
 * ============================================================ */
async function renderBorrow() {
  const main = $('#main');
  const st = S.borrow;
  main.innerHTML = '';

  // 章节一：借出
  const outCard = el('div', { class: 'card' },
    el('h2', {}, t('borrow.title') + ' · ' + t('borrow.tabOut')),
    el('div', { class: 'row' },
      el('input', { id: 'boQ', class: 'flex1', type: 'text', placeholder: t('items.keyword') }),
      btn(t('common.search'), 'sm', async () => {
        const d = await api('items.list', { q: $('#boQ').value.trim(), page_size: 100 }, { get: true });
        st.rows = d.rows.filter(r => r.location_code !== 'LTO');
        renderBorrowOut();
      }),
    ),
    el('div', { class: 'mt', id: 'boRows' }),
    el('div', { class: 'row mt' },
      el('label', { class: 'f', style: 'margin:0' }, t('borrow.borrower') + '：'),
      el('input', { id: 'boBorrower', type: 'text', placeholder: t('borrow.borrowerPh'), style: 'width:200px' }),
      btn(t('common.confirm'), '', async () => {
        const borrower = $('#boBorrower').value.trim();
        if (!borrower) { toast(t('borrow.noBorrower'), 'warn'); return; }
        if (!st.rows.some(r => r._sel)) { toast(t('items.selectFirst'), 'warn'); return; }
        const serials = st.rows.filter(r => r._sel).map(r => r.serial_no);
        if (!await confirmDlg(t('borrow.outConfirm').replace('{n}', serials.length).replace('{borrower}', borrower))) return;
        try {
          const res = await api('borrow.out', { serials, borrower });
          toast(t('borrow.outDone') + '（' + res.applied + '）' + (res.errors.length ? '；' + res.errors.join('；') : ''));
          st.rows = [];
          renderBorrowOut();
          await loadBorrowIn();
        } catch (e) { toast(e.message, 'err'); }
      }),
    )
  );
  main.appendChild(outCard);
  renderBorrowOut();

  // 章节二：归还
  const inCard = el('div', { class: 'card' },
    el('h2', {}, t('borrow.returnList')),
    el('div', { id: 'biRows' })
  );
  main.appendChild(inCard);
  await loadBorrowIn();

  // 章节三：借还记录
  const histCard = el('div', { class: 'card' },
    el('h2', {}, t('borrow.tabHistory')),
    el('div', { id: 'bhRows' })
  );
  main.appendChild(histCard);
  st.history = await api('borrow.history', {}, { get: true });
  renderBorrowHistory();
}

async function loadBorrowIn() {
  S.borrow.rows = await api('borrow.list', {}, { get: true });
  renderBorrowIn();
}

function renderBorrowOut() {
  const st = S.borrow;
  const box = $('#boRows');
  box.innerHTML = '';
  if (!st.rows.length) { box.appendChild(el('div', { class: 'empty' }, t('items.noResult'))); return; }
  const tbody = el('tbody', {});
  for (const r of st.rows) {
    const tr = el('tr', {},
      el('td', {}, el('input', { type: 'checkbox', onchange: e => { r._sel = e.target.checked; } })),
      el('td', { class: 'mono' }, r.serial_no),
      el('td', {}, r.name),
      el('td', {}, r.location_code || '—'),
      el('td', { class: 'num' }, r.quantity),
      el('td', {}, depBadge(r.depreciation)),
    );
    tbody.appendChild(tr);
  }
  box.appendChild(el('div', { class: 'table-wrap' },
    el('table', { class: 'pmd-table' },
      el('thead', {}, el('tr', {}, [t('common.select'), t('field.serial'), t('field.name'), t('field.location'), t('field.quantity'), t('field.depreciation')].map(h => el('th', {}, h)))),
      tbody)
  ));
}

function renderBorrowIn() {
  const st = S.borrow;
  const box = $('#biRows');
  box.innerHTML = '';
  if (!st.rows.length) { box.appendChild(el('div', { class: 'empty' }, t('borrow.returnListEmpty'))); return; }
  const tbody = el('tbody', {});
  for (const r of st.rows) {
    tbody.appendChild(el('tr', {},
      el('td', { class: 'mono' }, r.serial_no),
      el('td', {}, r.name),
      el('td', {}, r.borrower || '—'),
      el('td', {}, r.borrowed_at || '—'),
      el('td', { class: 'num' }, r.quantity),
      el('td', {}, depBadge(r.depreciation)),
      el('td', {}, btn(t('borrow.returnForm'), 'sm', () => borrowReturnModal(r))),
    ));
  }
  box.appendChild(el('div', { class: 'table-wrap' },
    el('table', { class: 'pmd-table' },
      el('thead', {}, el('tr', {}, [t('field.serial'), t('field.name'), t('field.borrower'), t('borrow.borrowedAt'), t('field.quantity'), t('field.depreciation'), t('common.actions')].map(h => el('th', {}, h)))),
      tbody)
  ));
}

function borrowReturnModal(r) {
  const qty = el('input', { type: 'number', min: '0', step: '1', value: r.quantity });
  const dep = el('input', { type: 'number', min: '0', max: '100', step: '1', value: r.depreciation });
  const loc = locationSelect({ withCustom: true }, r.location_code === 'LTO' ? '' : r.location_code, true);
  const note = el('input', { type: 'text', placeholder: t('field.notes') });
  const errBox = el('div', {});
  const m = modal({
    title: t('borrow.returnForm') + ' · ' + r.serial_no, size: 'sm',
    body: el('div', {},
      el('div', { class: 'desc' }, t('borrow.returnHint')),
      el('label', { class: 'f' }, t('borrow.returnQty')), qty,
      el('label', { class: 'f' }, t('borrow.returnDep')), dep,
      el('label', { class: 'f' }, t('borrow.returnLoc')), loc,
      el('label', { class: 'f' }, t('field.notes')), note,
      errBox,
    ),
    foot: [
      btn(t('common.cancel'), 'ghost', () => m.close()),
      btn(t('common.confirm'), '', async () => {
        try {
          await api('borrow.in', { serial_no: r.serial_no, quantity: qty.value, depreciation: dep.value, location: loc.value, note: note.value });
          toast(t('borrow.inDone'));
          m.close();
          renderBorrow();
        } catch (e) {
          errBox.className = 'alert err mt';
          errBox.textContent = e.message;
        }
      }),
    ],
  });
}

function renderBorrowHistory() {
  const st = S.borrow;
  const box = $('#bhRows');
  box.innerHTML = '';
  if (!st.history.length) { box.appendChild(el('div', { class: 'empty' }, t('common.empty'))); return; }
  const tbody = el('tbody', {});
  for (const h of st.history) {
    tbody.appendChild(el('tr', {},
      el('td', { class: 'mono' }, h.serial_no),
      el('td', {}, h.item_name || '—'),
      el('td', {}, h.borrower),
      el('td', {}, h.borrowed_at),
      el('td', {}, h.returned_at || '—'),
      el('td', {}, h.note || ''),
    ));
  }
  box.appendChild(el('div', { class: 'table-wrap' },
    el('table', { class: 'pmd-table' },
      el('thead', {}, el('tr', {}, [t('field.serial'), t('field.name'), t('field.borrower'), t('borrow.borrowedAt'), t('borrow.returnedAt'), t('field.notes')].map(h => el('th', {}, h)))),
      tbody)
  ));
}

/* ============================================================
 * 视图：设置
 * ============================================================ */
async function renderSettings() {
  const main = $('#main');
  const set = await api('settings.get', {}, { get: true });
  const tab = location.hash.includes('tab=cats') ? 'cats' : (location.hash.includes('tab=locs') ? 'locs' : (location.hash.includes('tab=hyg') ? 'hyg' : 'general'));
  main.appendChild(el('div', { class: 'card' },
    el('h2', {}, t('settings.title')),
    el('div', { class: 'tabs' },
      btn(t('settings.general'), tab === 'general' ? '' : 'ghost', () => { location.hash = '#/settings'; }),
      btn(t('settings.categories'), tab === 'cats' ? '' : 'ghost', () => { location.hash = '#/settings?tab=cats'; }),
      btn(t('settings.locations'), tab === 'locs' ? '' : 'ghost', () => { location.hash = '#/settings?tab=locs'; }),
      btn(t('settings.hygiene'), tab === 'hyg' ? '' : 'ghost', () => { location.hash = '#/settings?tab=hyg'; }),
    )
  ));
  if (tab === 'cats') renderSettingsCats(main);
  else if (tab === 'locs') renderSettingsLocs(main);
  else if (tab === 'hyg') renderSettingsHygiene(main);
  else renderSettingsGeneral(main, set);
}

function renderSettingsGeneral(main, set) {
  const title = el('input', { type: 'text', value: set.site_title });
  const accent = el('input', { type: 'color', value: set.theme_accent });
  const rowsPer = el('input', { type: 'number', min: '10', max: '500', value: set.rows_per_page, style: 'width:100px' });
  const langSel = el('select', { disabled: true },
    Object.entries(S.status.languages).map(([k, v]) => el('option', { value: k, selected: k === set.language }, v))
  );
  const logoBox = el('div', { class: 'row' });
  const logoFile = el('input', { type: 'file', accept: 'image/*', style: 'display:none' });
  const renderLogo = () => {
    logoBox.innerHTML = '';
    if (set.logo) {
      logoBox.appendChild(el('img', { src: '/uploads/logo/' + esc(set.logo), style: 'height:48px;border-radius:8px', alt: 'logo' }));
      logoBox.appendChild(btn(t('settings.logoRemove'), 'ghost sm', async () => {
        await api('settings.logo_remove');
        set.logo = '';
        renderLogo();
      }));
    }
    logoBox.appendChild(btn(t('settings.logoUpload'), 'ghost sm', () => logoFile.click()));
    logoBox.appendChild(logoFile);
  };
  renderLogo();
  logoFile.addEventListener('change', async () => {
    if (!logoFile.files[0]) return;
    const fd = new FormData();
    fd.append('logo', logoFile.files[0]);
    try {
      const d = await apiForm('settings.logo_upload', fd);
      set.logo = d.logo;
      S.status.logo = d.logo;
      renderLogo();
      toast(t('settings.saved'));
    } catch (e) { toast(e.message, 'err'); }
  });

  const pinOld = el('input', { type: 'password' });
  const pinNew = el('input', { type: 'password' });
  const pinNew2 = el('input', { type: 'password' });

  const card = el('div', { class: 'card' },
    el('h2', {}, t('settings.general')),
    el('div', { class: 'form-grid' },
      el('div', {}, el('label', { class: 'f' }, t('settings.siteTitle')), title),
      el('div', {}, el('label', { class: 'f' }, t('settings.theme')), accent, el('div', { class: 'hint' }, t('settings.accent'))),
      el('div', {}, el('label', { class: 'f' }, t('settings.rowsPerPage')), rowsPer),
      el('div', {}, el('label', { class: 'f' }, t('settings.language')), langSel, el('div', { class: 'hint' }, t('settings.langHint'))),
      el('div', { class: 'wide' }, el('label', { class: 'f' }, t('settings.logo')), logoBox),
    ),
    el('div', { class: 'mt' }, btn(t('common.save'), '', async () => {
      try {
        await api('settings.update', { site_title: title.value.trim(), theme_accent: accent.value.toUpperCase(), rows_per_page: rowsPer.value });
        S.status.site_title = title.value.trim();
        applyTheme(accent.value);
        document.title = title.value.trim();
        renderShell();
        route();
        toast(t('settings.saved'));
      } catch (e) { toast(e.message, 'err'); }
    })),
    el('hr', { style: 'margin:18px 0;border:0;border-top:1px solid var(--border)' }),
    el('h3', {}, t('settings.pin')),
    el('div', { class: 'form-grid' },
      el('div', {}, el('label', { class: 'f' }, t('settings.pinOld')), pinOld),
      el('div', {}, el('label', { class: 'f' }, t('settings.pinNew')), pinNew),
      el('div', {}, el('label', { class: 'f' }, t('settings.pinConfirm')), pinNew2),
    ),
    el('div', { class: 'mt' }, btn(t('common.save'), '', async () => {
      if (pinNew.value !== pinNew2.value) { toast(t('settings.pinMismatch'), 'err'); return; }
      try {
        await api('change_pin', { old_pin: pinOld.value, new_pin: pinNew.value });
        pinOld.value = pinNew.value = pinNew2.value = '';
        toast(t('settings.pinChanged'));
      } catch (e) { toast(e.message, 'err'); }
    }))
  );
  main.appendChild(card);
}

async function renderSettingsCats(main) {
  const cats = await api('categories.list', {}, { get: true });
  const tbody = el('tbody', {});
  for (const c of cats) {
    const nameInput = el('input', { type: 'text', value: c.sub_name, style: 'width:140px' });
    const tr = el('tr', {},
      el('td', {}, el('span', { class: 'badge main' }, c.main_code)),
      el('td', { class: 'mono' }, c.sub_code),
      el('td', {}, nameInput),
      el('td', {},
        btn(t('common.save'), 'ghost sm', async () => {
          await api('categories.update', { main_code: c.main_code, sub_code: c.sub_code, sub_name: nameInput.value.trim() });
          toast(t('settings.saved'));
          renderSettingsCats(main);
        }),
        ' ',
        btn(t('common.delete'), 'danger-ghost sm', async () => {
          try {
            await api('categories.delete', { main_code: c.main_code, sub_code: c.sub_code });
            toast(t('common.success'));
            renderSettingsCats(main);
          } catch (e) { toast(e.message, 'err'); }
        })
      ),
    );
    tbody.appendChild(tr);
  }
  const mainSel = el('select', { id: 'ncMain' }, Object.entries(S.status.main_categories).map(([k, v]) => el('option', { value: k }, `${k} ${v}`)));
  const subSel = el('input', { id: 'ncSub', type: 'text', maxlength: '2', placeholder: '如 XZ', style: 'width:80px;text-transform:uppercase' });
  const nameSel = el('input', { id: 'ncName', type: 'text', placeholder: t('settings.subName'), style: 'width:160px' });
  const card = el('div', { class: 'card' },
    el('h2', {}, t('settings.categories')),
    el('div', { class: 'desc' }, t('settings.categoriesHint')),
    el('div', { class: 'table-wrap' },
      el('table', { class: 'pmd-table', style: 'min-width:600px' },
        el('thead', {}, el('tr', {}, [t('settings.mainCat'), t('settings.subCat'), t('settings.subName'), t('common.actions')].map(h => el('th', {}, h)))),
        tbody)
    ),
    el('div', { class: 'row mt' },
      el('label', { class: 'f', style: 'margin:0' }, t('settings.addCategory') + '：'), mainSel, subSel, nameSel,
      btn(t('common.save'), 'sm', async () => {
        try {
          await api('categories.create', { main_code: mainSel.value, sub_code: subSel.value.trim().toUpperCase(), sub_name: nameSel.value.trim() });
          toast(t('common.success'));
          S.status.categories = await api('categories.list', {}, { get: true });
          renderSettingsCats(main);
        } catch (e) { toast(e.message, 'err'); }
      })
    )
  );
  main.appendChild(card);
}

async function renderSettingsLocs(main) {
  const locs = await api('locations.list', {}, { get: true });
  const tbody = el('tbody', {});
  for (const l of locs) {
    const nameInput = el('input', { type: 'text', value: l.name, style: 'width:180px' });
    const orderInput = el('input', { type: 'number', value: l.sort_order, style: 'width:60px' });
    const tr = el('tr', {},
      el('td', { class: 'mono' }, l.code + (l.code === 'LTO' ? ' 🔒' : '')),
      el('td', {}, nameInput),
      el('td', {}, orderInput),
      el('td', {},
        l.code === 'LTO' ? el('span', { class: 'muted' }, t('settings.ltoProtected')) : el('div', {},
          btn(t('common.save'), 'ghost sm', async () => {
            await api('locations.update', { code: l.code, name: nameInput.value.trim(), sort_order: orderInput.value });
            toast(t('settings.saved'));
            renderSettingsLocs(main);
          }),
          ' ',
          btn(t('common.delete'), 'danger-ghost sm', async () => {
            try {
              await api('locations.delete', { code: l.code });
              toast(t('common.success'));
              renderSettingsLocs(main);
            } catch (e) { toast(e.message, 'err'); }
          })
        )
      ),
    );
    tbody.appendChild(tr);
  }
  const codeIn = el('input', { id: 'nlCode', type: 'text', maxlength: '4', placeholder: '如 GARG', style: 'width:90px;text-transform:uppercase' });
  const nameIn = el('input', { id: 'nlName', type: 'text', placeholder: t('settings.locationName'), style: 'width:160px' });
  const orderIn = el('input', { id: 'nlOrder', type: 'number', value: '0', style: 'width:60px' });
  const card = el('div', { class: 'card' },
    el('h2', {}, t('settings.locations')),
    el('div', { class: 'desc' }, t('settings.locationsHint')),
    el('div', { class: 'table-wrap' },
      el('table', { class: 'pmd-table', style: 'min-width:600px' },
        el('thead', {}, el('tr', {}, [t('settings.locationCode'), t('settings.locationName'), '排序', t('common.actions')].map(h => el('th', {}, h)))),
        tbody)
    ),
    el('div', { class: 'row mt' },
      el('label', { class: 'f', style: 'margin:0' }, t('settings.addLocation') + '：'), codeIn, nameIn, orderIn,
      btn(t('common.save'), 'sm', async () => {
        try {
          await api('locations.create', { code: codeIn.value.trim().toUpperCase(), name: nameIn.value.trim(), sort_order: orderIn.value });
          toast(t('common.success'));
          S.status.locations = await api('locations.list', {}, { get: true });
          renderSettingsLocs(main);
        } catch (e) { toast(e.message, 'err'); }
      })
    )
  );
  main.appendChild(card);
}

async function renderSettingsHygiene(main) {
  const levels = await api('hygiene.list', {}, { get: true });
  const tbody = el('tbody', {});
  for (const l of levels) {
    const nameInput = el('input', { type: 'text', value: l.name, style: 'width:220px' });
    const orderInput = el('input', { type: 'number', value: l.sort_order, style: 'width:60px' });
    const tr = el('tr', {},
      el('td', { class: 'mono' }, l.code),
      el('td', {}, nameInput),
      el('td', {}, orderInput),
      el('td', {},
        btn(t('common.save'), 'ghost sm', async () => {
          try {
            await api('hygiene.update', { code: l.code, name: nameInput.value.trim(), sort_order: orderInput.value });
            toast(t('settings.saved'));
            renderSettingsHygiene(main);
          } catch (e) { toast(e.message, 'err'); }
        }),
        ' ',
        btn(t('common.delete'), 'danger-ghost sm', async () => {
          if (!await confirmDlg(t('settings.hygieneDelete').replace('{code}', l.code).replace('{name}', l.name), t('common.delete'))) return;
          try {
            await api('hygiene.delete', { code: l.code });
            toast(t('common.success'));
            S.status.hygiene_levels = await api('hygiene.list', {}, { get: true });
            renderSettingsHygiene(main);
          } catch (e) { toast(e.message, 'err'); }
        })
      ),
    );
    tbody.appendChild(tr);
  }
  const codeIn = el('input', { id: 'nhCode', type: 'text', maxlength: '1', placeholder: '如 E', style: 'width:60px;text-transform:uppercase' });
  const nameIn = el('input', { id: 'nhName', type: 'text', placeholder: t('settings.hygieneName'), style: 'width:200px' });
  const orderIn = el('input', { id: 'nhOrder', type: 'number', value: '0', style: 'width:60px' });
  const card = el('div', { class: 'card' },
    el('h2', {}, t('settings.hygiene')),
    el('div', { class: 'desc' }, t('settings.hygieneHint')),
    el('div', { class: 'table-wrap' },
      el('table', { class: 'pmd-table', style: 'min-width:560px' },
        el('thead', {}, el('tr', {}, [t('settings.hygieneCode'), t('settings.hygieneName'), '排序', t('common.actions')].map(h => el('th', {}, h)))),
        tbody)
    ),
    el('div', { class: 'row mt' },
      el('label', { class: 'f', style: 'margin:0' }, t('settings.addHygiene') + '：'), codeIn, nameIn, orderIn,
      btn(t('common.save'), 'sm', async () => {
        try {
          await api('hygiene.create', { code: codeIn.value.trim().toUpperCase(), name: nameIn.value.trim(), sort_order: orderIn.value });
          toast(t('common.success'));
          S.status.hygiene_levels = await api('hygiene.list', {}, { get: true });
          renderSettingsHygiene(main);
        } catch (e) { toast(e.message, 'err'); }
      })
    )
  );
  main.appendChild(card);
}

/* ---------------- 启动 ---------------- */
document.addEventListener('DOMContentLoaded', boot);
