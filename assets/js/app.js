/* File: assets/js/app.js - tiny enhancements. Server-side checks always apply too. */
(function () {
    'use strict';

    // Confirm destructive actions: <button data-confirm="Delete this?">
    document.addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            ev.preventDefault();
        }
    });

    // Auto-dismiss success flashes after 5 seconds (errors stay until closed).
    window.addEventListener('load', function () {
        document.querySelectorAll('.alert-success').forEach(function (box) {
            setTimeout(function () {
                if (window.bootstrap && window.bootstrap.Alert) {
                    window.bootstrap.Alert.getOrCreateInstance(box).close();
                } else {
                    box.remove();
                }
            }, 5000);
        });
    });
})();
