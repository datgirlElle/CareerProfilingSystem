/*
 * ProfilePath — CSRF token handling.
 * Loaded first, unpatched, on every page (NOT deferred — must patch
 * window.fetch before any other script has a chance to call it).
 *
 * Fetches the session's CSRF token once from api/session.php and attaches
 * it as the X-CSRF-Token header on every same-origin, state-changing
 * (POST/PUT/PATCH/DELETE) fetch() call. GET requests are left untouched.
 */
(function () {
  'use strict';

  var cachedToken = null;
  var tokenPromise = null;

  function fetchToken() {
    if (cachedToken) {
      return Promise.resolve(cachedToken);
    }
    if (!tokenPromise) {
      var originalFetch = window.fetch;
      tokenPromise = originalFetch('api/session.php')
        .then(function (res) { return res.json(); })
        .then(function (data) {
          cachedToken = data.csrfToken || null;
          return cachedToken;
        })
        .catch(function () {
          return null;
        });
    }
    return tokenPromise;
  }

  var originalFetch = window.fetch;
  var stateChangingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

  window.fetch = function (input, init) {
    init = init || {};
    var method = (init.method || 'GET').toUpperCase();

    var url = typeof input === 'string' ? input : (input && input.url) || '';
    var isApiCall = /(^|\/)api\//.test(url);

    if (isApiCall && stateChangingMethods.indexOf(method) !== -1) {
      return fetchToken().then(function (token) {
        var headers = new Headers(init.headers || {});
        if (token) {
          headers.set('X-CSRF-Token', token);
        }
        init.headers = headers;
        return originalFetch(input, init);
      });
    }

    return originalFetch(input, init);
  };
})();