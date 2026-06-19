// Profit & Loss Management
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching functionality
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

    // Profile Information Form Handling
    const profileForm = document.querySelector('.info-form');

    if (profileForm) {
        const updateBtn = profileForm.querySelector('.update-btn');
        const firstNameInput = profileForm.querySelector('input[placeholder*="First"]') ||
            Array.from(profileForm.querySelectorAll('input')).find(input =>
                input.previousElementSibling?.textContent?.includes('First'));
        const lastNameInput = profileForm.querySelector('input[placeholder*="Last"]') ||
            Array.from(profileForm.querySelectorAll('input')).find(input =>
                input.previousElementSibling?.textContent?.includes('Last'));

        // التحقق من صحة الاسم في الوقت الفعلي
        if (firstNameInput) {
            firstNameInput.addEventListener('input', function() {
                validateNameInput(this);
            });
        }

        if (lastNameInput) {
            lastNameInput.addEventListener('input', function() {
                validateNameInput(this);
            });
        }

        updateBtn.addEventListener('click', function(e) {
            e.preventDefault();

            // التحقق من صحة الأسماء قبل الحفظ
            let isValid = true;

            if (firstNameInput && !validateName(firstNameInput.value)) {
                showNameError(firstNameInput, 'First name can only contain letters');
                isValid = false;
            }

            if (lastNameInput && !validateName(lastNameInput.value)) {
                showNameError(lastNameInput, 'Last name can only contain letters');
                isValid = false;
            }

            if (!isValid) {
                showSuccessMessage('Please fix the name fields', true);
                return;
            }

            // Save profile data
            saveProfileData();

            // Update header username
            updateHeaderUsername();

            // Show success message
            showSuccessMessage('Profile updated successfully!', false);
        });

        // Load saved data when page loads
        loadProfileData();
    }

    // Password validation
    const passwordForm = document.getElementById('passwordForm');
    const newPassword = document.getElementById('newPassword');
    const confirmPassword = document.getElementById('confirmPassword');
    const passwordError = document.getElementById('passwordError');
    const confirmError = document.getElementById('confirmError');

    if (passwordForm) {
        passwordForm.addEventListener('submit', function(e) {
            e.preventDefault();

            let isValid = true;

            // Check if new password is at least 8 characters
            if (newPassword.value.length < 8) {
                if (passwordError) {
                    passwordError.style.display = 'block';
                    newPassword.classList.add('error');
                }
                isValid = false;
            } else {
                if (passwordError) {
                    passwordError.style.display = 'none';
                    newPassword.classList.remove('error');
                }
            }

            // Check if passwords match
            if (newPassword.value !== confirmPassword.value) {
                if (confirmError) {
                    confirmError.style.display = 'block';
                    confirmPassword.classList.add('error');
                }
                isValid = false;
            } else {
                if (confirmError) {
                    confirmError.style.display = 'none';
                    confirmPassword.classList.remove('error');
                }
            }

            if (isValid) {
                // Show custom success message
                showSuccessMessage('Password changed successfully!', false);

                // Reset form
                passwordForm.reset();

                // إزالة أخطاء التنسيق
                newPassword.classList.remove('error');
                confirmPassword.classList.remove('error');
            } else {
                showSuccessMessage('Please fix the password errors', true);
            }
        });

        // Real-time validation
        if (newPassword) {
            newPassword.addEventListener('input', function() {
                if (this.value.length >= 8) {
                    if (passwordError) passwordError.style.display = 'none';
                    this.classList.remove('error');
                } else {
                    if (passwordError) passwordError.style.display = 'block';
                    this.classList.add('error');
                }
            });
        }

        if (confirmPassword) {
            confirmPassword.addEventListener('input', function() {
                if (this.value === newPassword.value) {
                    if (confirmError) confirmError.style.display = 'none';
                    this.classList.remove('error');
                } else {
                    if (confirmError) confirmError.style.display = 'block';
                    this.classList.add('error');
                }
            });
        }
    }

    // Loss Management
    const addLossBtn = document.getElementById('addLossBtn');
    const lossesContainer = document.getElementById('lossesContainer');
    const totalLossesElement = document.getElementById('totalLosses');
    const totalRevenuesElement = document.getElementById('totalRevenues');

    if (addLossBtn && lossesContainer) {
        // Add new loss item
        addLossBtn.addEventListener('click', function() {
            const newLossItem = document.createElement('div');
            newLossItem.className = 'financial-item editable';
            newLossItem.innerHTML = `
                <input type="text" class="item-input" placeholder="Loss description">
                <input type="number" class="amount-input" placeholder="Amount" value="0">
                <button class="delete-btn"><i class="fas fa-trash"></i></button>
            `;

            // Insert before the total
            lossesContainer.insertBefore(newLossItem, lossesContainer.lastElementChild);

            // Add event listeners to new inputs
            addLossInputListeners(newLossItem);

            // Update totals
            updateAllTotals();
        });

        // Add event listeners to existing loss items
        document.querySelectorAll('.financial-item:not(.revenue-item).editable').forEach(item => {
            addLossInputListeners(item);
        });

        // Function to add loss input listeners
        function addLossInputListeners(item) {
            const amountInput = item.querySelector('.amount-input');
            const deleteBtn = item.querySelector('.delete-btn');

            amountInput.addEventListener('input', updateAllTotals);
            amountInput.addEventListener('change', updateAllTotals);
            deleteBtn.addEventListener('click', function() {
                item.remove();
                updateAllTotals();
            });
        }
    }

    // Function to update all totals
    function updateAllTotals() {
        console.log('Updating totals...');

        // Calculate total revenues (ثابت من قاعدة البيانات)
        let totalRevenues = 34100;

        // Update total revenues display
        if (totalRevenuesElement) {
            totalRevenuesElement.textContent = `$${totalRevenues.toLocaleString()}`;
        }

        // Calculate total losses
        let totalLosses = 0;
        const lossInputs = document.querySelectorAll('.financial-item:not(.revenue-item).editable .amount-input');

        lossInputs.forEach(input => {
            const value = parseFloat(input.value) || 0;
            totalLosses += value;
        });

        // Update total losses display
        if (totalLossesElement) {
            totalLossesElement.textContent = `$${totalLosses.toLocaleString()}`;
        }

        // Calculate and update net profit
        const totalSalaries = 14500;
        const totalExpenses = totalSalaries + totalLosses;
        const netProfit = totalRevenues - totalExpenses;

        // Update summary
        const summaryExpenses = document.getElementById('summaryExpenses');
        const netProfitElement = document.getElementById('netProfit');
        const summaryRevenues = document.getElementById('summaryRevenues');

        if (summaryExpenses) {
            summaryExpenses.textContent = `$${totalExpenses.toLocaleString()}`;
        }
        if (netProfitElement) {
            netProfitElement.textContent = `$${netProfit.toLocaleString()}`;
        }
        if (summaryRevenues) {
            summaryRevenues.textContent = `$${totalRevenues.toLocaleString()}`;
        }
    }

    // Add event listeners to existing loss inputs on page load
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('amount-input') &&
            e.target.closest('.financial-item:not(.revenue-item).editable')) {
            updateAllTotals();
        }
    });

    // Initial totals calculation
    updateAllTotals();

    // Profile Image Change Functionality
    const fileInput = document.getElementById('fileInput');
    const profileImage = document.getElementById('profileImage');

    // Handle profile image change
    if (fileInput && profileImage) {
        fileInput.addEventListener('change', function(event) {
            const file = event.target.files[0];

            if (file) {
                // Check if the file is an image
                if (!file.type.match('image.*')) {
                    showSuccessMessage('Please select an image file (JPEG, PNG, GIF, etc.)', true);
                    return;
                }

                // Check file size (max 5MB)
                if (file.size > 5 * 1024 * 1024) {
                    showSuccessMessage('Image size should be less than 5MB', true);
                    return;
                }

                const reader = new FileReader();

                reader.onload = function(e) {
                    // Update the profile image source
                    profileImage.src = e.target.result;

                    // تحديث صورة الهيدر باستخدام نفس المفتاح
                    updateHeaderProfileImage(e.target.result);

                    // Save to localStorage with consistent key
                    localStorage.setItem('profileImage', e.target.result);

                    // Show success message
                    showSuccessMessage('Profile image updated successfully!', false);
                };

                reader.onerror = function() {
                    showSuccessMessage('Error reading the file. Please try again.', true);
                };

                reader.readAsDataURL(file);
            }
        });
    }

    // Load saved profile image from localStorage on page load
    function loadSavedProfileImage() {
        const savedImage = localStorage.getItem('profileImage');
        if (savedImage && profileImage) {
            profileImage.src = savedImage;
            updateHeaderProfileImage(savedImage);
        }
    }

    // Load saved image when page loads
    loadSavedProfileImage();

    // Password visibility toggle
    addPasswordVisibilityToggle();

    // Load username and profile image on page load
    loadUserData();
});

