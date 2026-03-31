/**
 * assets/js/main.js -> ALiHS global JavaScript
 *
 * Single JavaScript file loaded on every page via:
 *   <script src="../assets/js/main.js"></script>
 *   or <script src="assets/js/main.js"> from the root index.php
 *
 * All modules are gated by checking for specific DOM elements,
 * so only the relevant code runs on each page. No module executes
 * on a page where its target element is absent.
 *
 * Modules, roughly in order of where they appear in this file:
 *   Session init -> cookie management, first-visit token creation
 *   Mobile nav -> hamburger toggle and dropdown keyboard a11y
 *   Job poller -> polls ?action=poll every 2s during pipeline runs
 *   Sequence table -> sortable and filterable table plus exclude checkboxes
 *   Length histogram -> Chart.js histogram on fetch.php
 *   Conservation chart -> Chart.js line chart on analysis.php
 *   Conserved regions bar -> colour-coded canvas strip below conservation chart
 *   Motif map canvas -> SVG/canvas motif domain map fallback on motifs.php
 *   FAQ accordion -> expand/collapse on help.php, also inline-handled
 *
 * Custom DOM events fired by inline page scripts that this file listens for:
 *   alihs:sequences-loaded (fetch.php) -> { jobId, sequences }
 *   alihs:load-conservation (analysis.php) -> { alignmentId, jobId }
 *   alihs:load-motif-map (motifs.php) -> { jobId }
 * 
 * Note: some variables might have phyloseq instead of alihs
 * This was just an AI-generated placeholder before I decided on ALiHS
 */

