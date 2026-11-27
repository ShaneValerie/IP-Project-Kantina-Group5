/* =========================================================
   Kantina — browser helper for talking to the Kantina API.
   Include it BEFORE app.js / your page script:
     student pages:  <script src="js/api.js"></script>
     seller pages:   <script src="../js/api.js"></script>

   What it gives you (all on window):
     Api.get(path, params)  Api.post(path, body)  Api.patch(path, body)  Api.del(path, params)
       → Promise with the JSON response. Paths are relative to the API: Api.get('/stalls')
       → on error throws ApiError { status, code, message, fields }. Show err.message to the user.
       → a 401 (not signed in / expired) sends the user to the sign-in page automatically.
     Auth.user()  Auth.save(token, user)  Auth.logout()  Auth.requireStudent()  Auth.requireStaff()
     Cart.items()  Cart.add(item)  Cart.update(i, changes)  Cart.remove(i)  Cart.clear()
     Cart.count()  Cart.subtotal()  Cart.total()  Cart.byStall()  Cart.SERVICE_FEE
     Cart.prefs()  Cart.setPrefs({pickup_date, pickup_time, reservation_ref})
     Fmt.peso(85) → "₱85"   Fmt.time12("13:00") → "1:00"   Fmt.ampm("13:00") → "1:00 PM"
     escapeHtml(str)   qs('ref') → value of ?ref= in the URL
   Full endpoint list with examples: docs/API.md
   ========================================================= */
