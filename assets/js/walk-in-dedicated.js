function initWalkInDedicatedForms() {
    'use strict';

    document.querySelectorAll('form.walk-in-dedicated').forEach(function (form) {
        var isRequest = form.getAttribute('data-walk-in-dedicated') === 'request';
        var steps = isRequest
            ? [
                { key: 'type', label: 'Certificate type' },
                { key: 'subject', label: 'Certificate subject' },
                { key: 'requester', label: 'Requester details' },
                { key: 'review', label: 'Review & save' }
            ]
            : [
                { key: 'type', label: 'Sacrament type' },
                { key: 'person', label: 'Person details' },
                { key: 'contact', label: 'Applicant details' },
                { key: 'review', label: 'Review & save' }
            ];
        var current = form.querySelector('[data-dedicated-step]');
        var submit = form.querySelector('[data-dedicated-submit]')
            || document.querySelector('[form="' + form.id + '"][data-dedicated-submit]');
        if (!current || !submit) return;

        var shell = document.createElement('div');
        shell.className = 'walk-in-dedicated-shell';

        var progress = document.createElement('ol');
        progress.className = 'walk-in-dedicated-progress';
        progress.setAttribute('aria-label', isRequest ? 'Certificate request progress' : 'Pre-application progress');

        var status = document.createElement('p');
        status.className = 'walk-in-dedicated-status';
        status.setAttribute('aria-live', 'polite');

        var alert = document.createElement('p');
        alert.className = 'walk-in-dedicated-error';
        alert.setAttribute('role', 'alert');
        alert.hidden = true;

        var panels = {};
        steps.forEach(function (step, index) {
            var panel = document.createElement('section');
            panel.className = 'walk-in-dedicated-panel';
            panel.dataset.dedicatedActive = step.key;
            panels[step.key] = panel;

            var item = document.createElement('li');
            var number = document.createElement('span');
            number.textContent = String(index + 1);
            var label = document.createElement('span');
            label.textContent = step.label;
            item.append(number, label);
            progress.appendChild(item);
        });

        document.querySelectorAll('[data-dedicated-panel]').forEach(function (node) {
            var key = node.getAttribute('data-dedicated-panel');
            if (panels[key]) panels[key].appendChild(node);
        });

        var spouse = document.querySelector('[data-dedicated-spouse]');
        var personKey = isRequest ? 'subject' : 'person';
        if (spouse && panels[personKey]) panels[personKey].appendChild(spouse);

        var note = document.querySelector('[data-dedicated-note]');
        if (note && panels.contact) panels.contact.appendChild(note);

        shell.append(progress, status, alert);
        steps.forEach(function (step) {
            shell.appendChild(panels[step.key]);
        });

        var controls = document.createElement('div');
        controls.className = 'walk-in-dedicated-actions';
        var back = document.createElement('button');
        back.type = 'button';
        back.className = 'btn btn-secondary';
        back.textContent = 'Back';
        var next = document.createElement('button');
        next.type = 'button';
        next.className = 'btn';
        next.textContent = 'Continue';
        controls.append(back, next, submit);
        shell.appendChild(controls);
        form.appendChild(shell);

        function currentFields(key) {
            var panel = panels[key];
            if (!panel) return [];
            return Array.prototype.filter.call(
                panel.querySelectorAll('input[required], select[required], textarea[required]'),
                function (field) {
                    return !field.disabled && !field.closest('[hidden]');
                }
            );
        }

        function updateReview() {
            var list = form.querySelector('[data-dedicated-review]');
            if (!list) return;
            list.textContent = '';

            form.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(function (field) {
                if (field.disabled || field.type === 'submit' || field.type === 'button') return;
                var value = field.tagName === 'SELECT'
                    ? (field.options[field.selectedIndex] ? field.options[field.selectedIndex].textContent : '')
                    : field.value;
                if (!value || !String(value).trim()) return;

                var label = field.closest('label');
                var name = label
                    ? label.childNodes[0].textContent.trim().replace(/\s*\*$/, '')
                    : field.name.replace(/[_\[\]]+/g, ' ').trim();
                if (!name) return;

                var term = document.createElement('dt');
                var detail = document.createElement('dd');
                term.textContent = name;
                detail.textContent = String(value).trim();
                list.append(term, detail);
            });
        }

        function showStep(index) {
            index = Math.max(1, Math.min(steps.length, index));
            current.value = String(index);

            steps.forEach(function (step, stepIndex) {
                panels[step.key].hidden = stepIndex + 1 !== index;
            });

            Array.prototype.forEach.call(progress.children, function (item, stepIndex) {
                item.classList.toggle('is-current', stepIndex + 1 === index);
                item.classList.toggle('is-done', stepIndex + 1 < index);
                if (stepIndex + 1 === index) item.setAttribute('aria-current', 'step');
                else item.removeAttribute('aria-current');
            });

            status.textContent = 'Step ' + index + ' of ' + steps.length + ' - ' + steps[index - 1].label;
            back.hidden = index === 1;
            next.hidden = index === steps.length;
            submit.hidden = index !== steps.length;
            alert.hidden = true;
            if (index === steps.length) updateReview();
        }

        next.addEventListener('click', function () {
            var index = Number(current.value);
            var missing = currentFields(steps[index - 1].key).filter(function (field) {
                return !field.value.trim();
            });
            if (missing.length) {
                alert.hidden = false;
                alert.textContent = 'Please complete the required fields before continuing.';
                missing[0].focus();
                return;
            }
            showStep(index + 1);
        });

        back.addEventListener('click', function () {
            showStep(Number(current.value) - 1);
        });

        form.addEventListener('submit', function (event) {
            if (Number(current.value) !== steps.length) event.preventDefault();
        });

        showStep(1);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWalkInDedicatedForms);
} else {
    initWalkInDedicatedForms();
}
