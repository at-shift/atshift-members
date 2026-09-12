(function (wp, config) {
    'use strict';
    if (!wp || !config) return;
    const { __, sprintf } = wp.i18n;
    const { createElement: el, useState, useEffect, useRef, createRoot } = wp.element;
    const { Modal, Button, Spinner, Notice } = wp.components;
    const endpoint = '/atshift-members/v1/media';
    let activePicker = null;
    const api = (path, options) => wp.apiFetch(Object.assign({ path: endpoint + path }, options || {}));
    const textError = error => error && error.message ? error.message : __('Could not complete the action. Please try again.', 'atshift-members');
    function allowed(item, types) {
        return !types || !types.length || types.some(type => type === item.type || type === item.mime);
    }
    function ScopeChoice({ onChoose, tab }) {
        const action = tab === 'library' ? __('Choose from saved images, videos, and documents.', 'atshift-members') : __('Upload images, videos, and documents.', 'atshift-members');
        return el('div', { className: 'asm-media-choices' },
            el('button', { type: 'button', className: 'asm-media-choice', onClick: () => onChoose('public') },
                el('strong', null, __('Public', 'atshift-members')), el('span', null, __('These files are visible to everyone. ', 'atshift-members') + action)),
            el('button', { type: 'button', className: 'asm-media-choice', onClick: () => onChoose('members') },
                el('strong', null, __('Members Only', 'atshift-members')), el('span', null, __('These files are visible only to authorized members. ', 'atshift-members') + action)));
    }
    function Picker({ onSelect, library = false, initialTab = 'library', types, multiple = false, files }) {
        const [mode, setMode] = useState(null), [tab, setTab] = useState(initialTab);
        const [items, setItems] = useState([]), [selected, setSelected] = useState([]), [busy, setBusy] = useState(false);
        const [error, setError] = useState(''), [search, setSearch] = useState(''), [query, setQuery] = useState('');
        const [page, setPage] = useState(1), [more, setMore] = useState(false), [revision, setRevision] = useState(0);
        const input = useRef();
        useEffect(() => {
            if (!mode || tab !== 'library') return;
            let cancelled = false;setBusy(true);setError('');
            api('?visibility=' + mode + '&page=' + page + '&search=' + encodeURIComponent(query))
                .then(result => { if (!cancelled) { setItems(result.items.filter(item => allowed(item, types)));setMore(result.more); } })
                .catch(e => { if (!cancelled) setError(textError(e)); })
                .finally(() => { if (!cancelled) setBusy(false); });
            return () => { cancelled = true; };
        }, [mode, tab, query, page, revision]);
        async function upload(list, visibility = mode) {
            const batch = Array.from(list || []);if (!batch.length || busy) return;
            setBusy(true);setError('');const uploaded = [];
            try {
                for (const file of batch) {
                    // translators: %s: Maximum upload size, such as 8 MB.
                    if (file.size > config.maxSize) throw new Error(sprintf(__('The file exceeds the server upload limit of %s.', 'atshift-members'), config.maxSizeLabel));
                    const data = new FormData();data.append('file', file);data.append('visibility', visibility);data.append('post_id', config.postId);
                    const item = await api('/upload', { method: 'POST', body: data });
                    if (!allowed(item, types)) throw new Error(__('Choose a format supported by this block.', 'atshift-members'));
                    uploaded.push(item);
                }
                if (library) { setTab('library');setRevision(v => v + 1); }
                else onSelect(multiple ? uploaded : uploaded[0]);
            } catch (e) { setError(textError(e)); }
            finally { setBusy(false); }
        }
        function chooseMode(next) {setMode(next);setPage(1);setSelected([]);if (files) upload(files, next);}
        async function insert() {
            if (!selected.length) return;setBusy(true);setError('');
            try {
                const chosen = [];
                for (const item of selected) chosen.push(await api('/select', { method: 'POST', data: { id: item.id, post_id: config.postId, visibility: mode } }));
                onSelect(multiple ? chosen : chosen[0]);
            } catch (e) { setError(textError(e));setBusy(false); }
        }
        function chooseItem(item) {
            setSelected(old => multiple ? old.some(x => x.id === item.id) ? old.filter(x => x.id !== item.id) : old.concat(item) : [item]);
        }
        if (!mode) return el(ScopeChoice, { onChoose: chooseMode, tab });
        return el('div', { className: 'asm-media-browser' },
            el('div', { className: 'asm-media-toolbar' }, el('strong', null, mode === 'members' ? __('Members-Only Media', 'atshift-members') : __('Public Media', 'atshift-members')),
                el(Button, { variant: 'tertiary', disabled: busy, onClick: () => { setMode(null);setSelected([]); } }, __('Change Visibility', 'atshift-members'))),
            el('p', { className: 'description' }, mode === 'members' ? __('Showing members-only files you can use. Files used in another post will be copied for that post.', 'atshift-members') : __('Showing public files you can use.', 'atshift-members')),
            el('div', { className: 'asm-media-tabs', role: 'tablist', 'aria-label': __('Choose How to Add Media', 'atshift-members') },
                ['upload', 'library'].map(value => el('button', { key: value, type: 'button', role: 'tab', 'aria-selected': tab === value, disabled: busy, onClick: () => { setTab(value);setSelected([]); } }, value === 'upload' ? __('Upload', 'atshift-members') : __('Media Library', 'atshift-members')))),
            error && el(Notice, { status: 'error', isDismissible: false }, error),
            busy && el('div', { role: 'status', className: 'asm-media-progress' }, el(Spinner), __('Processing…', 'atshift-members')),
            tab === 'upload' ? el('div', { className: 'asm-media-drop', onDragOver: e => e.preventDefault(), onDrop: e => { e.preventDefault();upload(e.dataTransfer.files); } },
                el('p', null, __('Drop Files to Upload', 'atshift-members')),
                el('input', { ref: input, type: 'file', hidden: true, accept: types && types.includes('image') ? '.jpg,.jpeg,.png,.gif,.webp' : types && types.includes('video') ? '.mp4,.webm' : config.accept, multiple: !!multiple || library, onChange: e => { upload(e.target.files);e.target.value = ''; } }),
                el(Button, { variant: 'secondary', disabled: busy, onClick: () => input.current.click() }, __('Select Files', 'atshift-members')),
                el('p', null, __('Server upload limit: ', 'atshift-members') + config.maxSizeLabel)) : el('div', null,
                el('form', { className: 'asm-media-search', onSubmit: e => { e.preventDefault();setPage(1);setQuery(search); } },
                    el('label', null, __('Search by Filename', 'atshift-members'), el('input', { type: 'search', value: search, onChange: e => setSearch(e.target.value) })), el(Button, { type: 'submit', variant: 'secondary', disabled: busy }, __('Search', 'atshift-members'))),
                el('div', { className: 'asm-media-grid' }, items.map(item => el('button', { key: item.id, type: 'button', className: 'asm-media-item', 'aria-pressed': selected.some(x => x.id === item.id), disabled: busy, onClick: () => chooseItem(item) },
                    item.type === 'image' ? el('img', { src: item.url, alt: '', loading: 'lazy' }) : el('span', { className: 'dashicons dashicons-media-default', 'aria-hidden': true }),
                    el('span', null, item.filename)))),
                !busy && !items.length && el('p', null, __('No files are available to select.', 'atshift-members')),
                el('div', { className: 'asm-media-pagination' },
                    page > 1 && el(Button, { variant: 'secondary', disabled: busy, onClick: () => { setPage(page - 1);setSelected([]); } }, __('Previous Page', 'atshift-members')),
                    more && el(Button, { variant: 'secondary', disabled: busy, onClick: () => { setPage(page + 1);setSelected([]); } }, __('Next Page', 'atshift-members')))),
            selected.length > 0 && el('div', { className: 'asm-media-footer' }, library ?
                el('div', null, el('strong', null, selected[0].filename), el('p', null, el('a', { href: selected[0].link, target: '_blank', rel: 'noopener' }, __('Open File', 'atshift-members')))) :
                el(Button, { variant: 'primary', disabled: busy, onClick: insert }, __('Insert Selected Files', 'atshift-members'))));
    }
    async function pick(options = {}) {
        if (config.prepare && !(await config.prepare())) return null;
        if (activePicker) return activePicker;
        const mount = document.createElement('div');document.body.appendChild(mount);const root = createRoot(mount);
        const focus = document.activeElement;
        activePicker = new Promise(resolve => {
            const finish = value => { root.unmount();mount.remove();activePicker = null;if (focus && focus.isConnected) focus.focus();resolve(value); };
            root.render(el(Modal, { title: __('Attachment Visibility', 'atshift-members'), className: 'asm-media-modal', onRequestClose: () => finish(null) },
                el(Picker, Object.assign({}, options, { onSelect: finish }))));
        });
        return activePicker;
    }
    function cache(selection) {
        const records = Array.isArray(selection) ? selection : [selection];
        records.filter(Boolean).forEach(item => {
            if (wp.media && wp.media.attachment) wp.media.attachment(item.id).set(item);
            if (wp.data && wp.data.dispatch('core')) wp.data.dispatch('core').receiveEntityRecords('postType', 'attachment', [{
                id: item.id, type: 'attachment', status: 'inherit', title: { raw: item.title, rendered: item.title },
                caption: { raw: '', rendered: '' }, description: { raw: '', rendered: '' }, source_url: item.url,
                alt_text: item.alt, media_type: item.type, mime_type: item.mime, media_details: item.media_details
            }]);
        });
        return selection;
    }
    const escape = value => String(value || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    function insertClassic(selection, id) {
        if (!selection) return;window.wpActiveEditor = id || window.wpActiveEditor;
        const html = (Array.isArray(selection) ? selection : [selection]).map(item => item.type === 'image' ?
            '<img class="wp-image-' + item.id + '" src="' + escape(item.url) + '" alt="' + escape(item.alt) + '" />' :
            item.type === 'video' ? '<video controls preload="metadata" src="' + escape(item.url) + '"></video>' : '<a href="' + escape(item.link) + '">' + escape(item.filename) + '</a>').join('\n');
        wp.media.editor.insert(html);
    }
    // Classic Editor and the block editor's Classic block share this entry point.
    if (wp.media && wp.media.editor && !config.library) {
        wp.media.editor.open = function (id) {pick({ multiple: true }).then(value => insertClassic(cache(value || []), id));};
    }
    if (!config.library && wp.hooks && wp.element && wp.data) {
        // Preserve the native placeholder and its buttons, but choose visibility before opening a file dialog.
        wp.hooks.addFilter('editor.MediaPlaceholder', 'atshift-members/upload-choice', Original => function (props) {
            return el('div', { className: 'asm-media-placeholder', onClickCapture: event => {
                if (!event.target.closest('.block-editor-media-placeholder__upload-button, input[type="file"]')) return;
                event.preventDefault();event.stopPropagation();
                pick({ initialTab: 'upload', types: props.allowedTypes, multiple: props.multiple }).then(value => { if (value) props.onSelect(cache(value)); });
            } }, el(Original, props));
        });
        wp.hooks.addFilter('editor.MediaUpload', 'atshift-members/media-picker', () => function (props) {
            return props.render({ open: () => pick({ types: props.allowedTypes, multiple: props.multiple }).then(value => { if (value) props.onSelect(cache(value));else if (props.onClose) props.onClose(); }) });
        }, 100);
        // Direct upload, paste and drop all use this block-editor upload setting.
        const wrapped = new WeakSet();let updating = false;
        function wrapUpload() {
            if (updating || !wp.data.select('core/block-editor')) return;
            const settings = wp.data.select('core/block-editor').getSettings();if (!settings) return;
            const original = settings.mediaUpload;if (!original || wrapped.has(original)) return;
            const upload = options => {
                const files = Array.from(options.filesList || []);
                pick({ files, initialTab: 'upload', types: options.allowedTypes, multiple: options.multiple !== false }).then(value => {
                    if (!value) { if (options.onError) options.onError({ code: 'asm_cancelled', message: __('Upload cancelled.', 'atshift-members') });return; }
                    const selected = Array.isArray(value) ? value : [value];cache(selected);
                    if (options.onFileChange) options.onFileChange(selected);
                    if (options.onSuccess) selected.forEach(item => options.onSuccess(item));
                });
            };
            wrapped.add(upload);updating = true;
            wp.data.dispatch('core/block-editor').updateSettings({ mediaUpload: upload });updating = false;
        }
        wp.data.subscribe(wrapUpload);wp.domReady(wrapUpload);
        // Core File defaults to PDF previews; member documents are download links.
        let fixing = false;
        wp.data.subscribe(() => {
            if (fixing || !wp.data.select('core/block-editor')) return;
            const store = wp.data.select('core/block-editor'), dispatch = wp.data.dispatch('core/block-editor');
            const scan = blocks => blocks.forEach(block => {
                if (block.name === 'core/file' && /action=asm_file/.test(block.attributes.href || '') && block.attributes.displayPreview !== false) dispatch.updateBlockAttributes(block.clientId, { displayPreview: false });
                if (block.innerBlocks) scan(block.innerBlocks);
            });
            fixing = true;scan(store.getBlocks() || []);fixing = false;
        });
    }
    wp.domReady(() => {
        // TinyMCE receives native clipboard/drop events inside its own document.
        if (!config.library && window.tinymce) {
            const attached = new WeakSet();
            const attach = editor => {
                const doc = editor.getDoc();if (!doc || attached.has(doc)) return;attached.add(doc);
                const receive = event => {
                    const list = event.clipboardData ? event.clipboardData.files : event.dataTransfer && event.dataTransfer.files;
                    if (!list || !list.length) return;
                    event.preventDefault();event.stopImmediatePropagation();
                    pick({ files: Array.from(list), initialTab: 'upload', multiple: true }).then(value => insertClassic(cache(value || []), editor.id));
                };
                doc.addEventListener('paste', receive, true);doc.addEventListener('drop', receive, true);
                doc.addEventListener('dragover', event => { if (event.dataTransfer && Array.from(event.dataTransfer.types).includes('Files')) event.preventDefault(); }, true);
            };
            window.tinymce.on('AddEditor', event => event.editor.on('init', () => attach(event.editor)));
            (window.tinymce.editors || []).forEach(editor => { if (editor.initialized) attach(editor);else editor.on('init', () => attach(editor)); });
        }
        const container = document.getElementById('asm-media-library');
        if (container) createRoot(container).render(el(Picker, { library: true, multiple: true, initialTab: config.startUpload ? 'upload' : 'library' }));
    });
})(window.wp, window.asmMediaConfig);
