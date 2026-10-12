let strokePlayerInstance = null;
let currentSpeedSetting = 0.75; // Default slower speed (calm and clear for learners)
let isLoopSetting = false;      // Default loop to OFF so user has full control

function stopStrokePlayer() {
    if (strokePlayerInstance) {
        strokePlayerInstance.stop();
    }
}
window.stopStrokePlayer = stopStrokePlayer;

function setupStrokePlayer(strokesJsonStr, animStyle, durationStr) {
    const container = document.getElementById('strokeVideoPlayerContainer');
    const svgEl = document.getElementById('strokeVideoSvg');
    const imgEl = document.getElementById('modalStroke') || document.getElementById('strokePreview');
    const emptyEl = document.getElementById('modalStrokeEmpty');
    const videoSection = document.getElementById('videoPlayerSection');
    const imageSection = document.getElementById('imageHeadingSection');
    const imagePreviewFrame = document.getElementById('imagePreviewFrame');

    if (!container || !svgEl) return;

    // Timeline elements
    const timelineTrack = document.getElementById('strokeTimelineTrack');
    const timelineProgress = document.getElementById('strokeTimelineProgress');
    const timelineThumb = document.getElementById('strokeTimelineThumb');
    const strokeCounterLabel = document.getElementById('strokeCounterLabel');
    const strokePctLabel = document.getElementById('strokePctLabel');
    const statusBadge = document.getElementById('strokeStatusBadge');
    const strokeStatusText = document.getElementById('strokeStatusText');

    // Button controls
    const btnPlayPause = document.getElementById('btnPlayStrokeVideo');
    const playIcon = document.getElementById('strokePlayIcon');
    const playText = document.getElementById('strokePlayText');
    const btnReplay = document.getElementById('btnReplayStrokeVideo');
    const btnPrev = document.getElementById('btnPrevStroke');
    const btnNext = document.getElementById('btnNextStroke');
    const btnSpeedToggle = document.getElementById('btnSpeedToggle');
    const strokeSpeedText = document.getElementById('strokeSpeedText');
    const speedMenu = document.getElementById('speedMenu');
    const btnLoopToggle = document.getElementById('btnLoopToggle');
    const loopBtnText = document.getElementById('loopBtnText');
    const stepPillsRow = document.getElementById('strokeStepPillsRow');

    // Parse strokes
    if (!strokesJsonStr || strokesJsonStr.trim() === '' || strokesJsonStr === '[]') {
        container.style.display = 'none';
        if (videoSection) videoSection.style.display = 'none';

        if (imgEl && (imgEl.getAttribute('src') || imgEl.tagName !== 'IMG')) {
            imgEl.style.display = 'block';
            if (emptyEl) emptyEl.hidden = true;
            if (imageSection) imageSection.style.display = 'flex';
            if (imagePreviewFrame) imagePreviewFrame.style.display = 'grid';
        } else {
            if (imgEl && imgEl.tagName === 'IMG') imgEl.style.display = 'none';
            if (emptyEl) emptyEl.hidden = false;
        }
        if (strokePlayerInstance) strokePlayerInstance.stop();
        return;
    }

    let parsedStrokes = [];
    try {
        parsedStrokes = JSON.parse(strokesJsonStr);
    } catch (e) {
        console.error('Failed to parse strokes JSON:', e);
    }

    if (!parsedStrokes || parsedStrokes.length === 0) {
        container.style.display = 'none';
        if (videoSection) videoSection.style.display = 'none';

        if (imgEl && (imgEl.getAttribute('src') || imgEl.tagName !== 'IMG')) {
            imgEl.style.display = 'block';
            if (emptyEl) emptyEl.hidden = true;
            if (imageSection) imageSection.style.display = 'flex';
            if (imagePreviewFrame) imagePreviewFrame.style.display = 'grid';
        } else {
            if (imgEl && imgEl.tagName === 'IMG') imgEl.style.display = 'none';
            if (emptyEl) emptyEl.hidden = false;
        }
        if (strokePlayerInstance) strokePlayerInstance.stop();
        return;
    }

    // Show image if available
    if (imgEl) {
        let hasImage = false;
        if (imgEl.tagName === 'IMG' && imgEl.getAttribute('src')) {
            hasImage = true;
        } else if (imgEl.tagName !== 'IMG' && !imgEl.innerHTML.includes('ไม่มี')) {
            hasImage = true;
        }
        
        if (hasImage) {
            imgEl.style.display = 'block';
            if (imageSection) imageSection.style.display = 'flex';
            if (imagePreviewFrame) imagePreviewFrame.style.display = 'grid';
        } else {
            imgEl.style.display = 'none';
            if (imageSection) imageSection.style.display = 'none';
            if (imagePreviewFrame) imagePreviewFrame.style.display = 'none';
        }
    }
    if (emptyEl) {
        emptyEl.hidden = true;
        emptyEl.style.display = 'none';
    }

    container.style.display = 'block';
    if (videoSection) {
        if (videoSection.classList.contains('detail-block')) {
            videoSection.style.display = 'flex';
        } else {
            videoSection.style.display = 'block';
        }
    }

    // Compute base duration (ensure calm and deliberate pace)
    const rawV = parseFloat(durationStr);
    const baseDurationSec = (!isNaN(rawV) && rawV >= 1.6) ? rawV : 2.0;

    // Helper to update Loop Button UI
    function updateLoopUI(isLoop) {
        if (!btnLoopToggle) return;
        btnLoopToggle.classList.toggle('active', isLoop);
        if (loopBtnText) {
            loopBtnText.textContent = isLoop ? 'วนซ้ำ: เปิด' : 'วนซ้ำ: ปิด';
        }
        btnLoopToggle.title = isLoop ? 'คลิกเพื่อปิดการเล่นวนซ้ำ' : 'คลิกเพื่อเปิดการเล่นวนซ้ำอัตโนมัติ';
    }

    // Helper to update Speed UI
    function updateSpeedUI(spd) {
        if (strokeSpeedText) {
            strokeSpeedText.textContent = `${spd}x`;
        }
        if (speedMenu) {
            const items = speedMenu.querySelectorAll('.speed-item');
            items.forEach(btn => {
                const s = parseFloat(btn.dataset.speed);
                btn.classList.toggle('active', s === spd);
            });
        }
    }

    // Helper to update Status Badge
    function updateStatusBadge(state) {
        if (!statusBadge || !strokeStatusText) return;
        if (state === 'playing') {
            statusBadge.className = 'stroke-player-badge';
            strokeStatusText.textContent = 'กำลังเล่น';
        } else if (state === 'paused') {
            statusBadge.className = 'stroke-player-badge paused';
            strokeStatusText.textContent = 'หยุดชั่วคราว';
        } else if (state === 'completed') {
            statusBadge.className = 'stroke-player-badge completed';
            strokeStatusText.textContent = 'เสร็จสิ้น';
        }
    }

    // Initialize player instance if not exists
    if (!strokePlayerInstance) {
        strokePlayerInstance = new JapaneseStrokeVideoPlayer(svgEl, {
            getStyle: () => animStyle || 'educational',
            getDuration: () => baseDurationSec,
            speed: currentSpeedSetting,
            loop: isLoopSetting,
            baseDuration: baseDurationSec,
            getShowPenTip: () => true
        });

        // Event hooks
        strokePlayerInstance.onProgressUpdate = (pct) => {
            const safePct = Math.max(0, Math.min(100, Math.round(pct)));
            if (timelineProgress) timelineProgress.style.width = `${safePct}%`;
            if (timelineThumb) timelineThumb.style.left = `${safePct}%`;
            if (strokePctLabel) strokePctLabel.textContent = `${safePct}%`;
        };

        strokePlayerInstance.onStrokeChange = (curr, total) => {
            if (strokeCounterLabel) {
                strokeCounterLabel.textContent = total > 0 ? `เส้นที่ ${curr} / ${total}` : 'เส้นที่ 0 / 0';
            }
            if (stepPillsRow) {
                const pills = stepPillsRow.querySelectorAll('.step-btn-pill');
                pills.forEach((p, idx) => {
                    if (idx === 0) {
                        // "All" button
                        p.classList.toggle('active', strokePlayerInstance.playMode === 'all');
                    } else {
                        // Individual stroke buttons
                        p.classList.toggle('active', idx === curr);
                    }
                });
            }
        };

        strokePlayerInstance.onStateChange = (playing) => {
            if (playing) {
                if (playIcon) playIcon.className = 'fa-solid fa-pause';
                if (playText) playText.textContent = 'พัก';
                updateStatusBadge('playing');
            } else {
                if (playIcon) playIcon.className = 'fa-solid fa-play';
                if (playText) playText.textContent = strokePlayerInstance.isCompleted ? 'เล่นอีกครั้ง' : 'เล่นวิดีโอ';
                updateStatusBadge(strokePlayerInstance.isCompleted ? 'completed' : 'paused');
            }
        };

        strokePlayerInstance.onLoopChange = (isLoop) => {
            isLoopSetting = isLoop;
            updateLoopUI(isLoop);
        };

        strokePlayerInstance.onSpeedChange = (spd) => {
            currentSpeedSetting = spd;
            updateSpeedUI(spd);
        };

        // Wire Button Listeners Once
        if (btnPlayPause) {
            btnPlayPause.addEventListener('click', () => {
                if (strokePlayerInstance.isPlaying) {
                    strokePlayerInstance.pause();
                } else {
                    strokePlayerInstance.play(strokePlayerInstance.playMode || 'all');
                }
            });
        }

        if (btnReplay) {
            btnReplay.addEventListener('click', () => {
                strokePlayerInstance.resetToStart();
                strokePlayerInstance.play('all');
            });
        }

        if (btnPrev) {
            btnPrev.addEventListener('click', () => {
                strokePlayerInstance.prevStroke();
            });
        }

        if (btnNext) {
            btnNext.addEventListener('click', () => {
                strokePlayerInstance.nextStroke();
            });
        }

        // Loop Toggle
        if (btnLoopToggle) {
            btnLoopToggle.addEventListener('click', () => {
                const isLoopEnabled = strokePlayerInstance.toggleLoop();
                if (!isLoopEnabled) {
                    strokePlayerInstance.pause();
                }
            });
        }

        // Speed Menu / Toggle
        if (btnSpeedToggle) {
            btnSpeedToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                if (speedMenu) speedMenu.classList.toggle('show');
            });
        }

        if (speedMenu) {
            speedMenu.addEventListener('click', (e) => {
                const target = e.target.closest('.speed-item');
                if (!target) return;
                const spd = parseFloat(target.dataset.speed);
                if (!isNaN(spd)) {
                    strokePlayerInstance.setSpeed(spd);
                }
                speedMenu.classList.remove('show');
            });
        }

        document.addEventListener('click', (e) => {
            if (speedMenu && speedMenu.classList.contains('show')) {
                if (!speedMenu.contains(e.target) && e.target !== btnSpeedToggle) {
                    speedMenu.classList.remove('show');
                }
            }
        });

        // Timeline Scrubbing / Seeking
        if (timelineTrack) {
            let isDragging = false;

            const handleSeek = (e) => {
                if (!strokePlayerInstance.strokes.length) return;
                const rect = timelineTrack.getBoundingClientRect();
                const ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
                strokePlayerInstance.seekRatio(ratio);
            };

            timelineTrack.addEventListener('mousedown', (e) => {
                isDragging = true;
                handleSeek(e);
            });

            window.addEventListener('mousemove', (e) => {
                if (isDragging) {
                    handleSeek(e);
                }
            });

            window.addEventListener('mouseup', () => {
                if (isDragging) {
                    isDragging = false;
                }
            });

            // Touch support for mobile devices
            timelineTrack.addEventListener('touchstart', (e) => {
                if (e.touches && e.touches[0]) {
                    isDragging = true;
                    handleSeek(e.touches[0]);
                }
            }, { passive: true });

            window.addEventListener('touchmove', (e) => {
                if (isDragging && e.touches && e.touches[0]) {
                    handleSeek(e.touches[0]);
                }
            }, { passive: true });

            window.addEventListener('touchend', () => {
                if (isDragging) isDragging = false;
            });
        }
    } else {
        // Update options for existing instance
        strokePlayerInstance.options.getStyle = () => animStyle || 'educational';
        strokePlayerInstance.options.getDuration = () => baseDurationSec;
        strokePlayerInstance.baseDuration = baseDurationSec;
        strokePlayerInstance.setSpeed(currentSpeedSetting);
        strokePlayerInstance.setLoop(isLoopSetting);
    }

    // Refresh UI controls state
    updateLoopUI(isLoopSetting);
    updateSpeedUI(currentSpeedSetting);
    updateStatusBadge('playing');

    // Build Step Pills for this character
    if (stepPillsRow) {
        stepPillsRow.innerHTML = '';

        const allBtn = document.createElement('button');
        allBtn.type = 'button';
        allBtn.className = 'step-btn-pill active';
        allBtn.innerHTML = '<i class="fa-solid fa-play" style="font-size:10px;"></i> ทั้งหมด';
        allBtn.title = 'เล่นลำดับเส้นทั้งหมดอย่างต่อเนื่อง';
        allBtn.addEventListener('click', () => {
            strokePlayerInstance.resetToStart();
            strokePlayerInstance.play('all');
        });
        stepPillsRow.appendChild(allBtn);

        parsedStrokes.forEach((st, idx) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'step-btn-pill';
            b.textContent = st.order || (idx + 1);
            b.title = `ดูเฉพาะเส้นที่ ${st.order || (idx + 1)}`;
            b.addEventListener('click', () => {
                strokePlayerInstance.jumpToStep(idx, true);
            });
            stepPillsRow.appendChild(b);
        });
    }

    // Load strokes into player and start playing
    strokePlayerInstance.setStrokes(parsedStrokes);
    setTimeout(() => {
        strokePlayerInstance.play('all');
    }, 250);
}
