(() => {
    document.querySelectorAll('[data-atshme-posting-mode]').forEach(select => {
        const groups = select.closest('.atshme-posting-rule').querySelector('.atshme-posting-groups');
        if (!groups) return;
        const update = () => {
            groups.hidden = select.value !== 'groups';
            if (!groups.hidden) groups.open = true;
        };
        select.addEventListener('change', update);
        update();
    });
})();
