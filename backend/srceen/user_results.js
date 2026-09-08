const resultState = { items: [], members: [] };
const resultBody = document.getElementById('resultsBody');
const membersBody = document.getElementById('membersBody');
const detailDialog = document.getElementById('detailDialog');
const detailContent = document.getElementById('detailContent');
const detailTitle = document.getElementById('detailTitle');
const endpoint = '/projectjapan/backend/php/action/admin_api.php';

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
}[character]));

const formatDate = (value) => value
    ? new Intl.DateTimeFormat('th-TH', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(String(value).replace(' ', 'T')))
    : '-';

const fullName = (item) => `${item.firstname || ''} ${item.lastname || ''}`.trim() || item.username || 'ไม่ระบุชื่อ';
const formatNumber = (value, digits = 1) => Number(value || 0).toFixed(digits);

function scoreBadge(percentage) {
    const score = Number(percentage || 0);
    return `<span class="detail-score-badge ${score >= 70 ? 'is-pass' : 'is-fail'}">${formatNumber(score)}%</span>`;
}

function renderResults() {
    const query = document.getElementById('searchInput').value.trim().toLowerCase();
    const items = resultState.items.filter((item) => !query || [item.firstname, item.lastname, item.email, item.quiz_title].join(' ').toLowerCase().includes(query));
    if (!items.length) {
        resultBody.innerHTML = '<tr><td colspan="6">ยังไม่มีผลการทดสอบ</td></tr>';
        return;
    }
    resultBody.innerHTML = items.map((item) => `<tr>
        <td><div class="user-info"><button class="user-profile-btn" data-user-id="${Number(item.user_id)}">${escapeHtml(fullName(item))}</button><div class="user-email">${escapeHtml(item.email)}</div></div></td>
        <td><span class="quiz-title">${escapeHtml(item.quiz_title)}</span></td>
        <td><span class="date-text"><i class="fa-regular fa-calendar"></i>${escapeHtml(formatDate(item.completed_at))}</span></td>
        <td><div class="score-box"><span class="score-num">${Number(item.score || 0)}/${Number(item.total_questions || 0)}</span>${scoreBadge(item.percentage)}</div></td>
        <td><div class="wrong-chars"><i class="fa-regular fa-circle-xmark"></i>${escapeHtml(item.wrong_characters || '-')}</div></td>
        <td><button class="detail-btn" data-type="${escapeHtml(item.result_type)}" data-id="${Number(item.result_id)}"><i class="fa-regular fa-file-lines"></i> ดูรายละเอียด</button></td>
    </tr>`).join('');
}

function renderMembers(items) {
    if (!items.length) {
        membersBody.innerHTML = '<tr><td colspan="5">ยังไม่มีสมาชิก</td></tr>';
        return;
    }
    membersBody.innerHTML = items.map((item) => `<tr>
        <td><div class="user-info"><button class="user-profile-btn" data-user-id="${Number(item.id)}">${escapeHtml(fullName(item))}</button><div class="user-email">${escapeHtml(item.email)}</div></div></td>
        <td>${Number(item.quiz_count || 0)}</td><td>${Number(item.writing_count || 0)}</td>
        <td>${escapeHtml(formatDate(item.created_at))}</td>
        <td><button class="history-btn" data-id="${Number(item.id)}" data-name="${escapeHtml(fullName(item))}"><i class="fa-solid fa-clock-rotate-left"></i> ดูประวัติ</button>
        <button class="delete-btn" data-id="${Number(item.id)}"><i class="fa-regular fa-trash-can"></i> ลบสมาชิก</button></td>
    </tr>`).join('');
}

