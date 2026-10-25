import axios from 'axios';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Realtime collaboration is optional. Production has no websocket server
// (BROADCAST_CONNECTION=null), so the Echo client only boots when a Reverb key
// was set at build time. Without one nothing is imported or connected, and
// window.Echo stays undefined (show-spreadsheet.blade.php already checks for it).
const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

if (reverbKey) {
    Promise.all([import('laravel-echo'), import('pusher-js')])
        .then(([{ default: Echo }, { default: Pusher }]) => {
            window.Pusher = Pusher;

            window.Echo = new Echo({
                broadcaster: 'reverb',
                key: reverbKey,
                wsHost: import.meta.env.VITE_REVERB_HOST ?? `ws.${location.hostname}`,
                wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
                wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
                forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
                enabledTransports: ['ws', 'wss'],
            });
        })
        .catch((error) => console.warn('Realtime client failed to load:', error));
}
