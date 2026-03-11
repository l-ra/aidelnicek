/**
 * Aidelnicek — M1/M2 JS
 */

document.addEventListener('DOMContentLoaded', function () {
    // Toggle zobrazení/skrytí hesla
    document.querySelectorAll('.password-toggle-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var wrapper = this.closest('.password-toggle');
            var input = wrapper.querySelector('input');
            if (input.type === 'password') {
                input.type = 'text';
                this.setAttribute('aria-label', 'Skrýt heslo');
                this.textContent = '🙈';
            } else {
                input.type = 'password';
                this.setAttribute('aria-label', 'Zobrazit heslo');
                this.textContent = '👁';
            }
        });
    });
});
