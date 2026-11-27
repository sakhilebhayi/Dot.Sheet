// The classic editor's own scripts (EA-4, P-08): its chart builder and guided tour use these globals. Every other
// page loads app.js without them.
import Chart from 'chart.js/auto';
import Shepherd from 'shepherd.js';
import 'shepherd.js/dist/css/shepherd.css';

window.Chart = Chart;
window.Shepherd = Shepherd;
