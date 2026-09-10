import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Axios 1.x only reads the XSRF-TOKEN cookie when told to; without this every
// write answers 419.
window.axios.defaults.withXSRFToken = true;

// Ask for JSON so the routes answer with data instead of an Inertia redirect.
window.axios.defaults.headers.common.Accept = 'application/json';
