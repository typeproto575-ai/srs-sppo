/* =====================================================================
   SRS-SPPO — SITE-WIDE FRONT-END HELPERS (vanilla JS, no frameworks)
   =====================================================================
   Purpose: Small progressive enhancements loaded last on every page
   (via includes/footer.php / admin-footer.php):
     1. Auto-dismiss flash messages marked with [data-auto-dismiss].
     2. Confirmation prompts for destructive submits marked with
        [data-confirm="message"] on the <form>, or on an individual submit
        button (a button-level message overrides the form's).
      3. Modal encode dialogs ([data-open-dialog], [data-autoshow]).
      4. Record-list filter panel sync + click-outside close.
      5. Admin sidebar collapse preference (localStorage).
   Every enhancement is opt-in via HTML attributes, so pages without
   the attributes are unaffected.
   ===================================================================== */

document.addEventListener('DOMContentLoaded', function () {
    // Surface server-rendered errors to assistive technology.
    document.querySelectorAll('.alert-error').forEach(function (alert) {
        alert.setAttribute('role', 'alert');
        alert.setAttribute('aria-live', 'polite');
    });

    // ---- 1) Flash messages fade away on their own -----------------------
    // Add data-auto-dismiss to an .alert element to enable; the value
    // (if any) is the delay in milliseconds.
    document.querySelectorAll('.alert[data-auto-dismiss]').forEach(function (el) {
        var delay = parseInt(el.getAttribute('data-auto-dismiss'), 10);
        setTimeout(function () { el.remove(); }, isNaN(delay) ? 6000 : delay);
    });

    // ---- 2) Confirm-before-submit for risky forms ------------------------
    // e.g. <form method="post" data-confirm="Delete this record?">
    // A form with several submit buttons can give each button its own message
    // via a button-level data-confirm; buttons without one fall back to the
    // form's message, so single-message forms behave exactly as before.
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            var message = (ev.submitter && ev.submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
            if (!window.confirm(message)) {
                ev.preventDefault(); // user cancelled — stop the submission
            }
        });
    });

    // Avoid duplicate submissions while leaving the review modal's two-step
    // flow in control of its own submit handling. This runs after confirmation
    // handlers so cancelling a confirmation never disables the button.
    document.querySelectorAll('form:not([data-review]):not([data-person-match-widget])').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (ev.defaultPrevented) return;
            var button = ev.submitter || form.querySelector('button[type="submit"], input[type="submit"]');
            if (!button || button.disabled) return;
            if (button.hasAttribute('data-preserve-submit')) return;
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            if (button.tagName === 'INPUT') button.value = 'Submitting…';
            else button.textContent = 'Submitting…';
        });
    });

    // ---- 3) Modal dialogs for the records encode forms -------------------
    // Buttons marked data-open-dialog="#id" pop the dialog up. A dialog
    // rendered with data-autoshow opens itself on load (edit mode or a
    // validation-error re-render). [data-close-dialog] buttons, the Esc
    // key, and clicks on the backdrop all dismiss it.
    document.querySelectorAll('[data-open-dialog]').forEach(function (btn) {
        var dlg = document.querySelector(btn.getAttribute('data-open-dialog'));
        if (!dlg || typeof dlg.showModal !== 'function') return;
        btn.addEventListener('click', function () { dlg.showModal(); });
    });
    document.querySelectorAll('dialog[data-autoshow]').forEach(function (dlg) {
        if (typeof dlg.showModal === 'function') dlg.showModal();
    });
    document.querySelectorAll('dialog [data-close-dialog]').forEach(function (btn) {
        btn.addEventListener('click', function () { btn.closest('dialog').close(); });
    });
    document.querySelectorAll('dialog.encode-dialog').forEach(function (dlg) {
        dlg.addEventListener('click', function (ev) {
            if (ev.target === dlg) dlg.close(); // click landed on the backdrop
        });
    });

    // ---- 4) Record list filter panel (records pages) ---------------------
    // The From/To year inputs only work while "Custom range" is selected;
    // they are disabled and greyed out for every other choice.
    document.querySelectorAll('.filter-panel').forEach(function (panel) {
        var radios = panel.querySelectorAll('input[name="range"]');
        var customBox = panel.querySelector('.filter-custom-dates');
        if (!radios.length || !customBox) return;
        var custom = customBox.querySelectorAll('input');
        var sync = function () {
            var checked = panel.querySelector('input[name="range"]:checked');
            var on = checked !== null && checked.value === 'custom';
            customBox.classList.toggle('is-off', !on);
            custom.forEach(function (el) { el.disabled = !on; });
        };
        radios.forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    });
    // Clicking anywhere outside an open filter panel closes it.
    document.querySelectorAll('details.filter-popup').forEach(function (popup) {
        document.addEventListener('click', function (ev) {
            if (popup.open && !popup.contains(ev.target)) popup.open = false;
        });
    });

    // ---- 5) Baptismal prerequisite finder -------------------------------
    // Search uses the values currently typed in the unsaved record. The
    // hidden ID changes only after the secretary explicitly selects a result.
    document.querySelectorAll('[data-baptismal-finder]').forEach(function (finder) {
        var search = finder.querySelector('[data-baptismal-search]');
        var findButton = finder.querySelector('[data-baptismal-find]');
        var hiddenId = finder.querySelector('[data-baptismal-id]');
        var selection = finder.querySelector('[data-baptismal-selection]');
        var resultsBox = finder.querySelector('[data-baptismal-results]');
        var form = finder.closest('form');
        var personSelect = form ? form.querySelector('[name="person_id"]') : null;
        if (!search || !findButton || !hiddenId || !selection || !resultsBox || !form) return;

        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'btn btn-secondary btn-small baptismal-finder-clear';
        clear.textContent = 'Clear selection';
        clear.hidden = true;
        selection.appendChild(clear);

        function renderSelection(label) {
            selection.textContent = '';
            if (!hiddenId.value) {
                clear.hidden = true;
                selection.appendChild(clear);
                return;
            }
            var text = document.createElement('strong');
            text.textContent = 'Selected: ' + (label || 'Baptismal record') + ' (#' + hiddenId.value + ')';
            selection.appendChild(text);
            selection.appendChild(clear);
            clear.hidden = false;
        }

        function value(name) {
            var input = form.querySelector('[name="' + name + '"]');
            return input ? input.value.trim() : '';
        }

        function composedName(prefix) {
            return [value(prefix + '_first_name'), value(prefix + '_middle_name'), value(prefix + '_last_name'), value(prefix + '_suffix')]
                .filter(Boolean).join(' ');
        }

        function renderResults(records) {
            resultsBox.textContent = '';
            if (!records.length) {
                resultsBox.textContent = 'No possible Baptismal records found.';
                return;
            }
            records.forEach(function (record) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'baptismal-finder-result';
                var name = document.createElement('strong');
                name.textContent = record.child_full_name || 'Unnamed record';
                var details = document.createElement('span');
                var reference = [record.book_no, record.page_no, record.line_no].filter(Boolean).join(' / ') || 'No logbook reference';
                details.textContent = '#' + record.id + ' | Birth: ' + (record.birth_date || 'not recorded') + ' | Baptism: ' + (record.baptism_date || 'not recorded') + ' | Parents: ' + (record.father_name || 'not recorded') + ' / ' + (record.mother_name || record.mother_maiden_name || 'not recorded') + ' | ' + reference;
                button.appendChild(name);
                button.appendChild(details);
                button.addEventListener('click', function () {
                    hiddenId.value = record.id;
                    search.value = record.child_full_name || '';
                    var birthdateInput = form.querySelector('[name="birthdate"]');
                    var birthdateUnknown = form.querySelector('[name="birthdate_not_recorded"]');
                    if (birthdateInput && !birthdateInput.value && record.birth_date) {
                        birthdateInput.value = record.birth_date;
                        if (birthdateUnknown) birthdateUnknown.checked = false;
                    }
                    renderSelection(record.child_full_name);
                    resultsBox.textContent = 'Record selected. Review the details above before saving.';
                });
                resultsBox.appendChild(button);
            });
        }

        findButton.addEventListener('click', function () {
            var params = new URLSearchParams({
                name: composedName('confirmand') || composedName('communicant') || value('confirmand_full_name') || value('communicant_full_name'),
                birth_date: value('birthdate'),
                father_name: value('father_name'),
                mother_name: value('mother_maiden_name'),
                person_id: personSelect ? personSelect.value : ''
            });
            findButton.disabled = true;
            resultsBox.textContent = 'Searching Baptismal records...';
            fetch(findButton.dataset.endpoint + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error('Search failed')); })
                .then(function (data) { renderResults(data.results || []); })
                .catch(function () { resultsBox.textContent = 'The Baptismal record search is unavailable. Please try again.'; })
                .finally(function () { findButton.disabled = false; });
        });
        clear.addEventListener('click', function () {
            hiddenId.value = '';
            search.value = '';
            renderSelection('');
            resultsBox.textContent = '';
        });
        renderSelection('Existing Baptismal record');
    });

    // ---- 6) Manual person identity finder -------------------------------
    document.querySelectorAll('[data-person-finder]').forEach(function (finder) {
        var form = finder.closest('form');
        var search = finder.querySelector('[data-person-search]');
        var findButton = finder.querySelector('[data-person-find]');
        var hiddenId = finder.querySelector('[data-person-id]');
        var birthDateSearch = finder.querySelector('[data-person-birth-date]');
        var selection = finder.querySelector('[data-person-selection]');
        var resultsBox = finder.querySelector('[data-person-results]');
        if (!form || !search || !findButton || !hiddenId || !selection || !resultsBox) return;

        if (finder.dataset.matchWidget === 'true') {
            var createToggle = finder.querySelector('[data-person-create-toggle]');
            var createPanel = finder.querySelector('[data-person-create-panel]');
            var createButton = finder.querySelector('[data-person-create]');
            var linkEndpoint = form.dataset.personLinkEndpoint;
            var latestMatchSearch = 0;
            var clearMatch = document.createElement('button');
            clearMatch.type = 'button';
            clearMatch.className = 'btn btn-secondary btn-small person-finder-clear';
            clearMatch.textContent = 'Clear link';
            clearMatch.hidden = true;

            function matchName(person) {
                return [person.first_name, person.middle_name, person.last_name, person.suffix]
                    .filter(Boolean).join(' ');
            }

            function renderMatchSelection(person, message) {
                selection.textContent = '';
                if (!hiddenId.value) {
                    clearMatch.hidden = true;
                    selection.appendChild(clearMatch);
                    return;
                }
                var name = document.createElement('strong');
                name.textContent = 'Linked: ' + (person ? matchName(person) : search.value) + ' (#' + hiddenId.value + ')';
                var state = document.createElement('span');
                state.textContent = message || 'This application is linked to the master person profile.';
                selection.appendChild(name);
                selection.appendChild(state);
                selection.appendChild(clearMatch);
                clearMatch.hidden = false;
            }

            function showMatchError(message) {
                resultsBox.textContent = message;
                resultsBox.setAttribute('role', 'alert');
            }

            function postMatch(action) {
                var body = new FormData(form);
                body.set(action, '1');
                return fetch(linkEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: body
                }).then(function (response) {
                    return response.json().catch(function () {
                        throw new Error('The server returned an unreadable response.');
                    }).then(function (data) {
                        if (!response.ok) throw new Error(data.error || 'The person could not be linked.');
                        return data;
                    });
                });
            }

            function linkMatch(person) {
                hiddenId.value = person.id;
                findButton.disabled = true;
                resultsBox.textContent = 'Linking person profile...';
                postMatch('link_application_person')
                    .then(function (data) {
                        search.value = matchName(data.person);
                        renderMatchSelection(data.person, data.message);
                        resultsBox.textContent = data.message;
                        resultsBox.removeAttribute('role');
                        if (createPanel) createPanel.hidden = true;
                    })
                    .catch(function (error) {
                        hiddenId.value = '';
                        renderMatchSelection();
                        showMatchError(error.message);
                    })
                    .finally(function () { findButton.disabled = false; });
            }

            function renderMatchResults(people) {
                resultsBox.textContent = '';
                resultsBox.removeAttribute('role');
                if (!people.length) {
                    resultsBox.textContent = 'No matching people found. Check the spelling and birth date before creating a new profile.';
                    return;
                }
                people.forEach(function (person) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'person-finder-result';
                    var name = document.createElement('strong');
                    name.textContent = matchName(person) || 'Unnamed person';
                    var details = document.createElement('span');
                    var parentNames = [person.father_name, person.mother_maiden_name].filter(Boolean).join(' / ');
                    details.textContent = [
                        person.birth_date ? 'Born ' + person.birth_date : '',
                        parentNames ? 'Parents: ' + parentNames : '',
                        person.address || ''
                    ].filter(Boolean).join(' · ');
                    button.appendChild(name);
                    button.appendChild(details);
                    button.addEventListener('click', function () { linkMatch(person); });
                    resultsBox.appendChild(button);
                });
            }

            function searchMatches() {
                var requestNumber = ++latestMatchSearch;
                var query = search.value.trim();
                var birthDate = birthDateSearch ? birthDateSearch.value : '';
                if (!query && !birthDate) {
                    findButton.disabled = false;
                    resultsBox.textContent = 'Enter a surname or date of birth to search.';
                    return;
                }
                var params = new URLSearchParams({ q: query, birth_date: birthDate });
                findButton.disabled = true;
                resultsBox.textContent = 'Searching master persons...';
                fetch(finder.dataset.endpoint + '?' + params.toString(), { credentials: 'same-origin' })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            if (!response.ok) throw new Error(data.error || 'Person search failed.');
                            return data;
                        });
                    })
                    .then(function (data) {
                        if (requestNumber === latestMatchSearch) renderMatchResults(data.results || []);
                    })
                    .catch(function (error) {
                        if (requestNumber === latestMatchSearch) showMatchError(error.message || 'Person search is unavailable.');
                    })
                    .finally(function () {
                        if (requestNumber === latestMatchSearch) findButton.disabled = false;
                    });
            }

            var matchSearchTimer = null;
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                searchMatches();
            });
            findButton.addEventListener('click', searchMatches);
            search.addEventListener('input', function () {
                latestMatchSearch++;
                hiddenId.value = '';
                renderMatchSelection();
                window.clearTimeout(matchSearchTimer);
                matchSearchTimer = window.setTimeout(searchMatches, 300);
            });
            if (birthDateSearch) {
                birthDateSearch.addEventListener('change', function () {
                    latestMatchSearch++;
                    hiddenId.value = '';
                    renderMatchSelection();
                    window.clearTimeout(matchSearchTimer);
                    searchMatches();
                });
            }
            if (createToggle && createPanel) {
                createToggle.addEventListener('click', function () {
                    createPanel.hidden = !createPanel.hidden;
                    if (!createPanel.hidden) {
                        var firstName = createPanel.querySelector('[name="new_first_name"]');
                        if (firstName) firstName.focus();
                    }
                });
            }
            if (createButton && createPanel) {
                createButton.addEventListener('click', function () {
                    if (hiddenId.value) {
                        showMatchError('Clear the current person link before creating a new profile.');
                        return;
                    }
                    if (!createPanel.querySelector('[name="new_first_name"]').reportValidity()
                        || !createPanel.querySelector('[name="new_last_name"]').reportValidity()) return;
                    createButton.disabled = true;
                    resultsBox.textContent = 'Creating and linking person profile...';
                    postMatch('create_person')
                        .then(function (data) {
                            hiddenId.value = data.person.id;
                            search.value = matchName(data.person);
                            renderMatchSelection(data.person, data.message);
                            resultsBox.textContent = data.message;
                            resultsBox.removeAttribute('role');
                            createPanel.hidden = true;
                        })
                        .catch(function (error) { showMatchError(error.message); })
                        .finally(function () { createButton.disabled = false; });
                });
            }
            clearMatch.addEventListener('click', function () {
                latestMatchSearch++;
                hiddenId.value = '';
                search.value = '';
                if (birthDateSearch) birthDateSearch.value = '';
                renderMatchSelection();
                resultsBox.textContent = '';
            });
            renderMatchSelection();
            return;
        }

        var clear = document.createElement('button');
        clear.type = 'button';
        clear.className = 'btn btn-secondary btn-small person-finder-clear';
        clear.textContent = 'Clear selection';
        clear.hidden = true;

        function field(name) {
            var input = form.querySelector('[name="' + name + '"]');
            return input ? input.value.trim() : '';
        }

        function namePrefix() {
            if (finder.dataset.namePrefix) return finder.dataset.namePrefix;
            if (form.querySelector('[name="child_first_name"]')) return 'child';
            if (form.querySelector('[name="confirmand_first_name"]')) return 'confirmand';
            return 'communicant';
        }

        function fillIfBlank(name, value) {
            var input = form.querySelector('[name="' + name + '"]');
            if (input && !input.value.trim() && value) input.value = value;
        }

        function fillFromProfile(name, value) {
            var input = form.querySelector('[name="' + name + '"]');
            if (input && value) input.value = value;
        }

        function structuredParts(fullName) {
            var parts = (fullName || '').trim().split(/\s+/).filter(Boolean);
            var suffix = '';
            if (parts.length && ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'].indexOf(parts[parts.length - 1].toLowerCase()) !== -1) {
                suffix = parts.pop();
            }
            return {
                first: parts.shift() || '',
                middle: parts.length > 1 ? parts.slice(0, -1).join(' ') : '',
                last: parts.length ? parts[parts.length - 1] : '',
                suffix: suffix
            };
        }

        function fillParentName(prefix, fullName) {
            var parts = structuredParts(fullName);
            fillFromProfile(prefix + '_first', parts.first);
            fillFromProfile(prefix + '_middle', parts.middle);
            fillFromProfile(prefix + '_last', parts.last);
            fillFromProfile(prefix + '_suffix', parts.suffix);
        }

        function renderSelection() {
            selection.textContent = '';
            if (!hiddenId.value) {
                clear.hidden = true;
                selection.appendChild(clear);
                return;
            }
            var text = document.createElement('strong');
            text.textContent = 'Selected person ID #' + hiddenId.value;
            selection.appendChild(text);
            selection.appendChild(clear);
            clear.hidden = false;
        }

        function clearStaleSelection() {
            hiddenId.value = '';
            renderSelection();
            resultsBox.textContent = '';
        }

        function renderResults(records) {
            resultsBox.textContent = '';
            if (!records.length) {
                resultsBox.textContent = 'No possible people found.';
                return;
            }
            records.forEach(function (record) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'person-finder-result';
                var name = document.createElement('strong');
                name.textContent = [record.first_name, record.middle_name, record.last_name, record.suffix].filter(Boolean).join(' ') || 'Unnamed person';
                var details = document.createElement('span');
                details.textContent = 'Person ID #' + record.id + (record.maiden_name ? ' | Maiden name: ' + record.maiden_name : '') + (record.birth_date ? ' | Born: ' + record.birth_date : '');
                button.appendChild(name);
                button.appendChild(details);
                button.addEventListener('click', function () {
                    hiddenId.value = record.id;
                    search.value = name.textContent;
                    var prefix = namePrefix();
                    var personName = structuredParts([record.first_name, record.middle_name, record.last_name, record.suffix].filter(Boolean).join(' '));
                    if (prefix === 'wife' && record.maiden_name) personName.last = record.maiden_name;
                    fillFromProfile(prefix + '_first_name', personName.first);
                    fillFromProfile(prefix + '_middle_name', personName.middle);
                    fillFromProfile(prefix + '_last_name', personName.last);
                    fillFromProfile(prefix + '_suffix', personName.suffix);
                    var birthField = finder.dataset.birthField || (prefix === 'child' ? 'birth_date' : 'birthdate');
                    fillFromProfile(birthField, record.birth_date);
                    fillFromProfile(finder.dataset.birthPlaceField || 'child_birthplace', record.birth_place);
                    fillParentName('father', record.father_name);
                    fillParentName('mother', record.mother_maiden_name);
                    var birthdateUnknown = form.querySelector('[name="birthdate_not_recorded"]');
                    if (birthdateUnknown && record.birth_date) birthdateUnknown.checked = false;
                    renderSelection();
                    resultsBox.textContent = 'Person selected. Review the identity before saving.';
                    if (finder.dataset.linkOnSelect === 'true') form.requestSubmit();
                });
                resultsBox.appendChild(button);
            });
        }

        function searchPeople() {
            var prefix = namePrefix();
            var birthField = finder.dataset.birthField || (prefix === 'child' ? 'birth_date' : prefix === 'confirmand' ? 'birthdate' : '');
            var query = search.value.trim();
            var requestedBirthDate = birthDateSearch ? birthDateSearch.value : (birthField ? field(birthField) : '');
            var params = new URLSearchParams({
                first_name: field(prefix + '_first_name'),
                middle_name: field(prefix + '_middle_name'),
                last_name: field(prefix + '_last_name'),
                suffix: field(prefix + '_suffix'),
                birth_date: requestedBirthDate
            });
            if (query) {
                params.set('q', query);
            }
            if (!params.get('first_name') && !params.get('middle_name') && !params.get('last_name') && !params.get('birth_date')) {
                if (!params.get('q')) {
                    resultsBox.textContent = 'Enter a name or birth date before searching.';
                    return;
                }
            }
            findButton.disabled = true;
            resultsBox.textContent = 'Searching possible people...';
            fetch(finder.dataset.endpoint + '?' + params.toString(), { credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error('Search failed')); })
                .then(function (data) { renderResults(data.results || []); })
                .catch(function () { resultsBox.textContent = 'The person search is unavailable. Please try again.'; })
                .finally(function () { findButton.disabled = false; });
            }

            var searchTimer = null;
            findButton.addEventListener('click', searchPeople);
            search.addEventListener('input', function () {
                clearStaleSelection();
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(searchPeople, 300);
            });
            if (birthDateSearch) {
                birthDateSearch.addEventListener('change', function () {
                    clearStaleSelection();
                    window.clearTimeout(searchTimer);
                    searchPeople();
                });
            }

        clear.addEventListener('click', function () {
            hiddenId.value = '';
            search.value = '';
            renderSelection();
            resultsBox.textContent = '';
        });

        ['first_name', 'middle_name', 'last_name', 'suffix'].forEach(function (part) {
            var input = form.querySelector('[name="' + namePrefix() + '_' + part + '"]');
            if (input) input.addEventListener('input', function () {
                clearStaleSelection();
                search.value = '';
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(searchPeople, 600);
            });
        });
        var birthInput = form.querySelector('[name="birth_date"], [name="birthdate"]');
        if (birthInput) birthInput.addEventListener('change', function () {
            clearStaleSelection();
            window.clearTimeout(searchTimer);
            searchPeople();
        });
        renderSelection();
    });

    // ---- 7) Person Mapper search-first assignment -----------------------
    // Mapper rows search identities only after the secretary asks for a
    // lookup. Nothing is assigned until a result is explicitly selected.
    document.querySelectorAll('[data-mapper-person-finder]').forEach(function (finder) {
        var search = finder.querySelector('[data-mapper-person-search]');
        var findButton = finder.querySelector('[data-mapper-person-find]');
        var hiddenId = finder.querySelector('[data-mapper-person-id]');
        var selection = finder.querySelector('[data-mapper-person-selection]');
        var resultsBox = finder.querySelector('[data-mapper-person-results]');
        var assignButton = finder.querySelector('[data-mapper-person-assign]');
        if (!search || !findButton || !hiddenId || !selection || !resultsBox || !assignButton) return;

        function clearSelection() {
            hiddenId.value = '';
            assignButton.disabled = true;
            selection.textContent = '';
        }

        function renderResults(records) {
            resultsBox.textContent = '';
            if (!records.length) {
                resultsBox.textContent = 'No possible people found.';
                return;
            }
            records.forEach(function (record) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'mapper-person-result';
                var name = document.createElement('strong');
                name.textContent = [record.first_name, record.middle_name, record.last_name, record.suffix].filter(Boolean).join(' ') || 'Unnamed person';
                var details = document.createElement('span');
                details.textContent = 'Person ID #' + record.id + (record.maiden_name ? ' | Maiden name: ' + record.maiden_name : '') + (record.birth_date ? ' | Born: ' + record.birth_date : '');
                button.appendChild(name);
                button.appendChild(details);
                button.addEventListener('click', function () {
                    hiddenId.value = record.id;
                    selection.textContent = 'Selected: ' + name.textContent + ' (#' + record.id + ')';
                    assignButton.disabled = false;
                    resultsBox.textContent = 'Person selected. Review the identity before assigning.';
                });
                resultsBox.appendChild(button);
            });
        }

        function searchPeople() {
            var query = search.value.trim();
            clearSelection();
            if (!query) {
                resultsBox.textContent = 'Enter a name, maiden name, or birth date first.';
                return;
            }
            findButton.disabled = true;
            resultsBox.textContent = 'Searching possible people...';
            fetch(findButton.closest('[data-mapper-person-finder]').dataset.endpoint + '?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                .then(function (response) { return response.ok ? response.json() : Promise.reject(new Error('Search failed')); })
                .then(function (data) { renderResults(data.results || []); })
                .catch(function () { resultsBox.textContent = 'The person search is unavailable. Please try again.'; })
                .finally(function () { findButton.disabled = false; });
        }

        findButton.addEventListener('click', searchPeople);
        search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchPeople();
            }
        });
        search.addEventListener('input', function () {
            clearSelection();
            resultsBox.textContent = '';
        });
    });
    // ---- 6) Admin sidebar ---------------------------------------------------
    // Group open state remains server-driven; only the compact rail preference
    // is saved. On narrow layouts the full-width menu is always shown.
    (function () {
        try { window.localStorage.removeItem('sppo-sidebar-open'); }
        catch (err) { /* storage unavailable — nothing to clear */ }

        var layout = document.querySelector('.admin-layout');
        var toggle = document.querySelector('[data-sidebar-toggle]');
        if (!layout || !toggle) return;

        var label = toggle.querySelector('[data-sidebar-toggle-label]');
        var storageKey = 'sppo-sidebar-collapsed';
        var collapsed = false;
        try {
            collapsed = window.localStorage.getItem(storageKey) === 'true';
        } catch (err) {
            // Keep the menu usable when browser storage is disabled.
        }

        function renderSidebar() {
            var compact = collapsed && window.innerWidth > 980;
            layout.classList.toggle('is-sidebar-collapsed', compact);
            toggle.setAttribute('aria-expanded', compact ? 'false' : 'true');
            toggle.setAttribute('aria-label', compact ? 'Show full menu' : 'Hide menu');
            toggle.title = compact ? 'Show full menu' : 'Hide menu';
            if (label) label.textContent = compact ? 'Show menu' : 'Hide menu';
        }

        toggle.addEventListener('click', function () {
            collapsed = !collapsed;
            try {
                window.localStorage.setItem(storageKey, collapsed ? 'true' : 'false');
            } catch (err) {
                // The preference still applies until the page is reloaded.
            }
            renderSidebar();
        });

        window.addEventListener('resize', renderSidebar);
        renderSidebar();
    })();

    // ---- 6) Password show/peek toggles --------------------------------------
    // A button.password-toggle inside .password-wrap flips its sibling
    // <input type="password"> between hidden and visible text. Both eye
    // icons live in the markup; the "off" one starts hidden and swaps with
    // the visible one on each press. Also mirrors the state to
    // aria-pressed so screen readers announce Show/Hide correctly.
    document.querySelectorAll('.password-toggle').forEach(function (btn) {
        var wrap = btn.closest('.password-wrap');
        var input = wrap ? wrap.querySelector('input[type="password"], input[type="text"]') : null;
        if (!input) return; // malformed markup — leave it alone

        btn.addEventListener('click', function () {
            var currentlyVisible = input.type === 'text';
            var nextVisible = !currentlyVisible;
            input.type = nextVisible ? 'text' : 'password';
            btn.setAttribute('aria-pressed', nextVisible ? 'true' : 'false');
            btn.setAttribute('aria-label', nextVisible ? 'Hide password' : 'Show password');
            btn.classList.toggle('is-showing', nextVisible);

            var toggleText = btn.querySelector('.password-toggle-text');
            if (toggleText) {
                toggleText.textContent = nextVisible ? 'Hide' : 'Show';
            }

            var eyeOn = btn.querySelector('.eye-on');
            var eyeOff = btn.querySelector('.eye-off');
            if (eyeOn) eyeOn.hidden = nextVisible;
            if (eyeOff) eyeOff.hidden = !nextVisible;
            // Keep the caret where the user was typing.
            input.focus({ preventScroll: true });
        });
    });

    // ---- 7) Admin notification bell ----------------------------------------
    // Global notifications stream (see admin/api/notifications.php):
    //   - badge shows unread notifications only, and the element is added or
    //     removed outright (never rendered as a zero);
    //   - reminders for today/tomorrow still appear in the panel but do not
    //     keep the red dot lit on their own;
    //   - the panel lists events first, then the 10 newest notifications;
    //   - clicking an item marks it read and opens its link;
    //   - re-polls every 60s, but only while the tab is visible.
    (function () {
        var wrap = document.querySelector('[data-notif-wrap]');
        if (!wrap) return; // public pages have no bell

        var api    = wrap.getAttribute('data-notif-api');
        var csrf   = wrap.getAttribute('data-csrf') || '';
        var bell   = wrap.querySelector('[data-notif-bell]');
        var panel  = wrap.querySelector('[data-notif-panel]');
        var badge  = null; // created on demand; absent from the DOM at count 0
        var list   = wrap.querySelector('[data-notif-list]');
        var empty  = wrap.querySelector('[data-notif-empty]');
        var markAll = wrap.querySelector('[data-notif-mark-all]');
        if (!bell || !panel || !list || !api) return;

        // "3m ago"-style labels; falls back to the raw date for old items.
        function timeAgo(iso) {
            var then = new Date(iso).getTime();
            if (isNaN(then)) return '';
            var secs = Math.max(0, Math.floor((Date.now() - then) / 1000));
            if (secs < 60) return 'just now';
            if (secs < 3600) return Math.floor(secs / 60) + 'm ago';
            if (secs < 86400) return Math.floor(secs / 3600) + 'h ago';
            if (secs < 7 * 86400) return Math.floor(secs / 86400) + 'd ago';
            return new Date(iso).toLocaleDateString();
        }

        function render(data) {
            // The badge is added and removed, never merely hidden: it must not
            // exist in the DOM at all while the count is zero. A `hidden`
            // attribute alone cannot do this — .notif-badge sets
            // `display: inline-flex`, and an author declaration overrides the
            // UA stylesheet's [hidden] { display: none }.
            var count = data.unread_count || 0;
            if (count > 0) {
                if (!badge || !badge.isConnected) {
                    badge = document.createElement('span');
                    badge.className = 'notif-badge';
                    badge.setAttribute('data-notif-badge', '');
                    bell.appendChild(badge);
                }
                badge.textContent = count > 99 ? '99+' : String(count);
            } else if (badge) {
                badge.remove();
                badge = null;
            }

            var items = data.items || [];
            list.innerHTML = '';
            items.forEach(function (item) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.className = 'notif-item notif-type-' + item.type + (item.is_read ? '' : ' is-unread');
                a.href = item.link;

                var title = document.createElement('span');
                title.className = 'notif-item-title';
                title.textContent = item.title;

                var msg = document.createElement('span');
                msg.className = 'notif-item-msg';
                msg.textContent = item.message;

                var time = document.createElement('span');
                time.className = 'notif-item-time';
                time.textContent = timeAgo(item.created_at);

                a.appendChild(title);
                a.appendChild(msg);
                a.appendChild(time);
                li.appendChild(a);
                list.appendChild(li);

                // Mark read before navigation so the browser does not cancel
                // the request when it leaves the current page.
                if (item.kind === 'notif' && !item.is_read) {
                    a.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        markRead('mark_read', item.id, function () {
                            window.location.href = item.link;
                        });
                    });
                }
            });

            empty.hidden = items.length > 0;
        }

        function apply(data) {
            if (!data || data.error) return;
            render(data);
        }

        function fetchNotifications() {
            // Skip polling in hidden tabs — the badge refreshes the moment
            // the user returns anyway (visibilitychange below).
            if (document.hidden) return;
            fetch(api, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(apply)
                .catch(function () { /* offline or logged out — badge just stays stale */ });
        }

        function markRead(action, id, after) {
            var body = new URLSearchParams({ action: action, csrf_token: csrf });
            if (id) body.append('id', id);
            fetch(api, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    apply(data);
                    if (after) after();
                })
                .catch(function () {
                    if (after) after();
                });
        }

        bell.addEventListener('click', function () {
            var open = panel.hidden;
            panel.hidden = !open;
            bell.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) fetchNotifications();
        });

        // Click outside closes the panel (standard dropdown behaviour).
        document.addEventListener('click', function (ev) {
            if (!panel.hidden && !wrap.contains(ev.target)) {
                panel.hidden = true;
                bell.setAttribute('aria-expanded', 'false');
            }
        });

        if (markAll) {
            markAll.addEventListener('click', function (ev) {
                ev.stopPropagation();
                markRead('mark_all_read', null);
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !panel.hidden) fetchNotifications();
        });

        fetchNotifications();
        window.setInterval(fetchNotifications, 60000);
    })();

    // ---- 8) Carousels (announcements + upcoming events) ---------------------
    // Dependency-free slider: flex track + translateX, arrows, dot nav,
    // touch swipe, optional autoplay that pauses on hover/focus and honors
    // the user's reduced-motion preference. Opt-in via [data-carousel].
        document.querySelectorAll('[data-carousel]').forEach(function (root) {
        var track = root.querySelector('.carousel-track');
        var slides = track ? track.querySelectorAll('.slide') : [];
        var prevBtn = root.querySelector('[data-carousel-prev]');
        var nextBtn = root.querySelector('[data-carousel-next]');
        var dotsWrap = root.querySelector('[data-carousel-dots]');
        if (!track || slides.length === 0) return;

        // A single slide needs no controls at all.
        if (slides.length < 2) {
            if (prevBtn) prevBtn.hidden = true;
            if (nextBtn) nextBtn.hidden = true;
            if (dotsWrap) dotsWrap.hidden = true;
            return;
        }

        var index = 0;
        var timer = null;
        var dots = []; // one dot per slide
        var autoplayMs = 7000; // enough time to read the stacked cards
        var reducedMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var autoplay = autoplayMs > 0 && !reducedMotion;

        // Move the track horizontally by whole slides and sync the dots.
        function applyPosition() {
            track.style.transform = 'translateX(-' + (index * 100) + '%)';
            dots.forEach(function (dot, i) {
                dot.classList.toggle('is-active', i === index);
            });
        }

        function goTo(n) {
            // Circular wrap: next from the last goes to the first, and vice versa.
            index = (n + slides.length) % slides.length;
            applyPosition();
            restartAutoplay();
        }

        function stopAutoplay() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        function restartAutoplay() {
            stopAutoplay();
            if (autoplay) timer = setInterval(function () { goTo(index + 1); }, autoplayMs);
        }

        // Build one dot per slide; clicking jumps straight to that slide.
        if (dotsWrap) {
            for (var i = 0; i < slides.length; i++) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'carousel-dot' + (i === 0 ? ' is-active' : '');
                dot.setAttribute('aria-label', 'Show batch ' + (i + 1) + ' of ' + slides.length);
                dot.addEventListener('click', (function (n) {
                    return function () { goTo(n); };
                })(i));
                dotsWrap.appendChild(dot);
                dots.push(dot);
            }
        }

        if (prevBtn) prevBtn.addEventListener('click', function () { goTo(index - 1); });
        if (nextBtn) nextBtn.addEventListener('click', function () { goTo(index + 1); });

        applyPosition();
        restartAutoplay();

        // Pause while the visitor is reading (hover) or navigating by keyboard.
        root.addEventListener('mouseenter', stopAutoplay);
        root.addEventListener('mouseleave', restartAutoplay);
        root.addEventListener('focusin', stopAutoplay);
        root.addEventListener('focusout', restartAutoplay);

        // Touch swipe: a 40px+ horizontal drag flips the slide.
        var touchStartX = null;
        root.addEventListener('touchstart', function (ev) {
            touchStartX = ev.touches[0].clientX;
            stopAutoplay();
        }, { passive: true });
        root.addEventListener('touchend', function (ev) {
            if (touchStartX === null) return;
            var dx = ev.changedTouches[0].clientX - touchStartX;
            if (Math.abs(dx) > 40) goTo(index + (dx < 0 ? 1 : -1));
            touchStartX = null;
            restartAutoplay();
        }, { passive: true });
    });

    // ---- Review-before-submit for public forms ---------------------------
    // Add data-review to a public request/application form. When the visitor
    // submits it (after the browser's own required-field checks pass), a
    // summary dialog lists everything they entered, grouped by fieldset.
    // "Go back and edit" closes the dialog with nothing sent; "Confirm and
    // submit" sends the form. Optional attributes on the <form>:
    //   data-review-title="..."    heading of the dialog
    //   data-review-confirm="..."  text of the confirm button
    // If <dialog> is unsupported the form simply submits as before, and the
    // server still validates everything, so this is a convenience layer only.
    document.querySelectorAll('form[data-review], form[data-review-form]').forEach(function (form) {
        if (typeof HTMLDialogElement === 'undefined') return;

        // Controls that are never shown in the summary.
        var SKIP_NAMES = { terms_accepted: true, privacy_consent: true };

        function cleanLabel(text) {
            return text.replace(/\s+/g, ' ').replace(/\s*\*\s*$/, '').trim();
        }

        // The visible question for a control: the text of its wrapping
        // <label> (or label[for]) minus the control itself and any hints.
        function labelFor(control) {
            var label = control.closest('label');
            if (!label && control.id) label = form.querySelector('label[for="' + control.id + '"]');
            if (!label) return cleanLabel(control.getAttribute('aria-label') || control.name || '');
            var clone = label.cloneNode(true);
            clone.querySelectorAll('input, select, textarea, .field-hint, small').forEach(function (n) { n.remove(); });
            return cleanLabel(clone.textContent);
        }

        function formatDate(value) {
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
            if (!m) return value;
            var d = new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
            return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        }

        function formatTime(value) {
            var m = /^(\d{2}):(\d{2})/.exec(value);
            if (!m) return value;
            var h = parseInt(m[1], 10);
            return ((h % 12) || 12) + ':' + m[2] + ' ' + (h < 12 ? 'AM' : 'PM');
        }

        // Returns { label, value } for a control, or null when it should be
        // left out (empty, hidden, button, unchecked, or on the skip list).
        function describe(control) {
            var type = (control.type || '').toLowerCase();
            if (!control.name || control.disabled) return null;
            if (type === 'hidden' || type === 'submit' || type === 'button' || type === 'reset' || type === 'file') return null;
            if (SKIP_NAMES[control.name]) return null;

            var value = '';
            var label = labelFor(control);
            if (control.tagName === 'SELECT') {
                var opt = control.options[control.selectedIndex];
                value = opt && opt.value !== '' ? opt.text.trim() : '';
            } else if (type === 'checkbox') {
                value = control.checked ? 'Yes' : '';
            } else if (type === 'radio') {
                if (!control.checked) return null;
                var group = control.closest('fieldset');
                var legend = group ? group.querySelector('legend') : null;
                value = label;
                label = legend ? cleanLabel(legend.textContent) : cleanLabel(control.name);
            } else if (type === 'date') {
                value = formatDate(control.value);
            } else if (type === 'time') {
                value = formatTime(control.value);
            } else {
                value = control.value.trim();
            }
            if (value === '') {
                // Soft nudge: inputs the admin marked data-nudge are shown as
                // an em-dash row instead of being skipped. Everything else
                // keeps the old behavior (omitted when empty).
                if (control.hasAttribute && control.hasAttribute('data-nudge')) {
                    return { label: label, value: '\u2014', empty: true };
                }
                return null;
            }
            // Repeated fields (e.g. godfather_name[]) get their row number so
            // "Godfather Name 3" and "Residence 3" stay matched up.
            if (control.name.slice(-2) === '[]') {
                var same = Array.prototype.filter.call(form.elements, function (c) { return c.name === control.name; });
                if (same.length > 1) label += ' ' + (same.indexOf(control) + 1);
            }
            return { label: label, value: value };
        }

        // ---- Build the dialog once; it is refilled on every submit --------
        var dlg = document.createElement('dialog');
        dlg.className = 'review-dialog';
        var titleId = 'review-dialog-title-' + Math.random().toString(36).slice(2);
        dlg.setAttribute('aria-labelledby', titleId);

        var inner = document.createElement('div');
        inner.className = 'review-dialog-inner';

        var head = document.createElement('div');
        head.className = 'review-head';
        var title = document.createElement('h2');
        title.id = titleId;
        title.textContent = form.getAttribute('data-review-title') || 'Please check your details';
        var lead = document.createElement('p');
        lead.textContent = 'Read through what you entered. Nothing is sent until you confirm.';
        head.appendChild(title);
        head.appendChild(lead);

        var body = document.createElement('div');
        body.className = 'review-body';

        var actions = document.createElement('div');
        actions.className = 'review-actions';
        var backBtn = document.createElement('button');
        backBtn.type = 'button';
        backBtn.className = 'btn btn-secondary';
        backBtn.textContent = 'Go back and edit';
        var confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'btn';
        var confirmText = form.getAttribute('data-review-confirm') || 'Confirm and submit';
        confirmBtn.textContent = confirmText;
        actions.appendChild(backBtn);
        actions.appendChild(confirmBtn);

        inner.appendChild(head);
        inner.appendChild(body);
        inner.appendChild(actions);
        dlg.appendChild(inner);
        document.body.appendChild(dlg);

        function fillSummary() {
            body.textContent = '';
            var section = null, list = null, currentGroup = undefined;
            Array.prototype.forEach.call(form.elements, function (control) {
                var item = describe(control);
                if (!item) return;
                var group = control.closest('fieldset');
                if (section === null || group !== currentGroup) {
                    currentGroup = group;
                    section = document.createElement('section');
                    section.className = 'review-section';
                    var legend = group ? group.querySelector('legend') : null;
                    if (legend) {
                        var h = document.createElement('h3');
                        h.textContent = cleanLabel(legend.textContent);
                        section.appendChild(h);
                    }
                    list = document.createElement('dl');
                    list.className = 'review-list';
                    section.appendChild(list);
                    body.appendChild(section);
                }
                var dt = document.createElement('dt');
                dt.textContent = item.label;
                var dd = document.createElement('dd');
                dd.textContent = item.value; // textContent only: never inject user input as HTML
                if (item.empty) dd.className = 'is-empty-nudge';
                list.appendChild(dt);
                list.appendChild(dd);
            });
        }

        form.addEventListener('submit', function (ev) {
            if (form.getAttribute('data-reviewed') === '1') return; // already confirmed
            ev.preventDefault();
            form._reviewSubmitter = ev.submitter || null;
            fillSummary();
            confirmBtn.disabled = false;
            confirmBtn.textContent = confirmText;
            body.scrollTop = 0;
            dlg.showModal();
        });

        backBtn.addEventListener('click', function () { dlg.close(); });
        dlg.addEventListener('click', function (ev) {
            if (ev.target === dlg) dlg.close(); // click landed on the backdrop
        });

        confirmBtn.addEventListener('click', function () {
            // Lock the button so a double-click cannot send two requests
            // (the server also caps submissions per email address per day).
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Submitting…';
            form.setAttribute('data-reviewed', '1');
            if (form._reviewSubmitter && typeof form.requestSubmit === 'function') {
                form.requestSubmit(form._reviewSubmitter);
            } else {
                form.submit();
            }
        });

        // Coming back to the page with the Back button (bfcache) should not
        // leave the form stuck in its "already confirmed" state.
        window.addEventListener('pageshow', function (ev) {
            if (ev.persisted) {
                form.removeAttribute('data-reviewed');
                confirmBtn.disabled = false;
                confirmBtn.textContent = confirmText;
                if (dlg.open) dlg.close();
            }
        });
    });

    // ---- Show a field only while another field has a given value ---------
    // <label data-show-if="purpose=other"> is shown (and submitted) only while
    // the control named "purpose" in the same form has the value "other".
    // Without JavaScript the field simply stays visible.
    document.querySelectorAll('[data-show-if]').forEach(function (el) {
        var parts = el.getAttribute('data-show-if').split('=');
        var form = el.closest('form');
        var control = form && form.elements[parts[0]];
        if (!control) return;
        function sync() {
            var show = control.value === parts[1];
            el.hidden = !show;
            el.querySelectorAll('input, select, textarea').forEach(function (i) {
                i.disabled = !show;
                i.required = show;
            });
        }
        control.addEventListener('change', sync);
        sync();
    });

    // ---- 9) Repeatable "Add another" rows (sponsor/witness sets) ------
    // [data-repeat-group] wraps the .godparent-row rows of one repeatable
    // set (baptismal godparents, marriage sponsors/witnesses, …); the
    // [data-repeat-add] button (matched by data-repeat-add="id")
    // clones a blanked copy of the group's first row, and the
    // [data-repeat-remove] link on every row deletes it again. The first
    // row can never be removed (its link stays hidden), and the Add button
    // hides itself once the optional data-repeat-max row count is reached.
    // Without JavaScript the server-rendered rows simply submit as before.
    document.querySelectorAll('[data-repeat-group]').forEach(function (group) {
        var template = group.querySelector('.godparent-row');
        if (!template) return;
        var addButton = document.querySelector('[data-repeat-add="' + group.getAttribute('data-repeat-group') + '"]');

        function refresh() {
            var rows = group.querySelectorAll('.godparent-row');
            rows.forEach(function (row, idx) {
                var removeBtn = row.querySelector('[data-repeat-remove]');
                if (removeBtn) removeBtn.hidden = idx === 0;
            });
            if (addButton) {
                var max = parseInt(group.getAttribute('data-repeat-max'), 10);
                addButton.hidden = !isNaN(max) && rows.length >= max;
            }
        }

        if (addButton) {
            addButton.addEventListener('click', function () {
                var clone = template.cloneNode(true);
                clone.querySelectorAll('input, select, textarea').forEach(function (input) {
                    input.value = '';
                });
                group.appendChild(clone);
                refresh();
                var firstInput = clone.querySelector('input, select, textarea');
                if (firstInput) firstInput.focus();
            });
        }

        group.addEventListener('click', function (ev) {
            var removeBtn = ev.target.closest('[data-repeat-remove]');
            if (!removeBtn) return;
            var row = removeBtn.closest('.godparent-row');
            if (row) row.remove();
            refresh();
        });

        refresh();
    });

    // ---- 10) "(?)" help toggles -------------------------------------------
    // A button.help-btn shows/hides the .help-text panel named by its
    // aria-controls (see the .help-btn rules in assets/css/style.css). A
    // single setup block wires up every .help-btn on the page, so the
    // component is reusable anywhere by dropping in the two elements.
    var helpButtons = document.querySelectorAll('.help-btn[aria-controls]');
    if (helpButtons.length) {
        // The button usually lives inside the paragraph above the panel, so
        // it is looked up by aria-controls instead of by sibling order.
        var closeHelp = function (panel) {
            panel.hidden = true;
            var owner = document.querySelector('.help-btn[aria-controls="' + panel.id + '"]');
            if (owner) owner.setAttribute('aria-expanded', 'false');
            return owner;
        };
        helpButtons.forEach(function (btn) {
            var panel = document.getElementById(btn.getAttribute('aria-controls'));
            if (!panel) return;
            btn.addEventListener('click', function () {
                var willOpen = panel.hidden;
                // Only one panel is open at a time, so long help text never
                // stacks up and pushes the action buttons out of view.
                document.querySelectorAll('.help-text:not([hidden])').forEach(function (open) {
                    if (open !== panel) closeHelp(open);
                });
                panel.hidden = !willOpen;
                btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        });
        // Esc dismisses any open help panel and hands focus back to its button.
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') return;
            document.querySelectorAll('.help-text:not([hidden])').forEach(function (panel) {
                var owner = closeHelp(panel);
                if (owner) owner.focus();
            });
        });
    }

    // ---- 11) Phased walk-in request/application forms --------------------
    document.querySelectorAll('form.walk-in-wizard[data-walk-in-kind]').forEach(function (form) {
        var kind = form.getAttribute('data-walk-in-kind');
        var isRequest = kind === 'request';
        var phaseConfig = isRequest
            ? [
                { key: 'type', label: 'Request type' },
                { key: 'record', label: 'Find the record' },
                { key: 'contact', label: 'Requester details' },
                { key: 'wrap-up', label: 'Purpose & payment' },
                { key: 'review', label: 'Review' }
            ]
            : [
                { key: 'type', label: 'Application type' },
                { key: 'details', label: 'Applicant details' },
                { key: 'wrap-up', label: 'Notes & payment' },
                { key: 'review', label: 'Review' }
            ];
        var currentInput = form.querySelector('[data-walk-in-current-step]');
        var submitButton = form.querySelector('[data-walk-in-submit]');
        var typeSelect = form.querySelector('#sacrament_type');
        if (!currentInput || !submitButton || !typeSelect) return;
        form.querySelectorAll('button[type="submit"][name]').forEach(function (button) {
            button.setAttribute('data-preserve-submit', '');
        });

        var wizard = document.createElement('div');
        wizard.className = 'walk-in-wizard-shell';
        var progress = document.createElement('ol');
        progress.className = 'walk-in-progress';
        progress.setAttribute('aria-label', isRequest ? 'Request progress' : 'Application progress');
        var status = document.createElement('p');
        status.className = 'walk-in-phase-status';
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('aria-atomic', 'true');
        var alert = document.createElement('p');
        alert.className = 'walk-in-phase-error';
        alert.setAttribute('role', 'alert');
        alert.hidden = true;

        var panels = phaseConfig.map(function (phase) {
            var panel = document.createElement('section');
            panel.className = 'walk-in-wizard-panel';
            panel.dataset.phase = phase.key;
            panel.setAttribute('aria-label', phase.label);
            wizard.appendChild(panel);
            return panel;
        });
        var phaseNodes = form.querySelectorAll('[data-walk-in-phase]');
        phaseNodes.forEach(function (node) {
            var panel = panels.find(function (item) {
                return item.dataset.phase === node.getAttribute('data-walk-in-phase');
            });
            if (panel) panel.appendChild(node);
        });

        var reviewPanel = panels[panels.length - 1];
        var reviewHeading = document.createElement('h2');
        reviewHeading.className = 'walk-in-review-heading';
        reviewHeading.textContent = 'Check the details before saving';
        var reviewList = document.createElement('dl');
        reviewList.className = 'walk-in-review-list';
        reviewPanel.append(reviewHeading, reviewList);

        var controls = document.createElement('div');
        controls.className = 'walk-in-wizard-actions';
        var backButton = document.createElement('button');
        backButton.type = 'button';
        backButton.className = 'btn btn-secondary';
        backButton.textContent = 'Back';
        var nextButton = document.createElement('button');
        nextButton.type = 'button';
        nextButton.className = 'btn';
        nextButton.textContent = 'Continue';
        controls.append(backButton, nextButton);
        wizard.insertBefore(progress, wizard.firstChild);
        wizard.insertBefore(status, progress.nextSibling);
        wizard.insertBefore(alert, status.nextSibling);
        wizard.appendChild(controls);
        controls.appendChild(submitButton);
        form.appendChild(wizard);
        form.noValidate = true;

        phaseConfig.forEach(function (phase, index) {
            var item = document.createElement('li');
            item.dataset.step = String(index + 1);
            var number = document.createElement('span');
            number.textContent = String(index + 1);
            var label = document.createElement('span');
            label.textContent = phase.label;
            item.append(number, label);
            progress.appendChild(item);
        });

        function activeSacramentSection() {
            return form.querySelector('#section-' + typeSelect.value);
        }

        function fieldValue(field) {
            if (field.type === 'checkbox') return field.checked ? 'Yes' : 'No';
            if (field.type === 'radio') return field.checked ? field.value : '';
            if (field.tagName === 'SELECT') {
                var option = field.options[field.selectedIndex];
                return option && option.value ? option.textContent.trim() : '';
            }
            return field.value.trim();
        }

        function updateReview() {
            reviewList.textContent = '';
            var controlsInForm = form.querySelectorAll('input, select, textarea');
            controlsInForm.forEach(function (field) {
                if (field.type === 'hidden' || field.type === 'submit' || field.type === 'button'
                    || field.disabled || field.closest('[data-phase="review"]')) return;
                var sacramentSection = field.closest('.sacrament-section');
                if (sacramentSection && sacramentSection.id !== 'section-' + typeSelect.value) return;
                if (field.type === 'radio' && !field.checked) return;
                var value = fieldValue(field);
                if (!value) return;
                var label = field.closest('label');
                var labelText = label ? label.childNodes[0].textContent.trim().replace(/\s*\*$/, '') : '';
                if (!labelText && field.name) labelText = field.name.replace(/[_\[\]]+/g, ' ').trim();
                if (!labelText) return;
                var term = document.createElement('dt');
                var detail = document.createElement('dd');
                term.textContent = labelText;
                detail.textContent = value;
                reviewList.append(term, detail);
            });
            if (!reviewList.children.length) {
                var empty = document.createElement('dd');
                empty.textContent = 'No details have been entered yet.';
                reviewList.appendChild(empty);
            }
        }

        function validateCurrent(index) {
            var type = typeSelect.value;
            var errors = [];
            var focusTarget = null;
            function requireValue(name, label) {
                var activeSection = activeSacramentSection();
                var field = activeSection && phaseConfig[index].key === 'details'
                    ? activeSection.querySelector('[name="' + name + '"]')
                    : form.querySelector('[name="' + name + '"]');
                if (!field || field.disabled) return;
                if (!field.value.trim()) {
                    errors.push(label);
                    focusTarget = focusTarget || field;
                }
            }

            if (phaseConfig[index].key === 'type') {
                if (!type) {
                    errors.push('Choose a sacrament type.');
                    focusTarget = typeSelect;
                }
            } else if (isRequest && phaseConfig[index].key === 'record') {
                var prefixes = {
                    baptismal: { prefix: 'bap', required: [['bap_child_full_name', "Child's full name"], ['bap_father_full_name', "Father's full name"], ['bap_mother_full_name', "Mother's full name"], ['bap_birthdate', 'Birthdate']] },
                    confirmation: { prefix: 'conf', required: [['conf_confirmand_full_name', "Confirmand's full name"], ['conf_father_full_name', "Father's full name"]] },
                    first_communion: { prefix: 'fc', required: [['fc_communicant_full_name', "Communicant's full name"], ['fc_father_full_name', "Father's full name"]] },
                    marriage: { prefix: 'mar', required: [['party_a_full_name', "Party A's full name"], ['party_b_full_name', "Party B's full name"]] }
                };
                var record = prefixes[type];
                if (record && !form.querySelector('[name="' + record.prefix + '_matched_id"]')) {
                    errors.push('Search the register or choose “No match — continue manually” before continuing.');
                } else if (record) {
                    record.required.forEach(function (rule) { requireValue(rule[0], rule[1]); });
                    if (type === 'confirmation') requireValue('conf_mother_full_name', "Mother's full name");
                    if (type === 'first_communion') requireValue('fc_mother_full_name', "Mother's full name");
                }
            } else if (!isRequest && phaseConfig[index].key === 'details') {
                var fields = type === 'baptismal'
                    ? [['child_full_name', "Child's full name"], ['child_birthdate', 'Birthdate'], ['child_birthplace', 'Birthplace'], ['parent_guardian_name', 'Parent/guardian name'], ['email', 'Email']]
                    : (type === 'confirmation' || type === 'first_communion')
                        ? [['applicant_full_name', "Applicant's full name"], ['applicant_birthdate', 'Birthdate'], ['parent_guardian_name', 'Parent/guardian name'], ['email', 'Email']]
                        : [['groom_full_name', "Groom's full name"], ['groom_birthdate', "Groom's birthdate"], ['bride_full_name', "Bride's full name"], ['bride_birthdate', "Bride's birthdate"]];
                fields.forEach(function (rule) { requireValue(rule[0], rule[1]); });
            } else if (isRequest && phaseConfig[index].key === 'contact') {
                [['requester_name', "Requester's name"], ['requester_relationship', 'Relationship'], ['requester_email', 'Email address']]
                    .forEach(function (rule) { requireValue(rule[0], rule[1]); });
            } else if (isRequest && phaseConfig[index].key === 'wrap-up') {
                requireValue('purpose', 'Purpose');
            }

            alert.hidden = errors.length === 0;
            alert.textContent = errors.length ? 'Please complete: ' + errors.join(', ') + '.' : '';
            if (focusTarget) focusTarget.focus();
            return errors.length === 0;
        }

        function showStep(step, focus) {
            var index = Math.max(0, Math.min(phaseConfig.length - 1, step - 1));
            currentInput.value = String(index + 1);
            panels.forEach(function (panel, panelIndex) {
                panel.hidden = panelIndex !== index;
            });
            Array.from(progress.children).forEach(function (item, itemIndex) {
                item.classList.toggle('is-current', itemIndex === index);
                item.classList.toggle('is-done', itemIndex < index);
                if (itemIndex === index) item.setAttribute('aria-current', 'step');
                else item.removeAttribute('aria-current');
            });
            status.textContent = 'Phase ' + (index + 1) + ' of ' + phaseConfig.length + ' — ' + phaseConfig[index].label;
            backButton.hidden = index === 0;
            nextButton.hidden = index === phaseConfig.length - 1;
            submitButton.hidden = index !== phaseConfig.length - 1;
            alert.hidden = true;
            if (index === phaseConfig.length - 1) updateReview();
            if (focus) panels[index].querySelector('input:not([type="hidden"]), select, textarea, button')?.focus();
        }

        nextButton.addEventListener('click', function () {
            var index = Number(currentInput.value) - 1;
            if (validateCurrent(index)) showStep(index + 2, true);
        });
        backButton.addEventListener('click', function () {
            showStep(Number(currentInput.value) - 1, true);
        });
        typeSelect.addEventListener('change', function () {
            if (Number(currentInput.value) > 1 && phaseConfig[Number(currentInput.value) - 1].key === 'type') {
                var section = activeSacramentSection();
                if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
        form.addEventListener('submit', function (event) {
            var submitter = event.submitter;
            if (submitter && (/_(step|use_record)$/.test(submitter.name))) {
                currentInput.value = String(phaseConfig.findIndex(function (phase) { return phase.key === 'record'; }) + 1);
                return;
            }
            if (Number(currentInput.value) !== phaseConfig.length) {
                event.preventDefault();
                return;
            }
            if (submitter === submitButton && !validateCurrent(phaseConfig.length - 2)) {
                event.preventDefault();
            }
        }, true);
        showStep(currentInput.value || '1');
    });

});
