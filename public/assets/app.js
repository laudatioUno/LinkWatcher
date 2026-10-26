// Small progressive enhancements – the app works without JavaScript.
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', () => {
      const btn = form.querySelector('button[type="submit"]');
      if (btn && form.method.toLowerCase() === 'post') {
        btn.disabled = true;
        btn.classList.add('is-busy');
      }
    });
  });
});
