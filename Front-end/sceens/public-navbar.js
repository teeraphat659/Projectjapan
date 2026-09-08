(function () {
    const mount = document.querySelector('[data-navbar]');
    if (!mount) return;
    const fallback = `<header class="site-header"><nav class="site-nav" aria-label="เมนูหลัก"><a class="brand" href="index.html" aria-label="JP Notes หน้าหลัก"><span class="brand-mark">あ</span><span><strong>JP Notes</strong><small>learn one character at a time</small></span></a><button class="nav-toggle" type="button" aria-expanded="false" aria-controls="siteNavLinks"><span class="sr-only">เปิดเมนู</span><i class="fa-solid fa-bars" aria-hidden="true"></i></button><div class="nav-links" id="siteNavLinks"><a href="index.html" data-nav="home">หน้าหลัก</a><a href="indexfree.html" data-nav="table">ตารางตัวอักษร</a><a href="register.html" data-nav="register">สมัครสมาชิก</a><a class="nav-login" href="login.html" data-nav="login">เข้าสู่ระบบ <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a></div></nav></header>`;
    function addAdminLink(state) {
        if (state.role !== 'admin') return;
        const links = mount.querySelector('.nav-links');
        if (!links || links.querySelector('[data-nav="backend"]')) return;
        const link = document.createElement('a');
        link.href = '../../backend/php/srceen/index.php';
        link.dataset.nav = 'backend';
        link.textContent = 'ไปหลังบ้าน';
        links.insertBefore(link, links.querySelector('.nav-login'));
    }

    function activate() {
        const page = (location.pathname.split('/').pop() || 'index.html').toLowerCase();
        const activePages = { 'index.html': 'home', 'indexfree.html': 'table', 'login.html': 'login', 'register.html': 'register' };
        const active = activePages[page] || 'home';
        mount.querySelectorAll('[data-nav]').forEach(link => link.classList.toggle('is-active', link.dataset.nav === active));
        const toggle = mount.querySelector('.nav-toggle');
        const links = mount.querySelector('.nav-links');
        if (!toggle || !links) return;
        toggle.addEventListener('click', () => { const open = links.classList.toggle('is-open'); toggle.setAttribute('aria-expanded', String(open)); });
    }
    fetch('_navbar2.html').then(response => response.ok ? response.text() : Promise.reject()).then(html => {
        mount.innerHTML = html;
        fetch('../connectapi/nav_state.php', { credentials: 'same-origin' })
            .then(response => response.json())
            .then(addAdminLink)
            .catch(() => {})
            .finally(activate);
    }).catch(() => {
        mount.innerHTML = fallback;
        fetch('../connectapi/nav_state.php', { credentials: 'same-origin' })
            .then(response => response.json())
            .then(addAdminLink)
            .catch(() => {})
            .finally(activate);
    });
})();
