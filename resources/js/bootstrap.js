// ══════════════════════════════════════════════════════════════════
//  El Tara — HTTP client setup
//  Location: resources/js/bootstrap.js
//
//  axios, for the few requests that are not Inertia page visits.
//  Sends Laravel's XSRF security cookie automatically, so every
//  request is protected against forged submissions.
// ══════════════════════════════════════════════════════════════════

import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;
window.axios.defaults.withXSRFToken = true;
