    </div>
<footer>
    <p>&copy; 2024 Service Portal. All rights reserved.</p> 
</footer>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var profileMenu = document.querySelector('.nav-profile-menu');
        var profileButton = document.querySelector('.nav-profile-button');

        if (profileMenu && profileButton) {
            profileButton.addEventListener('click', function (event) {
                event.stopPropagation();
                var isOpen = profileMenu.classList.contains('is-open');
                profileMenu.classList.toggle('is-open', !isOpen);
                profileButton.setAttribute('aria-expanded', String(!isOpen));
            });

            document.addEventListener('click', function (event) {
                if (!profileMenu.contains(event.target)) {
                    profileMenu.classList.remove('is-open');
                    profileButton.setAttribute('aria-expanded', 'false');
                }
            });
        }

        document.querySelectorAll('input[type="password"]').forEach(function (input) {
            if (input.closest('.password-field')) {
                return;
            }

            var wrapper = document.createElement('div');
            wrapper.className = 'password-field';

            var toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'password-toggle';
            toggle.setAttribute('aria-label', 'Show password');
            toggle.textContent = '👁';
            toggle.title = 'Show password';

            toggle.addEventListener('click', function () {
                var isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                toggle.textContent = isPassword ? '🙈' : '👁';
                toggle.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                toggle.title = isPassword ? 'Hide password' : 'Show password';
            });

            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);
            wrapper.appendChild(toggle);
        });
    });
</script>
</body>
</html>