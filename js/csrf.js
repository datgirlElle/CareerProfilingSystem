/*
 * ProfilePath — CSRF token handling + session-expiry handling.
 * Loaded first, unpatched, on every page (NOT deferred — must patch
 * window.fetch before any other script has a chance to call it).
 *
 * Four things happen here, all built around one window.fetch patch:
 *  1. Every same-origin, state-changing (POST/PUT/PATCH/DELETE) call to
 *     api/* gets an X-CSRF-Token header attached automatically.
 *  2. If a request is ever rejected specifically for a bad/stale CSRF
 *     token (e.g. the session rotated between fetching the token and
 *     sending the request), the token is refreshed and the request is
 *     retried once automatically — silently, never surfaced to the user
 *     as an error. Only if the retry also fails does the caller see a
 *     (generic) error.
 *  3. Any api/* call (any method) that comes back 401 with the exact
 *     "Not authenticated" error (idle timeout or no session — see
 *     Auth::requireLogin()) redirects to login. This is the reactive
 *     fallback: it only fires when the user actually does something.
 *  4. A proactive idle-timeout warning: tracks the same "time since last
 *     successful api/ call" the server itself uses (Auth::currentUser()'s
 *     sliding window — see lib/Auth.php), and once that crosses the
 *     configured Session Timeout, shows a banner and redirects on its own,
 *     without waiting for the user to click something first.
 */
(function () {
  'use strict';

  var cachedToken = null;
  var sessionInfoPromise = null;
  var sessionPolicy = null; // { enabled, minutes } — only present once the server confirms a logged-in user
  var lastActivityAt = Date.now();
  var bannerShown = false;
  var IDLE_CHECK_INTERVAL_MS = 10000; // how often to check, not the timeout itself
  var REDIRECT_DELAY_MS = 3000; // how long the banner stays up before redirecting

  function isLoginPage() {
    var path = window.location.pathname;
    return path === '/' || path === '/login' || /\/?(staff-)?login(\.html)?$/.test(path);
  }

  // Staff and students have separate sign-in pages; go back to the one this browser last used.
  function loginUrl() {
    var portal = null;
    try { portal = localStorage.getItem('pp_portal'); } catch (e) {}
    return (portal === 'staff' ? 'staff-login' : 'login') + '?expired=1';
  }

  function fetchSessionInfo(forceRefresh) {
    if (forceRefresh) {
      sessionInfoPromise = null;
      cachedToken = null;
    }
    if (sessionInfoPromise) {
      return sessionInfoPromise;
    }
    sessionInfoPromise = originalFetch('api/session.php')
      .then(function (res) { return res.json(); })
      .then(function (data) {
        cachedToken = data.csrfToken || null;
        sessionPolicy = data.sessionTimeout || null;
        return cachedToken;
      })
      .catch(function () {
        return null;
      });
    return sessionInfoPromise;
  }

  function showIdleBanner() {
    if (bannerShown) {
      return;
    }
    bannerShown = true;
    var banner = document.createElement('div');
    banner.textContent = 'Your session has expired due to inactivity. Returning to the login page…';
    // Inline styles on purpose — this has to render correctly on every page
    // regardless of whether the page's own stylesheet has loaded by the
    // time this fires, so it can't depend on utility classes.
    banner.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:2147483647;'
      + 'background:#001c43;color:#ffffff;text-align:center;padding:12px 16px;'
      + 'font-family:"Segoe UI",Helvetica,Arial,sans-serif;font-size:14px;'
      + 'box-shadow:0 2px 8px rgba(0,0,0,0.2);';
    document.body.appendChild(banner);
    setTimeout(function () {
      window.location.href = loginUrl();
    }, REDIRECT_DELAY_MS);
  }

  function checkIdle() {
    if (!sessionPolicy || !sessionPolicy.enabled || isLoginPage()) {
      return;
    }
    var idleMs = Date.now() - lastActivityAt;
    if (idleMs > sessionPolicy.minutes * 60 * 1000) {
      showIdleBanner();
    }
  }

  function isCsrfError(data) {
    return !!(data && typeof data.error === 'string' && /csrf/i.test(data.error));
  }

  var originalFetch = window.fetch;
  var stateChangingMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

  function sendStateChanging(input, init, token, isRetry) {
    var headers = new Headers(init.headers || {});
    if (token) {
      headers.set('X-CSRF-Token', token);
    }
    var reqInit = {};
    for (var key in init) { if (Object.prototype.hasOwnProperty.call(init, key)) { reqInit[key] = init[key]; } }
    reqInit.headers = headers;

    return originalFetch(input, reqInit).then(function (res) {
      if (res.status === 403 && !isRetry) {
        return res.clone().json().then(function (data) {
          if (isCsrfError(data)) {
            // Token was stale (e.g. session rotated between fetching it and
            // sending this request) — refresh and retry once, silently.
            // The user never sees this happen.
            return fetchSessionInfo(true).then(function (freshToken) {
              return sendStateChanging(input, init, freshToken, true);
            });
          }
          markResult(res);
          return res;
        }).catch(function () {
          markResult(res);
          return res;
        });
      }
      markResult(res);
      return res;
    });
  }

  // Handles the reactive session-expiry case and feeds the proactive idle
  // timer above. Split out from sendStateChanging so both the
  // state-changing and plain-GET paths share the same handling.
  function markResult(response) {
    if (response.ok) {
      lastActivityAt = Date.now();
      return;
    }
    if (response.status === 401 && !isLoginPage()) {
      response.clone().json().then(function (data) {
        if (data && data.error === 'Not authenticated') {
          window.location.href = loginUrl();
        }
      }).catch(function () {
        // Non-JSON 401 body — nothing to key off of, leave it to the caller.
      });
    }
  }

  window.fetch = function (input, init) {
    init = init || {};
    var method = (init.method || 'GET').toUpperCase();

    var url = typeof input === 'string' ? input : (input && input.url) || '';
    // Matches both relative ('api/login.php', './api/login.php') and
    // absolute ('/api/login.php', 'https://host/api/login.php') forms.
    var isApiCall = /(^|\/)api\//.test(url);

    if (!isApiCall) {
      return originalFetch(input, init);
    }

    if (stateChangingMethods.indexOf(method) !== -1) {
      return fetchSessionInfo().then(function (token) {
        return sendStateChanging(input, init, token, false);
      });
    }

    return originalFetch(input, init).then(function (res) {
      markResult(res);
      return res;
    });
  };

  if (!isLoginPage()) {
    fetchSessionInfo();
    setInterval(checkIdle, IDLE_CHECK_INTERVAL_MS);
  }
})();
