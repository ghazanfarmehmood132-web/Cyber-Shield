const SecuritySystem = {
    video: null,
    canvas: null,
    ctx: null,
    stream: null,
    cameraActive: false,

    detectionActive: false,
    alarmActive: false,
    detectionHistory: [],
    detectionCount: 0,

    confidence: 75,      // maps to landmark-visibility threshold
    sensitivity: 50,      // maps to skin-ratio threshold
    autoDetectEnabled: true,
    soundEnabled: true,
    rafId: null,

    poseLandmarker: null,
    lastVideoTime: -1,

    // debounce state per tracked slot so the alert / history doesn't
    // flicker every single frame
    trackState: {}, // { personIndex: { helmet, gloves, shoes, stableCount, lastMissingKey } }

    elements: {},

    init() {
        this.cacheElements();
        this.setupEventListeners();
        this.updateClock();
        console.log('Cyber Shield PPE System Initialized');
    },

    cacheElements() {
        this.video = document.getElementById('webcam');
        this.canvas = document.getElementById('canvas');
        this.ctx = this.canvas.getContext('2d', { willReadFrequently: true });

        this.elements = {
            alertBanner: document.getElementById('alert-banner'),
            alertText: document.getElementById('alert-text'),
            systemStatus: document.getElementById('system-status'),
            alarmStatus: document.getElementById('alarm-status'),
            alarmIndicator: document.getElementById('alarm-indicator'),
            detectionCount: document.getElementById('detection-count'),
            historyTableBody: document.getElementById('history-table-body'),
            confidenceSlider: document.getElementById('confidence-slider'),
            confidenceValue: document.getElementById('confidence-value'),
            sensitivitySlider: document.getElementById('sensitivity-slider'),
            sensitivityValue: document.getElementById('sensitivity-value'),
            autoDetectToggle: document.getElementById('auto-detect-toggle'),
            soundToggle: document.getElementById('sound-toggle'),
            startCameraBtn: document.getElementById('start-camera-btn'),
            stopCameraBtn: document.getElementById('stop-camera-btn'),
            simulateDetectionBtn: document.getElementById('simulate-detection-btn'),
            clearAlertBtn: document.getElementById('clear-alert-btn'),
            resetBtn: document.getElementById('reset-btn'),
            clearHistoryBtn: document.getElementById('clear-history-btn'),
            ppePanel: document.getElementById('ppe-violations-panel')
        };
    },

    setupEventListeners() {
        this.elements.startCameraBtn.addEventListener('click', () => this.startCamera());
        this.elements.stopCameraBtn.addEventListener('click', () => this.stopCamera());
        this.elements.confidenceSlider.addEventListener('input', (e) => {
            this.confidence = parseInt(e.target.value);
            this.elements.confidenceValue.textContent = this.confidence;
        });
        this.elements.sensitivitySlider.addEventListener('input', (e) => {
            this.sensitivity = parseInt(e.target.value);
            this.elements.sensitivityValue.textContent = this.sensitivity;
        });
        this.elements.autoDetectToggle.addEventListener('change', (e) => {
            this.autoDetectEnabled = e.target.checked;
            if (this.autoDetectEnabled && this.cameraActive) this.startDetection();
            else this.stopDetection();
        });
        this.elements.soundToggle.addEventListener('change', (e) => {
            this.soundEnabled = e.target.checked;
        });

        // "Simulate" button now just forces one detection pass immediately
        this.elements.simulateDetectionBtn.addEventListener('click', () => {
            if (this.cameraActive) this.detectFrame();
            else alert('Pehle camera start karein.');
        });
        this.elements.clearAlertBtn.addEventListener('click', () => this.clearAlert());
        this.elements.resetBtn.addEventListener('click', () => this.resetSystem());
        this.elements.clearHistoryBtn.addEventListener('click', () => this.clearHistory());
    },

    async loadPoseLandmarker() {
        if (this.poseLandmarker) return this.poseLandmarker;

        this.setStatus('Loading detection model...');
        const vision = await import(
            'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/vision_bundle.mjs'
        );
        const { PoseLandmarker, FilesetResolver } = vision;

        const filesetResolver = await FilesetResolver.forVisionTasks(
            'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/wasm'
        );

        this.poseLandmarker = await PoseLandmarker.createFromOptions(filesetResolver, {
            baseOptions: {
                modelAssetPath:
                    'https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task',
                delegate: 'GPU'
            },
            runningMode: 'VIDEO',
            numPoses: 3
        });

        this.setStatus('Model loaded');
        return this.poseLandmarker;
    },

    setStatus(text) {
        if (this.elements.systemStatus) {
            this.elements.systemStatus.textContent = text;
        }
    },

    async startCamera() {
        try {
            const constraints = { video: { width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false };
            this.stream = await navigator.mediaDevices.getUserMedia(constraints);
            this.video.srcObject = this.stream;

            this.video.onloadedmetadata = async () => {
                this.cameraActive = true;
                this.elements.startCameraBtn.disabled = true;
                this.elements.stopCameraBtn.disabled = false;
                this.canvas.width = this.video.videoWidth;
                this.canvas.height = this.video.videoHeight;

                await this.loadPoseLandmarker();

                if (this.autoDetectEnabled) this.startDetection();
            };
        } catch (error) {
            console.error(error);
            alert('Camera access denied ya unavailable hai.');
        }
    },

    stopCamera() {
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.video.srcObject = null;
        }
        this.stopDetection();
        this.cameraActive = false;
        this.elements.startCameraBtn.disabled = false;
        this.elements.stopCameraBtn.disabled = true;
    },

    startDetection() {
        this.detectionActive = true;
        const loop = () => {
            if (!this.detectionActive) return;
            this.detectFrame();
            this.rafId = requestAnimationFrame(loop);
        };
        this.rafId = requestAnimationFrame(loop);
    },

    stopDetection() {
        this.detectionActive = false;
        if (this.rafId) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
    },

    // ---- core detection ----

    detectFrame() {
        if (!this.cameraActive || !this.poseLandmarker) return;
        if (this.video.currentTime === this.lastVideoTime) return; // no new frame yet
        this.lastVideoTime = this.video.currentTime;

        this.ctx.drawImage(this.video, 0, 0, this.canvas.width, this.canvas.height);

        const result = this.poseLandmarker.detectForVideo(this.video, performance.now());
        const poses = result?.landmarks || [];

        const visibilityThreshold = 0.3 + (1 - this.confidence / 100) * 0.4; // 0.3–0.7
        const skinThreshold = 0.55 - (this.sensitivity / 100) * 0.3;         // 0.25–0.55

        const currentViolations = [];

        poses.forEach((landmarks, idx) => {
            const status = this.checkPPEForPerson(landmarks, visibilityThreshold, skinThreshold, idx);
            const missing = Object.entries(status)
                .filter(([, present]) => present === false)
                .map(([part]) => this.partLabel(part));

            const state = this.trackState[idx] || { stableCount: 0, lastMissingKey: null };
            const missingKey = missing.join(',');

            if (missingKey === state.lastMissingKey) {
                state.stableCount++;
            } else {
                state.stableCount = 1;
                state.lastMissingKey = missingKey;
            }
            this.trackState[idx] = state;

            // require a few consistent frames before acting, to avoid flicker
            if (state.stableCount === 3 && missing.length > 0) {
                const image = this.cropThumbnail(landmarks);
                const violation = {
                    id: idx,
                    name: `Worker ${idx + 1}`,
                    missing,
                    image
                };
                currentViolations.push(violation);
                this.addToHistory(violation);
            }

            if (missing.length > 0) {
                const image = this.cropThumbnail(landmarks);
                currentViolations.push({ id: idx, name: `Worker ${idx + 1}`, missing, image, _live: true });
            }
        });

        if (currentViolations.length > 0) {
            this.handlePPEDetection(currentViolations);
        } else if (poses.length > 0) {
            this.clearAlert();
        }
    },

    // returns { helmet: true/false/null, gloves: true/false/null, shoes: true/false/null }
    // null = that body part isn't visible enough in frame to judge
    //
    // IMPORTANT: MediaPipe Pose estimates all 33 body landmarks even when a
    // body part is NOT actually in frame (it guesses a plausible pose). The
    // `visibility` score alone isn't enough to filter that out — we also
    // require `presence` (how likely the landmark genuinely exists in this
    // image) and that its normalized coords fall inside the frame. Wrists
    // and feet get a stricter combined threshold since they're the parts
    // most often "hallucinated" when out of frame.
    checkPPEForPerson(lm, visibilityThreshold, skinThreshold, personIdx) {
        const w = this.canvas.width, h = this.canvas.height;

        const inFrame = (i) => {
            const p = lm[i];
            if (!p) return false;
            if (p.x < -0.03 || p.x > 1.03 || p.y < -0.03 || p.y > 1.03) return false;
            const visibility = p.visibility ?? 1;
            const presence = p.presence ?? 1;
            return visibility > visibilityThreshold && presence > visibilityThreshold;
        };
        // stricter combined gate for extremities, which are most prone to being
        // "guessed" by the pose model when actually out of frame
        const strictInFrame = (i) => {
            const p = lm[i];
            if (!inFrame(i)) return false;
            const visibility = p.visibility ?? 1;
            const presence = p.presence ?? 1;
            const strictThreshold = Math.max(visibilityThreshold + 0.2, 0.6);
            return visibility > strictThreshold && presence > strictThreshold;
        };

        const result = { helmet: null, gloves: null, shoes: null };
        const smoothed = this.getSmoothedState(personIdx);

        // --- HEAD / HELMET ---
        const nose = lm[0];
        const leftShoulder = lm[11], rightShoulder = lm[12];
        if (inFrame(0) && inFrame(11) && inFrame(12)) {
            const shoulderY = ((leftShoulder.y + rightShoulder.y) / 2) * h;
            const noseY = nose.y * h;
            const headSize = Math.max(20, shoulderY - noseY); // rough head height scale
            const centerX = nose.x * w;
            // box covering top of head (above eyes/ears), where a helmet would sit
            const box = {
                x: centerX - headSize * 0.55,
                y: noseY - headSize * 1.4,
                w: headSize * 1.1,
                h: headSize
            };
            const raw = this.regionSkinRatio(box);
            result.helmet = this.evaluatePart(smoothed, 'helmet', raw, skinThreshold);
        } else {
            this.clearSmoothed(smoothed, 'helmet');
        }

        // --- HANDS / GLOVES ---
        const scale = this.bodyScale(lm, w, h);
        const checkWrist = (wristIdx, key) => {
            if (!strictInFrame(wristIdx)) {
                this.clearSmoothed(smoothed, key);
                return null;
            }
            const wrist = lm[wristIdx];
            const size = scale * 0.55;
            const box = { x: wrist.x * w - size / 2, y: wrist.y * h - size / 2, w: size, h: size };
            const raw = this.regionSkinRatio(box);
            return this.evaluatePart(smoothed, key, raw, skinThreshold);
        };
        const leftGlove = checkWrist(15, 'leftGlove');
        const rightGlove = checkWrist(16, 'rightGlove');
        if (leftGlove !== null || rightGlove !== null) {
            // missing if EITHER visible hand shows bare skin
            result.gloves = !((leftGlove === null || leftGlove) && (rightGlove === null || rightGlove));
        }

        // --- FEET / SHOES ---
        const checkFoot = (ankleIdx, footIdx, key) => {
            const useFootIdx = strictInFrame(footIdx);
            const useAnkleIdx = strictInFrame(ankleIdx);
            if (!useFootIdx && !useAnkleIdx) {
                this.clearSmoothed(smoothed, key);
                return null;
            }
            const idx = useFootIdx ? footIdx : ankleIdx;
            const pt = lm[idx];
            const size = scale * 0.65;
            const box = { x: pt.x * w - size / 2, y: pt.y * h - size / 2, w: size, h: size * 0.75 };
            const raw = this.regionSkinRatio(box);
            return this.evaluatePart(smoothed, key, raw, skinThreshold);
        };
        const leftShoe = checkFoot(27, 31, 'leftShoe');
        const rightShoe = checkFoot(28, 32, 'rightShoe');
        if (leftShoe !== null || rightShoe !== null) {
            result.shoes = !((leftShoe === null || leftShoe) && (rightShoe === null || rightShoe));
        }

        return result;
    },

    clearSmoothed(smooth, key) {
        // drop stale smoothed value once a part leaves frame, so it doesn't
        // "remember" an old reading if it comes back into frame differently
        delete smooth[key];
    },

    getSmoothedState(personIdx) {
        if (!this.trackState[personIdx]) this.trackState[personIdx] = {};
        if (!this.trackState[personIdx].smooth) this.trackState[personIdx].smooth = {};
        return this.trackState[personIdx].smooth;
    },

    // Applies exponential smoothing to the raw skin ratio for a given part,
    // then applies hysteresis around skinThreshold so borderline/noisy readings
    // (common when the region is small, e.g. person far from camera) don't
    // flip the result every frame — it only changes state when the smoothed
    // reading clearly crosses into the other zone.
    evaluatePart(smooth, key, raw, skinThreshold) {
        if (raw === null) return smooth[key]?.present ?? null;

        const prevValue = smooth[key]?.value;
        const alpha = prevValue === undefined ? 1 : 0.25; // faster settle on first reading
        const value = prevValue === undefined ? raw : prevValue + alpha * (raw - prevValue);

        const margin = 0.08; // dead-zone half-width around the threshold
        let present = smooth[key]?.present ?? null;

        if (value > skinThreshold + margin) {
            present = false; // clearly skin -> missing
        } else if (value < skinThreshold - margin) {
            present = true; // clearly covered -> worn
        }
        // else: inside dead-zone, keep previous decision (reduces distance-related flicker)

        smooth[key] = { value, present };
        return present;
    },

    bodyScale(lm, w, h) {
        const ls = lm[11], rs = lm[12];
        if (!ls || !rs) return 60;
        const dx = (ls.x - rs.x) * w;
        const dy = (ls.y - rs.y) * h;
        return Math.max(30, Math.hypot(dx, dy)); // shoulder width as scale reference
    },

    // samples a box region on the canvas, returns fraction of "skin-like" pixels, or null if box invalid
    regionSkinRatio(box) {
        const x = Math.max(0, Math.round(box.x));
        const y = Math.max(0, Math.round(box.y));
        const w = Math.min(this.canvas.width - x, Math.round(box.w));
        const h = Math.min(this.canvas.height - y, Math.round(box.h));
        if (w <= 4 || h <= 4) return null;

        let imageData;
        try {
            imageData = this.ctx.getImageData(x, y, w, h).data;
        } catch (e) {
            return null;
        }

        const pixelCount = w * h;
        // small region (far away person) -> sample every pixel; larger region -> skip for speed
        const step = pixelCount < 900 ? 4 : 4 * 3;

        let skinCount = 0;
        let total = 0;
        for (let i = 0; i < imageData.length; i += step) {
            const r = imageData[i], g = imageData[i + 1], b = imageData[i + 2];
            total++;
            if (this.isSkinPixel(r, g, b)) skinCount++;
        }
        // too few samples even after dense sampling -> unreliable, skip this frame's reading
        if (total < 12) return null;
        return skinCount / total;
    },

    // broad RGB-space skin heuristic (works across a range of skin tones,
    // still imperfect — lighting matters a lot)
    isSkinPixel(r, g, b) {
        const max = Math.max(r, g, b), min = Math.min(r, g, b);
        const diff = max - min;
        return (
            r > 60 && g > 30 && b > 15 &&
            diff > 10 &&
            Math.abs(r - g) > 8 &&
            r > g && r > b
        );
    },

    cropThumbnail(lm) {
        const nose = lm[0];
        const w = this.canvas.width, h = this.canvas.height;
        const size = 80;
        const x = Math.max(0, Math.min(this.canvas.width - size, nose.x * w - size / 2));
        const y = Math.max(0, Math.min(this.canvas.height - size, nose.y * h - size / 2));
        const tmp = document.createElement('canvas');
        tmp.width = size; tmp.height = size;
        tmp.getContext('2d').drawImage(this.canvas, x, y, size, size, 0, 0, size, size);
        return tmp.toDataURL('image/jpeg', 0.6);
    },

    partLabel(part) {
        return { helmet: 'Helmet', gloves: 'Gloves', shoes: 'Safety Shoes' }[part] || part;
    },

    // ---- UI ----

    handlePPEDetection(violations) {
        let alertHTML = `<strong>DANGER! PPE Violation${violations.length > 1 ? 's' : ''}</strong><br>`;
        violations.forEach(v => {
            alertHTML += `<strong>${v.name}:</strong> Missing - ${v.missing.join(", ")}<br>`;
        });

        this.elements.alertText.innerHTML = alertHTML;
        this.elements.alertBanner.classList.remove('d-none');
        this.elements.alertBanner.classList.add('show');

        this.updatePPEDisplay(violations);
        if (!this.alarmActive) this.playAlarmSound();
        this.alarmActive = true;
        if (this.elements.alarmStatus) this.elements.alarmStatus.textContent = 'ALARM';
        if (this.elements.alarmIndicator) this.elements.alarmIndicator.classList.add('active');
    },

    updatePPEDisplay(violations) {
        let html = '<div class="row g-3">';
        violations.forEach(v => {
            html += `
                <div class="col-md-6">
                    <div class="card border-danger">
                        <div class="card-body d-flex align-items-center">
                            <img src="${v.image}" class="rounded-circle me-3" width="55" height="55">
                            <div>
                                <h6 class="mb-1">${v.name}</h6>
                                <small class="text-danger">Missing: ${v.missing.join(", ")}</small>
                            </div>
                        </div>
                    </div>
                </div>`;
        });
        html += '</div>';
        this.elements.ppePanel.innerHTML = html;
    },

    addToHistory(person) {
        const detection = {
            id: this.detectionHistory.length + 1,
            timestamp: new Date(),
            person: person.name,
            missing: person.missing.join(", "),
        };

        this.detectionHistory.unshift(detection);
        this.detectionCount++;
        this.updateDetectionCount();
        this.addHistoryRow(detection);
    },

    addHistoryRow(detection) {
        const tbody = this.elements.historyTableBody;
        const empty = tbody.querySelector('tr[colspan]');
        if (empty) empty.remove();

        const row = document.createElement('tr');
        row.innerHTML = `
            <td>#${detection.id}</td>
            <td>${this.formatDateTime(detection.timestamp)}</td>
            <td>${detection.person}</td>
            <td><span class="badge bg-danger">${detection.missing}</span></td>
        `;
        tbody.insertBefore(row, tbody.firstChild);

        if (tbody.children.length > 12) tbody.removeChild(tbody.lastChild);
    },

    formatDateTime(date) {
        return date.toLocaleString('en-US', {
            month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        });
    },

    playAlarmSound() {
        if (!this.soundEnabled) return;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.frequency.value = 900;
            osc.type = 'sawtooth';
            gain.gain.setValueAtTime(0.4, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
            osc.start();
            osc.stop(ctx.currentTime + 0.6);
        } catch (e) {}
    },

    clearAlert() {
        this.elements.alertBanner.classList.add('d-none');
        this.elements.alertBanner.classList.remove('show');
        this.elements.ppePanel.innerHTML = `<p class="text-muted text-center py-4">No violations detected</p>`;
        this.alarmActive = false;
        if (this.elements.alarmStatus) this.elements.alarmStatus.textContent = 'STANDBY';
        if (this.elements.alarmIndicator) this.elements.alarmIndicator.classList.remove('active');
    },

    resetSystem() {
        this.stopCamera();
        this.clearAlert();
        this.clearHistory();
        this.detectionCount = 0;
        this.trackState = {};
        this.updateDetectionCount();
    },

    clearHistory() {
        if (confirm('Clear all history?')) {
            this.detectionHistory = [];
            this.elements.historyTableBody.innerHTML = `
                <tr><td colspan="5" class="text-center text-muted py-4">
                    <i class="fas fa-inbox me-2"></i>No detections yet
                </td></tr>`;
        }
    },

    updateDetectionCount() {
        this.elements.detectionCount.innerHTML = `<i class="fas fa-person me-2"></i>${this.detectionCount}`;
    },

    updateClock() {
        setInterval(() => {
            const t = new Date();
            const el = document.getElementById('current-time');
            if (el) {
                el.textContent =
                    `${String(t.getHours()).padStart(2,'0')}:${String(t.getMinutes()).padStart(2,'0')}:${String(t.getSeconds()).padStart(2,'0')}`;
            }
        }, 1000);
    }
};

document.addEventListener('DOMContentLoaded', () => SecuritySystem.init());
