// Tab switching functionality
document.addEventListener('DOMContentLoaded', function() {
    const tabLinks = document.querySelectorAll('.tab-link');

    tabLinks.forEach(link => {
        link.addEventListener('click', function() {
            // Remove active class from all tabs
            tabLinks.forEach(tab => tab.classList.remove('active'));

            // Add active class to clicked tab
            this.classList.add('active');

            // Hide all tab content
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));

            // Show the selected tab content
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
        });
    });

    // Password validation
    const passwordForm = document.getElementById('passwordForm');
    const newPassword = document.getElementById('newPassword');
    const confirmPassword = document.getElementById('confirmPassword');
    const passwordError = document.getElementById('passwordError');
    const confirmError = document.getElementById('confirmError');

    passwordForm.addEventListener('submit', function(e) {
        e.preventDefault();

        let isValid = true;

        // Check if new password is at least 8 characters
        if (newPassword.value.length < 8) {
            passwordError.style.display = 'block';
            isValid = false;
        } else {
            passwordError.style.display = 'none';
        }

        // Check if passwords match
        if (newPassword.value !== confirmPassword.value) {
            confirmError.style.display = 'block';
            isValid = false;
        } else {
            confirmError.style.display = 'none';
        }

        if (isValid) {
            alert('Password changed successfully!');
            // In a real application, you would submit the form here
            // passwordForm.submit();
        }
    });

    // Real-time validation
    newPassword.addEventListener('input', function() {
        if (this.value.length >= 8) {
            passwordError.style.display = 'none';
        }
    });

    confirmPassword.addEventListener('input', function() {
        if (this.value === newPassword.value) {
            confirmError.style.display = 'none';
        }
    });

    // Profile image upload preview
    const fileInput = document.getElementById('fileInput');
    const profileImage = document.getElementById('profileImage');

    fileInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                profileImage.src = e.target.result;
            }
            reader.readAsDataURL(file);
        }
    });
});
localStorage.setItem('studentFinalCertificate', 'https://files.catbox.moe/8z1f0j.pdf');