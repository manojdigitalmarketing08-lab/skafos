/* Loads hero-form.html into #hero-form, pre-selects the service (data-service),
   styles the service dropdown and validates on submit. */
(function () {
    var mount = document.getElementById('hero-form');
    if (!mount) return;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'hero-form.html', true);
    xhr.onload = function () {
        if (xhr.status !== 200) return;
        mount.innerHTML = xhr.responseText;

        var form = mount.querySelector('form');
        var wanted = (mount.getAttribute('data-service') || '').toLowerCase();
        if (wanted) {
            Array.prototype.forEach.call(form.service.options, function (o) {
                if (o.text.toLowerCase() === wanted) form.service.value = o.text;
            });
        }
        fillAttribution(form);
        enhanceSelect(form.service);
        enhanceSelect(form.visit_time);
        setupDate(form.visit_date);

        form.addEventListener('submit', function (e) {
            var phone = form.phone.value.replace(/[^0-9]/g, '');
            if (!form.name.value.trim() || phone.length < 10 || !form.service.value) {
                e.preventDefault();
                alert('Please enter your name, a valid mobile number and select a service.');
            }
        });
    };
    xhr.onerror = function () { console.error('Failed to load hero-form.html'); };
    xhr.send();

    /* Lead attribution: where the visitor came from (first touch of this session) and the page the lead was submitted on. */
    function getAttribution() {
        var key = 'skafos_attr', saved = null;
        try { saved = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (e) {}
        if (saved) return saved;

        var q = new URLSearchParams(location.search);
        saved = {
            landing_page: location.href.split('#')[0],
            referrer: document.referrer || '',
            utm_source: q.get('utm_source') || (q.get('gclid') ? 'google' : (q.get('fbclid') ? 'facebook' : '')),
            utm_medium: q.get('utm_medium') || (q.get('gclid') ? 'cpc' : ''),
            utm_campaign: q.get('utm_campaign') || ''
        };
        try { sessionStorage.setItem(key, JSON.stringify(saved)); } catch (e) {}
        return saved;
    }

    function fillAttribution(form) {
        var a = getAttribution();
        form.page_url.value = location.href.split('#')[0];
        form.page_title.value = document.title;
        form.landing_page.value = a.landing_page;
        form.referrer.value = a.referrer;
        form.utm_source.value = a.utm_source;
        form.utm_medium.value = a.utm_medium;
        form.utm_campaign.value = a.utm_campaign;
    }

    /* Text-looking placeholder that turns into a native date picker on focus; blocks past dates. */
    function setupDate(input) {
        var d = new Date();
        var today = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
        input.addEventListener('focus', function () {
            input.type = 'date';
            input.min = today;
            if (input.showPicker) { try { input.showPicker(); } catch (e) {} }
        });
        input.addEventListener('blur', function () {
            if (!input.value) input.type = 'text';
        });
    }

    /* Replace the native select popup with a styled list; the real select stays in the form. */
    function enhanceSelect(select) {
        var wrap = document.createElement('div');
        wrap.className = 'hlf-select';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('hlf-native');
        select.tabIndex = -1;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'hlf-select-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');

        var list = document.createElement('ul');
        list.className = 'hlf-select-list';
        list.setAttribute('role', 'listbox');

        var items = [];
        Array.prototype.forEach.call(select.options, function (o) {
            if (o.value === '') return; /* placeholder is shown on the button only */
            var li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.textContent = o.text;
            li.addEventListener('click', function () { choose(li.textContent); close(); btn.focus(); });
            list.appendChild(li);
            items.push(li);
        });

        wrap.appendChild(btn);
        wrap.appendChild(list);
        sync();

        function sync() {
            var v = select.value;
            btn.textContent = v || select.options[0].text;
            btn.classList.toggle('is-placeholder', !v);
            items.forEach(function (li) {
                var on = li.textContent === v;
                li.classList.toggle('is-selected', on);
                li.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }
        function choose(text) {
            select.value = text;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            sync();
        }
        function open() {
            wrap.classList.add('is-open');
            btn.setAttribute('aria-expanded', 'true');
        }
        function close() {
            wrap.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            items.forEach(function (li) { li.classList.remove('is-active'); });
        }
        function move(dir) {
            var cur = items.findIndex(function (li) { return li.classList.contains('is-active'); });
            if (cur < 0) cur = items.findIndex(function (li) { return li.classList.contains('is-selected'); });
            var next = Math.max(0, Math.min(items.length - 1, cur + dir));
            items.forEach(function (li) { li.classList.remove('is-active'); });
            items[next].classList.add('is-active');
            items[next].scrollIntoView({ block: 'nearest' });
        }

        btn.addEventListener('click', function () {
            if (wrap.classList.contains('is-open')) close(); else open();
        });
        btn.addEventListener('keydown', function (e) {
            var isOpen = wrap.classList.contains('is-open');
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!isOpen) open();
                move(e.key === 'ArrowDown' ? 1 : -1);
            } else if ((e.key === 'Enter' || e.key === ' ') && isOpen) {
                e.preventDefault();
                var act = items.filter(function (li) { return li.classList.contains('is-active'); })[0];
                if (act) choose(act.textContent);
                close();
            } else if (e.key === 'Escape') {
                close();
            }
        });
        document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    }
})();