(function () {
  'use strict';

  var script = document.currentScript;
  var APP_ROOT = new URL('../', script.src);                 // .../kantina/
  var API_BASE = new URL('api', APP_ROOT).href;              // .../kantina/api
  var KEYS = { token: 'kantina.token', user: 'kantina.user', cart: 'kantina.cart', prefs: 'kantina.checkout' };

  // ---------- storage helpers (never crash if storage is blocked) ----------
  function read(key, fallback) {
    try {
      var raw = localStorage.getItem(key);
      return raw === null ? fallback : JSON.parse(raw);
    } catch (e) { return fallback; }
  }
  function write(key, value) {
    try {
      if (value === null || value === undefined) localStorage.removeItem(key);
      else localStorage.setItem(key, JSON.stringify(value));
    } catch (e) { /* storage full or disabled */ }
  }

  // ---------- errors ----------
  function ApiError(status, code, message, fields) {
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.message = message;
    this.fields = fields || {};
  }
  ApiError.prototype = Object.create(Error.prototype);
  ApiError.prototype.constructor = ApiError;

  // ---------- requests ----------
  function buildUrl(path, params) {
    var url = API_BASE + (path.charAt(0) === '/' ? path : '/' + path);
    if (params) {
      var search = new URLSearchParams();
      Object.keys(params).forEach(function (k) {
        if (params[k] !== undefined && params[k] !== null && params[k] !== '') search.append(k, params[k]);
      });
      var s = search.toString();
      if (s) url += (url.indexOf('?') === -1 ? '?' : '&') + s;
    }
    return url;
  }

  function request(method, path, body, params) {
    var headers = { 'Accept': 'application/json' };
    var token = Auth.token();
    if (token) {
      headers['Authorization'] = 'Bearer ' + token;
      headers['X-Auth-Token'] = token;               // some servers drop Authorization
    }
    var options = { method: method, headers: headers };
    if (body !== undefined && body !== null) {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    return fetch(buildUrl(path, params), options)
      .catch(function () {
        throw new ApiError(0, 'network_error', "Can't reach Kantina right now. Check your connection and try again.");
      })
      .then(function (res) {
        if (res.status === 204) return null;
        return res.text().then(function (text) {
          var data = null;
          try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
          if (res.ok) return data;
          var err = (data && data.error) || {};
          if (res.status === 401 && token && !/\/auth\/(staff\/)?login$/.test(path)) {
            Auth.clear();
            Auth.goToLogin();
          }
          throw new ApiError(res.status, err.code || 'http_' + res.status, err.message || 'Something went wrong (' + res.status + ').', err.fields);
        });
      });
  }

  var Api = {
    base: API_BASE,
    get: function (path, params) { return request('GET', path, null, params); },
    post: function (path, body) { return request('POST', path, body || {}); },
    patch: function (path, body) { return request('PATCH', path, body || {}); },
    del: function (path, params) { return request('DELETE', path, null, params); }
  };

  // ---------- signed-in user ----------
  var Auth = {
    token: function () { return read(KEYS.token, null); },
    user: function () { return read(KEYS.user, null); },
    isStaff: function () { var u = Auth.user(); return !!u && (u.role === 'staff' || u.role === 'admin'); },
    save: function (token, user) { write(KEYS.token, token); write(KEYS.user, user); },
    clear: function () { write(KEYS.token, null); write(KEYS.user, null); },
    loginUrl: function () {
      var staff = /\/seller\//.test(location.pathname) || Auth.isStaff();
      var url = new URL(staff ? 'seller/login.html' : 'signin.html', APP_ROOT);
      url.searchParams.set('next', location.pathname + location.search);
      return url.href;
    },
    goToLogin: function () { location.href = Auth.loginUrl(); },
    logout: function () {
      var staff = Auth.isStaff() || /\/seller\//.test(location.pathname);
      Auth.clear();
      if (!staff) Cart.clear();
      location.href = new URL(staff ? 'seller/login.html' : 'signin.html', APP_ROOT).href;
    },
    /** Call at the top of student pages. Returns the user or redirects to sign-in. */
    requireStudent: function () {
      var u = Auth.user();
      if (!Auth.token() || !u || u.role !== 'student') { Auth.clear(); Auth.goToLogin(); throw new Error('redirecting'); }
      return u;
    },
    /** Call at the top of store console pages. */
    requireStaff: function () {
      if (!Auth.token() || !Auth.isStaff()) {
        Auth.clear();
        location.href = new URL('seller/login.html', APP_ROOT).href;
        throw new Error('redirecting');
      }
      return Auth.user();
    },
    /** Where to go after signing in (?next=…), only within this site. */
    nextUrl: function (fallback) {
      var next = new URLSearchParams(location.search).get('next');
      return next && next.charAt(0) === '/' && next.charAt(1) !== '/' ? next : fallback;
    }
  };

  // ---------- tray / cart (kept in localStorage until checkout) ----------
  function sameAddons(a, b) {
    var x = (a || []).map(function (i) { return i.sku; }).sort().join(',');
    var y = (b || []).map(function (i) { return i.sku; }).sort().join(',');
    return x === y;
  }

  var Cart = {
    SERVICE_FEE: 5,
    items: function () { return read(KEYS.cart, []); },
    save: function (items) { write(KEYS.cart, items); return items; },
    /** item: { sku, name, price, qty, addons: [{sku, name, price}], note, stall: {id, name}, photo_url } */
    add: function (item) {
      var items = Cart.items();
      var clean = {
        sku: item.sku, name: item.name, price: Number(item.price), qty: Math.max(1, Number(item.qty) || 1),
        addons: item.addons || [], note: item.note || '', stall: item.stall, photo_url: item.photo_url || null
      };
      var existing = items.find(function (i) { return i.sku === clean.sku && i.note === clean.note && sameAddons(i.addons, clean.addons); });
      if (existing) existing.qty = Math.min(20, existing.qty + clean.qty);
      else items.push(clean);
      return Cart.save(items);
    },
    update: function (index, changes) {
      var items = Cart.items();
      if (items[index]) Object.assign(items[index], changes);
      return Cart.save(items);
    },
    remove: function (index) {
      var items = Cart.items();
      items.splice(index, 1);
      return Cart.save(items);
    },
    clear: function () { write(KEYS.cart, null); write(KEYS.prefs, null); },
    count: function () { return Cart.items().reduce(function (n, i) { return n + i.qty; }, 0); },
    lineTotal: function (i) {
      var extras = (i.addons || []).reduce(function (s, a) { return s + Number(a.price); }, 0);
      return (Number(i.price) + extras) * i.qty;
    },
    subtotal: function () { return Cart.items().reduce(function (s, i) { return s + Cart.lineTotal(i); }, 0); },
    total: function () { var sub = Cart.subtotal(); return sub ? sub + Cart.SERVICE_FEE : 0; },
    /** Items grouped by stall: [{ stall: {id, name}, items: [{...item, index}] }] */
    byStall: function () {
      var groups = [];
      Cart.items().forEach(function (item, index) {
        var g = groups.find(function (x) { return x.stall.id === item.stall.id; });
        if (!g) { g = { stall: item.stall, items: [] }; groups.push(g); }
        g.items.push(Object.assign({ index: index }, item));
      });
      return groups;
    },
    stallIds: function () { return Cart.byStall().map(function (g) { return g.stall.id; }); },
    /** Payload for POST /orders */
    orderItems: function () {
      return Cart.items().map(function (i) {
        return { sku: i.sku, qty: i.qty, addons: (i.addons || []).map(function (a) { return a.sku; }), note: i.note || undefined };
      });
    },
    prefs: function () { return read(KEYS.prefs, {}); },
    setPrefs: function (changes) { var p = Object.assign(Cart.prefs(), changes); write(KEYS.prefs, p); return p; }
  };

  // ---------- formatting ----------
  var Fmt = {
    peso: function (n) {
      n = Number(n) || 0;
      return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 });
    },
    /** "13:00" -> "1:00" (12-hour, like the pickup buttons) */
    time12: function (t) {
      var p = String(t).split(':'); var h = Number(p[0]) % 12 || 12;
      return h + ':' + (p[1] || '00');
    },
    /** "13:00" -> "1:00 PM" */
    ampm: function (t) {
      var p = String(t).split(':'); var h = Number(p[0]);
      return (h % 12 || 12) + ':' + (p[1] || '00') + (h < 12 ? ' AM' : ' PM');
    }
  };

  function escapeHtml(str) {
    return String(str === null || str === undefined ? '' : str)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function qs(name) { return new URLSearchParams(location.search).get(name); }

  window.Api = Api;
  window.ApiError = ApiError;
  window.Auth = Auth;
  window.Cart = Cart;
  window.Fmt = Fmt;
  window.escapeHtml = escapeHtml;
  window.qs = qs;
})();
