/* embed-resize.js — iframe height handshake for embedding (spec 2.7).
   Loaded on the booking page; posts the current content height to the parent
   window on load, resize, and DOM mutations. The embed snippet on the business
   site listens for { eratotime:true, type:'resize', height } and resizes the
   iframe accordingly. Standalone (no module scope) so it runs anywhere. */
(function () {
    'use strict';
    function post() {
        if (window.parent === window) return;
        var h = Math.max(
            document.body ? document.body.scrollHeight : 0,
            document.documentElement ? document.documentElement.scrollHeight : 0
        );
        window.parent.postMessage({ eratotime: true, type: 'resize', height: h }, '*');
    }
    var timer = null;
    function debounced() {
        if (timer) window.clearTimeout(timer);
        timer = window.setTimeout(post, 120);
    }
    window.addEventListener('load', post);
    window.addEventListener('resize', debounced);
    if (window.MutationObserver) {
        var observer = new MutationObserver(debounced);
        if (document.body) {
            observer.observe(document.body, { childList: true, subtree: true, attributes: true });
        }
    }
    post();
})();