function renderMemberHistory(memberId, memberName) {
    const items = resultState.items.filter((item) => Number(item.user_id) === Number(memberId));
    detailTitle.textContent = `ประวัติการทำแบบทดสอบ`;
    if (!items.length) {
        detailContent.innerHTML = `<div class="empty-detail"><i class="fa-regular fa-folder-open"></i><strong>${escapeHtml(memberName)}</strong><span>ยังไม่มีประวัติการทำแบบทดสอบ</span></div>`;
        return;
    }
    detailContent.innerHTML = `<div class="member-history-heading"><div class="detail-avatar">${escapeHtml(memberName.charAt(0).toUpperCase())}</div><div><strong>${escapeHtml(memberName)}</strong><span>${items.length} รายการทดสอบ</span></div></div><div class="history-list">${items.map((item) => `<div class="history-item"><div class="history-item-main"><strong>${escapeHtml(item.quiz_title)}</strong><span>${escapeHtml(formatDate(item.completed_at))}</span></div><div class="history-item-score"><b>${Number(item.score || 0)}/${Number(item.total_questions || 0)}</b>${scoreBadge(item.percentage)}</div><button class="detail-btn" data-type="${escapeHtml(item.result_type)}" data-id="${Number(item.result_id)}">ดูรายละเอียด</button></div>`).join('')}</div>`;
}

function renderUserProfile(memberId) {
    const member = resultState.members.find((item) => Number(item.id) === Number(memberId));
    if (!member) {
        detailTitle.textContent = 'ไม่พบข้อมูลสมาชิก';
        detailContent.innerHTML = '<div class="empty-detail"><i class="fa-regular fa-user"></i><span>ไม่พบข้อมูลสมาชิกคนนี้</span></div>';
        return;
    }
    const history = resultState.items.filter((item) => Number(item.user_id) === Number(memberId));
    const average = history.length ? history.reduce((total, item) => total + Number(item.percentage || 0), 0) / history.length : 0;
    const passed = history.filter((item) => Number(item.percentage || 0) >= 70).length;
    detailTitle.textContent = `ข้อมูลสมาชิก: ${fullName(member)}`;
    detailContent.innerHTML = `<div class="profile-header"><div class="profile-avatar">${escapeHtml(fullName(member).charAt(0).toUpperCase())}</div><div><h4>${escapeHtml(fullName(member))}</h4><span>${escapeHtml(member.email || '-')}</span><span>ชื่อผู้ใช้: ${escapeHtml(member.username || '-')}</span></div></div>
        <div class="profile-fields"><div><span>รหัสสมาชิก</span><b>#${Number(member.id)}</b></div><div><span>สมัครสมาชิกเมื่อ</span><b>${escapeHtml(formatDate(member.created_at))}</b></div><div><span>ทำแบบทดสอบ</span><b>${history.length} ครั้ง</b></div><div><span>ผ่านเกณฑ์</span><b>${passed} ครั้ง</b></div><div><span>คะแนนเฉลี่ย</span><b>${formatNumber(average)}%</b></div></div>
        <div class="profile-history-title"><strong>ประวัติการทำแบบทดสอบ</strong><span>${history.length ? 'เลือกดูรายละเอียดรายครั้งได้' : 'ยังไม่มีข้อมูล'}</span></div>
        ${history.length ? `<div class="history-list">${history.map((item) => `<div class="history-item"><div class="history-item-main"><strong>${escapeHtml(item.quiz_title)}</strong><span>${escapeHtml(formatDate(item.completed_at))}</span></div><div class="history-item-score"><b>${Number(item.score || 0)}/${Number(item.total_questions || 0)}</b>${scoreBadge(item.percentage)}</div><button class="detail-btn" data-type="${escapeHtml(item.result_type)}" data-id="${Number(item.result_id)}">รายละเอียด</button></div>`).join('')}</div>` : '<div class="empty-detail compact"><i class="fa-regular fa-folder-open"></i><span>สมาชิกคนนี้ยังไม่มีประวัติการทำแบบทดสอบ</span></div>'}`;
}