// وظائف التحقق من الاسم
function validateName(name) {
    const nameRegex = /^[A-Za-z\u0600-\u06FF\s]+$/;
    return nameRegex.test(name);
}

function validateNameInput(input) {
    const error = showNameError(input, '');

    if (!validateName(input.value) && input.value !== '') {
        showNameError(input, 'Name can only contain letters');
        input.style.borderColor = '#ff4444';
    } else {
        error.style.display = 'none';
        input.style.borderColor = '#e0e0e0';
    }
}

function showNameError(input, message) {
    let errorElement = input.parentNode.querySelector('.name-error');

    if (!errorElement) {
        errorElement = document.createElement('div');
        errorElement.className = 'name-error password-error';
        input.parentNode.appendChild(errorElement);
    }

    errorElement.textContent = message;
    errorElement.style.display = message ? 'block' : 'none';

    return errorElement;
}

// Profile Data Functions
function saveProfileData() {
    const profileForm = document.querySelector('.info-form');
    if (!profileForm) return;

    const profileData = {};
    const inputs = profileForm.querySelectorAll('input');

    inputs.forEach(input => {
        if (input.type !== 'file' && input.type !== 'radio') {
            const label = input.previousElementSibling;
            if (label && label.tagName === 'LABEL') {
                const fieldName = label.textContent.replace(':', '').trim().toLowerCase().replace(/ /g, '_');
                profileData[fieldName] = input.value;
            }
        }
    });

    // Handle gender
    const selectedGender = document.querySelector('input[name="gender"]:checked');
    if (selectedGender) {
        profileData['gender'] = selectedGender.parentElement.textContent.trim();
    }

    localStorage.setItem('profileData', JSON.stringify(profileData));
}

