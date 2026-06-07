/**
 * Custom RSVP questions UI: types, options, and conditional visibility (depends_on_*).
 * Form indices are renumbered 0..n-1 so __idx_N matches server merge order.
 */
(function (window) {
    'use strict';

    function rowLabel(row) {
        var t = row.querySelector('.eq-qtext');
        var s = t && t.value ? String(t.value).trim() : '';
        if (s.length > 45) s = s.slice(0, 45) + '…';
        return s || 'Question';
    }

    function listRows(container) {
        return container.querySelectorAll('.eq-question-row');
    }

    function optionValueForPrevRow(prevRow, domIndex) {
        var hid = prevRow.querySelector('input[name*="[id]"]');
        if (hid && hid.value && String(hid.value).trim() !== '') {
            return String(hid.value).trim();
        }
        return '__idx_' + domIndex;
    }

    function findPrevRow(container, depVal) {
        if (!depVal || depVal === '') return null;
        var m = /^__idx_(\d+)$/.exec(String(depVal));
        if (m) {
            var rows = listRows(container);
            var j = parseInt(m[1], 10);
            return rows[j] || null;
        }
        var n = parseInt(depVal, 10);
        if (!isNaN(n) && n > 0) {
            var rows2 = listRows(container);
            for (var r = 0; r < rows2.length; r++) {
                var h = rows2[r].querySelector('input[name*="[id]"]');
                if (h && parseInt(h.value, 10) === n) return rows2[r];
            }
        }
        return null;
    }

    function buildDependsValueOptions(parentRow) {
        var opts = [{ value: '__any__', label: 'Not empty' }];
        if (!parentRow) return opts;
        var tsel = parentRow.querySelector('.question-type-select');
        var pt = tsel ? tsel.value : 'short_text';
        if (pt === 'checkbox') {
            opts.push({ value: 'Yes', label: 'Yes (checked)' });
            return opts;
        }
        if (pt === 'radio' || pt === 'dropdown' || pt === 'multi_checkbox') {
            var inps = parentRow.querySelectorAll('.question-options-list input[name*="[option_label]"]');
            for (var i = 0; i < inps.length; i++) {
                var lab = (inps[i].value || '').trim();
                if (lab !== '') opts.push({ value: lab, label: lab });
            }
            return opts;
        }
        return opts;
    }

    function refreshDependsValueRow(container, row) {
        var wrap = row.querySelector('.eq-depends-value-wrap');
        var vsel = row.querySelector('.eq-depends-value');
        var dsel = row.querySelector('.eq-depends-on');
        if (!wrap || !vsel || !dsel) return;
        var dep = dsel.value;
        if (!dep) {
            wrap.classList.add('hidden');
            wrap.classList.remove('flex');
            vsel.innerHTML = '';
            return;
        }
        var parentRow = findPrevRow(container, dep);
        wrap.classList.remove('hidden');
        wrap.classList.add('flex');
        var cur = vsel.value;
        var options = buildDependsValueOptions(parentRow);
        vsel.innerHTML = '';
        for (var i = 0; i < options.length; i++) {
            var o = document.createElement('option');
            o.value = options[i].value;
            o.textContent = options[i].label;
            vsel.appendChild(o);
        }
        var ok = false;
        for (var j = 0; j < vsel.options.length; j++) {
            if (vsel.options[j].value === cur) {
                ok = true;
                break;
            }
        }
        vsel.value = ok ? cur : (options[0] ? options[0].value : '__any__');
    }

    function refreshAllDependsOn(container) {
        var rows = listRows(container);
        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var dsel = row.querySelector('.eq-depends-on');
            if (!dsel) continue;
            var prevVal = dsel.value;
            dsel.innerHTML = '';
            var none = document.createElement('option');
            none.value = '';
            none.textContent = 'None';
            dsel.appendChild(none);
            for (var j = 0; j < i; j++) {
                var prev = rows[j];
                var opt = document.createElement('option');
                opt.value = optionValueForPrevRow(prev, j);
                opt.textContent = rowLabel(prev);
                dsel.appendChild(opt);
            }
            var still = false;
            for (var k = 0; k < dsel.options.length; k++) {
                if (dsel.options[k].value === prevVal) {
                    still = true;
                    break;
                }
            }
            dsel.value = still ? prevVal : '';
            refreshDependsValueRow(container, row);
        }
    }

    function reindexQuestionRows(container, qIndexRef) {
        var rows = listRows(container);
        rows.forEach(function (row, i) {
            row.querySelectorAll('[name^="questions["]').forEach(function (el) {
                el.name = el.name.replace(/^questions\[\d+\]/, 'questions[' + i + ']');
            });
            var so = row.querySelector('input[name*="[sort_order]"]');
            if (so) so.value = String(i);
            row.setAttribute('data-eq-index', String(i));
        });
        if (qIndexRef) qIndexRef.n = rows.length;
    }

    function addQuestionRow(container, qIndexRef, data) {
        data = data || {};
        var thisQIndex = qIndexRef.n++;
        var row = document.createElement('div');
        row.className = 'border border-gray-200 rounded-lg bg-white p-3 space-y-2 eq-question-row';
        row.setAttribute('data-eq-index', String(thisQIndex));

        var idHtml = '';
        if (data.id) {
            idHtml = '<input type="hidden" name="questions[' + thisQIndex + '][id]" value="' + Number(data.id) + '">';
        }

        row.innerHTML =
            idHtml +
            '<div class="flex flex-wrap items-center gap-2">' +
            '<input type="text" name="questions[' +
            thisQIndex +
            '][question_text]" placeholder="Question text" class="eq-qtext flex-1 min-w-[200px] border border-gray-300 rounded px-3 py-2 text-sm">' +
            '<select name="questions[' +
            thisQIndex +
            '][question_type]" class="question-type-select border border-gray-300 rounded px-3 py-2 text-sm bg-white min-w-[11rem]">' +
            '<option value="short_text">Short text</option><option value="text">Long text</option><option value="number">Number</option><option value="checkbox">Single checkbox (yes/no)</option>' +
            '<option value="radio">Radio (single choice)</option><option value="dropdown">Dropdown</option><option value="multi_checkbox">Checkbox (multiple choices)</option>' +
            '</select>' +
            '<label class="flex items-center gap-1 text-sm"><input type="checkbox" name="questions[' +
            thisQIndex +
            '][is_required]" value="1"> Required</label>' +
            '<input type="hidden" name="questions[' +
            thisQIndex +
            '][sort_order]" value="' +
            (data.sort_order != null ? data.sort_order : thisQIndex) +
            '">' +
            '<button type="button" class="remove-question text-red-600 hover:text-red-800 text-sm">Remove</button>' +
            '</div>' +
            '<div class="question-options-block pl-2 border-l-2 border-indigo-200 hidden">' +
            '<div class="flex items-center justify-between mb-1"><span class="text-xs font-semibold text-indigo-700">Options</span>' +
            '<button type="button" class="add-option-btn text-xs font-bold text-indigo-600 hover:text-indigo-800">+ Add option</button></div>' +
            '<div class="question-options-list space-y-1"></div></div>' +
            '<p class="question-type-hint hidden text-xs text-gray-500 mt-1">Single checkbox: one yes/no field with no options. Add options below to create multiple checkboxes instead.</p>' +
            '<div class="eq-conditional-block mt-2 pt-2 border-t border-gray-100">' +
            '<div class="flex flex-wrap items-center gap-2 text-sm">' +
            '<span class="text-xs font-medium text-gray-500">Show only when</span>' +
            '<select name="questions[' +
            thisQIndex +
            '][depends_on_question_id]" class="eq-depends-on border border-gray-200 rounded-lg px-2 py-1 text-sm bg-white max-w-[14rem]">' +
            '<option value="">None</option></select>' +
            '<span class="eq-depends-value-wrap hidden items-center gap-1 flex-wrap">' +
            '<span class="text-xs text-gray-500">answer is</span>' +
            '<select name="questions[' +
            thisQIndex +
            '][depends_on_value]" class="eq-depends-value border border-gray-200 rounded px-2 py-1 text-sm bg-white max-w-[14rem]"></select>' +
            '</span></div><p class="text-xs text-gray-400 mt-1">Optional: show this question only if a previous answer matches.</p></div>';

        container.appendChild(row);

        var txt = row.querySelector('.eq-qtext');
        if (txt && data.question_text) txt.value = data.question_text;
        var sel = row.querySelector('.question-type-select');
        if (sel && data.question_type) sel.value = data.question_type;
        var req = row.querySelector('input[name*="[is_required]"]');
        if (req && data.is_required) req.checked = true;

        var typeSelect = row.querySelector('.question-type-select');
        var optionsBlock = row.querySelector('.question-options-block');
        var optionsList = row.querySelector('.question-options-list');
        var typeHint = row.querySelector('.question-type-hint');
        var optionCount = 0;

        function toggleOptionsVisibility() {
            var v = typeSelect.value;
            if (v === 'radio' || v === 'dropdown' || v === 'multi_checkbox' || v === 'checkbox') {
                optionsBlock.classList.remove('hidden');
            } else {
                optionsBlock.classList.add('hidden');
            }
            if (typeHint) {
                if (v === 'checkbox') {
                    typeHint.classList.remove('hidden');
                } else {
                    typeHint.classList.add('hidden');
                }
            }
            refreshAllDependsOn(container);
        }

        typeSelect.addEventListener('change', toggleOptionsVisibility);

        row.querySelector('.add-option-btn').addEventListener('click', function () {
            var ri = Array.prototype.indexOf.call(listRows(container), row);
            if (ri < 0) ri = 0;
            var optIdx = optionCount++;
            var optRow = document.createElement('div');
            optRow.className = 'flex items-center gap-2';
            optRow.innerHTML =
                '<input type="text" name="questions[' +
                ri +
                '][options][' +
                optIdx +
                '][option_label]" placeholder="Option label" class="eq-opt-label flex-1 min-w-0 border border-gray-300 rounded px-2 py-1 text-sm">' +
                '<button type="button" class="remove-option text-red-600 hover:text-red-800 text-xs font-bold">Remove</button>';
            optionsList.appendChild(optRow);
            optRow.querySelector('.remove-option').addEventListener('click', function () {
                optRow.remove();
                reindexQuestionRows(container, qIndexRef);
                refreshAllDependsOn(container);
            });
            optRow.querySelector('.eq-opt-label').addEventListener('input', function () {
                refreshAllDependsOn(container);
            });
        });

        if (data.options && data.options.length) {
            data.options.forEach(function (opt) {
                var ri = Array.prototype.indexOf.call(listRows(container), row);
                if (ri < 0) ri = 0;
                var optIdx = optionCount++;
                var optRow = document.createElement('div');
                optRow.className = 'flex items-center gap-2';
                optRow.innerHTML =
                    '<input type="text" name="questions[' +
                    ri +
                    '][options][' +
                    optIdx +
                    '][option_label]" placeholder="Option label" class="eq-opt-label flex-1 min-w-0 border border-gray-300 rounded px-2 py-1 text-sm">' +
                    '<button type="button" class="remove-option text-red-600 hover:text-red-800 text-xs font-bold">Remove</button>';
                optionsList.appendChild(optRow);
                optRow.querySelector('.remove-option').addEventListener('click', function () {
                    optRow.remove();
                    reindexQuestionRows(container, qIndexRef);
                    refreshAllDependsOn(container);
                });
                var inp = optRow.querySelector('input[type="text"]');
                if (inp && opt.option_label) inp.value = opt.option_label;
                optRow.querySelector('.eq-opt-label').addEventListener('input', function () {
                    refreshAllDependsOn(container);
                });
            });
        }

        row.querySelector('.remove-question').addEventListener('click', function () {
            row.remove();
            reindexQuestionRows(container, qIndexRef);
            refreshAllDependsOn(container);
        });

        var dSel = row.querySelector('.eq-depends-on');
        if (dSel) {
            dSel.addEventListener('change', function () {
                refreshDependsValueRow(container, row);
            });
        }

        row.querySelector('.eq-qtext').addEventListener('input', function () {
            refreshAllDependsOn(container);
        });

        toggleOptionsVisibility();
        return row;
    }

    function applyInitialDepends(container, initialRows) {
        if (!initialRows || !initialRows.length) return;
        var rows = listRows(container);
        for (var i = 0; i < rows.length && i < initialRows.length; i++) {
            var q = initialRows[i];
            if (!q) continue;
            var dSel = rows[i].querySelector('.eq-depends-on');
            var vSel = rows[i].querySelector('.eq-depends-value');
            if (q.depends_on_question_id != null && q.depends_on_question_id !== '') {
                var dv = String(q.depends_on_question_id);
                var found = false;
                for (var k = 0; k < dSel.options.length; k++) {
                    if (dSel.options[k].value === dv) {
                        found = true;
                        break;
                    }
                }
                if (found) dSel.value = dv;
                refreshDependsValueRow(container, rows[i]);
                if (vSel && q.depends_on_value != null && q.depends_on_value !== '') {
                    var vv = String(q.depends_on_value);
                    for (var vi = 0; vi < vSel.options.length; vi++) {
                        if (vSel.options[vi].value === vv) {
                            vSel.value = vv;
                            break;
                        }
                    }
                }
            }
        }
    }

    function mount(containerId, options) {
        options = options || {};
        var container = document.getElementById(containerId);
        if (!container) return;

        var qIndexRef = { n: 0 };
        var initial = options.initialRows || [];

        function doAdd(data) {
            addQuestionRow(container, qIndexRef, data);
        }

        var addBtn = document.getElementById(options.addButtonId || 'add-question-btn');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                doAdd(null);
                reindexQuestionRows(container, qIndexRef);
                refreshAllDependsOn(container);
            });
        }

        if (initial.length) {
            initial.forEach(function (q) {
                doAdd(q);
            });
        } else {
            doAdd(null);
        }
        reindexQuestionRows(container, qIndexRef);
        refreshAllDependsOn(container);
        applyInitialDepends(container, initial);
    }

    window.EventCustomQuestions = {
        mount: mount,
        reindexQuestionRows: reindexQuestionRows,
        refreshAllDependsOn: refreshAllDependsOn,
    };
})(window);
