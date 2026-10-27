/* Lead tracking, loaded on every page.
   1. Remembers where the visit came from (first touch of the session: landing page, referrer, UTM / ad click ids).
   2. Works out a readable lead source (google, facebook, instagram, direct, ...).
   3. Sends GA4 events with the page and source, including clicks on WhatsApp / call buttons.
   The same session key is read by js/hero-form.js, which stores it with each lead in the database. */
(function () {
    var KEY = 'skafos_attr';
    var SOURCES = {
        google: 'google', bing: 'bing', yahoo: 'yahoo', duckduckgo: 'duckduckgo',
        facebook: 'facebook', instagram: 'instagram', youtube: 'youtube',
        whatsapp: 'whatsapp', 't.co': 'twitter', twitter: 'twitter', linkedin: 'linkedin'
    };

    function read() {
        try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) { return null; }
    }

    function getAttribution() {
        var saved = read();
        if (saved) return saved;
        var q = new URLSearchParams(location.search);
        saved = {
            landing_page: location.href.split('#')[0],
            referrer: document.referrer || '',
            utm_source: q.get('utm_source') || (q.get('gclid') ? 'google' : (q.get('fbclid') ? 'facebook' : '')),
            utm_medium: q.get('utm_medium') || (q.get('gclid') ? 'cpc' : ''),
            utm_campaign: q.get('utm_campaign') || ''
        };
        try { sessionStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {}
        return saved;
    }

    /* utm_source wins, then the referrer site, otherwise "direct". */
    function leadSource(a) {
        a = a || getAttribution();
        if (a.utm_source) return a.utm_source.toLowerCase();
        var host = '';
        try { host = new URL(a.referrer).hostname.toLowerCase(); } catch (e) {}
        if (!host || host === location.hostname.toLowerCase()) return 'direct';
        for (var needle in SOURCES) {
            if (host.indexOf(needle) !== -1) return SOURCES[needle];
        }
        return host;
    }

    function track(name, params) {
        var a = getAttribution();
        var data = {
            page_path: location.pathname,
            page_title: document.title,
            lead_source: leadSource(a),
            utm_medium: a.utm_medium || '',
            utm_campaign: a.utm_campaign || ''
        };
        for (var k in (params || {})) data[k] = params[k];
        if (typeof window.gtag === 'function') window.gtag('event', name, data);
    }

    window.SkafosTracking = { getAttribution: getAttribution, leadSource: leadSource, track: track };
    getAttribution();

    /* Which page do WhatsApp / call buttons get clicked from? */
    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href]') : null;
        if (!a) return;
        var href = a.getAttribute('href') || '';
        if (href.indexOf('wa.me') !== -1 || href.indexOf('api.whatsapp.com') !== -1) {
            track('whatsapp_click', { link_text: (a.textContent || '').trim().slice(0, 60) });
        } else if (href.indexOf('tel:') === 0) {
            track('call_click', { link_text: (a.textContent || '').trim().slice(0, 60) });
        }
    });
})();
