
    // Student Profile Management
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

    // Save to localStorage for persistence
    localStorage.setItem('studentProfileImage', e.target.result);

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
    const savedImage = localStorage.getItem('studentProfileImage');
    if (savedImage && profileImage) {
    profileImage.src = savedImage;
}
}

    // Load saved image when page loads
    loadSavedProfileImage();

    // Password visibility toggle
    addPasswordVisibilityToggle();

    // Initialize messages for students
    initializeStudentMessages();
});

    // وظائف التحقق من الاسم
    function validateName(name) {
    // فقط أحرف إنجليزية وعربية مسموحة
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

    localStorage.setItem('studentProfileData', JSON.stringify(profileData));
}

    function loadProfileData() {
    const profileForm = document.querySelector('.info-form');
    if (!profileForm) return;

    const savedData = JSON.parse(localStorage.getItem('studentProfileData')) || {};

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
    localStorage.setItem('studentFullName', fullName);
}
}

    // Success Message Function
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

                    // Save to localStorage for persistence
                    localStorage.setItem('studentProfileImage', e.target.result);

                    // تحديث الصورة في الهيدر مباشرة
                    updateHeaderProfileImage();

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
    document.addEventListener('DOMContentLoaded', function() {

        // --- 1. إعدادات الشهادة (مهم جداً ضبط الإحداثيات هنا) ---
        const certificateConfig = {
            templateSrc: 'img/cer.png', // مسار صورة الشهادة الفارغة
            fontBase: 'serif',          // نوع الخط
            textColor: '#3b3b3b',       // لون النص

            // إحداثيات (X, Y) للنصوص بناءً على حجم الصورة الأصلي
            // قد تحتاج لتجربة وتغيير هذه الأرقام لتناسب مكان الفراغ في صورتك بالضبط
            name: { x: 1000, y: 560, fontSize: '90px', fontWeight: 'bold' }, // المنتصف تقريباً للاسم
            course: { x: 1000, y: 850, fontSize: '60px', fontWeight: 'normal' }, // المنتصف لاسم الكورس
            date: { x: 1000, y: 1150, fontSize: '30px', fontWeight: 'normal' }   // في الأسفل للتاريخ
        };

        // بيانات وهمية للكورسات المكتملة (يمكنك جلبها لاحقاً من قاعدة البيانات)
        const completedCourses = [
            { id: 1, title: "Intro To Programming", date: "12/4/2025" },
            { id: 2, title: "Web Development Basics", date: "20/5/2025" }
        ];

        // ---------------------------------------------------------
        // --- 2. دالة توليد الشهادة (الرسم على Canvas) ---
        // ---------------------------------------------------------
        function generateCertificate(studentName, courseName, dateStr, callback) {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            const img = new Image();

            // إضافة Timestamp لتجنب الكاش
            img.src = certificateConfig.templateSrc + '?t=' + new Date().getTime();
            img.crossOrigin = "Anonymous"; // للسماح بتحميل الصورة وتعديلها

            img.onload = function() {
                // ضبط حجم الكانفاس نفس حجم الصورة الأصلية للحفاظ على الجودة
                canvas.width = img.width;
                canvas.height = img.height;

                // رسم القالب
                ctx.drawImage(img, 0, 0);

                // إعدادات النصوص المشتركة
                ctx.textAlign = 'center'; // محاذاة النص في المنتصف
                ctx.fillStyle = certificateConfig.textColor;

                // 1. رسم اسم الطالب
                ctx.font = `${certificateConfig.name.fontWeight} ${certificateConfig.name.fontSize} ${certificateConfig.fontBase}`;
                // نستخدم canvas.width / 2 لضمان التوسط الأفقي
                ctx.fillText(studentName, canvas.width / 2, certificateConfig.name.y);

                // 2. رسم اسم الكورس
                ctx.font = `${certificateConfig.course.fontWeight} ${certificateConfig.course.fontSize} ${certificateConfig.fontBase}`;
                ctx.fillText(courseName, canvas.width / 2, certificateConfig.course.y);

                // 3. رسم التاريخ
                ctx.font = `${certificateConfig.date.fontWeight} ${certificateConfig.date.fontSize} ${certificateConfig.fontBase}`;
                // التاريخ غالباً ليس في المنتصف تماماً، نستخدم الإحداثيات اليدوية
                ctx.fillText(dateStr, canvas.width / 2, certificateConfig.date.y);

                // إرجاع الصورة بصيغة Base64
                const dataURL = canvas.toDataURL('image/png');
                callback(dataURL);
            };

            img.onerror = function() {
                console.error("Error loading certificate template. Check the path: " + certificateConfig.templateSrc);
            };
        }

        // ---------------------------------------------------------
        // --- 3. عرض الشهادات في الصفحة ---
        // ---------------------------------------------------------
        function loadCertificates() {
            const container = document.getElementById('certificatesContainer');

            // بيانات الطالب (كما في الكود السابق)
            let studentName = localStorage.getItem('studentFullName') || "Yaqeen Shataat";

            container.innerHTML = ''; // تنظيف القائمة

            if (completedCourses.length === 0) {
                container.innerHTML = '<div class="empty-state">No certificates available yet.</div>';
                return;
            }

            completedCourses.forEach(course => {
                // 1. إنشاء "كرت" الشهادة (الهيكل الخارجي)
                const certCard = document.createElement('div');
                certCard.className = 'cert-card';

                // 2. المحتوى الداخلي (لاحظ أننا وضعنا صورة تحميل مؤقتة)
// ... داخل دالة loadCertificates ...

// المحتوى الداخلي الجديد (يتم وضعه داخل const certCard = document.createElement('div');)
                certCard.innerHTML = `
    <div class="certificate-card" 
        style="
            border: 1px solid #ddd; 
            padding: 15px; 
            border-radius: 10px;
            margin-bottom: 15px;
            background: white;
        "
    >

        <div class="cert-details-wrapper" style="display: flex; align-items: center; gap: 15px;">
            
            <!-- الصورة المصغرة مع حدود وتكبير -->
            <div class="thumbnail-wrapper" 
                style="
                    width: 95px; 
                    height: 95px; 
                    background: #f5f5f5; 
                    border-radius: 8px; 
                    overflow: hidden;
                    border: .5px solid #675788;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                "
            >
                <div class="loading-spinner">Generating...</div>
            </div>

            <!-- اسم الكورس والتاريخ -->
            <div class="cert-info" style="flex-grow: 1;">
                <h4 style="margin: 0; font-size: 17px; font-weight: 600;">${course.title}</h4>
                <p style="margin: 4px 0 0; font-size: 12px; color: #6c757d;">
                    <i class="far fa-calendar-alt"></i> ${course.date}
                </p>
            </div>

            <!-- الأزرار على اليمين -->
            <div class="cert-actions" style="display: flex; flex-direction: column; gap: 6px;">

                <button class="update-btn view-btn" 
                    style="
                        background-color: #675788; 
                        color: white; 
                        border: none; 
                        padding: 6px 12px; 
                        border-radius: 5px; 
                        cursor: pointer; 
                        font-size: 12px;
                    ">
                    <i class="fas fa-eye"></i> View
                </button>

                <a class="download-link" style="display:none;"></a>

                <button class="update-btn download-btn" 
                    style="
                        background-color: #675788; 
                        color: white; 
                        border: none; 
                        padding: 6px 12px; 
                        border-radius: 5px; 
                        cursor: pointer; 
                        font-size: 12px;
                    ">
                    <i class="fas fa-download"></i> Save
                </button>

            </div>

        </div>

    </div>
`;

// ... يتبع بقية كود loadCertificates ...
                container.appendChild(certCard);

                // 3. استدعاء دالة الرسم (Generate)
                generateCertificate(studentName, course.title, course.date, (imgDataUrl) => {

                    // أ) إنشاء عنصر الصورة المصغرة
                    // ... داخل دالة loadCertificates ...

// أ) إنشاء عنصر الصورة المصغرة
                    const thumbImg = document.createElement('img');
                    thumbImg.src = imgDataUrl;
                    thumbImg.className = 'cert-thumbnail';

// --- الإضافة الجديدة: إجبار الصورة على التصغير فوراً ---
                    thumbImg.style.width = '150px';       // عرض ثابت
                    thumbImg.style.height = '100px';      // ارتفاع ثابت
                    thumbImg.style.objectFit = 'cover';   // لضمان عدم مط الصورة
                    thumbImg.style.borderRadius = '8px';  // حواف ناعمة
                    thumbImg.style.border = '1px solid #ddd';
                    thumbImg.style.cursor = 'pointer';
                    thumbImg.style.display = 'block';     // لمنع مشاكل المحاذاة
// -----------------------------------------------------

                    thumbImg.alt = "Certificate Thumbnail";

// ب) استبدال اللودينج بالصورة المصغرة
                    const thumbWrapper = certCard.querySelector('.thumbnail-wrapper');
                    if (thumbWrapper) {
                        thumbWrapper.innerHTML = '';
                        thumbWrapper.appendChild(thumbImg);
                    }



                    // ج) تفعيل زر الـ View (لفتح الصورة الكبيرة)
                    const viewBtn = certCard.querySelector('.view-btn');
                    viewBtn.addEventListener('click', () => {
                        openCertModal(imgDataUrl); // فتح المودال بالصورة الكبيرة
                    });

                    // د) تفعيل النقر على الصورة المصغرة نفسها
                    thumbImg.addEventListener('click', () => {
                        openCertModal(imgDataUrl);
                    });

                    // هـ) تفعيل زر التحميل
                    const downloadBtn = certCard.querySelector('.download-btn');
                    downloadBtn.addEventListener('click', () => {
                        const link = document.createElement('a');
                        link.href = imgDataUrl;
                        link.download = `Certificate-${course.title}.png`;
                        link.click();
                    });
                });
            });
        }
        // دالة فتح المودال
        function openCertModal(imgSrc) {
            const modal = document.getElementById('certificateModal');
            const modalImg = document.getElementById('modalCertImage');
            const downloadBtn = document.getElementById('downloadBtn');

            modalImg.src = imgSrc;
            downloadBtn.href = imgSrc;
            modal.style.display = 'block';
        }

        // إغلاق المودال
        const modal = document.getElementById('certificateModal');
        const closeBtn = document.getElementById('closeModal');

        if (closeBtn) {
            closeBtn.onclick = function() {
                modal.style.display = "none";
            }
        }

        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }

        // ---------------------------------------------------------
        // --- بقية الكود الأصلي الخاص بك (Tabs, Profile, etc.) ---
        // ---------------------------------------------------------

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

                // إذا تم فتح تاب الشهادات، قم بتحميلها
                if(tabId === 'Certificate') {
                    loadCertificates();
                }
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

            if (firstNameInput) {
                firstNameInput.addEventListener('input', function() { validateNameInput(this); });
            }
            if (lastNameInput) {
                lastNameInput.addEventListener('input', function() { validateNameInput(this); });
            }

            updateBtn.addEventListener('click', function(e) {
                e.preventDefault();
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
                saveProfileData();
                updateHeaderUsername();
                showSuccessMessage('Profile updated successfully!', false);

                // إعادة تحميل الشهادات بالاسم الجديد إذا كنا في صفحة الشهادات
                if(document.getElementById('Certificate').classList.contains('active')){
                    loadCertificates();
                }
            });
            loadProfileData();
        }

        // Password validation logic... (تم اختصاره لأنه موجود لديك بالفعل)
        // ... [ضع بقية كود الباسورد والصورة الشخصية كما هو في ملفك الأصلي هنا] ...

        // (فقط تأكد من إغلاق دالة DOMContentLoaded في النهاية)

        // Helper Functions
        function validateName(name) {
            const nameRegex = /^[A-Za-z\u0600-\u06FF\s]+$/;
            return nameRegex.test(name);
        }

        function validateNameInput(input) {
            // ... (نفس كودك)
        }

        function showNameError(input, message) {
            // ... (نفس كودك)
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

        function saveProfileData() {
            const inputs = document.querySelectorAll('.info-form input');
            const profileData = {};
            // ... (كود الحفظ الخاص بك)
            // تأكد من حفظ الاسم الكامل ليستخدم في الشهادة
            const fname = document.querySelector('input[placeholder*="First"]')?.value;
            const lname = document.querySelector('input[placeholder*="Last"]')?.value;
            if(fname && lname) localStorage.setItem('studentFullName', fname + ' ' + lname);
        }

        function loadProfileData() {
            // ... (كود التحميل الخاص بك)
        }

        function updateHeaderUsername() {
            // ... (كود التحديث الخاص بك)
        }

        function showSuccessMessage(message, isError = false) {
            // ... (كود الرسائل الخاص بك)
            const alert = document.createElement('div');
            alert.className = 'custom-alert';
            alert.textContent = message;
            alert.style.cssText = `position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: ${isError ? 'red' : 'green'}; color: white; padding: 10px 20px; border-radius: 5px; z-index: 1000;`;
            document.body.appendChild(alert);
            setTimeout(() => alert.remove(), 3000);
        }

    });
