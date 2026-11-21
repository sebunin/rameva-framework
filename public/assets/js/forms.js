(() => {
  'use strict';

  document.querySelectorAll('form[data-rameva-form]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const message = form.querySelector('[data-form-message]');
      const errors = form.querySelector('[data-form-errors]');
      if (message) message.textContent = '';
      if (errors) errors.replaceChildren();
      const id = form.dataset.ramevaForm;
      const data = new FormData(form);
      try {
        const response = await fetch(`/form/${encodeURIComponent(id)}`, {
          method: 'POST',
          body: data,
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
          redirect: 'error'
        });
        const result = await response.json();
        if (message) message.textContent = result.message || 'Не удалось отправить форму';
        if (result.success === true) {
          form.reset();
          return;
        }
        if (errors && result.errors && typeof result.errors === 'object') {
          Object.entries(result.errors).forEach(([field, error]) => {
            const li = document.createElement('li');
            li.textContent = `${field}: ${Array.isArray(error) ? error.join(', ') : error}`;
            errors.append(li);
          });
        }
      } catch (_) {
        if (message) message.textContent = 'Не удалось связаться с сервером. Попробуйте позже.';
      }
    });
  });
})();