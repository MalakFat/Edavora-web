
document.addEventListener('DOMContentLoaded', function() {
    // تحميل صورة البروفايل - Student Version
    const savedImage = localStorage.getItem('studentProfileImage');
    const headerProfileImg = document.querySelector('.user-btn img');

    if (savedImage && headerProfileImg) {
        headerProfileImg.src = savedImage;
    }

    // تحميل اسم المستخدم - Student Version
    const savedName = localStorage.getItem('studentFullName');
    const usernameElement = document.querySelector('.username');

    if (savedName && usernameElement) {
        usernameElement.textContent = savedName;
    }

});


// تأكد من أن هذا الكود موجود في head.js
// Sample data
const teachers = [
    { id: 1, name: "Ahmed Mohamed" },
    { id: 2, name: "Fatima Ali" },
    { id: 3, name: "Mohamed Khaled" },
    { id: 4, name: "Sara Ahmed" },
    { id: 5, name: "Youssef Ibrahim" }
];

const manegars = [
    { id: 1, name: "Mariam Hassan" },
    { id: 2, name: "Omar Saeed" },
    { id: 3, name: "Laila Mahmoud" },
    { id: 4, name: "Khaled Abdullah" },
    { id: 5, name: "Nora Saleem" }
];

// Function to format time
function formatTime(date) {
    // إذا كان date ليس كائن Date، حوليه
    if (!(date instanceof Date)) {
        date = new Date(date);
    }

    const now = new Date();
    const diff = now - date;

    if (diff < 60000) { // أقل من دقيقة
        return "Just now";
    } else if (diff < 3600000) { // أقل من ساعة
        const minutes = Math.floor(diff / 60000);
        return `${minutes} min ago`;
    } else if (diff < 86400000) { // أقل من يوم
        const hours = Math.floor(diff / 3600000);
        return `${hours} hour${hours > 1 ? 's' : ''} ago`;
    } else if (diff < 604800000) { // أقل من أسبوع
        const days = Math.floor(diff / 86400000);
        return `${days} day${days > 1 ? 's' : ''} ago`;
    } else {
        // أكثر من أسبوع - عرض التاريخ
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }
}

function getCurrentTime() {
    return new Date().toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
}

// Sample conversations data
let conversations = [];

// Notifications data
let notifications = [
    {
        id: 1,
        title: "New Assignment",
        message: "A new assignment has been added in Mathematics. Deadline: May 15",
        time: "5 minutes ago",
        type: "info",
        read: false,
        timestamp: new Date(Date.now() - 5 * 60 * 1000) // 5 minutes ago
    },
    {
        id: 2,
        title: "Assignment Submitted",
        message: "English assignment has been submitted successfully",
        time: "1 hour ago",
        type: "success",
        read: false,
        timestamp: new Date(Date.now() - 60 * 60 * 1000) // 1 hour ago
    },
    {
        id: 3,
        title: "Deadline Reminder",
        message: "Only 3 days left to submit the Science project",
        time: "2 days ago",
        type: "warning",
        read: true,
        timestamp: new Date(Date.now() - 2 * 24 * 60 * 60 * 1000) // 2 days ago
    }
];

// Function to save conversations to localStorage
function saveConversations() {
    localStorage.setItem('edvoraConversations', JSON.stringify(conversations));
}

// Function to save notifications to localStorage
function saveNotifications() {
    localStorage.setItem('edvoraNotifications', JSON.stringify(notifications));
}

