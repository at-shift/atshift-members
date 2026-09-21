(() => {
  const labels = window.atshmeRoleGroups;
  if (!labels) return;
  document.querySelectorAll('select[name="role"], select#new_role, select#new_role2').forEach(select => {
    const selected = select.value;
    const groups = [document.createElement('optgroup'), document.createElement('optgroup'), document.createElement('optgroup')];
    groups.forEach((group, i) => { group.label = [labels.members, labels.standard, labels.other][i]; });
    const memberRoles = ['administrator', 'atshme_operator', 'atshme_member'];
    const standardRoles = ['editor', 'author', 'contributor', 'subscriber'];
    const options = Array.from(select.options);
    memberRoles.forEach(role => { const option = options.find(item => item.value === role); if (option) { if (labels.names[role]) option.textContent = labels.names[role]; groups[0].append(option); } });
    options.forEach(option => {
      if (memberRoles.includes(option.value) || !option.value || option.value === '-1') return;
      groups[standardRoles.includes(option.value) ? 1 : 2].append(option);
    });
    groups.forEach(group => { if (group.children.length) select.append(group); });
    select.value = selected;
    if (select.name === 'role') {
      const help = document.createElement('p'); help.className = 'description'; help.textContent = labels.help;
      select.after(help);
    }
  });
})();
