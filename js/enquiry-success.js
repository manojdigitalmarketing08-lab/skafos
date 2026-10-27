/* Enquiry success page: confirms the lead, then opens WhatsApp with the visitor's details pre-filled.
   The lead summary is stored by js/hero-form.js when the form is submitted; the page only gets here after hero-lead.php has saved it. */
(function () {
    var WA_NUMBER = '917305512199';
    var SECONDS = 6;

    var lead = null;
    try { lead = JSON.parse(sessionStorage.getItem('skafos_lead') || 'null'); } catch (e) {}
    var T = window.SkafosTracking;

    function $(id) { return document.getElementById(id); }

    /* Message the clinic will receive on WhatsApp. */
    function buildMessage(l) {
        if (!l) return "Hello Skafos Men's Wellness, I just submitted an enquiry and would like to chat.";
        var msg = "Hello Skafos Men's Wellness, I'm " + l.name + '. I just submitted an enquiry for ' + l.service;
        if (l.location) msg += ' (' + l.location + ')';
        msg += '.';
        if (l.visit_date || l.visit_time) {
            msg += ' Preferred slot: ' + [l.visit_date, l.visit_time].filter(Boolean).join(', ') + '.';
        }
        return msg + ' Please contact me.';
    }

    var waUrl = 'https://wa.me/' + WA_NUMBER + '?text=' + encodeURIComponent(buildMessage(lead));
    var waBtn = $('waContinue');
    waBtn.href = waUrl;

    if (lead) {
        $('leadName').textContent = ', ' + lead.name.split(' ')[0];
        $('leadSummary').textContent = lead.service +
            (lead.location ? ' - ' + lead.location : '') +
            (lead.visit_date || lead.visit_time ? ' - ' + [lead.visit_date, lead.visit_time].filter(Boolean).join(', ') : '');
        $('leadSummaryBox').style.display = '';
        if (lead.page_url) { $('backLink').href = lead.page_url; $('backLink').style.display = ''; }

        /* Count this lead once, even if the page is refreshed. */
        if (T && !lead.tracked) {
            T.track('generate_lead', { form_name: 'hero-form', service: lead.service, source_page: lead.page_path || '' });
            lead.tracked = true;
            try { sessionStorage.setItem('skafos_lead', JSON.stringify(lead)); } catch (e) {}
        }
    }

    /* Auto-open WhatsApp after a short countdown; the visitor can cancel or continue straight away. */
    var left = SECONDS, timer = null;
    var timerBox = $('waTimer'), num = $('waCountdown'), cancel = $('waCancel');

    function goWhatsApp() {
        if (T) T.track('whatsapp_after_lead', { service: lead ? lead.service : '' });
        window.location.href = waUrl;
    }

    if (lead) {
        timerBox.style.display = '';
        timer = setInterval(function () {
            left--;
            num.textContent = left < 10 ? '0' + left : left;
            if (left <= 0) { clearInterval(timer); goWhatsApp(); }
        }, 1000);
        cancel.addEventListener('click', function (e) {
            e.preventDefault();
            clearInterval(timer);
            timerBox.style.display = 'none';
        });
    }

    waBtn.addEventListener('click', function () { clearInterval(timer); });
})();
