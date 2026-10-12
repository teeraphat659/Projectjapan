/**
 * JapaneseStrokeVideoPlayer
 * Canvas/SVG stroke order video animation player with full playback control,
 * speed adjustment, stroke stepping, timeline scrubbing, and loop toggle.
 */
class JapaneseStrokeVideoPlayer {
  constructor(svgEl, options = {}) {
    this.svg = svgEl;
    this.options = options;
    this.strokes = [];
    this.pathItems = [];
    this.penTip = null;

    this.isPlaying = false;
    this.isCompleted = false;
    this.playMode = 'all'; // 'all' or 'single'
    this.currentStrokeIndex = 0;
    this.currentStrokeProgress = 0; // 0.0 to 1.0
    this.speed = typeof options.speed === 'number' ? options.speed : 0.75;
    this.isLoop = options.loop !== undefined ? Boolean(options.loop) : false;
    this.baseDuration = options.baseDuration || 2.0;
    this.pauseBetweenStrokesMs = 450;
    this.waitingPauseUntil = 0;
    this.lastTimestamp = 0;
    this.animFrameId = null;
    this.loopTimeoutId = null;

    this.onProgressUpdate = null;
    this.onStrokeChange = null;
    this.onStateChange = null;
    this.onSpeedChange = null;
    this.onLoopChange = null;
  }

  setStrokes(strokes) {
    this.stop();
    this.strokes = Array.isArray(strokes) ? strokes : [];
    this.currentStrokeIndex = 0;
    this.currentStrokeProgress = 0;
    this.isCompleted = false;
    this.waitingPauseUntil = 0;
    this.buildSvgDom();
  }

  setSpeed(newSpeed) {
    const parsed = parseFloat(newSpeed);
    if (!isNaN(parsed) && parsed > 0) {
      this.speed = parsed;
      if (this.onSpeedChange) this.onSpeedChange(this.speed);
    }
  }

  setLoop(enableLoop) {
    this.isLoop = Boolean(enableLoop);
    this.clearLoopTimeout();
    if (this.onLoopChange) this.onLoopChange(this.isLoop);
  }

  toggleLoop() {
    this.setLoop(!this.isLoop);
    return this.isLoop;
  }

  clearLoopTimeout() {
    if (this.loopTimeoutId) {
      clearTimeout(this.loopTimeoutId);
      this.loopTimeoutId = null;
    }
  }

  buildSvgDom() {
    this.svg.innerHTML = '';
    this.pathItems = [];

    if (!this.strokes.length) {
      this.svg.innerHTML = `<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#94a3b8" font-size="12" font-family="sans-serif">ไม่มีข้อมูลลำดับเส้น</text>`;
      return;
    }

    const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
    defs.innerHTML = `<filter id="penTipGlow" x="-50%" y="-50%" width="200%" height="200%">
      <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="#dc2626" flood-opacity="0.65"/>
    </filter>`;
    this.svg.appendChild(defs);

    const style = (this.options.getStyle && this.options.getStyle()) || 'assisted';

    // Ghost / Guide paths
    const ghostGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    ghostGroup.setAttribute('class', 'stroke-ghost-group');
    let ghostOpacity = '0.14';
    if (style === 'ghost') ghostOpacity = '0.35';
    else if (style === 'educational') ghostOpacity = '0.20';
    ghostGroup.setAttribute('opacity', ghostOpacity);

    this.strokes.forEach(st => {
      const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      p.setAttribute('d', st.path);
      p.setAttribute('fill', 'none');
      p.setAttribute('stroke', '#64748b');
      p.setAttribute('stroke-width', '4.6');
      p.setAttribute('stroke-linecap', 'round');
      p.setAttribute('stroke-linejoin', 'round');
      ghostGroup.appendChild(p);
    });
    this.svg.appendChild(ghostGroup);

    // Active strokes group
    const activeGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    activeGroup.setAttribute('class', 'stroke-active-group');
    this.svg.appendChild(activeGroup);

    const strokeColor = (style === 'assisted') ? '#ef4444' : (style === 'calligraphy' ? '#0f172a' : '#1e293b');
    const strokeWidth = (style === 'calligraphy') ? '4.8' : (style === 'assisted' ? '4.4' : '4.2');

    this.strokes.forEach((st, idx) => {
      const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      p.setAttribute('d', st.path);
      p.setAttribute('fill', 'none');
      p.setAttribute('stroke', strokeColor);
      p.setAttribute('stroke-width', strokeWidth);
      p.setAttribute('stroke-linecap', 'round');
      p.setAttribute('stroke-linejoin', 'round');

      activeGroup.appendChild(p);

      const length = Math.max(1, p.getTotalLength());
      p.style.strokeDasharray = `${length} ${length}`;
      p.style.strokeDashoffset = `${length}`;

      let numGroup = null;
      const startX = (st.start_x !== null && st.start_x !== undefined) ? Number(st.start_x) : null;
      const startY = (st.start_y !== null && st.start_y !== undefined) ? Number(st.start_y) : null;

      if (startX !== null && startY !== null) {
        numGroup = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        numGroup.setAttribute('class', 'stroke-num-badge');
        numGroup.setAttribute('opacity', '0');

        const numBg = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        numBg.setAttribute('cx', Math.max(5, startX - 2));
        numBg.setAttribute('cy', Math.max(5, startY - 2));
        numBg.setAttribute('r', '4');
        numBg.setAttribute('fill', '#ef4444');

        const numText = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        numText.setAttribute('x', Math.max(5, startX - 2));
        numText.setAttribute('y', Math.max(5, startY - 2) + 2.5);
        numText.setAttribute('text-anchor', 'middle');
        numText.setAttribute('font-size', '5.5');
        numText.setAttribute('font-weight', 'bold');
        numText.setAttribute('fill', '#ffffff');
        numText.setAttribute('font-family', 'sans-serif');
        numText.textContent = String(st.order || (idx + 1));

        numGroup.appendChild(numBg);
        numGroup.appendChild(numText);
        activeGroup.appendChild(numGroup);
      }

      this.pathItems.push({
        pathEl: p,
        numGroup: numGroup,
        length: length,
        order: st.order || (idx + 1),
        startX: startX,
        startY: startY
      });
    });

    // Pen tip indicator
    this.penTip = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    this.penTip.setAttribute('r', '3.8');
    this.penTip.setAttribute('fill', '#ef4444');
    this.penTip.setAttribute('stroke', '#ffffff');
    this.penTip.setAttribute('stroke-width', '1.3');
    this.penTip.setAttribute('filter', 'url(#penTipGlow)');
    this.penTip.setAttribute('opacity', '0');
    this.svg.appendChild(this.penTip);

    this.applyCurrentState();
  }

