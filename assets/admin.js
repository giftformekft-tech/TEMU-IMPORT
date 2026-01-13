(function(){
const { createElement: el, useEffect, useMemo, useState } = wp.element;
const { Button, Card, CardBody, CardHeader, CheckboxControl, Notice, PanelBody, PanelRow, Spinner, TextControl, SelectControl } = wp.components;

// Biztosítsuk a REST cookie auth noncet akkor is, ha valami cache/optimalizáló nem futtatja le a PHP inline middleware-t.
try {
  if (typeof WTT !== 'undefined' && WTT.restNonce && wp && wp.apiFetch) {
    wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(WTT.restNonce));
  }
} catch (e) {}

const api = (path, opts={}) => wp.apiFetch({ path: WTT.restPath + path, ...opts });

function clamp(n, a, b){ return Math.max(a, Math.min(b, n)); }

function App(){
  const [step, setStep] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(false);
  const [products, setProducts] = useState([]);
  const [total, setTotal] = useState(0);
  const [selectedIds, setSelectedIds] = useState({});

  const [scanLoading, setScanLoading] = useState(false);
  const [scan, setScan] = useState(null);
  const [selType, setSelType] = useState({});
  const [selColor, setSelColor] = useState({});
  const [selSize, setSelSize] = useState({});

  const [sessionId, setSessionId] = useState('');
  const [genLoading, setGenLoading] = useState(false);
  const [rows, setRows] = useState([]);
  const [rowsTotal, setRowsTotal] = useState(0);
  const [rowsPage, setRowsPage] = useState(1);
  const [rowsPerPage, setRowsPerPage] = useState(50);
  const [warnings, setWarnings] = useState([]);

  const [settings, setSettings] = useState(WTT.settings || {});
  const [savingSettings, setSavingSettings] = useState(false);

  const selectedCount = useMemo(() => Object.values(selectedIds).filter(Boolean).length, [selectedIds]);

  const loadProducts = async () => {
    setLoading(true);
    try {
      const q = `?page=${page}&per_page=${perPage}&search=${encodeURIComponent(search)}`;
      const res = await api('/products' + q);
      setProducts(res.items || []);
      setTotal(res.total || 0);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { loadProducts(); }, [page, perPage]);

  const onSearch = async () => {
    setPage(1);
    await loadProducts();
  };

  const toggleId = (id, v) => setSelectedIds(prev => ({...prev, [id]: v}));

  const selectVisible = (v=true) => {
    const next = {...selectedIds};
    (products || []).forEach(p => { next[p.id] = v; });
    setSelectedIds(next);
  };

  const goScan = async () => {
    const ids = Object.keys(selectedIds).filter(k => selectedIds[k]).map(n => parseInt(n,10));
    if (!ids.length) return;
    setScanLoading(true);
    try {
      const res = await api('/scan', { method:'POST', data: { product_ids: ids } });
      setScan(res);
      // default: select all found values
      const mk = arr => (arr||[]).reduce((a,x)=>{a[x]=true;return a;},{});
      setSelType(mk(res.termektipus));
      setSelColor(mk(res.szin));
      setSelSize(mk(res.meret));
      setStep(2);
    } finally {
      setScanLoading(false);
    }
  };

  const generate = async () => {
    const ids = Object.keys(selectedIds).filter(k => selectedIds[k]).map(n => parseInt(n,10));
    setGenLoading(true);
    try {
      const pick = obj => Object.keys(obj).filter(k => obj[k]);
      const payload = {
        product_ids: ids,
        termektipus: pick(selType),
        szin: pick(selColor),
        meret: pick(selSize),
      };
      const res = await api('/generate', { method:'POST', data: payload });
      setSessionId(res.session_id);
      setWarnings(res.warnings || []);
      setRowsPage(1);
      setStep(3);
      await loadRows(res.session_id, 1, rowsPerPage);
    } finally {
      setGenLoading(false);
    }
  };

  const loadRows = async (sid, p, pp) => {
    const q = `?session_id=${encodeURIComponent(sid)}&page=${p}&per_page=${pp}`;
    const res = await api('/session' + q);
    if (res.error) {
      setWarnings([res.error]);
      setRows([]);
      setRowsTotal(0);
      return;
    }
    setRows(res.items || []);
    setRowsTotal(res.total || 0);
    setWarnings(res.warnings || []);
  };

  useEffect(() => {
    if (step === 3 && sessionId) {
      loadRows(sessionId, rowsPage, rowsPerPage);
    }
  }, [rowsPage, rowsPerPage]);

  const downloadUrl = sessionId ? (WTT.ajaxUrl + '?action=wtt_export_csv&session_id=' + encodeURIComponent(sessionId) + '&_wpnonce=' + encodeURIComponent(WTT.exportNonce)) : '';
const saveSettings = async () => {
    setSavingSettings(true);
    try {
      const res = await api('/settings', { method:'POST', data: settings });
      setSettings(res.settings || settings);
    } finally {
      setSavingSettings(false);
    }
  };

  const ListStep = () => el('div', { className:'wtt-grid' },
    el(Card, {},
      el(CardHeader, {}, el('h2', {className:'wtt-title'}, 'Woo → Temu Export (V1)')),
      el(CardBody, {},
        el('div', {className:'wtt-toolbar'},
          el(TextControl, {
            label: 'Keresés (név / SKU)',
            value: search,
            onChange: setSearch,
            onKeyDown: (e)=>{ if(e.key==='Enter') onSearch(); }
          }),
          el(SelectControl, {
            label: 'Oldalméret',
            value: perPage,
            options: [
              {label:'25', value:25},
              {label:'50', value:50},
              {label:'100', value:100},
            ],
            onChange: (v)=>{ setPerPage(parseInt(v,10)); setPage(1); }
          }),
          el(Button, { variant:'secondary', onClick: onSearch }, 'Keresés'),
          el(Button, { variant:'secondary', onClick: ()=>selectVisible(true) }, 'Láthatóak kijelölése'),
          el(Button, { variant:'secondary', onClick: ()=>selectVisible(false) }, 'Kijelölés törlése'),
          el(Button, { variant:'primary', disabled: selectedCount===0 || scanLoading, onClick: goScan }, scanLoading ? el(Spinner, {}) : `Következő: variánsok (${selectedCount})`)
        ),
        loading ? el('div', {className:'wtt-center'}, el(Spinner, {})) :
          el('div', {className:'wtt-table-wrap'},
            el('table', {className:'wtt-table'},
              el('thead', {}, el('tr', {},
                el('th', {}, ''),
                el('th', {}, 'Kép'),
                el('th', {}, 'Termék'),
                el('th', {}, 'SKU'),
                el('th', {}, 'Típus'),
                el('th', {}, 'Státusz')
              )),
              el('tbody', {},
                (products||[]).map(p => el('tr', {key:p.id},
                  el('td', {}, el(CheckboxControl, { checked: !!selectedIds[p.id], onChange: (v)=>toggleId(p.id,v) })),
                  el('td', {}, p.image ? el('img', {src:p.image, className:'wtt-thumb'}) : ''),
                  el('td', {}, el('strong', {}, p.name), el('div', {className:'wtt-muted'}, `#${p.id}`)),
                  el('td', {}, p.sku || '—'),
                  el('td', {}, p.type),
                  el('td', {}, p.status)
                ))
              )
            )
          ),
        el('div', {className:'wtt-pager'},
          el(Button, { variant:'secondary', disabled: page<=1, onClick: ()=>setPage(page-1) }, '← Előző'),
          el('span', {className:'wtt-muted'}, `Oldal ${page} / ${Math.max(1, Math.ceil(total/perPage))}  (összes: ${total})`),
          el(Button, { variant:'secondary', disabled: page>=Math.ceil(total/perPage), onClick: ()=>setPage(page+1) }, 'Következő →')
        )
      )
    ),
    el(Card, {},
      el(CardHeader, {}, el('h3', {className:'wtt-subtitle'}, 'Beállítások (attribútum megfeleltetés)')),
      el(CardBody, {},
        el(PanelBody, { title: 'Fix 3 attribútum (slug)', initialOpen: true },
          el(PanelRow, {}, el(TextControl, { label:'Terméktípus slug', value: settings.attr_type||'', onChange: v=>setSettings(s=>({...s, attr_type:v})) })),
          el(PanelRow, {}, el(TextControl, { label:'Szín slug', value: settings.attr_color||'', onChange: v=>setSettings(s=>({...s, attr_color:v})) })),
          el(PanelRow, {}, el(TextControl, { label:'Méret slug', value: settings.attr_size||'', onChange: v=>setSettings(s=>({...s, attr_size:v})) })),
          el(PanelRow, {}, el(SelectControl, { label:'Leírás forrása', value: settings.desc_source||'short', options:[{label:'Rövid leírás',value:'short'},{label:'Hosszú leírás',value:'long'}], onChange: v=>setSettings(s=>({...s, desc_source:v})) })),
          el(PanelRow, {}, el(TextControl, { label:'Összefűzés elválasztó', value: settings.join_sep||' | ', onChange: v=>setSettings(s=>({...s, join_sep:v})) })),
          el(PanelRow, {}, el(Button, { variant:'primary', onClick: saveSettings, disabled: savingSettings }, savingSettings? el(Spinner, {}) : 'Beállítások mentése'))
        )
      )
    )
  );

  const CheckList = ({title, items, state, setState}) => {
    const allOn = items.length && items.every(x => state[x]);
    const noneOn = items.every(x => !state[x]);
    const setAll = (v) => {
      const next = {...state};
      items.forEach(x => next[x] = v);
      setState(next);
    };
    return el(Card, {},
      el(CardHeader, {}, el('h3', {className:'wtt-subtitle'}, title)),
      el(CardBody, {},
        el('div', {className:'wtt-toolbar'},
          el(Button, { variant:'secondary', onClick: ()=>setAll(true), disabled: allOn }, 'Összes'),
          el(Button, { variant:'secondary', onClick: ()=>setAll(false), disabled: noneOn }, 'Semmi')
        ),
        el('div', {className:'wtt-checklist'},
          (items||[]).map(val => el('div', {key:val, className:'wtt-check'},
            el(CheckboxControl, { checked: !!state[val], onChange: v=>setState(prev=>({...prev,[val]:v})) }),
            el('span', {}, val)
          ))
        )
      )
    );
  };

  const ScanStep = () => el('div', {},
    el('div', {className:'wtt-toolbar'},
      el(Button, { variant:'secondary', onClick: ()=>setStep(1) }, '← Vissza a terméklistához'),
      el(Button, { variant:'primary', onClick: generate, disabled: genLoading }, genLoading ? el(Spinner,{}) : 'Táblázat generálása')
    ),
    (scanLoading || !scan) ? el('div', {className:'wtt-center'}, el(Spinner, {})) :
      el('div', {className:'wtt-grid3'},
        el(CheckList, { title: `Terméktípus (${(scan.termektipus||[]).length})`, items: scan.termektipus||[], state: selType, setState: setSelType }),
        el(CheckList, { title: `Szín (${(scan.szin||[]).length})`, items: scan.szin||[], state: selColor, setState: setSelColor }),
        el(CheckList, { title: `Méret (${(scan.meret||[]).length})`, items: scan.meret||[], state: selSize, setState: setSelSize })
      ),
    warnings?.length ? el(Notice, { status:'warning', isDismissible:false }, el('div', {},
      el('strong', {}, 'Figyelmeztetések:'),
      el('ul', {}, warnings.slice(0,10).map((w,i)=>el('li',{key:i},w)))
    )) : null
  );

  const PreviewStep = () => el('div', {},
    el('div', {className:'wtt-toolbar'},
      el(Button, { variant:'secondary', onClick: ()=>setStep(2) }, '← Vissza a variáns választóhoz'),
      el(Button, { variant:'primary', href: downloadUrl }, 'CSV letöltése'),
      el(SelectControl, { label:'Előnézet oldalméret', value: rowsPerPage, options:[{label:'50',value:50},{label:'100',value:100},{label:'200',value:200}], onChange: v=>{ setRowsPerPage(parseInt(v,10)); setRowsPage(1); } }),
      el('span', {className:'wtt-muted'}, `Sorok: ${rowsTotal}`)
    ),
    warnings?.length ? el(Notice, { status:'warning', isDismissible:true }, el('div', {},
      el('strong', {}, 'Figyelmeztetések:'),
      el('ul', {}, warnings.slice(0,12).map((w,i)=>el('li',{key:i},w)))
    )) : null,
    el('div', {className:'wtt-table-wrap'},
      el('table', {className:'wtt-table'},
        el('thead', {}, el('tr', {},
          el('th', {}, 'Terméknév'),
          el('th', {}, 'SKU'),
          el('th', {}, 'Leírás'),
          el('th', {}, 'Kateg+Tag+Leírás'),
          el('th', {}, 'Méret'),
          el('th', {}, 'Szín'),
          el('th', {}, 'Kép URL')
        )),
        el('tbody', {},
          (rows||[]).map((r,idx) => el('tr', {key:idx},
            el('td', {}, r.termek_nev||''),
            el('td', {}, r.sku||''),
            el('td', {}, r.leiras||''),
            el('td', {}, r.kat_tag_leiras||''),
            el('td', {}, r.meret||''),
            el('td', {}, r.szin||''),
            el('td', {}, r.varians_img_url ? el('a', {href:r.varians_img_url, target:'_blank', rel:'noreferrer'}, 'link') : '—')
          ))
        )
      )
    ),
    el('div', {className:'wtt-pager'},
      el(Button, { variant:'secondary', disabled: rowsPage<=1, onClick: ()=>setRowsPage(rowsPage-1) }, '← Előző'),
      el('span', {className:'wtt-muted'}, `Oldal ${rowsPage} / ${Math.max(1, Math.ceil(rowsTotal/rowsPerPage))}`),
      el(Button, { variant:'secondary', disabled: rowsPage>=Math.ceil(rowsTotal/rowsPerPage), onClick: ()=>setRowsPage(rowsPage+1) }, 'Következő →')
    )
  );

  return el('div', {className:'wtt-root'},
    step===1 ? el(ListStep) : null,
    step===2 ? el(ScanStep) : null,
    step===3 ? el(PreviewStep) : null,
  );
}

wp.element.render(el(App), document.getElementById('wtt-admin-root'));
})();