function renderMatchingDetail(item) {
    const details = item.answer_details || {};
    const questions = Array.isArray(details.questions) ? details.questions : Array.isArray(details) ? details : [];
    const correctCount = questions.filter((question) => question.correct === true || question.isCorrect === true).length;
    return `<div class="detail-summary"><div class="detail-user"><div class="detail-avatar">${escapeHtml(fullName(item).charAt(0).toUpperCase())}</div><div><strong>${escapeHtml(fullName(item))}</strong><span>${escapeHtml(item.email)}</span></div></div><div class="detail-summary-score"><span>คะแนนรวม</span><b>${Number(item.score || 0)}/${Number(item.total_questions || 0)}</b>${scoreBadge(item.percentage)}</div></div>
        <div class="detail-meta"><span><i class="fa-regular fa-calendar"></i>${escapeHtml(formatDate(item.completed_at))}</span><span><i class="fa-regular fa-clock"></i>${Number(item.time_taken || 0)} วินาที</span><span><i class="fa-solid fa-circle-check"></i>${correctCount} ข้อถูก</span></div>
        <div class="answer-list">${questions.length ? questions.map((question, index) => { const correct = question.correct === true || question.isCorrect === true; return `<div class="answer-row ${correct ? 'is-correct' : 'is-wrong'}"><div class="answer-number">${index + 1}</div><div class="answer-question"><strong>${escapeHtml(question.question || question.character || '-')}</strong><span>คำตอบของสมาชิก: <b>${escapeHtml(question.selectedAnswer || question.answer || '-')}</b></span><span>เฉลย: <b>${escapeHtml(question.correctAnswer || question.correct_answer || '-')}</b></span></div><div class="answer-status"><i class="fa-solid ${correct ? 'fa-check' : 'fa-xmark'}"></i>${correct ? 'ถูก' : 'ผิด'}</div></div>`; }).join('') : '<div class="empty-detail">ไม่มีรายละเอียดคำตอบของรายการนี้</div>'}</div>`;
}

function renderWritingDetail(item) {
    const attempts = Array.isArray(item.attempts) ? item.attempts : [];
    return `<div class="detail-summary"><div class="detail-user"><div class="detail-avatar">${escapeHtml(fullName(item).charAt(0).toUpperCase())}</div><div><strong>${escapeHtml(fullName(item))}</strong><span>${escapeHtml(item.email)}</span></div></div><div class="detail-summary-score"><span>ตัวอักษรที่ทำ</span><b>${Number(item.completed_characters || 0)}/${Number(item.total_characters || 0)}</b>${scoreBadge(item.total_characters ? Number(item.completed_characters) * 100 / Number(item.total_characters) : 0)}</div></div>
        <div class="detail-meta"><span><i class="fa-regular fa-calendar"></i>${escapeHtml(formatDate(item.completed_at))}</span><span><i class="fa-regular fa-clock"></i>${Math.round(Number(item.total_time_ms || 0) / 1000)} วินาที</span><span><i class="fa-solid fa-pen-nib"></i>${escapeHtml(item.writing_mode || '-')}</span></div>
        <div class="writing-table-wrap"><table class="writing-detail-table"><thead><tr><th>#</th><th>ตัวอักษร</th><th>โรมาจิ</th><th>ความแม่นยำ</th><th>ตำแหน่งเริ่ม</th><th>สถานะ</th></tr></thead><tbody>${attempts.length ? attempts.map((attempt) => `<tr><td>${Number(attempt.sequence_no || 0)}</td><td class="writing-character">${escapeHtml(attempt.target_character)}</td><td>${escapeHtml(attempt.romaji)}</td><td><div class="accuracy-cell"><div class="accuracy-bar"><span style="width:${Math.min(100, Number(attempt.shape_accuracy || 0))}%"></span></div><b>${formatNumber(attempt.shape_accuracy)}%</b></div></td><td>${Number(attempt.is_start_position_correct) ? '<span class="table-status is-pass">ถูก</span>' : '<span class="table-status is-fail">ผิด</span>'}</td><td><span class="table-status is-pass"><i class="fa-solid fa-check"></i> บันทึกแล้ว</span></td></tr>`).join('') : '<tr><td colspan="6">ไม่มีรายละเอียดตัวอักษร</td></tr>'}</tbody></table></div>`;
}

