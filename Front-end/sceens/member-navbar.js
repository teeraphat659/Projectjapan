(function () {
    const mount = document.querySelector('[data-member-navbar]');
    if (!mount) return;

    const fallback = `<header class="member-header"><nav class="member-nav" aria-label="เมนูสมาชิก"><a class="member-brand" href="../data/member.php" aria-label="JP Notes หน้าหลักสมาชิก"><span class="member-brand-mark">あ</span><span><strong>JP Notes</strong><small>member learning space</small></span></a><button class="member-nav-toggle" type="button" aria-expanded="false" aria-controls="memberNavLinks"><span class="member-sr-only">เปิดเมนู</span><i class="fa-solid fa-bars" aria-hidden="true"></i></button><div class="member-nav-links" id="memberNavLinks"><a href="../data/member.php" data-member-nav="dashboard"><i class="fa-solid fa-house" aria-hidden="true"></i> หน้าหลัก</a><a href="../data/kanatable.php" data-member-nav="characters"><i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> ตารางตัวอักษร</a><a href="../data/ex2.php" data-member-nav="writing"><i class="fa-solid fa-pen-nib" aria-hidden="true"></i> ฝึกเขียน</a><a href="../data/jpmatch.php" data-member-nav="match"><i class="fa-solid fa-puzzle-piece" aria-hidden="true"></i> เกมจับคู่</a><a href="../data/history.php" data-member-nav="history"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> ประวัติ</a></div><div class="member-profile-menu-wrapper"><button class="member-profile-toggle" type="button" aria-expanded="false" aria-controls="memberProfileMenu" aria-label="เมนูโปรไฟล์สมาชิก"><span class="member-avatar"><i class="fa-solid fa-user" aria-hidden="true"></i></span><span class="member-name" id="navUsername">สมาชิก</span><i class="fa-solid fa-chevron-down member-profile-caret" aria-hidden="true"></i></button><div class="member-profile-menu" id="memberProfileMenu" role="menu" aria-label="เมนูโปรไฟล์ผู้ใช้"><a class="member-profile-link" href="../data/profile.php" role="menuitem"><i class="fa-regular fa-user" aria-hidden="true"></i> โปรไฟล์</a><a class="member-profile-link" href="../data/change_password.php" role="menuitem"><i class="fa-solid fa-key" aria-hidden="true"></i> เปลี่ยนรหัสผ่าน</a><div class="member-profile-divider"></div><a class="member-profile-link logout" href="../connectapi/logout.php" role="menuitem"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> ออกจากระบบ</a></div></div></nav></header>`;

    const page = (location.pathname.split('/').pop() || 'member.html').toLowerCase();
    const pageToNav = {
        'member.html': 'dashboard', 'member.php': 'dashboard',
        'kanatable.html': 'characters', 'kanatable.php': 'characters',
        'ex2.html': 'writing', 'ex2.php': 'writing', 'writing.html': 'writing', 'writing.php': 'writing',
        'writingresult.html': 'writing', 'writingresult.php': 'writing',
        'jpmatch.html': 'match', 'jpmatch.php': 'match', 'jpmatch_play.html': 'match', 'jpmatch_play.php': 'match',
        'history.html': 'history', 'history.php': 'history', 'testhistorydetail.html': 'history', 'testhistorydetail.php': 'history', 'matchhistorydetail.html': 'history', 'matchhistorydetail.php': 'history',
        'profile.html': 'dashboard', 'profile.php': 'dashboard', 'change_password.html': 'dashboard', 'change_password.php': 'dashboard'
    };

    function activate() {
        const active = pageToNav[page] || 'dashboard';
        mount.querySelectorAll('[data-member-nav]').forEach(link => {
            const isCurrentPage = link.dataset.memberNav === active;
            link.classList.toggle('is-active', isCurrentPage);
            link.toggleAttribute('aria-current', isCurrentPage);
            if (isCurrentPage) {
                link.addEventListener('click', event => event.preventDefault());
            }
        });

        const brand = mount.querySelector('.member-brand');
        const isMemberHomePage = page === 'member.html' || page === 'member.php';
        if (brand) {
            if (isMemberHomePage) {
                brand.setAttribute('aria-current', 'page');
            } else {
                brand.removeAttribute('aria-current');
            }
        }

        const toggle = mount.querySelector('.member-nav-toggle');
        const links = mount.querySelector('.member-nav-links');
        if (toggle && links) {
            toggle.addEventListener('click', () => {
                const open = links.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', String(open));
            });
        }

        const profileToggle = mount.querySelector('.member-profile-toggle');
        const profileMenu = mount.querySelector('.member-profile-menu');
        if (profileToggle && profileMenu) {
            const closeProfileMenu = () => {
                profileMenu.classList.remove('is-open');
                profileToggle.setAttribute('aria-expanded', 'false');
            };

            profileToggle.addEventListener('click', event => {
                event.stopPropagation();
                const isOpen = profileMenu.classList.toggle('is-open');
                profileToggle.setAttribute('aria-expanded', String(isOpen));
            });

            document.addEventListener('click', event => {
                if (!profileMenu.contains(event.target) && !profileToggle.contains(event.target)) {
                    closeProfileMenu();
                }
            });

            profileMenu.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', closeProfileMenu);
            });
        }
    }

    function addAdminLink() {
        fetch('../connectapi/nav_state.php', { credentials: 'same-origin' })
            .then(response => response.json())
            .then(state => {
                if (state.role !== 'admin') return;
                const links = mount.querySelector('.member-nav-links');
                if (!links || links.querySelector('[data-member-nav="backend"]')) return;
                const link = document.createElement('a');
                link.href = '../../backend/php/srceen/index.php';
                link.dataset.memberNav = 'backend';
                link.innerHTML = '<i class="fa-solid fa-gauge-high" aria-hidden="true"></i> ไปหลังบ้าน';
                links.appendChild(link);
            })
            .catch(() => {});
    }

    function render(html) {
        mount.innerHTML = html;
        activate();
        addAdminLink();
    }

    if (mount.querySelector('.member-header')) {
        activate();
        addAdminLink();
    } else {
        render(fallback);
    }
})();
