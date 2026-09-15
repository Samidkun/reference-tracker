// Intentionally minimal.
//
// Breeze scaffolds `import './bootstrap'` for an axios instance, but this app
// uses fetch() for its two AJAX calls (DOI lookup, .bib upload) — axios would
// be ~50 kB of dead weight. The file is kept so the import resolves and so
// there is an obvious place to add real setup later.
//
// Laravel sends the CSRF token via <meta name="csrf-token"> in app.blade.php;
// fetch() callers read it from there.