(function (global) {
    'use strict';


    /**
     * Utilities -> shared helpers used across multiple modules
     */

    /**
     * getCookie -> read a cookie value by name.
     * Returns the value string or null if not found.
     */
    function getCookie(name) {
        const match = document.cookie.match(
            new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)')
        );
        return match ? decodeURIComponent(match[1]) : null;
    }

    /**
     * setCookie -> write a cookie with a max-age in seconds.
     */
    function setCookie(name, value, maxAge) {
        document.cookie =
            encodeURIComponent(name) + '=' + encodeURIComponent(value) +
            '; max-age=' + maxAge +
            '; path=/; SameSite=Lax';
    }

    /**
     * generateToken -> produce a random 32-character hex token.
     * Used as the session identifier when no cookie exists yet.
     */
    function generateToken() {
        const arr = new Uint8Array(16);
        crypto.getRandomValues(arr);
        return Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
    }

    /**
     * escHtml -> escape a string for safe insertion as HTML text.
     */
    function escHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /**
     * clamp -> keep a number within a given range.
     */
    function clamp(val, min, max) {
        return Math.min(Math.max(val, min), max);
    }

    /**
     * scoreToColour -> map a conservation score between 0 and 1 to a CSS colour.
     * Red for low conservation, amber for mid, green for high.
     * I went back and forth on the exact thresholds here quite a bit.
     */
    function scoreToColour(score) {
        if (score >= 0.8) return '#2e7d4f'; // green
        if (score >= 0.6) return '#5aaa7a'; // light green
        if (score >= 0.4) return '#e8a020'; // amber
        if (score >= 0.2) return '#d06030'; // orange-red
        return '#b03020'; // red
    }

    // Cookie name -> must match the SESSION_COOKIE constant in config.php
    const COOKIE_NAME = 'alihs_session';
    // Cookie TTL: 90 days in seconds
    const COOKIE_TTL = 90 * 24 * 60 * 60;


    /**
     * Session init
     *
     * Runs on every page load. Checks for the session cookie; if it's not there,
     * a fresh token gets generated, stored in the cookie, and stashed in
     * localStorage as a human-recoverable backup for the revisit page.
     * If the cookie already exists, the TTL just gets renewed -> sliding expiry.
     */

    (function sessionInit() {
        let token = getCookie(COOKIE_NAME);

        if (!token) {
            // No existing cookie -> generate a fresh token
            token = generateToken();
            setCookie(COOKIE_NAME, token, COOKIE_TTL);
        } else {
            // Renew the cookie TTL on each visit
            setCookie(COOKIE_NAME, token, COOKIE_TTL);
        }

        // Store in localStorage as a human-recoverable backup
        try {
            localStorage.setItem(COOKIE_NAME, token);
        } catch (e) {
            // localStorage may be unavailable in some contexts -> ignore
        }

        // Expose on global so inline page scripts can read it
        global.__phyloseqToken = token;

        // Pre-populate any token input fields on the page
        // -> this is mainly for the revisit.php token-input field
        const tokenInput = document.getElementById('token-input');
        if (tokenInput && !tokenInput.value) {
            tokenInput.value = token;
        }

    }());


    /**
     * Mobile nav and dropdown accessibility
     *
     * Runs on every page. Handles the hamburger toggle and Escape-to-close
     * for dropdowns. Nothing clever going on here, just event listeners.
     */

    (function mobileNav() {
        const hamburger = document.querySelector('.navi_hamburger');
        const navLinks = document.querySelector('.navi_links');

        if (hamburger && navLinks) {
            hamburger.addEventListener('click', function () {
                const expanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', String(!expanded));
                navLinks.classList.toggle('nav-open');
            });
        }

        // Close all dropdowns when Escape is pressed
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.dropdown-menu').forEach(menu => {
                    menu.style.display = '';
                });
                if (navLinks) navLinks.classList.remove('nav-open');
                if (hamburger) hamburger.setAttribute('aria-expanded', 'false');
            }
        });

        // Close nav when clicking outside on mobile
        document.addEventListener('click', function (e) {
            if (navLinks && navLinks.classList.contains('nav-open')) {
                if (!navLinks.contains(e.target) &&
                    hamburger && !hamburger.contains(e.target)) {
                    navLinks.classList.remove('nav-open');
                    hamburger.setAttribute('aria-expanded', 'false');
                }
            }
        });

    }());


    /**
     * Job poller
     *
     * Active on pages that have a #job-status-bar element and a #active-job-id
     * hidden input with a positive value. Polls ?action=poll every 2 seconds
     * and updates the progress bar accordingly.
     *
     * Page-specific logic fires after a status change via callbacks stored in
     * global.__phyloseqOnJobDone and __phyloseqOnJobFailed. I could have
     * used CustomEvents for this too but the globals felt simpler given how
     * few pages actually use the poller.
     */

    (function jobPoller() {
        const statusBar = document.getElementById('job-status-bar');
        const progressEl = document.getElementById('progress-bar');
        const statusMsg = document.getElementById('status-message');
        const jobIdInput = document.getElementById('active-job-id');

        // Only activate if all required elements are present
        if (!statusBar || !progressEl || !jobIdInput) return;

        const jobId = parseInt(jobIdInput.value);
        if (!jobId || jobId <= 0) return;

        // Determine which page we are on by looking for page-specific elements
        const onFetch = !!document.getElementById('fetch-form-section');
        const onAnalysis = !!document.getElementById('conservation-chart');
        const onMotifs = !!document.getElementById('motif-summary-table');

        // Rotating status messages to show while waiting -> these are more
        // reassuring than a spinner with no text, I think
        const messages = [
            'Processing\u2026',
            'Querying NCBI\u2026',
            'Retrieving sequences\u2026',
            'Running alignment\u2026',
            'Scoring conservation\u2026',
            'Scanning motifs\u2026',
            'Almost done\u2026',
        ];

        let ticks = 0;
        let fakeProgress = 15;
        let pollInterval = null;

        function setProgress(pct, msg) {
            if (progressEl) progressEl.style.width = clamp(pct, 0, 100) + '%';
            if (statusMsg) statusMsg.textContent = msg || '';
        }

        function stopPolling() {
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
        }

        // Determine the current page's action=poll URL
        const currentPage = window.location.pathname.split('/').pop();

        pollInterval = setInterval(function () {
            ticks++;
            // Slowly advance the fake progress bar while waiting
            fakeProgress = Math.min(fakeProgress + 2, 88);
            setProgress(fakeProgress, messages[ticks % messages.length]);

            fetch(currentPage + '?action=poll&job_id=' + jobId)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    const status = data.job_status || data.status || '';

                    if (status === 'done') {
                        stopPolling();
                        setProgress(100, 'Complete.');

                        setTimeout(function () {
                            statusBar.classList.remove('visible');
                            // Fire page-specific done callback if registered
                            if (typeof global.__phyloseqOnJobDone === 'function') {
                                global.__phyloseqOnJobDone(data);
                            }
                        }, 500);

                    } else if (status === 'failed') {
                        stopPolling();
                        statusBar.classList.remove('visible');
                        if (typeof global.__phyloseqOnJobFailed === 'function') {
                            global.__phyloseqOnJobFailed(data);
                        }
                    }
                    // 'running' or 'queued' -> keep polling
                })
                .catch(function () {
                    // Network hiccup -> keep polling and don't abort
                });

        }, 2000);

        // Expose stop function for inline scripts that want to halt polling
        global.__phyloseqStopPoller = stopPolling;

    }());


    /**
     * Sequence table
     *
     * Active on fetch.php when #seq-table is present. Handles:
     *   rendering rows from the alihs:sequences-loaded event,
     *   live search and filter,
     *   column sort by organism, accession, or length,
     *   per-row include/exclude checkbox logic,
     *   select-all and deselect-all buttons,
     *   running included-count update.
     */

    document.addEventListener('alihs:sequences-loaded', function (e) {
        const { sequences } = e.detail;
        const tbody = document.getElementById('seq-table-body');
        const searchInput = document.getElementById('table-search');
        const includedCount = document.getElementById('included-count');
        const selectAllBtn = document.getElementById('select-all-btn');
        const deselectAllBtn = document.getElementById('deselect-all-btn');
        const checkAll = document.getElementById('check-all');

        if (!tbody) return;

        // Render rows
        let allRows = sequences; // keep reference for filter/sort

        function renderRows(rows) {
            tbody.innerHTML = rows.map(function (seq) {
                const excluded = seq.excluded == 1;
                return [
                    '<tr class="' + (excluded ? 'excluded' : '') + '">',
                    '<td>',
                    '<input type="checkbox" ',
                    'data-seq-id="' + escHtml(seq.seq_id) + '" ',
                    (excluded ? '' : 'checked') + ' ',
                    'aria-label="Include ' + escHtml(seq.organism) + '">',
                    '</td>',
                    '<td>',
                    '<a class="accession-link" ',
                    'href="https://www.ncbi.nlm.nih.gov/protein/' + escHtml(seq.accession) + '" ',
                    'target="_blank" rel="noopener noreferrer">',
                    escHtml(seq.accession),
                    '</a>',
                    '</td>',
                    '<td><em>' + escHtml(seq.organism) + '</em></td>',
                    '<td>' + escHtml(seq.order_name || '\u2014') + '</td>',
                    '<td>' + (parseInt(seq.length) || 0) + '</td>',
                    '<td style="font-size:0.82rem;color:var(--colour-text-muted);max-width:260px;">',
                    escHtml(seq.description || ''),
                    '</td>',
                    '</tr>',
                ].join('');
            }).join('');

            updateIncludedCount();
            attachCheckboxListeners();
        }

        // Checkbox logic
        function updateIncludedCount() {
            const total = tbody.querySelectorAll('input[type="checkbox"]').length;
            const checked = tbody.querySelectorAll('input[type="checkbox"]:checked').length;
            if (includedCount) includedCount.textContent = checked;
            if (checkAll) {
                checkAll.checked = checked === total && total > 0;
                checkAll.indeterminate = checked > 0 && checked < total;
            }
        }

        function attachCheckboxListeners() {
            tbody.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                cb.addEventListener('change', function () {
                    const row = this.closest('tr');
                    if (row) row.classList.toggle('excluded', !this.checked);
                    updateIncludedCount();
                });
            });
        }

        // Header checkbox -> select or deselect all visible rows
        if (checkAll) {
            checkAll.addEventListener('change', function () {
                const visible = tbody.querySelectorAll('tr:not([style*="none"])');
                visible.forEach(function (row) {
                    const cb = row.querySelector('input[type="checkbox"]');
                    if (cb) {
                        cb.checked = checkAll.checked;
                        row.classList.toggle('excluded', !checkAll.checked);
                    }
                });
                updateIncludedCount();
            });
        }

        // Select-all and Deselect-all buttons
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function () {
                tbody.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                    cb.checked = true;
                    const row = cb.closest('tr');
                    if (row) row.classList.remove('excluded');
                });
                updateIncludedCount();
            });
        }
        if (deselectAllBtn) {
            deselectAllBtn.addEventListener('click', function () {
                tbody.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                    cb.checked = false;
                    const row = cb.closest('tr');
                    if (row) row.classList.add('excluded');
                });
                updateIncludedCount();
            });
        }

        // Search and filter
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const q = this.value.trim().toLowerCase();
                tbody.querySelectorAll('tr').forEach(function (row) {
                    const text = row.textContent.toLowerCase();
                    row.style.display = (!q || text.includes(q)) ? '' : 'none';
                });
            });
        }

        // Column sort
        const table = document.getElementById('seq-table');
        if (table) {
            const headers = table.querySelectorAll('th[data-col]');
            let sortCol = null;
            let sortDir = 1;

            headers.forEach(function (th) {
                th.style.cursor = 'pointer';
                th.addEventListener('click', function () {
                    const col = this.dataset.col;
                    sortDir = (col === sortCol) ? -sortDir : 1;
                    sortCol = col;

                    // Re-sort allRows in place
                    allRows = allRows.slice().sort(function (a, b) {
                        let av = a[col] ?? '';
                        let bv = b[col] ?? '';
                        const an = parseFloat(av);
                        const bn = parseFloat(bv);
                        if (!isNaN(an) && !isNaN(bn)) return (an - bn) * sortDir;
                        return String(av).localeCompare(String(bv)) * sortDir;
                    });
                    renderRows(allRows);

                    // Update sort indicators
                    headers.forEach(function (h) { h.style.opacity = '1'; });
                    this.style.opacity = '0.65';
                });
            });
        }

        // Render on load
        renderRows(allRows);

    });


    /**
     * Length histogram (fetch.php)
     *
     * Active when #length-chart canvas is present. Triggered by the
     * alihs:sequences-loaded event -> renders a Chart.js bar histogram
     * of sequence lengths. Up to 12 bins, calculated from the actual
     * min/max of the dataset, which I think is more honest than hardcoding
     * a range and hoping for the best.
     */

    document.addEventListener('alihs:sequences-loaded', function (e) {
        const canvas = document.getElementById('length-chart');
        if (!canvas) return;

        const sequences = e.detail.sequences;
        if (!sequences || sequences.length === 0) return;

        const lengths = sequences.map(function (s) { return parseInt(s.length) || 0; });
        const minLen = Math.min.apply(null, lengths);
        const maxLen = Math.max.apply(null, lengths);

        // Build histogram buckets, up to 12 bins
        const binCount = Math.min(12, Math.max(4, sequences.length));
        const binWidth = Math.ceil((maxLen - minLen + 1) / binCount) || 1;
        const bins = Array(binCount).fill(0);
        const labels = [];

        for (let i = 0; i < binCount; i++) {
            const lo = minLen + i * binWidth;
            const hi = lo + binWidth - 1;
            labels.push(lo + (binWidth > 1 ? '\u2013' + hi : ''));
        }

        lengths.forEach(function (len) {
            const idx = Math.min(Math.floor((len - minLen) / binWidth), binCount - 1);
            bins[idx]++;
        });

        // Destroy existing chart instance if present
        if (global.__phyloseqLengthChart) {
            global.__phyloseqLengthChart.destroy();
        }

        /* global Chart */
        if (typeof Chart === 'undefined') return;

        global.__phyloseqLengthChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Number of sequences',
                    data: bins,
                    backgroundColor: 'rgba(26,107,114,0.65)',
                    borderColor: 'rgba(26,107,114,0.9)',
                    borderWidth: 1,
                    borderRadius: 3,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function (ctx) {
                                return 'Length: ' + ctx[0].label + ' aa';
                            },
                            label: function (ctx) {
                                return ctx.raw + ' sequence' + (ctx.raw !== 1 ? 's' : '');
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Sequence length (aa)',
                            font: { size: 10 },
                        },
                        ticks: { font: { size: 9 }, maxRotation: 45 },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 9 } },
                        title: {
                            display: true,
                            text: 'Sequences',
                            font: { size: 10 },
                        },
                    },
                },
            },
        });
    });


    /**
     * Conservation line chart (analysis.php)
     *
     * Active when #conservation-chart canvas is present. Triggered by the
     * alihs:load-conservation custom event. Fetches score data from
     * analysis.php?action=get_conservation and renders a Chart.js line chart
     * with colour-gradient segments and hover tooltips.
     *
     * The individual point dots are hidden for performance -> there can be
     * hundreds of positions and rendering them all made the chart sluggish
     * on longer alignments. Hover radius is still set so tooltips work fine.
     */

    document.addEventListener('alihs:load-conservation', function (e) {
        const canvas = document.getElementById('conservation-chart');
        if (!canvas) return;

        const alignmentId = e.detail.alignmentId;
        if (!alignmentId) return;

        fetch('analysis.php?action=get_conservation&alignment_id=' + alignmentId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                const scores = data.scores || [];
                if (scores.length === 0) return;

                const positions = scores.map(function (s) { return s.position; });
                const conservation = scores.map(function (s) { return parseFloat(s.conservation_score); });
                const gaps = scores.map(function (s) { return parseFloat(s.gap_fraction); });

                // Build per-point background colours based on conservation score
                const pointColours = conservation.map(scoreToColour);

                // Destroy existing chart before re-rendering
                if (global.__phyloseqConservationChart) {
                    global.__phyloseqConservationChart.destroy();
                }

                if (typeof Chart === 'undefined') return;

                global.__phyloseqConservationChart = new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: positions,
                        datasets: [{
                            label: 'Conservation score',
                            data: conservation,
                            borderColor: 'rgba(26,107,114,0.85)',
                            backgroundColor: 'rgba(26,107,114,0.08)',
                            borderWidth: 1.5,
                            pointRadius: 0, // hidden for performance on long alignments
                            pointHoverRadius: 4,
                            pointHoverBackgroundColor: pointColours,
                            tension: 0.2,
                            fill: true,
                        }],
                    },
                    options: {
                        responsive: true,
                        animation: { duration: 300 },
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    title: function (ctx) {
                                        return 'Position ' + ctx[0].label;
                                    },
                                    label: function (ctx) {
                                        const score = ctx.raw.toFixed(3);
                                        const gap = (gaps[ctx.dataIndex] * 100).toFixed(1);
                                        return [
                                            'Conservation: ' + score,
                                            'Gap fraction: ' + gap + '%',
                                        ];
                                    },
                                    labelColor: function (ctx) {
                                        return {
                                            borderColor: scoreToColour(ctx.raw),
                                            backgroundColor: scoreToColour(ctx.raw),
                                        };
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                title: {
                                    display: true,
                                    text: 'Alignment position',
                                    font: { size: 10 },
                                },
                                ticks: {
                                    maxTicksLimit: 20,
                                    font: { size: 9 },
                                },
                            },
                            y: {
                                min: 0,
                                max: 1,
                                title: {
                                    display: true,
                                    text: 'Conservation score',
                                    font: { size: 10 },
                                },
                                ticks: {
                                    font: { size: 9 },
                                    callback: function (val) {
                                        return val.toFixed(1);
                                    },
                                },
                            },
                        },
                    },
                });

                // Count and update the >80% conserved columns stat card
                const highlyConserved = conservation.filter(function (s) {
                    return s >= 0.8;
                }).length;
                const statEl = document.getElementById('stat-conserved-cols');
                if (statEl) statEl.textContent = highlyConserved;

                // Render the conserved-regions bar beneath the chart
                renderConservedBar(conservation);
            })
            .catch(function (err) {
                console.error('[PhyloSeq] Conservation chart load failed:', err);
            });
    });


    /**
     * Conserved regions bar (analysis.php)
     *
     * Renders a thin colour-coded canvas strip below the conservation chart.
     * Each pixel column represents one alignment position, coloured by its
     * conservation score -> same colour scale as scoreToColour above.
     * It's a pretty simple canvas draw but it adds a lot of visual context
     * at a glance, which I think is worth the extra few lines.
     */

    function renderConservedBar(conservationScores) {
        const barCanvas = document.getElementById('conserved-regions-bar');
        if (!barCanvas) return;

        const W = barCanvas.offsetWidth || 700;
        const H = 18;
        barCanvas.width = W;
        barCanvas.height = H;

        const ctx = barCanvas.getContext('2d');
        if (!ctx) return;

        const n = conservationScores.length;
        const colWidth = W / n;

        conservationScores.forEach(function (score, i) {
            ctx.fillStyle = scoreToColour(score);
            ctx.fillRect(Math.floor(i * colWidth), 0,
                Math.max(1, Math.ceil(colWidth)), H);
        });

        // Threshold line at 0.8 -> dashed, semi-transparent white
        ctx.strokeStyle = 'rgba(255,255,255,0.6)';
        ctx.lineWidth = 1;
        ctx.setLineDash([4, 3]);
        ctx.beginPath();
        ctx.moveTo(0, H * 0.5);
        ctx.lineTo(W, H * 0.5);
        ctx.stroke();
        ctx.setLineDash([]);
    }

    /**
     * Motif map canvas (motifs.php)
     *
     * Active when #motif-map-canvas is present, which only happens when the
     * SVG file is unavailable. Triggered by the alihs:load-motif-map custom
     * event. Fetches data from motifs.php?action=get_motifs and renders a
     * domain map on HTML canvas -> each row is a sequence, each coloured block
     * is a motif hit. I tried doing this as a table first but the canvas
     * approach handles variable sequence lengths much more naturally.
     */

    document.addEventListener('alihs:load-motif-map', function (e) {
        const canvas = document.getElementById('motif-map-canvas');
        if (!canvas) return;

        const jobId = e.detail.jobId;
        if (!jobId) return;

        fetch('motifs.php?action=get_motifs&job_id=' + jobId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                const hits = data.hits || [];
                const summary = data.summary || [];
                const totalSeqs = data.total_seqs || 0;

                if (hits.length === 0) return;

                // Build a consistent colour map per motif_id -> cycles through
                // the palette if there are more than 10 motifs
                const PALETTE = [
                    '#1a6b72', '#e8a020', '#2e7d4f', '#9a3a60', '#3a5aa0',
                    '#c05a20', '#5a3a9a', '#2a7a5a', '#a05a1a', '#1a5a8a',
                ];
                const colourMap = {};
                summary.forEach(function (m, i) {
                    colourMap[m.motif_id] = PALETTE[i % PALETTE.length];
                });

                // Group hits by sequence
                const hitsBySeq = {};
                const seqLengths = {};
                hits.forEach(function (h) {
                    const key = h.accession;
                    if (!hitsBySeq[key]) hitsBySeq[key] = [];
                    hitsBySeq[key].push(h);
                    seqLengths[key] = Math.max(
                        seqLengths[key] || 0,
                        parseInt(h.seq_length) || 0
                    );
                });

                const accessions = Object.keys(hitsBySeq);
                const maxLen = Math.max.apply(null, Object.values(seqLengths)) || 500;

                // Canvas layout constants -> took some trial and error to land on
                // values that don't look cramped or weirdly spacious
                const ROW_H = 24;
                const LABEL_W = 160;
                const BAR_W = 500;
                const PAD = 8;
                const LEGEND_H = Math.ceil(summary.length / 3) * 20 + 30;
                const W = LABEL_W + BAR_W + PAD * 2;
                const H = PAD + accessions.length * (ROW_H + 4) + LEGEND_H + PAD;

                canvas.width = W;
                canvas.height = H;
                canvas.style.width = W + 'px';
                canvas.style.height = H + 'px';

                const ctx = canvas.getContext('2d');
                if (!ctx) return;

                ctx.fillStyle = '#f7f9fb';
                ctx.fillRect(0, 0, W, H);

                // Draw rows -> one per sequence
                accessions.forEach(function (acc, rowIdx) {
                    const y = PAD + rowIdx * (ROW_H + 4);
                    const seqLen = seqLengths[acc] || maxLen;
                    const barLen = (seqLen / maxLen) * BAR_W;

                    // Background bar representing the full sequence length
                    ctx.fillStyle = '#e8ecee';
                    ctx.strokeStyle = '#c8d0d4';
                    ctx.lineWidth = 0.5;
                    ctx.beginPath();
                    ctx.rect(LABEL_W, y + (ROW_H - 12) / 2, barLen, 12);
                    ctx.fill();
                    ctx.stroke();

                    // Motif blocks overlaid on the background bar
                    (hitsBySeq[acc] || []).forEach(function (h) {
                        const startNorm = (h.start_pos - 1) / maxLen;
                        const widthNorm = Math.max(
                            0.005,
                            (h.end_pos - h.start_pos + 1) / maxLen
                        );
                        ctx.fillStyle = colourMap[h.motif_id] || '#888';
                        ctx.fillRect(
                            LABEL_W + startNorm * BAR_W,
                            y + (ROW_H - 14) / 2,
                            widthNorm * BAR_W,
                            14
                        );
                    });

                    // Accession label to the left of each row
                    ctx.fillStyle = '#444';
                    ctx.font = '10px sans-serif';
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'middle';
                    const shortAcc = acc.split('|').pop().substring(0, 20);
                    ctx.fillText(shortAcc, LABEL_W - 6, y + ROW_H / 2);
                });

                // Legend at the bottom -> capped at 10 entries in a 3-column grid
                const legendY = PAD + accessions.length * (ROW_H + 4) + PAD;
                ctx.fillStyle = '#666';
                ctx.font = 'bold 10px sans-serif';
                ctx.textAlign = 'left';
                ctx.textBaseline = 'top';
                ctx.fillText('Motifs:', PAD, legendY);

                summary.slice(0, 10).forEach(function (m, i) {
                    const col = i % 3;
                    const row = Math.floor(i / 3);
                    const lx = PAD + col * 170;
                    const ly = legendY + 18 + row * 20;
                    ctx.fillStyle = colourMap[m.motif_id] || '#888';
                    ctx.fillRect(lx, ly + 2, 12, 12);
                    ctx.fillStyle = '#444';
                    ctx.font = '9px sans-serif';
                    ctx.textBaseline = 'top';
                    ctx.fillText(m.motif_id, lx + 16, ly);
                });
            })
            .catch(function (err) {
                console.error('[PhyloSeq] Motif map canvas load failed:', err);
            });
    });


    /**
     * FAQ accordion (help.php)
     *
     * Active when .faq-question elements are present. This is a global fallback;
     * help.php also has an inline version -> both are safe to coexist because the
     * inline version checks for aria-expanded state before acting, so nothing
     * fires twice. I discovered that the hard way when help.php was toggling
     * sections twice per click, which was embarrassing.
     */

    (function faqAccordion() {
        const questions = document.querySelectorAll('.faq-question');
        if (questions.length === 0) return;

        questions.forEach(function (btn) {
            // Skip if the inline script already bound this element
            if (btn.dataset.faqBound) return;
            btn.dataset.faqBound = 'true';

            btn.addEventListener('click', function () {
                const isOpen = this.getAttribute('aria-expanded') === 'true';
                const answerId = this.getAttribute('aria-controls');
                const answer = answerId ? document.getElementById(answerId) : null;

                // Close all open items first
                questions.forEach(function (b) {
                    b.setAttribute('aria-expanded', 'false');
                    const aid = b.getAttribute('aria-controls');
                    if (aid) {
                        const a = document.getElementById(aid);
                        if (a) a.classList.remove('open');
                    }
                });

                // Open the clicked item if it was previously closed
                if (!isOpen) {
                    this.setAttribute('aria-expanded', 'true');
                    if (answer) answer.classList.add('open');
                }
            });
        });
    }());


    /**
     * Back-to-top button
     *
     * Shown after scrolling down 400px, smooth-scrolls back to the top.
     * Injected dynamically so there's no need to add it to every template.
     * Only injects if the button isn't already in the HTML -> some pages
     * might include it statically in future and this check keeps it idempotent.
     */

    (function backToTop() {
        if (document.getElementById('back-to-top')) return;

        const btn = document.createElement('button');
        btn.id = 'back-to-top';
        btn.textContent = '\u2191';
        btn.setAttribute('aria-label', 'Back to top');
        btn.style.cssText = [
            'position:fixed',
            'bottom:1.5rem',
            'right:1.5rem',
            'width:38px',
            'height:38px',
            'border-radius:50%',
            'background:var(--colour-primary,#1a6b72)',
            'color:#fff',
            'border:none',
            'font-size:1.1rem',
            'line-height:1',
            'cursor:pointer',
            'opacity:0',
            'transition:opacity 0.25s',
            'z-index:500',
            'box-shadow:0 2px 8px rgba(0,0,0,0.18)',
        ].join(';');

        document.body.appendChild(btn);

        window.addEventListener('scroll', function () {
            btn.style.opacity = window.scrollY > 400 ? '1' : '0';
            btn.style.pointerEvents = window.scrollY > 400 ? 'auto' : 'none';
        }, { passive: true });

        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }());


}(window));
