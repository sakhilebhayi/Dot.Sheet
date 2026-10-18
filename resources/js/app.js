import './bootstrap';
import FormulaParser from './formula-parser';
import Chart from 'chart.js/auto';
import Shepherd from 'shepherd.js';
import 'shepherd.js/dist/css/shepherd.css';

// Globals
window.FormulaParser = FormulaParser;
window.Chart = Chart;
window.Shepherd = Shepherd;

// User scripts are off unless the server says they are on (config sheet.scripts_enabled,
// SHEET_SCRIPTS_ENABLED). The layout prints the meta tag below only then. While it is
// missing the worker is never created and window.runSpreadsheetScriptSandbox does not exist.
//
// Even when on, the worker is NOT a security boundary: it has no DOM and no cookies, but it can
// still call fetch(), XMLHttpRequest and WebSocket on this origin and any other.
const scriptsEnabled = document.querySelector('meta[name="sheet-scripts-enabled"]')?.content === '1';

let scriptSandboxWorker = null;

const getScriptSandboxWorker = () => {
	if (!scriptsEnabled || typeof Worker === 'undefined') {
		return null;
	}

	scriptSandboxWorker ??= new Worker(new URL('./workers/script-sandbox.worker.js', import.meta.url), { type: 'module' });

	return scriptSandboxWorker;
};

if (scriptsEnabled) {
	window.runSpreadsheetScriptSandbox = (payload = {}) => {
		const worker = getScriptSandboxWorker();
		if (!worker) {
			return Promise.resolve({ ok: false, error: 'Web Worker is not supported in this browser.' });
		}

		return new Promise((resolve) => {
			const timeoutHandle = setTimeout(() => {
				// A script stuck in a loop would keep the worker busy for good: throw it away.
				worker.terminate();
				scriptSandboxWorker = null;
				cleanup();
				resolve({ ok: false, error: 'Script timed out.' });
			}, 3500);

			const cleanup = () => {
				clearTimeout(timeoutHandle);
				worker.removeEventListener('message', onMessage);
				worker.removeEventListener('error', onError);
			};

			const onMessage = (event) => {
				cleanup();
				resolve(event.data || { ok: false, error: 'Unknown worker response.' });
			};

			const onError = (event) => {
				cleanup();
				resolve({ ok: false, error: event.message || 'Worker execution failed.' });
			};

			worker.addEventListener('message', onMessage);
			worker.addEventListener('error', onError);
			worker.postMessage({
				type: 'run',
				body: payload.body || '',
				context: payload.context || {},
			});
		});
	};
}
