// Shown while the desktop app starts the viewer, and when the viewer cannot start.
// The app reports failures through the "peep://startup-failed" event.
(function () {
  var tauri = window.__TAURI__;

  if (!tauri || !tauri.event) {
    return;
  }

  tauri.event.listen('startup-failed', function (event) {
    document.getElementById('title').textContent = 'Peep could not start';
    document.getElementById('detail').textContent = event.payload.message;

    var log = document.getElementById('log');
    log.textContent = event.payload.log;
    log.hidden = event.payload.log === '';
  });
})();