  play(mode = 'all') {
    if (!this.strokes.length) return;
    this.clearLoopTimeout();

    if (this.isCompleted) {
      this.resetToStart();
    }
    this.playMode = mode;
    this.isPlaying = true;
    this.lastTimestamp = performance.now();
    if (isNaN(this.currentStrokeProgress)) {
      this.currentStrokeProgress = 0;
    }
    if (this.onStateChange) this.onStateChange(true);
    if (this.animFrameId) cancelAnimationFrame(this.animFrameId);
    this.animFrameId = requestAnimationFrame((ts) => this.tick(ts));
  }

  pause() {
    this.clearLoopTimeout();
    this.isPlaying = false;
    if (this.animFrameId) {
      cancelAnimationFrame(this.animFrameId);
      this.animFrameId = null;
    }
    if (this.onStateChange) this.onStateChange(false);
  }

  stop() {
    this.clearLoopTimeout();
    this.pause();
    this.currentStrokeIndex = 0;
    this.currentStrokeProgress = 0;
    this.waitingPauseUntil = 0;
    this.isCompleted = false;
    this.applyCurrentState();
  }

  resetToStart() {
    this.clearLoopTimeout();
    this.currentStrokeIndex = 0;
    this.currentStrokeProgress = 0;
    this.waitingPauseUntil = 0;
    this.isCompleted = false;
    this.applyCurrentState();
  }

  jumpToStep(stepIndex, autoPlay = true) {
    this.clearLoopTimeout();
    this.pause();
    if (stepIndex < 0 || stepIndex >= this.strokes.length) return;
    this.currentStrokeIndex = stepIndex;
    this.currentStrokeProgress = 0;
    this.waitingPauseUntil = 0;
    this.isCompleted = false;
    this.applyCurrentState();
    if (autoPlay) {
      this.play('single');
    }
  }

  prevStroke() {
    if (!this.strokes.length) return;
    let target = this.currentStrokeIndex;
    if (this.currentStrokeProgress > 0.1 || this.isCompleted) {
      // If we are halfway through current stroke or completed, replay current stroke
      target = this.currentStrokeIndex;
    } else {
      // Otherwise go to previous stroke
      target = Math.max(0, this.currentStrokeIndex - 1);
    }
    this.jumpToStep(target, true);
  }

  nextStroke() {
    if (!this.strokes.length) return;
    const target = Math.min(this.strokes.length - 1, this.currentStrokeIndex + 1);
    this.jumpToStep(target, true);
  }

  seekRatio(ratio) {
    this.clearLoopTimeout();
    const clamped = Math.max(0, Math.min(1, ratio));
    const total = this.pathItems.length;
    if (total === 0) return;

    if (clamped >= 1) {
      this.currentStrokeIndex = total - 1;
      this.currentStrokeProgress = 1.0;
      this.isCompleted = true;
      this.pause();
    } else {
      const pos = clamped * total;
      this.currentStrokeIndex = Math.min(total - 1, Math.floor(pos));
      this.currentStrokeProgress = Math.max(0, Math.min(1, pos - this.currentStrokeIndex));
      this.isCompleted = false;
    }
    this.applyCurrentState();
  }

