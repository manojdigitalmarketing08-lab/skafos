/* Home hero headings (both slides): white -> teal gradient, applied per character
   so it survives the SplitText typing animation (a CSS text-clip gradient hides animated characters). */
(function () {
    var FROM = [255, 255, 255], TO = [110, 231, 216], HOLD = 0.35;
    var selector = '.hero-slider .hero-content .section-title h1, .hero-slider .hero-content .section-title h2';

    function mix(t) {
        var k = t <= HOLD ? 0 : (t - HOLD) / (1 - HOLD);
        return 'rgb(' + FROM.map(function (c, i) { return Math.round(c + (TO[i] - c) * k); }).join(',') + ')';
    }

    function paint(el) {
        var chars = Array.prototype.filter.call(el.querySelectorAll('*'), function (n) {
            return n.children.length === 0 && n.textContent.trim().length === 1;
        });
        if (!chars.length) return false;
        chars.forEach(function (c, i) {
            c.style.color = mix(chars.length === 1 ? 0 : i / (chars.length - 1));
        });
        el.setAttribute('data-gradient', '1');
        return true;
    }

    function run() {
        Array.prototype.forEach.call(document.querySelectorAll(selector), function (el) {
            if (!el.getAttribute('data-gradient')) paint(el);
        });
    }

    var tries = 0;
    var timer = setInterval(function () {
        run();
        if (++tries > 30) clearInterval(timer); /* ~9s: covers late SplitText init and Swiper clones */
    }, 300);
})();