function loadProfileData() {
    const profileForm = document.querySelector('.info-form');
    if (!profileForm) return;

    const savedData = JSON.parse(localStorage.getItem('profileData')) || {};

    const inputs = profileForm.querySelectorAll('input');
    inputs.forEach(input => {
        if (input.type !== 'file' && input.type !== 'radio') {
            const label = input.previousElementSibling;
            if (label && label.tagName === 'LABEL') {
                const fieldName = label.textContent.replace(':', '').trim().toLowerCase().replace(/ /g, '_');
                if (savedData[fieldName]) {
                    input.value = savedData[fieldName];
                }
            }
        }
    });

    // Handle gender
    if (savedData['gender']) {
        const genderRadios = document.querySelectorAll('input[name="gender"]');
        genderRadios.forEach(radio => {
            if (radio.parentElement.textContent.trim() === savedData['gender']) {
                radio.checked = true;
            }
        });
    }
}

// تحديث اسم المستخدم في الهيدر
function updateHeaderUsername() {
    const profileForm = document.querySelector('.info-form');
    if (!profileForm) return;

    const firstNameInput = Array.from(profileForm.querySelectorAll('input')).find(input =>
        input.previousElementSibling?.textContent?.includes('First'));
    const lastNameInput = Array.from(profileForm.querySelectorAll('input')).find(input =>
        input.previousElementSibling?.textContent?.includes('Last'));

    if (firstNameInput && lastNameInput) {
        const fullName = `${firstNameInput.value} ${lastNameInput.value}`;
        const usernameElement = document.querySelector('.username');

        if (usernameElement) {
            usernameElement.textContent = fullName;
        }

        // حفظ الاسم في localStorage للاستخدام في الصفحات الأخرى
        localStorage.setItem('userFullName', fullName);
    }
}

// تحديث صورة الملف الشخصي في الهيدر
function updateHeaderProfileImage(imageSrc) {
    const headerProfileImg = document.querySelector('.user-btn img');

    if (headerProfileImg) {
        headerProfileImg.src = imageSrc;
        console.log('Header profile image updated successfully');
    } else {
        console.log('Header profile image element not found');
    }
}