  applyCurrentState() {
    const showPen = (this.options.getShowPenTip ? this.options.getShowPenTip() : true);
    const style = (this.options.getStyle && this.options.getStyle()) || 'assisted';
    const activeColor = (style === 'assisted') ? '#ef4444' : (style === 'calligraphy' ? '#0f172a' : '#1e293b');

    this.pathItems.forEach((item, idx) => {
      if (idx < this.currentStrokeIndex) {
        // Fully drawn past strokes
        item.pathEl.style.strokeDashoffset = '0';
        item.pathEl.setAttribute('stroke', activeColor);
        item.pathEl.style.opacity = '1';
        if (item.numGroup) item.numGroup.setAttribute('opacity', '0.7');
      } else if (idx === this.currentStrokeIndex) {
        // Current stroke being drawn
        const safeProgress = Math.max(0, Math.min(1, this.currentStrokeProgress || 0));
        const offset = item.length * (1.0 - safeProgress);
        item.pathEl.style.strokeDashoffset = `${offset}`;
        item.pathEl.setAttribute('stroke', activeColor);
        item.pathEl.style.opacity = '1';
        if (item.numGroup) item.numGroup.setAttribute('opacity', '1');
      } else {
        // Future strokes not yet reached
        item.pathEl.style.strokeDashoffset = `${item.length}`;
        if (item.numGroup) item.numGroup.setAttribute('opacity', '0');
      }
    });

    // Pen tip positioning
    if (this.currentStrokeIndex < this.pathItems.length && !this.isCompleted && showPen && this.penTip) {
      const curItem = this.pathItems[this.currentStrokeIndex];
      const safeProgress = Math.max(0, Math.min(1, this.currentStrokeProgress || 0));
      const distance = Math.min(curItem.length, Math.max(0, curItem.length * safeProgress));
      const pt = curItem.pathEl.getPointAtLength(distance);
      if (pt && !isNaN(pt.x) && !isNaN(pt.y)) {
        this.penTip.setAttribute('cx', pt.x);
        this.penTip.setAttribute('cy', pt.y);
        this.penTip.setAttribute('opacity', '1');
      } else {
        this.penTip.setAttribute('opacity', '0');
      }
    } else if (this.penTip) {
      this.penTip.setAttribute('opacity', '0');
    }

    // Callbacks
    const total = this.pathItems.length;
    const safeProgress = Math.max(0, Math.min(1, this.currentStrokeProgress || 0));
    const overallProgress = total > 0 
      ? Math.min(100, Math.max(0, (((this.currentStrokeIndex + safeProgress) / total) * 100)))
      : 0;

    if (this.onProgressUpdate) this.onProgressUpdate(overallProgress);
    if (this.onStrokeChange) this.onStrokeChange(Math.min(total, this.currentStrokeIndex + 1), total);
  }

  tick(timestamp) {
    if (!this.isPlaying) return;
    const deltaMs = Math.max(0, Math.min(100, timestamp - this.lastTimestamp));
    this.lastTimestamp = timestamp;

    if (this.waitingPauseUntil > 0) {
      if (timestamp < this.waitingPauseUntil) {
        this.animFrameId = requestAnimationFrame((ts) => this.tick(ts));
        return;
      }
      this.waitingPauseUntil = 0;
    }

    // Base duration per stroke (calibrated to slow down comfortably for learning)
    const baseDuration = (this.options.getDuration ? this.options.getDuration() : this.baseDuration);
    const durationSec = (!isNaN(baseDuration) && baseDuration > 0) ? baseDuration : 2.0;
    const currentSpeed = Math.max(0.2, this.speed || 0.75);
    const strokeDurationMs = Math.max(200, (durationSec * 1000) / currentSpeed);

    if (isNaN(this.currentStrokeProgress)) {
      this.currentStrokeProgress = 0;
    }

    this.currentStrokeProgress += deltaMs / strokeDurationMs;

    if (this.currentStrokeProgress >= 1.0) {
      this.currentStrokeProgress = 1.0;
      this.applyCurrentState();

      if (this.playMode === 'single') {
        this.pause();
        return;
      }

      this.currentStrokeIndex++;
      this.currentStrokeProgress = 0;

      if (this.currentStrokeIndex >= this.pathItems.length) {
        this.onVideoComplete();
        return;
      } else {
        const pauseMs = Math.max(180, this.pauseBetweenStrokesMs / currentSpeed);
        this.waitingPauseUntil = timestamp + pauseMs;
      }
    } else {
      this.applyCurrentState();
    }
    this.animFrameId = requestAnimationFrame((ts) => this.tick(ts));
  }

  onVideoComplete() {
    this.clearLoopTimeout();
    this.isPlaying = false;
    this.isCompleted = true;
    this.currentStrokeProgress = 1.0;
    this.currentStrokeIndex = Math.max(0, this.pathItems.length - 1);
    this.applyCurrentState();
    if (this.penTip) this.penTip.setAttribute('opacity', '0');
    if (this.onProgressUpdate) this.onProgressUpdate(100);
    if (this.onStateChange) this.onStateChange(false);

    if (this.isLoop) {
      this.loopTimeoutId = setTimeout(() => {
        if (this.isLoop) {
          this.resetToStart();
          this.play('all');
        }
      }, 1200);
    }
  }
}

window.JapaneseStrokeVideoPlayer = JapaneseStrokeVideoPlayer;