// Function to load conversations from localStorage
function loadSavedConversations() {
    const saved = localStorage.getItem('edvoraConversations');
    if (saved) {
        conversations = JSON.parse(saved);
    } else {
        // If no saved conversations, use sample data
        const sampleTime = new Date(Date.now() - 2 * 60 * 60 * 1000); // 2 hours ago
        conversations = [
            {
                id: 1,
                userId: 1,
                userName: "Ahmed Mohamed",
                userType: "teacher",
                unread: true,
                lastMessage: "Please submit your assignment by Friday",
                lastMessageTime: formatTime(sampleTime),
                messages: [
                    { text: "Hello, I have a question about the math assignment", sender: "me", time: "10:30 AM", timestamp: new Date(Date.now() - 4 * 60 * 60 * 1000) },
                    { text: "Sure, what would you like to know?", sender: "them", time: "10:32 AM", timestamp: new Date(Date.now() - 4 * 60 * 60 * 1000 + 2 * 60 * 1000) },
                    { text: "I'm having trouble with problem number 5", sender: "me", time: "10:35 AM", timestamp: new Date(Date.now() - 4 * 60 * 60 * 1000 + 5 * 60 * 1000) },
                    { text: "I can help you with that during office hours tomorrow", sender: "them", time: "10:40 AM", timestamp: new Date(Date.now() - 4 * 60 * 60 * 1000 + 10 * 60 * 1000) },
                    { text: "Please submit your assignment by Friday", sender: "them", time: getCurrentTime(), timestamp: sampleTime }
                ]
            },
            {
                id: 2,
                userId: 2,
                userName: "Fatima Ali",
                userType: "teacher",
                unread: false,
                lastMessage: "The exam will be next week",
                lastMessageTime: formatTime(new Date(Date.now() - 24 * 60 * 60 * 1000)),
                messages: [
                    { text: "When is our next exam?", sender: "me", time: "Yesterday", timestamp: new Date(Date.now() - 24 * 60 * 60 * 1000) },
                    { text: "The exam will be next week", sender: "them", time: "Yesterday", timestamp: new Date(Date.now() - 24 * 60 * 60 * 1000) }
                ]
            },
            {
                id: 3,
                userId: 1,
                userName: "Mariam Hassan",
                userType: "manegar",
                unread: true,
                lastMessage: "Can you help me with the science project?",
                lastMessageTime: formatTime(new Date(Date.now() - 30 * 60 * 1000)),
                messages: [
                    { text: "Hi, are you available to study together?", sender: "them", time: "2 days ago", timestamp: new Date(Date.now() - 48 * 60 * 60 * 1000) },
                    { text: "Yes, I'm free tomorrow afternoon", sender: "me", time: "2 days ago", timestamp: new Date(Date.now() - 48 * 60 * 60 * 1000) },
                    { text: "Can you help me with the science project?", sender: "them", time: "30 minutes ago", timestamp: new Date(Date.now() - 30 * 60 * 1000) }
                ]
            }
        ];
        saveConversations();
    }
}