// تحميل بيانات المستخدم من localStorage
function loadUserData() {
    // تحميل اسم المستخدم
    const savedName = localStorage.getItem('userFullName');
    if (savedName) {
        const usernameElement = document.querySelector('.username');
        if (usernameElement) {
            usernameElement.textContent = savedName;
        }
    }

    // تحميل صورة الهيدر
    const savedImage = localStorage.getItem('profileImage');
    if (savedImage) {
        const headerProfileImg = document.querySelector('.user-btn img');
        if (headerProfileImg) {
            headerProfileImg.src = savedImage;
        }
    }
}
// Success Message Function - Updated version
// Success Message Function - تختفي عند الضغط على أي مكان
function showSuccessMessage(message, isError = false) {
    // Remove existing messages
    const existingMessages = document.querySelectorAll('.custom-alert');
    existingMessages.forEach(msg => msg.remove());

    // Create alert element
    const alert = document.createElement('div');
    alert.className = 'custom-alert';
    alert.style.cssText = `
        position: fixed;
        top: 100px;
        left: 50%;
        transform: translateX(-50%) translateY(-20px);
        background: ${isError ? 'rgba(244,67,54,0.9)' : 'rgba(76,175,80,0.9)'};
        color: white;
        padding: 15px 30px;
        border-radius: 8px;
        z-index: 1;
        transition: all 0.3s ease-in-out;
        display: flex;
        align-items: center;
        gap: 12px;
        font-family: Arial, sans-serif;
        font-size: 16px;
        font-weight: 600;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        opacity: 0;
        max-width: 90%;
        text-align: center;
        cursor: pointer;
    `;

    alert.innerHTML = `
        <i class="fas ${isError ? 'fa-exclamation-circle' : 'fa-check-circle'}" style="font-size: 18px;"></i>
        ${message}
    `;

    document.body.appendChild(alert);

    // Animate in
    setTimeout(() => {
        alert.style.opacity = '1';
        alert.style.transform = 'translateX(-50%) translateY(0)';
    }, 100);

    let autoHideTimeout = setTimeout(hideMessage, 5000);

    function hideMessage() {
        alert.style.opacity = '0';
        alert.style.transform = 'translateX(-50%) translateY(-20px)';
        setTimeout(() => {
            if (alert.parentNode) {
                alert.parentNode.removeChild(alert);
            }
        }, 300);
        // نزيل الـ event listener عندما تختفي الرسالة
        document.removeEventListener('click', hideOnClick);
    }

    // function لإخفاء الرسالة عند النقر في أي مكان
    function hideOnClick(event) {
        // ما نهمل إذا المستخدم نقر على الرسالة نفسها
        if (!alert.contains(event.target)) {
            clearTimeout(autoHideTimeout);
            hideMessage();
        }
    }

    // نضيف event listener للنقر على أي مكان في الصفحة
    setTimeout(() => {
        document.addEventListener('click', hideOnClick);
    }, 100);

    // إذا المستخدم نقر على الرسالة نفسها، نخفيها مباشرة
    alert.addEventListener('click', function(e) {
        e.stopPropagation();
        clearTimeout(autoHideTimeout);
        hideMessage();
    });
}


// Password visibility toggle
function addPasswordVisibilityToggle() {
    const passwordFields = [
        document.getElementById('currentPassword'),
        document.getElementById('newPassword'),
        document.getElementById('confirmPassword')
    ];

    passwordFields.forEach(field => {
        if (field) {
            // Create eye icon
            const eyeIcon = document.createElement('span');
            eyeIcon.className = 'password-toggle';
            eyeIcon.innerHTML = '<i class="fas fa-eye"></i>';

            // Add styles
            eyeIcon.style.cssText = `
                position: absolute;
                right: 12px;
                top: 50%;
                transform: translateY(-50%);
                cursor: pointer;
                color: #666;
                z-index: 10;
                padding: 5px;
            `;

            // Wrap field in container
            const container = document.createElement('div');
            container.style.position = 'relative';
            container.style.width = '100%';

            field.parentNode.insertBefore(container, field);
            container.appendChild(field);
            container.appendChild(eyeIcon);

            // Add styles to password field
            field.style.paddingRight = '40px';
            field.style.width = '100%';
            field.style.boxSizing = 'border-box';

            // Add toggle functionality
            eyeIcon.addEventListener('click', function() {
                const type = field.type === 'password' ? 'text' : 'password';
                field.type = type;

                // Change eye icon
                if (type === 'text') {
                    this.innerHTML = '<i class="fas fa-eye-slash"></i>';
                    this.style.color = '#675788';
                } else {
                    this.innerHTML = '<i class="fas fa-eye"></i>';
                    this.style.color = '#666';
                }
            });
        }
    });
}