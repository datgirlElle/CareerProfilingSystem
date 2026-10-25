/*
 * The careers of one MMCL program as an editable list (Program Mapping Crosswalk: Edit program and Add Program).
 * Add a career (Enter or the Add button; pasting several lines adds each one), find one with the search box, remove one
 * with its x. The same rules as the server (modules/CareerDataset/lib/Careers.php): spaces tidied, nothing longer than
 * 80 characters, no repeats in any capitals, at most 500 (a safety cap, not a school limit). Values only ever go into
 * the page as text (textContent), never as HTML.
 *
 *   const editor = PP.careerEditor(containerElement, { idPrefix: 'edit' });
 *   editor.set(['Registered Nurse', ...]);   editor.get();   // -> the list, in order
 */
(function () {
  var MAX_LENGTH = 80;
  var MAX_CAREERS = 500;   // Careers::MAX_PER_PROGRAM
  var tidy = function (s) { return String(s).replace(/\s+/g, ' ').trim(); };

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  window.PP = window.PP || {};
  PP.careerEditor = function (root, opts) {
    var id = (opts && opts.idPrefix) || 'careers';
    var careers = [];
    var query = '';

    root.classList.add('ce');
    root.innerHTML = '';

    var addRow = el('div', 'ce-add');
    var addInput = el('input', 'ce-input');
    addInput.type = 'text';
    addInput.id = id + 'CareerAdd';
    addInput.maxLength = 2000;   // a paste of several lines; each line is checked on its own
    addInput.placeholder = 'Add a career, e.g. Data Analyst';
    addInput.setAttribute('aria-label', 'Add a career');
    var addBtn = el('button', 'pp-btn pp-btn-secondary ce-add-btn', 'Add');
    addBtn.type = 'button';
    addRow.append(addInput, addBtn);

    var message = el('p', 'ce-message hidden');
    message.setAttribute('role', 'status');

    var searchInput = el('input', 'ce-input ce-search');
    searchInput.type = 'search';
    searchInput.placeholder = 'Search the careers';
    searchInput.setAttribute('aria-label', 'Search the careers of this program');

    var count = el('p', 'ce-count');
    count.setAttribute('aria-live', 'polite');

    var list = el('ul', 'ce-list');
    list.setAttribute('aria-label', 'Careers of this program');

    root.append(addRow, message, searchInput, count, list);

    function say(text) {
      message.textContent = text;
      message.classList.toggle('hidden', !text);
    }

    function render() {
      var q = query.toLowerCase();
      var shown = careers.filter(function (c) { return !q || c.toLowerCase().indexOf(q) !== -1; });
      list.replaceChildren.apply(list, shown.map(function (c) {
        var li = el('li', 'ce-item');
        var name = el('span', 'ce-name', c);
        var remove = el('button', 'ce-remove', '×');
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Remove ' + c);
        remove.addEventListener('click', function () {
          careers = careers.filter(function (x) { return x !== c; });
          say('Removed "' + c + '".');
          render();
          addInput.focus();
        });
        li.append(name, remove);
        return li;
      }));
      if (!careers.length) list.replaceChildren(el('li', 'ce-empty', 'No careers yet. Add the first one above.'));
      else if (!shown.length) list.replaceChildren(el('li', 'ce-empty', 'No career matches "' + query + '".'));
      count.textContent = careers.length + (careers.length === 1 ? ' career' : ' careers') +
        (q ? ' (showing ' + shown.length + ')' : '');
    }

    function add(text) {
      var lines = String(text).split(/\r\n|\r|\n/).map(tidy).filter(Boolean);
      var added = 0, repeats = 0, tooLong = 0, full = false;
      lines.forEach(function (c) {
        if (c.length > MAX_LENGTH) { tooLong++; return; }
        if (careers.some(function (x) { return x.toLowerCase() === c.toLowerCase(); })) { repeats++; return; }
        if (careers.length >= MAX_CAREERS) { full = true; return; }
        careers.push(c);
        added++;
      });
      var notes = [];
      if (added) notes.push(added === 1 ? 'Added "' + careers[careers.length - 1] + '".' : 'Added ' + added + ' careers.');
      if (repeats) notes.push(repeats === 1 ? 'One is already in the list.' : repeats + ' are already in the list.');
      if (tooLong) notes.push(tooLong + ' longer than ' + MAX_LENGTH + ' characters ' + (tooLong === 1 ? 'was' : 'were') + ' left out.');
      if (full) notes.push('The list is full (' + MAX_CAREERS + ').');
      say(notes.join(' '));
      render();
    }

    addBtn.addEventListener('click', function () { add(addInput.value); addInput.value = ''; addInput.focus(); });
    addInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); addBtn.click(); }
    });
    // Pasting several lines (a list copied from a document) adds each one.
    addInput.addEventListener('paste', function (e) {
      var text = (e.clipboardData || window.clipboardData).getData('text');
      if (/\r|\n/.test(text)) { e.preventDefault(); add(text); addInput.value = ''; }
    });
    searchInput.addEventListener('input', function () { query = tidy(searchInput.value); render(); });

    render();
    return {
      // A career typed but not added yet (Save clicked straight away) is kept, not lost.
      get: function () {
        if (tidy(addInput.value)) { add(addInput.value); addInput.value = ''; }
        return careers.slice();
      },
      set: function (values) {
        careers = [];
        query = '';
        searchInput.value = '';
        say('');
        (values || []).map(tidy).filter(Boolean).forEach(function (c) {
          if (c.length <= MAX_LENGTH && !careers.some(function (x) { return x.toLowerCase() === c.toLowerCase(); })) careers.push(c);
        });
        render();
      }
    };
  };
})();
