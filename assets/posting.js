(() => {
    document.querySelectorAll('[data-asm-posting-mode]').forEach(select => {
        const groups = select.closest('.asm-posting-rule').querySelector('.asm-posting-groups');
        if (!groups) return;
        const update = () => {
            groups.hidden = select.value !== 'groups';
            if (!groups.hidden) groups.open = true;
        };
        select.addEventListener('change', update);
        update();
    });
})();
