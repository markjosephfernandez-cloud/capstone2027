(function () {
  'use strict';
  var approved = new WeakMap();
  var pending = null;

  // Registered before the AJAX submit handler, after each form's selection checks.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
    var submitter = event.submitter || null;
    var source = submitter && submitter.dataset.confirmKind ? submitter : form;
    var kind = source.dataset.confirmKind;
    if (kind !== 'trash' && kind !== 'delete') return;
    if (approved.has(form) && approved.get(form) === submitter) {
      approved.delete(form);
      return;
    }
    event.preventDefault();
    if (pending && pending.form.isConnected) return;

    var element = document.getElementById('actionConfirmationModal');
    if (!element || !window.bootstrap || !window.bootstrap.Modal) return;
    var accept = document.getElementById('actionConfirmationAccept');
    var cancel = document.getElementById('actionConfirmationCancel');
    var deleting = kind === 'delete';
    document.getElementById('actionConfirmationHeading').textContent = deleting ? 'Delete permanently?' : 'Move to Trash?';
    document.getElementById('actionConfirmationMessage').textContent = source.dataset.confirmMessage || (deleting ? 'Permanently delete the selected items?' : 'Move the selected items to Trash?');
    document.getElementById('actionConfirmationWarning').textContent = deleting ? 'This cannot be undone. The files will be permanently removed from storage.' : 'You can restore these items from Trash before they expire.';
    accept.textContent = deleting ? 'Delete Permanently' : 'Move to Trash';
    accept.disabled = false;
    var request = { form: form, confirmed: false };
    pending = request;
    var modal = window.bootstrap.Modal.getOrCreateInstance(element);
    var confirm = function () {
      if (request.confirmed) return;
      request.confirmed = true;
      accept.disabled = true;
      modal.hide();
    };
    accept.addEventListener('click', confirm);
    element.addEventListener('shown.bs.modal', function () { cancel.focus(); }, { once: true });
    element.addEventListener('hidden.bs.modal', function () {
      accept.removeEventListener('click', confirm);
      if (pending === request) pending = null;
      if (!form.isConnected || (submitter && (!submitter.isConnected || submitter.disabled))) return;
      if (submitter) submitter.focus();
      if (!request.confirmed) return;
      // Preserve the original submitter's formaction, selected IDs and CSRF token.
      approved.set(form, submitter);
      try {
        if (submitter) form.requestSubmit(submitter);
        else form.requestSubmit();
      } finally {
        approved.delete(form);
      }
    }, { once: true });
    modal.show();
  });
}());
