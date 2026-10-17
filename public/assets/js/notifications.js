(function () {
  'use strict';

  var timers = new WeakMap();

  function clearTimer(alert) {
    if (timers.has(alert)) {
      window.clearTimeout(timers.get(alert));
      timers.delete(alert);
    }
  }

  function dismiss(alert) {
    clearTimer(alert);
    if (!alert.isConnected) {
      return;
    }
    if (alert.dataset.autoDismiss === 'hide') {
      // Scan notifications reuse the same element for subsequent errors.
      alert.classList.add('d-none');
    } else if (window.bootstrap && window.bootstrap.Alert) {
      window.bootstrap.Alert.getOrCreateInstance(alert).close();
    } else {
      alert.remove();
    }
  }

  function schedule(alert) {
    clearTimer(alert);
    if (!alert.isConnected || alert.hidden || alert.classList.contains('d-none')) {
      return;
    }
    alert.classList.add('alert-dismissible');
    if (!alert.querySelector('.btn-close')) {
      var close = document.createElement('button');
      close.type = 'button';
      close.className = 'btn-close';
      close.setAttribute('aria-label', 'Close');
      close.addEventListener('click', function () { dismiss(alert); });
      alert.appendChild(close);
    }
    timers.set(alert, window.setTimeout(function () { dismiss(alert); }, 5000));
  }

  window.GreyhoundNotifications = {
    schedule: schedule,
    init: function () {
      document.querySelectorAll('.alert-dismissible, .alert[data-auto-dismiss]').forEach(schedule);
    }
  };
})();
