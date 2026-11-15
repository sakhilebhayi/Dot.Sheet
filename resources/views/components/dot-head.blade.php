{{-- The shared Dot UI assets of every signed-in page (dot-ui 0.6.1): one minified stylesheet (Roboto, type scale, tokens, search, switcher, header) first in the head, and one deferred script,
     js/dot-ui.min.js: search, results controller, switcher and header behaviour. This host's header search (resources/js/pages/header-search.js) calls DotSearchResults.attach(), so it loads the
     bundle with the controller inside rather than dot-ui-core.min.js (where the controller is lazy and attach() does not exist). All from this origin. --}}
<link rel="stylesheet" href="/dot-ui/css/dot-ui.min.css">
<script src="/dot-ui/js/dot-ui.min.js" defer></script>
