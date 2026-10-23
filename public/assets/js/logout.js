(function () {
  if (window.greyhoundLogoutReady) return;
  window.greyhoundLogoutReady = true;

  document.addEventListener('submit', async function (event) {
    var form = event.target;
    if (!form.matches('form[data-logout-token-url]') || event.defaultPrevented) return;
    event.preventDefault();
    if (form.dataset.logoutBusy) return;
    form.dataset.logoutBusy = 'true';
    var button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;

    try {
      // Other tabs and background POSTs may have rotated the page's token.
      var response = await fetch(form.dataset.logoutTokenUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
      });
      if (!response.ok) throw new Error('Token refresh failed');
      var data = await response.json();
      if (!data.csrfTokenName || !data.csrfToken) throw new Error('Missing token');
      var input = form.querySelector('input[type="hidden"]');
      input.name = data.csrfTokenName;
      input.value = data.csrfToken;
      HTMLFormElement.prototype.submit.call(form);
    } catch (error) {
      delete form.dataset.logoutBusy;
      if (button) button.disabled = false;
      window.alert('Unable to sign out. Please check your connection and try again.');
    }
  });
})();
