// Навигация с анимацией
const frame = document.getElementById('pageFrame');
const navLinks = document.querySelectorAll('.nav-item');

// Функция обновления активного пункта меню
function updateActiveNav(currentPath) {
    navLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && href.includes(currentPath)) {
            link.classList.add('active');
        } else {
            link.classList.remove('active');
        }
    });
}

// При загрузке устанавливаем активный пункт (главная по умолчанию)
updateActiveNav('home.html');

// Обработка навигации
navLinks.forEach(link => {
    link.addEventListener('click', (e) => {
        e.preventDefault();
        const href = link.getAttribute('href');
        
        // Анимация исчезновения
        frame.style.animation = 'none';
        frame.offsetHeight;
        frame.style.opacity = '0';
        frame.style.transform = 'translateY(10px)';
        
        setTimeout(() => {
            frame.src = href;
            frame.style.opacity = '1';
            frame.style.transform = 'translateY(0)';
            frame.style.animation = 'fadeSlideUp 0.45s ease-out forwards';
        }, 200);
        
        // Обновляем активный класс
        navLinks.forEach(l => l.classList.remove('active'));
        link.classList.add('active');
    });
});

// При загрузке iframe обновляем активный пункт
frame.addEventListener('load', () => {
    const src = frame.src;
    if (src.includes('home')) updateActiveNav('home.html');
    else if (src.includes('wiki')) updateActiveNav('wiki.html');
    else if (src.includes('pass')) updateActiveNav('pass.html');
    else if (src.includes('organizations')) updateActiveNav('organizations.html');
    else if (src.includes('members')) updateActiveNav('members.html');
    else if (src.includes('rules')) updateActiveNav('rules.html');
    else if (src.includes('profile')) updateActiveNav('profile1.html');
});

// Анимация онлайн-счетчика (имитация обновления)
let onlineCount = 248;
setInterval(() => {
    const change = Math.floor(Math.random() * 7) - 3;
    let newCount = onlineCount + change;
    if (newCount < 120) newCount = 120;
    if (newCount > 450) newCount = 450;
    onlineCount = newCount;
    const onlineSpan = document.getElementById('onlineCount');
    if (onlineSpan) onlineSpan.textContent = onlineCount;
}, 30000);