async function showResultDetail(button) {
    detailDialog.showModal();
    detailTitle.textContent = 'กำลังโหลดรายละเอียด';
    detailContent.innerHTML = '<div class="loading-detail"><i class="fa-solid fa-spinner fa-spin"></i> กำลังโหลดข้อมูล...</div>';
    const response = await fetch(`${endpoint}?action=result_detail&type=${encodeURIComponent(button.dataset.type)}&id=${Number(button.dataset.id)}`, { credentials: 'same-origin' });
    const result = await response.json();
    if (!result.success) { detailContent.innerHTML = `<div class="empty-detail">${escapeHtml(result.message || 'ไม่สามารถโหลดรายละเอียดได้')}</div>`; return; }
    const item = result.item;
    detailTitle.textContent = result.type === 'writing' ? `รายละเอียดแบบทดสอบเขียน: ${item.writing_type || '-'}` : `รายละเอียดแบบทดสอบ: ${item.quiz_type || '-'}`;
    detailContent.innerHTML = result.type === 'writing' ? renderWritingDetail(item) : renderMatchingDetail(item);
}

async function loadPage() {
    const [resultsResponse, membersResponse] = await Promise.all([fetch(`${endpoint}?action=results`, { credentials: 'same-origin' }), fetch(`${endpoint}?action=member_list`, { credentials: 'same-origin' })]);
    const result = await resultsResponse.json();
    const members = await membersResponse.json();
    if (!result.success) throw new Error(result.message);
    if (!members.success) throw new Error(members.message);
    resultState.items = result.items || [];
    resultState.members = members.items || [];
    document.getElementById('averageScore').textContent = `${Number(result.summary.average || 0).toFixed(1)}%`;
    document.getElementById('totalTests').textContent = Number(result.summary.tests || 0).toLocaleString();
    document.getElementById('totalMembers').textContent = Number(result.summary.members || 0).toLocaleString();
    renderMembers(resultState.members);
    renderResults();
}

membersBody.addEventListener('click', async (event) => {
    const historyButton = event.target.closest('.history-btn');
    if (historyButton) { detailDialog.showModal(); renderMemberHistory(historyButton.dataset.id, historyButton.dataset.name); return; }
    const deleteButton = event.target.closest('.delete-btn');
    if (!deleteButton || !confirm('ต้องการลบสมาชิกและประวัติการทดสอบทั้งหมดใช่หรือไม่?')) return;
    const response = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'member_delete', id: Number(deleteButton.dataset.id) }) });
    const result = await response.json();
    if (!result.success) { alert(result.message || 'ลบสมาชิกไม่สำเร็จ'); return; }
    await loadPage();
});

document.addEventListener('click', (event) => { const button = event.target.closest('.detail-btn'); if (button) showResultDetail(button).catch(() => { detailContent.innerHTML = '<div class="empty-detail">ไม่สามารถโหลดรายละเอียดได้</div>'; }); });
document.addEventListener('click', (event) => { const button = event.target.closest('.user-profile-btn'); if (!button) return; detailDialog.showModal(); renderUserProfile(button.dataset.userId); });
document.querySelector('.dialog-close').addEventListener('click', () => detailDialog.close());
document.getElementById('searchInput').addEventListener('input', renderResults);
loadPage().catch((error) => { resultBody.innerHTML = `<tr><td colspan="6">${escapeHtml(error.message || 'ไม่สามารถโหลดข้อมูลได้')}</td></tr>`; membersBody.innerHTML = `<tr><td colspan="5">${escapeHtml(error.message || 'ไม่สามารถโหลดข้อมูลได้')}</td></tr>`; });