// Function to load notifications from localStorage
function loadSavedNotifications() {
    const saved = localStorage.getItem('edvoraNotifications');
    if (saved) {
        notifications = JSON.parse(saved);
    } else {
        // If no saved notifications, use sample data
        notifications = [
            {
                id: 1,
                title: "New Assignment",
                message: "A new assignment has been added in Mathematics. Deadline: May 15",
                time: "5 minutes ago",
                type: "info",
                read: false,
                timestamp: new Date(Date.now() - 5 * 60 * 1000)
            },
            {
                id: 2,
                title: "Assignment Submitted",
                message: "English assignment has been submitted successfully",
                time: "1 hour ago",
                type: "success",
                read: false,
                timestamp: new Date(Date.now() - 60 * 60 * 1000)
            },
            {
                id: 3,
                title: "Deadline Reminder",
                message: "Only 3 days left to submit the Science project",
                time: "2 days ago",
                type: "warning",
                read: true,
                timestamp: new Date(Date.now() - 2 * 24 * 60 * 60 * 1000)
            }
        ];
        saveNotifications();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Load saved data
    loadSavedConversations();
    loadSavedNotifications();

    // Notification elements
    const notificationIcon = document.querySelector('.notification-icon');
    const notificationsDropdown = document.querySelector('.notifications-dropdown');
    const markAllReadBtn = document.querySelector('.mark-all-read');
    const notificationBadge = document.querySelector('.notification-badge');
    const notificationsList = document.querySelector('.notifications-list');

    // Message elements
    const messageIcon = document.querySelector('.message-icon');
    const messagesDropdown = document.querySelector('.messages-dropdown');
    const messageBadge = document.querySelector('.message-badge');
    const conversationsList = document.getElementById('conversationsList');
    const conversationView = document.getElementById('conversationView');
    const backToConversations = document.getElementById('backToConversations');
    const conversationUserName = document.getElementById('conversationUserName');
    const messagesContainer = document.getElementById('messagesContainer');
    const messageInput = document.getElementById('messageInput');
    const sendMessageBtn = document.getElementById('sendMessageBtn');

    // Message modal elements
    const messageModal = document.getElementById('messageModal');
    const openMessageModalBtn = document.getElementById('openMessageModal');
    const closeMessageModalBtn = document.getElementById('closeMessageModal');
    const cancelMessageBtn = document.getElementById('cancelMessage');
    const sendMessageModalBtn = document.getElementById('sendMessage');
    const typeButtons = document.querySelectorAll('.type-btn');
    const selectAllBtn = document.getElementById('selectAll');
    const recipientsContainer = document.getElementById('recipientsContainer');
    const messageText = document.getElementById('messageText');
    const successMessage = document.getElementById('successMessage');

    let currentRecipients = teachers;
    let currentType = 'teachers';
    let currentConversation = null;

    // Initialize
    loadConversations();
    loadNotifications();
    updateMessageBadge();
    updateNotificationBadge();

    // دالة للتمرير إلى الأسفل
    function scrollToBottom() {
        setTimeout(() => {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }, 100);
    }

    // Load notifications
    function loadNotifications() {
        notificationsList.innerHTML = '';

        notifications.forEach(notification => {
            const notificationItem = document.createElement('div');
            notificationItem.className = `notification-item ${notification.read ? '' : 'unread'}`;
            notificationItem.innerHTML = `
                <div class="notification-icon-small ${notification.type}">
                    <i class="fas ${getNotificationIcon(notification.type)}"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title">${notification.title}</div>
                    <div class="notification-message">${notification.message}</div>
                    <div class="notification-time">${formatTime(notification.timestamp)}</div>
                </div>
                ${!notification.read ? '<div class="notification-dot"></div>' : ''}
            `;

            notificationItem.addEventListener('click', function() {
                if (!notification.read) {
                    notification.read = true;
                    saveNotifications();
                    loadNotifications();
                    updateNotificationBadge();
                }
            });

            notificationsList.appendChild(notificationItem);
        });
    }

    function getNotificationIcon(type) {
        switch(type) {
            case 'info': return 'fa-book';
            case 'success': return 'fa-check-circle';
            case 'warning': return 'fa-exclamation-triangle';
            default: return 'fa-bell';
        }
    }

    // Update notification badge
    function updateNotificationBadge() {
        const unreadCount = notifications.filter(n => !n.read).length;
        if (unreadCount > 0) {
            notificationBadge.textContent = unreadCount;
            notificationBadge.style.display = 'flex';
        } else {
            notificationBadge.style.display = 'none';
        }
    }

    // Toggle notifications dropdown
    notificationIcon.addEventListener('click', function(e) {
        e.stopPropagation();
        notificationsDropdown.classList.toggle('active');
        messagesDropdown.classList.remove('active');

        // Hide notification badge when opening notifications
        if (notificationsDropdown.classList.contains('active')) {
            notificationBadge.style.display = 'none';
        }
    });

    // Toggle messages dropdown
    messageIcon.addEventListener('click', function(e) {
        e.stopPropagation();
        messagesDropdown.classList.toggle('active');
        notificationsDropdown.classList.remove('active');

        // Reset to conversations list when opening
        if (messagesDropdown.classList.contains('active')) {
            conversationView.classList.remove('active');
            conversationsList.style.display = 'block';
            loadConversations();
        }
    });

    // Close dropdowns when clicking outside
    document.addEventListener('click', function() {
        notificationsDropdown.classList.remove('active');
        messagesDropdown.classList.remove('active');
    });

    // Prevent dropdowns from closing when clicking inside
    notificationsDropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    messagesDropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });

    // Mark all notifications as read
    markAllReadBtn.addEventListener('click', function() {
        notifications.forEach(notification => {
            notification.read = true;
        });
        saveNotifications();
        loadNotifications();
        updateNotificationBadge();
    });

    // Open message modal
    openMessageModalBtn.addEventListener('click', function() {
        messageModal.classList.add('active');
        loadRecipients(currentRecipients);
        successMessage.classList.remove('active');
    });

    // Close message modal
    function closeMessageModal() {
        messageModal.classList.remove('active');
        // Reset form
        messageText.value = '';
        recipientsContainer.querySelectorAll('.recipient-item').forEach(item => {
            item.classList.remove('selected');
        });
        selectAllBtn.textContent = 'Select All';
        successMessage.classList.remove('active');
    }

    closeMessageModalBtn.addEventListener('click', closeMessageModal);
    cancelMessageBtn.addEventListener('click', closeMessageModal);

    // Close modal when clicking outside
    messageModal.addEventListener('click', function(e) {
        if (e.target === messageModal) {
            closeMessageModal();
        }
    });

    // Change recipient type
    typeButtons.forEach(button => {
        button.addEventListener('click', function() {
            typeButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');

            currentType = this.dataset.type;
            currentRecipients = currentType === 'teachers' ? teachers : manegars;

            loadRecipients(currentRecipients);
        });
    });

    // Load recipients list
    function loadRecipients(recipients) {
        recipientsContainer.innerHTML = '';

        recipients.forEach(recipient => {
            const recipientItem = document.createElement('div');
            recipientItem.className = 'recipient-item';
            recipientItem.innerHTML = `
                <div class="recipient-name">${recipient.name}</div>
            `;

            recipientItem.addEventListener('click', function() {
                this.classList.toggle('selected');
            });

            recipientsContainer.appendChild(recipientItem);
        });
    }

    // Select all / Deselect all
    selectAllBtn.addEventListener('click', function() {
        const recipientItems = recipientsContainer.querySelectorAll('.recipient-item');
        const allSelected = Array.from(recipientItems).every(item => item.classList.contains('selected'));

        recipientItems.forEach(item => {
            if (allSelected) {
                item.classList.remove('selected');
            } else {
                item.classList.add('selected');
            }
        });

        selectAllBtn.textContent = allSelected ? 'Select All' : 'Deselect All';
    });

    // Send message from modal
    sendMessageModalBtn.addEventListener('click', function() {
        const selectedRecipients = Array.from(recipientsContainer.querySelectorAll('.recipient-item.selected'))
            .map(item => item.querySelector('.recipient-name').textContent);

        if (selectedRecipients.length === 0) {
            alert('Please select at least one recipient');
            return;
        }

        if (!messageText.value.trim()) {
            alert('Please enter a message');
            return;
        }

        const currentTime = new Date();

        // Create new conversations for selected recipients
        selectedRecipients.forEach(recipientName => {
            const recipient = [...teachers, ...manegars].find(r => r.name === recipientName);
            if (recipient) {
                const existingConversation = conversations.find(c => c.userName === recipientName);
                if (!existingConversation) {
                    const userType = teachers.some(t => t.name === recipientName) ? 'teacher' : 'manegar';
                    conversations.push({
                        id: Date.now(),
                        userId: recipient.id,
                        userName: recipientName,
                        userType: userType,
                        unread: false,
                        lastMessage: messageText.value,
                        lastMessageTime: formatTime(currentTime),
                        messages: [
                            {
                                text: messageText.value,
                                sender: "me",
                                time: getCurrentTime(),
                                timestamp: currentTime
                            }
                        ]
                    });
                } else {
                    // Update existing conversation
                    existingConversation.lastMessage = messageText.value;
                    existingConversation.lastMessageTime = formatTime(currentTime);
                    existingConversation.messages.push({
                        text: messageText.value,
                        sender: "me",
                        time: getCurrentTime(),
                        timestamp: currentTime
                    });
                }
            }
        });

        // Save conversations to localStorage
        saveConversations();

        // Update UI
        loadConversations();
        updateMessageBadge();

        // Show success message
        successMessage.classList.add('active');

        // التمرير التلقائي إلى رسالة النجاح
        setTimeout(() => {
            successMessage.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }, 100);

        // إغلاق النافذة بعد 2 ثانية
        setTimeout(() => {
            closeMessageModal();
        }, 2000);
    });

    // Load conversations list
    function loadConversations() {
        conversationsList.innerHTML = '';

        if (conversations.length === 0) {
            conversationsList.innerHTML = '<div class="conversation-item" style="text-align: center; color: var(--gray);">No conversations yet</div>';
            return;
        }

        // تحديث الأوقات تلقائياً
        conversations.forEach(conversation => {
            if (conversation.messages.length > 0) {
                // أخذ آخر رسالة (الأحدث)
                const lastMessage = conversation.messages[conversation.messages.length - 1];
                conversation.lastMessageTime = formatTime(new Date(lastMessage.timestamp));
                conversation.lastMessage = lastMessage.text;
            }
        });

        // عرض المحادثات من الأحدث إلى الأقدم
        const sortedConversations = [...conversations].sort((a, b) => {
            const timeA = a.messages.length > 0 ? new Date(a.messages[a.messages.length - 1].timestamp) : new Date(0);
            const timeB = b.messages.length > 0 ? new Date(b.messages[b.messages.length - 1].timestamp) : new Date(0);
            return timeB - timeA; // من الأحدث إلى الأقدم
        });

        sortedConversations.forEach(conversation => {
            const conversationItem = document.createElement('div');
            conversationItem.className = `conversation-item ${conversation.unread ? 'unread' : ''}`;
            conversationItem.innerHTML = `
            <div class="conversation-header">
                <div class="conversation-user">${conversation.userName}</div>
                <div class="conversation-time">${conversation.lastMessageTime}</div>
            </div>
            <div class="conversation-preview">${conversation.lastMessage}</div>
            ${conversation.unread ? '<div class="conversation-dot"></div>' : ''}
        `;

            conversationItem.addEventListener('click', function() {
                openConversation(conversation.id);
                // Mark as read
                conversation.unread = false;
                saveConversations();
                updateMessageBadge();
                loadConversations();
            });

            conversationsList.appendChild(conversationItem);
        });
    }

    // Open conversation
    function openConversation(conversationId) {
        currentConversation = conversations.find(c => c.id === conversationId);
        if (!currentConversation) return;

        conversationUserName.textContent = currentConversation.userName;
        loadMessages(currentConversation.messages);

        // Switch to conversation view
        conversationsList.style.display = 'none';
        conversationView.classList.add('active');

        // التأكد من التمرير بعد تحميل المحادثة
        setTimeout(() => {
            scrollToBottom();
        }, 200);
    }

    // Load messages in conversation
    function loadMessages(messages) {
        messagesContainer.innerHTML = '';

        if (messages.length === 0) {
            messagesContainer.innerHTML = '<div style="text-align: center; color: var(--gray); padding: 20px;">No messages yet</div>';
            scrollToBottom();
            return;
        }

        messages.forEach(message => {
            const messageElement = document.createElement('div');
            messageElement.className = `message ${message.sender === 'me' ? 'sent' : 'received'}`;
            messageElement.innerHTML = `
                <div class="message-text">${message.text}</div>
                <div class="message-time">${message.time}</div>
            `;
            messagesContainer.appendChild(messageElement);
        });

        // التمرير إلى أحدث رسالة
        scrollToBottom();
    }


    // Back to conversations list
    backToConversations.addEventListener('click', function() {
        conversationView.classList.remove('active');
        conversationsList.style.display = 'block';
    });

    // Send message in conversation
    function sendMessage() {
        const text = messageInput.value.trim();
        if (!text) return;

        // Add message to current conversation
        if (currentConversation) {
            const currentTime = new Date();
            const newMessage = {
                text: text,
                sender: 'me',
                time: getCurrentTime(),
                timestamp: currentTime
            };

            currentConversation.messages.push(newMessage);
            currentConversation.lastMessage = text;
            currentConversation.lastMessageTime = formatTime(currentTime);

            // Save conversations to localStorage
            saveConversations();

            // Update UI
            loadMessages(currentConversation.messages);
            loadConversations();
            updateMessageBadge();

            // Clear input
            messageInput.value = '';

            // تمرير إضافي للتأكد
            scrollToBottom();
        }
    }

    sendMessageBtn.addEventListener('click', sendMessage);

    messageInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            sendMessage();
        }
    });

    // Update message badge count
    function updateMessageBadge() {
        const unreadCount = conversations.filter(c => c.unread).length;
        if (unreadCount > 0) {
            messageBadge.textContent = unreadCount;
            messageBadge.style.display = 'flex';
        } else {
            messageBadge.style.display = 'none';
        }
    }

    // Load initial recipients
    loadRecipients(currentRecipients);

    // تحديث الوقت تلقائياً كل دقيقة
    setInterval(() => {
        if (messagesDropdown.classList.contains('active')) {
            loadConversations();
        }
    }, 60000);
});

