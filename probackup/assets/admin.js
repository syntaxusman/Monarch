/* ProBackup admin app — vanilla JS, no build step. */
(function () {
	'use strict';

	var C = window.ProBackup || {};
	var app = document.getElementById('probackup-app');
	if (!app) { return; }

	/* ---------- helpers ---------- */
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function $(sel, root) { return (root || app).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); }
	function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
	function fmtBytes(b) {
		if (!b) { return '0 B'; }
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = Math.floor(Math.log(b) / Math.log(1024));
		return (b / Math.pow(1024, i)).toFixed(i ? 1 : 0) + ' ' + u[i];
	}
	function fmtTime(s) {
		s = Math.max(0, Math.round(s));
		var m = Math.floor(s / 60), r = s % 60;
		return (m ? m + 'm ' : '') + r + 's';
	}

	var I = {
		up: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-5 5m5-5 5 5M4 20h16"/></svg>',
		down: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v12m0 0 5-5m-5 5-5-5M4 20h16"/></svg>',
		send: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z"/></svg>',
		check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>',
		alert: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>',
		copy: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>',
		db: '<svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>',
		folder: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>',
		key: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="15" r="4"/><path d="m11 12 9-9m-3 3 3 3m-6 0 2 2"/></svg>',
		box: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="m3 8 9 5 9-5M12 13v8"/></svg>',
		trash: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6m4-6v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>',
		back: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18 9 12l6-6"/></svg>',
		arrow: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-5-5 5 5-5 5"/></svg>',
		restore: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8m0-5v5h5"/></svg>'
	};

	/* ---------- AJAX with retry (shared hosts time out) ---------- */
	function api(sub, data, opts) {
		opts = opts || {};
		var tries = opts.retries == null ? 5 : opts.retries;
		var attempt = 0;
		function once() {
			var fd = new FormData();
			fd.append('action', 'probackup_step');
			fd.append('nonce', C.nonce);
			fd.append('sub', sub);
			Object.keys(data || {}).forEach(function (k) {
				var v = data[k];
				if (v instanceof Blob) { fd.append(k, v, 'chunk.bin'); } else { fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v); }
			});
			return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
				return r.text().then(function (t) {
					var j = null;
					try { j = JSON.parse(t); } catch (e) {
						var m = t.match(/\{"success":(true|false)[\s\S]*\}$/);
						if (m) { try { j = JSON.parse(m[0]); } catch (e2) { j = null; } }
					}
					if (r.status >= 500 || !j) {
						var err = new Error('Server error (HTTP ' + r.status + ')');
						err.retry = true;
						throw err;
					}
					if (!j.success) {
						var e3 = new Error((j.data && j.data.message) || 'Request failed');
						e3.data = j.data;
						throw e3;
					}
					return j.data;
				});
			}, function () {
				var e = new Error('Network error — check your connection.');
				e.retry = true;
				throw e;
			});
		}
		function run() {
			return once().catch(function (e) {
				if (e.retry && attempt < tries) {
					attempt++;
					return sleep(Math.min(16000, 1000 * Math.pow(2, attempt - 1))).then(run);
				}
				throw e;
			});
		}
		return run();
	}

	/* ---------- toast ---------- */
	function toast(msg, type) {
		var t = document.createElement('div');
		t.className = 'pb-toast pb-toast--' + (type || 'info');
		t.setAttribute('role', 'status');
		t.textContent = msg;
		document.body.appendChild(t);
		setTimeout(function () { t.classList.add('is-in'); }, 10);
		setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 300); }, 3800);
	}
	function copy(text) {
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(function () { toast('Copied to clipboard', 'ok'); });
		} else {
			var ta = document.createElement('textarea');
			ta.value = text; document.body.appendChild(ta); ta.select();
			try { document.execCommand('copy'); toast('Copied to clipboard', 'ok'); } catch (e) { toast('Copy failed', 'err'); }
			ta.remove();
		}
	}

	/* ---------- state ---------- */
	var S = {
		tab: 'migrate',
		view: 'home',
		running: false,
		backups: C.backups || [],
		settings: C.settings || {},
		croc: null,
		exportOpts: { database: true, uploads: true, plugins: true, themes: true, muplugins: true, other: true, no_spam: true, no_revisions: false, no_transients: true },
		job: null,
		started: 0,
		cancelFlag: false
	};
	try { var saved = JSON.parse(localStorage.getItem('pb_export_opts') || 'null'); if (saved) { S.exportOpts = Object.assign(S.exportOpts, saved); } } catch (e) { /* ignore */ }

	window.addEventListener('beforeunload', function (e) {
		if (S.running) { e.preventDefault(); e.returnValue = ''; return ''; }
	});

	/* ---------- layout ---------- */
	function shell(inner) {
		var tabs = [['migrate', 'Migrate'], ['backups', 'Backups'], ['settings', 'Settings']];
		return '' +
			'<header class="pb-header">' +
				'<div class="pb-brand"><span class="pb-logo">' + I.box + '</span><div><div class="pb-title">ProBackup</div><div class="pb-sub">Whole-site migration · chunked database · encrypted croc transfer</div></div></div>' +
				'<span class="pb-pill">v' + esc(C.version) + '</span>' +
			'</header>' +
			'<nav class="pb-tabs" role="tablist">' + tabs.map(function (t) {
				return '<button role="tab" class="pb-tab' + (S.tab === t[0] ? ' is-active' : '') + '" aria-selected="' + (S.tab === t[0]) + '" data-tab="' + t[0] + '"' + (S.running ? ' disabled' : '') + '>' + t[1] + (t[0] === 'backups' && S.backups.length ? ' <span class="pb-count">' + S.backups.length + '</span>' : '') + '</button>';
			}).join('') + '</nav>' +
			'<main class="pb-main">' + inner + '</main>';
	}

	function render(inner) {
		app.innerHTML = shell(inner);
		$$('.pb-tab').forEach(function (b) {
			b.addEventListener('click', function () { S.tab = b.dataset.tab; S.view = 'home'; route(); });
		});
	}

	function route() {
		if (S.tab === 'backups') { return viewBackups(); }
		if (S.tab === 'settings') { return viewSettings(); }
		return viewHome();
	}

	function steps(list, current) {
		return '<ol class="pb-steps">' + list.map(function (s, i) {
			var cls = i < current ? 'is-done' : (i === current ? 'is-current' : '');
			return '<li class="' + cls + '"><span class="pb-steps__n">' + (i < current ? I.check : (i + 1)) + '</span>' + esc(s) + '</li>';
		}).join('') + '</ol>';
	}

	function toggle(key, label, hint, checked, icon) {
		return '<label class="pb-toggle">' +
			(icon ? '<span class="pb-toggle__icon">' + icon + '</span>' : '') +
			'<span class="pb-toggle__text"><strong>' + esc(label) + '</strong>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</span>' +
			'<input type="checkbox" data-opt="' + key + '"' + (checked ? ' checked' : '') + '><span class="pb-switch" aria-hidden="true"></span>' +
		'</label>';
	}

	/* ---------- home ---------- */
	function viewHome() {
		if (C.multisite) {
			return render('<div class="pb-card pb-alert pb-alert--warn">' + I.alert + '<div><strong>Multisite is not supported yet.</strong><p>ProBackup currently migrates single WordPress sites.</p></div></div>');
		}
		render(
			'<div class="pb-hero">' +
				'<h2>What would you like to do?</h2>' +
				'<p>Move this whole site — database, media, plugins and themes — to another server, or bring a site in. Large databases are processed in small, resumable chunks.</p>' +
			'</div>' +
			'<div class="pb-choices">' +
				'<button class="pb-choice" data-go="export"><span class="pb-choice__icon pb-choice__icon--blue">' + I.up + '</span><span class="pb-choice__title">Export / Send this site</span><span class="pb-choice__text">Create a .pbk package, then send it directly with croc or download it.</span><span class="pb-choice__cta">Start export ' + I.arrow + '</span></button>' +
				'<button class="pb-choice" data-go="import"><span class="pb-choice__icon pb-choice__icon--green">' + I.down + '</span><span class="pb-choice__title">Import / Receive a site</span><span class="pb-choice__text">Receive with a croc code phrase, upload a .pbk file, or restore a saved backup.</span><span class="pb-choice__cta">Start import ' + I.arrow + '</span></button>' +
			'</div>' +
			'<div class="pb-facts">' +
				'<div>' + I.db + '<span><strong>Chunked database</strong>Statement-by-statement import into temporary tables, swapped in atomically.</span></div>' +
				'<div>' + I.key + '<span><strong>End-to-end encrypted</strong>croc transfers server-to-server with a one-time code phrase.</span></div>' +
				'<div>' + I.restore + '<span><strong>Resumable</strong>Every step can stop and continue — no more timeouts.</span></div>' +
			'</div>'
		);
		$$('[data-go]').forEach(function (b) {
			b.addEventListener('click', function () { if (b.dataset.go === 'export') { viewExportOptions(); } else { viewImportSource(); } });
		});
	}

	/* ---------- export ---------- */
	function viewExportOptions() {
		var o = S.exportOpts;
		render(
			'<button class="pb-back" data-back>' + I.back + 'Back</button>' +
			steps(['Choose content', 'Create package', 'Send or download'], 0) +
			'<div class="pb-grid2">' +
				'<section class="pb-card"><h3>' + I.db + ' Database</h3>' +
					toggle('database', 'Include database', 'All tables with prefix of this site', o.database) +
					'<div class="pb-sub-opts' + (o.database ? '' : ' is-disabled') + '">' +
						toggle('no_spam', 'Skip spam & trashed comments', '', o.no_spam) +
						toggle('no_revisions', 'Skip post revisions', 'Makes the package smaller', o.no_revisions) +
						toggle('no_transients', 'Skip transients (cache)', 'Recommended', o.no_transients) +
					'</div>' +
				'</section>' +
				'<section class="pb-card"><h3>' + I.folder + ' Files (wp-content)</h3>' +
					toggle('uploads', 'Uploads', 'Media library', o.uploads) +
					toggle('plugins', 'Plugins', '', o.plugins) +
					toggle('themes', 'Themes', '', o.themes) +
					toggle('muplugins', 'Must-use plugins', '', o.muplugins) +
					toggle('other', 'Other directories in wp-content', 'Languages, fonts, custom folders…', o.other) +
				'</section>' +
			'</div>' +
			'<div class="pb-actions"><span class="pb-muted">Free disk space: ' + esc(C.diskFree || 'unknown') + '</span><button class="pb-btn pb-btn--primary pb-btn--lg" data-start>' + I.box + 'Create package</button></div>'
		);
		$('[data-back]').addEventListener('click', function () { S.view = 'home'; viewHome(); });
		$$('[data-opt]').forEach(function (cb) {
			cb.addEventListener('change', function () {
				S.exportOpts[cb.dataset.opt] = cb.checked;
				try { localStorage.setItem('pb_export_opts', JSON.stringify(S.exportOpts)); } catch (e) { /* ignore */ }
				if (cb.dataset.opt === 'database') { $('.pb-sub-opts').classList.toggle('is-disabled', !cb.checked); }
			});
		});
		$('[data-start]').addEventListener('click', function () {
			var o2 = S.exportOpts;
			if (!o2.database && !o2.uploads && !o2.plugins && !o2.themes && !o2.muplugins && !o2.other) {
				return toast('Select at least one thing to export.', 'err');
			}
			startExport();
		});
	}

	function progressView(title, stepIdx, stepList, extra) {
		return steps(stepList, stepIdx) +
			'<section class="pb-card pb-progress">' +
				'<div class="pb-progress__head"><div><h3 data-p-title>' + esc(title) + '</h3><p class="pb-muted" data-p-label>Preparing…</p></div><div class="pb-progress__pct" data-p-pct>0%</div></div>' +
				'<div class="pb-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span data-p-bar></span></div>' +
				'<div class="pb-progress__meta"><span>Elapsed: <b data-p-time>0s</b></span><span class="pb-muted">Please keep this tab open.</span></div>' +
				(extra || '') +
				'<div data-p-error></div>' +
				'<details class="pb-log"><summary>Activity log</summary><ul data-p-log></ul></details>' +
				'<div class="pb-actions pb-actions--end"><button class="pb-btn pb-btn--ghost" data-cancel>Cancel</button></div>' +
			'</section>';
	}

	function updateProgress(p) {
		var pct = Math.max(0, Math.min(100, p.percent || 0));
		var bar = $('[data-p-bar]');
		if (!bar) { return; }
		bar.style.width = pct + '%';
		bar.parentNode.setAttribute('aria-valuenow', Math.round(pct));
		$('[data-p-pct]').textContent = (pct >= 10 ? Math.round(pct) : pct.toFixed(1)) + '%';
		if (p.label) { $('[data-p-label]').textContent = p.label; }
		$('[data-p-time]').textContent = fmtTime((Date.now() - S.started) / 1000);
		if (p.log) {
			$('[data-p-log]').innerHTML = p.log.slice().reverse().map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('');
		}
	}

	function startExport() {
		S.running = true; S.cancelFlag = false; S.started = Date.now();
		render(progressView('Creating package', 1, ['Choose content', 'Create package', 'Send or download']));
		bindCancel();
		api('export_start', { opts: S.exportOpts }).then(function (p) {
			S.job = p.id;
			return runJob(p);
		}).catch(failGeneric);
	}

	function bindCancel() {
		var b = $('[data-cancel]');
		if (!b) { return; }
		b.addEventListener('click', function () {
			if (!confirm('Cancel this job? Nothing on this site will be changed.')) { return; }
			S.cancelFlag = true;
			var id = S.job;
			S.running = false;
			(id ? api('cancel', { id: id }) : Promise.resolve()).then(function () { toast('Cancelled'); S.view = 'home'; route(); });
		});
	}

	function runJob(p) {
		updateProgress(p);
		if (S.cancelFlag) { return; }
		if (p.error) { S.running = false; return showImportError(p); }
		if (p.done) { S.running = false; return p.stages ? importDone(p) : exportDone(p); }
		if (p.stages) { updateStepper(p); }
		return sleep(p.busy ? 2500 : 120).then(function () {
			if (S.cancelFlag) { return; }
			return api('step', { id: S.job }).then(runJob);
		});
	}

	function failGeneric(e) {
		S.running = false;
		var box = $('[data-p-error]');
		var html = '<div class="pb-alert pb-alert--err">' + I.alert + '<div><strong>Something went wrong</strong><p>' + esc(e.message) + '</p>' +
			(e.data && e.data.login ? '<p><a class="pb-btn pb-btn--primary" href="' + esc(e.data.login) + '">Log in again</a></p>' : '') +
			(S.job ? '<p><button class="pb-btn pb-btn--primary" data-retry>Retry</button></p>' : '') + '</div></div>';
		if (box) { box.innerHTML = html; } else { render(html); }
		var r = $('[data-retry]');
		if (r) {
			r.addEventListener('click', function () {
				box.innerHTML = '';
				S.running = true;
				api('step', { id: S.job }).then(runJob).catch(failGeneric);
			});
		}
	}

	function exportDone(p) {
		S.job = null;
		refreshBackups();
		render(
			steps(['Choose content', 'Create package', 'Send or download'], 2) +
			'<section class="pb-card pb-done">' +
				'<div class="pb-done__icon">' + I.check + '</div>' +
				'<h2>Your package is ready</h2>' +
				'<p class="pb-file"><code>' + esc(p.file) + '</code> · ' + esc(p.size) + ' · ' + fmtTime((Date.now() - S.started) / 1000) + '</p>' +
				'<div class="pb-choices pb-choices--sm">' +
					'<button class="pb-choice" data-send><span class="pb-choice__icon pb-choice__icon--blue">' + I.send + '</span><span class="pb-choice__title">Send with croc</span><span class="pb-choice__text">Encrypted server-to-server transfer. You get a code phrase to enter on the destination site.</span></button>' +
					'<a class="pb-choice" href="' + esc(C.download + encodeURIComponent(p.file)) + '"><span class="pb-choice__icon pb-choice__icon--green">' + I.down + '</span><span class="pb-choice__title">Download .pbk</span><span class="pb-choice__text">Save the package to your computer, then upload it on the destination site.</span></a>' +
				'</div>' +
			'</section>'
		);
		$('[data-send]').addEventListener('click', function () { viewCrocSend(p.file); });
	}

	/* ---------- croc ---------- */
	function crocReady() {
		return api('croc_info', {}).then(function (info) { S.croc = info; return info; });
	}

	function crocSetupHtml(info, mode, file) {
		var manualCode = 'your-code-phrase';
		var m = mode === 'send'
			? '<p>Linux / macOS:</p>' + cmd('CROC_SECRET="' + manualCode + '" croc send ' + (file || 'backup.pbk')) + '<p>Windows:</p>' + cmd('croc send --code ' + manualCode + ' ' + (file || 'backup.pbk'))
			: '<p>Linux / macOS:</p>' + cmd('CROC_SECRET="' + manualCode + '" croc') + '<p>Windows:</p>' + cmd('croc ' + manualCode);
		if (!info.exec) {
			return '<div class="pb-alert pb-alert--warn">' + I.alert + '<div><strong>Your host doesn\'t allow running programs (proc_open is disabled).</strong>' +
				'<p>You can still use croc from your own computer: ' + (mode === 'send' ? 'download the .pbk and send it' : 'receive the .pbk and then upload it here') + ' with the commands below. Get croc at <a href="https://github.com/schollz/croc" target="_blank" rel="noopener">github.com/schollz/croc</a>.</p>' + m + '</div></div>';
		}
		if (!info.installed) {
			return '<div class="pb-card pb-inline">' + I.key + '<div><strong>croc needs to be installed once</strong><p class="pb-muted">ProBackup downloads the official croc ' + (info.asset ? '(<code>' + esc(info.asset) + '</code>) ' : '') + 'from GitHub and verifies its SHA-256 checksum.</p></div>' +
				'<button class="pb-btn pb-btn--primary" data-install' + (info.asset ? '' : ' disabled') + '>Install croc</button></div>';
		}
		return '';
	}

	function cmd(text) {
		return '<div class="pb-cmd"><code>' + esc(text) + '</code><button class="pb-icon-btn" data-copy="' + esc(text) + '" aria-label="Copy command">' + I.copy + '</button></div>';
	}

	function bindCopy() {
		$$('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { copy(b.dataset.copy); }); });
	}

	function bindInstall(after) {
		var b = $('[data-install]');
		if (!b) { return; }
		b.addEventListener('click', function () {
			b.disabled = true; b.innerHTML = '<span class="pb-spin"></span>Installing…';
			api('croc_install', {}, { retries: 0 }).then(function (info) {
				S.croc = info; toast('croc installed ' + (info.version || ''), 'ok'); after();
			}).catch(function (e) { b.disabled = false; b.textContent = 'Install croc'; toast(e.message, 'err'); });
		});
	}

	function viewCrocSend(file) {
		S.tab = 'migrate';
		render('<button class="pb-back" data-back>' + I.back + 'Back</button><section class="pb-card"><h3>' + I.send + ' Send with croc</h3><p class="pb-muted"><span class="pb-spin"></span>Checking server…</p></section>');
		$('[data-back]').addEventListener('click', function () { S.view = 'home'; route(); });
		crocReady().then(function (info) {
			var setup = crocSetupHtml(info, 'send', file);
			render(
				'<button class="pb-back" data-back>' + I.back + 'Back</button>' +
				'<section class="pb-card pb-croc">' +
					'<h3>' + I.send + ' Send <code>' + esc(file) + '</code></h3>' +
					(setup || (
						'<p class="pb-muted">A code phrase is generated for you. You can also type your own (letters, numbers, dashes; at least 6 characters).</p>' +
						'<div class="pb-field-row"><input type="text" class="pb-input" data-code placeholder="Leave empty to auto-generate" maxlength="64" autocomplete="off" spellcheck="false"><button class="pb-btn pb-btn--primary" data-go>' + I.send + 'Start sending</button></div>'
					)) +
				'</section>'
			);
			$('[data-back]').addEventListener('click', function () { S.view = 'home'; route(); });
			bindCopy();
			bindInstall(function () { viewCrocSend(file); });
			var go = $('[data-go]');
			if (go) {
				go.addEventListener('click', function () {
					var code = $('[data-code]').value.trim();
					if (code && !/^[A-Za-z0-9-]{6,64}$/.test(code)) { return toast('Use letters, numbers and dashes only (6–64 characters).', 'err'); }
					go.disabled = true;
					api('croc_send', { name: file, code: code }, { retries: 0 }).then(function (s) { crocSending(file, s); }).catch(function (e) { go.disabled = false; toast(e.message, 'err'); });
				});
			}
		}).catch(function (e) { toast(e.message, 'err'); });
	}

	function crocSending(file, s) {
		S.running = true; S.started = Date.now();
		render(
			'<section class="pb-card pb-croc">' +
				'<h3>' + I.send + ' Sending <code>' + esc(file) + '</code></h3>' +
				'<p>On the destination site open <strong>ProBackup → Migrate → Import → Receive with croc</strong> and enter this code:</p>' +
				'<div class="pb-code"><span data-codetext>' + esc(s.code) + '</span><button class="pb-btn pb-btn--ghost" data-copy="' + esc(s.code) + '">' + I.copy + 'Copy</button></div>' +
				'<div class="pb-bar"><span data-p-bar></span></div>' +
				'<div class="pb-progress__meta"><span data-c-state class="pb-badge">Waiting for the receiver…</span><span>Elapsed: <b data-p-time>0s</b></span></div>' +
				'<p class="pb-muted" data-c-msg></p>' +
				'<details class="pb-log"><summary>Receive from a computer instead</summary>' + cmd(s.manual.receive_unix) + cmd(s.manual.receive_win) + '</details>' +
				'<div class="pb-actions pb-actions--end"><button class="pb-btn pb-btn--ghost" data-stop>Stop sending</button></div>' +
			'</section>'
		);
		bindCopy();
		$('[data-stop]').addEventListener('click', function () {
			S.running = false;
			api('croc_cancel', { id: s.id }).then(function () { toast('Transfer stopped'); S.tab = 'backups'; route(); });
		});
		pollCroc(s.id, function (r) {
			toast('Transfer complete', 'ok');
			$('[data-c-state]').textContent = 'Sent successfully';
			$('[data-c-state]').className = 'pb-badge pb-badge--ok';
			$('[data-stop]').textContent = 'Close';
		});
	}

	function pollCroc(id, onDone) {
		function tick() {
			if (!S.running) { return; }
			api('croc_poll', { id: id }).then(function (r) {
				var bar = $('[data-p-bar]');
				if (!bar) { return; }
				bar.style.width = r.percent + '%';
				$('[data-p-time]').textContent = fmtTime(r.elapsed);
				var st = $('[data-c-state]');
				if (r.state === 'transferring') { st.textContent = 'Transferring… ' + r.percent + '%'; st.className = 'pb-badge pb-badge--info'; }
				if (r.message && $('[data-c-msg]')) { $('[data-c-msg]').textContent = r.message; }
				if (r.state === 'done') { S.running = false; bar.style.width = '100%'; return onDone(r); }
				if (r.state === 'error') {
					S.running = false;
					st.textContent = 'Transfer failed'; st.className = 'pb-badge pb-badge--err';
					return;
				}
				setTimeout(tick, 2000);
			}).catch(function (e) { setTimeout(tick, 4000); toast(e.message, 'err'); });
		}
		tick();
	}

	/* ---------- import ---------- */
	function viewImportSource(preset) {
		S.tab = 'migrate';
		var src = preset || 'croc';
		var options = S.backups.map(function (b) { return '<option value="' + esc(b.name) + '">' + esc(b.name) + ' — ' + esc(b.human) + ' — ' + esc(b.date) + '</option>'; }).join('');
		render(
			'<button class="pb-back" data-back>' + I.back + 'Back</button>' +
			steps(['Get the package', 'Review', 'Import'], 0) +
			'<div class="pb-segment" role="tablist">' +
				'<button data-src="croc" class="' + (src === 'croc' ? 'is-active' : '') + '">' + I.key + 'Receive with croc</button>' +
				'<button data-src="upload" class="' + (src === 'upload' ? 'is-active' : '') + '">' + I.up + 'Upload file</button>' +
				'<button data-src="existing" class="' + (src === 'existing' ? 'is-active' : '') + '">' + I.box + 'Saved backups</button>' +
			'</div>' +
			'<section class="pb-card" data-panel="croc"' + (src === 'croc' ? '' : ' hidden') + '>' +
				'<h3>Enter the code phrase from the source site</h3>' +
				'<div data-croc-setup></div>' +
				'<div class="pb-field-row" data-croc-form><input type="text" class="pb-input pb-input--code" data-code placeholder="e.g. 4821-amber-tiger-ocean-maple" maxlength="64" autocomplete="off" spellcheck="false"><button class="pb-btn pb-btn--primary" data-receive>' + I.down + 'Receive</button></div>' +
			'</section>' +
			'<section class="pb-card" data-panel="upload"' + (src === 'upload' ? '' : ' hidden') + '>' +
				'<label class="pb-drop" data-drop tabindex="0"><input type="file" accept=".pbk" hidden data-file>' + I.up +
					'<strong>Drop your .pbk file here</strong><span class="pb-muted">or click to browse · any size, uploaded in ' + fmtBytes(C.chunk) + ' pieces, resumable</span></label>' +
			'</section>' +
			'<section class="pb-card" data-panel="existing"' + (src === 'existing' ? '' : ' hidden') + '>' +
				(S.backups.length ? '<div class="pb-field-row"><select class="pb-input" data-existing>' + options + '</select><button class="pb-btn pb-btn--primary" data-use>Continue ' + I.arrow + '</button></div>' : '<p class="pb-muted">No backups on this server yet.</p>') +
			'</section>'
		);
		$('[data-back]').addEventListener('click', function () { S.view = 'home'; viewHome(); });
		$$('[data-src]').forEach(function (b) { b.addEventListener('click', function () { viewImportSource(b.dataset.src); }); });

		if (src === 'croc') {
			crocReady().then(function (info) {
				var setup = crocSetupHtml(info, 'receive');
				var box = $('[data-croc-setup]');
				if (!box) { return; }
				box.innerHTML = setup;
				if (setup) { $('[data-croc-form]').hidden = true; }
				bindCopy();
				bindInstall(function () { viewImportSource('croc'); });
			});
			$('[data-receive]').addEventListener('click', function () {
				var code = $('[data-code]').value.trim();
				if (!/^[A-Za-z0-9-]{6,64}$/.test(code)) { return toast('Please enter the code phrase shown on the source site.', 'err'); }
				api('croc_receive', { code: code }, { retries: 0 }).then(function (s) { crocReceiving(s.id); }).catch(function (e) { toast(e.message, 'err'); });
			});
		}
		if (src === 'upload') { bindUpload(); }
		if (src === 'existing' && S.backups.length) {
			$('[data-use]').addEventListener('click', function () { viewConfirm($('[data-existing]').value); });
		}
	}

	function crocReceiving(id) {
		S.running = true; S.started = Date.now();
		render(
			steps(['Get the package', 'Review', 'Import'], 0) +
			'<section class="pb-card pb-croc"><h3>' + I.down + ' Receiving with croc</h3>' +
				'<div class="pb-bar"><span data-p-bar></span></div>' +
				'<div class="pb-progress__meta"><span data-c-state class="pb-badge">Connecting to sender…</span><span>Elapsed: <b data-p-time>0s</b></span></div>' +
				'<p class="pb-muted" data-c-msg></p>' +
				'<div class="pb-actions pb-actions--end"><button class="pb-btn pb-btn--ghost" data-stop>Cancel</button></div>' +
			'</section>'
		);
		$('[data-stop]').addEventListener('click', function () {
			S.running = false;
			api('croc_cancel', { id: id }).then(function () { toast('Cancelled'); viewImportSource('croc'); });
		});
		pollCroc(id, function (r) {
			toast('Package received', 'ok');
			refreshBackups();
			viewConfirm(r.file);
		});
	}

	function bindUpload() {
		var drop = $('[data-drop]'), input = $('[data-file]');
		drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
		['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
		['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
		drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) { upload(e.dataTransfer.files[0]); } });
		input.addEventListener('change', function () { if (input.files[0]) { upload(input.files[0]); } });
	}

	function safeName(n) {
		n = n.replace(/[^A-Za-z0-9._-]/g, '-').replace(/^[^A-Za-z0-9]+/, '');
		if (!/\.pbk$/i.test(n)) { n += '.pbk'; }
		return n.replace(/\.PBK$/, '.pbk').slice(-180);
	}

	function upload(file) {
		if (!/\.pbk$/i.test(file.name)) { return toast('Please choose a .pbk file created by ProBackup.', 'err'); }
		var name = safeName(file.name);
		S.running = true; S.started = Date.now();
		render(
			steps(['Get the package', 'Review', 'Import'], 0) +
			'<section class="pb-card"><h3>' + I.up + ' Uploading <code>' + esc(file.name) + '</code></h3>' +
				'<div class="pb-bar"><span data-p-bar></span></div>' +
				'<div class="pb-progress__meta"><span data-u-text>Starting…</span><span>Elapsed: <b data-p-time>0s</b></span></div>' +
				'<div class="pb-actions pb-actions--end"><button class="pb-btn pb-btn--ghost" data-stop>Cancel</button></div>' +
			'</section>'
		);
		var stop = false;
		$('[data-stop]').addEventListener('click', function () { stop = true; S.running = false; viewImportSource('upload'); });
		var size = file.size;
		api('upload_status', { name: name }).then(function (st) {
			var offset = st.received > size ? 0 : st.received;
			function next() {
				if (stop) { return; }
				var bar = $('[data-p-bar]');
				if (bar) {
					bar.style.width = (offset / size * 100) + '%';
					var secs = (Date.now() - S.started) / 1000;
					$('[data-u-text]').textContent = fmtBytes(offset) + ' of ' + fmtBytes(size) + (secs > 2 ? ' · ' + fmtBytes(offset / secs) + '/s' : '');
					$('[data-p-time]').textContent = fmtTime(secs);
				}
				var blob = file.slice(offset, Math.min(size, offset + C.chunk));
				return api('upload_chunk', { name: name, offset: offset, total: size, chunk: blob }, { retries: 5 }).then(function (r) {
					offset = r.received;
					if (r.complete) {
						S.running = false;
						toast('Upload complete', 'ok');
						refreshBackups();
						return viewConfirm(r.name);
					}
					return next();
				});
			}
			return next();
		}).catch(function (e) { S.running = false; toast(e.message, 'err'); viewImportSource('upload'); });
	}

	function viewConfirm(name) {
		S.tab = 'migrate';
		render(steps(['Get the package', 'Review', 'Import'], 1) + '<section class="pb-card"><p class="pb-muted"><span class="pb-spin"></span>Checking package…</p></section>');
		api('import_inspect', { name: name }).then(function (m) {
			if (m.multisite) { throw new Error('This package comes from a multisite network, which is not supported yet.'); }
			render(
				'<button class="pb-back" data-back>' + I.back + 'Back</button>' +
				steps(['Get the package', 'Review', 'Import'], 1) +
				'<section class="pb-card">' +
					'<h3>Review the migration</h3>' +
					'<div class="pb-route"><div><small>From</small><strong>' + esc(m.source) + '</strong></div><span>' + I.arrow + '</span><div><small>To (this site)</small><strong>' + esc(m.target) + '</strong></div></div>' +
					'<dl class="pb-stats">' +
						'<div><dt>Package</dt><dd>' + esc(m.size) + '</dd></div>' +
						'<div><dt>Tables</dt><dd>' + (m.database ? m.tables : '—') + '</dd></div>' +
						'<div><dt>Files</dt><dd>' + Number(m.files).toLocaleString() + '</dd></div>' +
						'<div><dt>WordPress</dt><dd>' + esc(m.wp_version) + '</dd></div>' +
						'<div><dt>PHP</dt><dd>' + esc(m.php) + '</dd></div>' +
						'<div><dt>Created</dt><dd>' + esc((m.created || '').replace('T', ' ').slice(0, 16)) + '</dd></div>' +
					'</dl>' +
					'<details class="pb-log"><summary>Advanced: extra find &amp; replace</summary><p class="pb-muted">URLs and server paths are replaced automatically (serialization-safe). Add more pairs here, one per line: <code>old text =&gt; new text</code></p><textarea class="pb-input" rows="3" data-extra placeholder="old@email.com => new@email.com"></textarea></details>' +
					'<div class="pb-alert pb-alert--warn">' + I.alert + '<div><strong>This will replace this site\'s database and wp-content files.</strong><p>Your current site keeps working until the very last step. After the import you will log in with the <b>source site\'s</b> username and password.</p></div></div>' +
					'<label class="pb-check"><input type="checkbox" data-agree> I understand, replace this site</label>' +
					'<div class="pb-actions pb-actions--end"><button class="pb-btn pb-btn--danger pb-btn--lg" data-import disabled>' + I.down + 'Start import</button></div>' +
				'</section>'
			);
			$('[data-back]').addEventListener('click', function () { viewImportSource('existing'); });
			$('[data-agree]').addEventListener('change', function (e) { $('[data-import]').disabled = !e.target.checked; });
			$('[data-import]').addEventListener('click', function () {
				var extra = $('[data-extra]').value.split('\n').map(function (l) {
					var i = l.indexOf('=>'); return i > 0 ? [l.slice(0, i).trim(), l.slice(i + 2).trim()] : null;
				}).filter(Boolean);
				startImport(name, extra);
			});
		}).catch(function (e) {
			render('<button class="pb-back" data-back>' + I.back + 'Back</button><div class="pb-alert pb-alert--err">' + I.alert + '<div><strong>This package can\'t be imported</strong><p>' + esc(e.message) + '</p></div></div>');
			$('[data-back]').addEventListener('click', function () { viewImportSource('existing'); });
		});
	}

	var IMPORT_STAGES = [
		['db', 'Import database', 'Into temporary tables — your site stays online'],
		['replace', 'Replace URLs & paths', 'Serialization-safe'],
		['files', 'Restore files', 'Media, plugins and themes'],
		['swap', 'Activate new site', 'Atomic table swap']
	];

	function stepperHtml() {
		return '<ol class="pb-vstep" data-vstep>' + IMPORT_STAGES.map(function (s) {
			return '<li data-st="' + s[0] + '"><span class="pb-vstep__dot"></span><div><strong>' + s[1] + '</strong><small>' + s[2] + '</small></div></li>';
		}).join('') + '</ol>';
	}

	function updateStepper(p) {
		var order = ['db', 'replace', 'files', 'swap', 'done'];
		var cur = order.indexOf(p.stage);
		$$('[data-st]').forEach(function (li) {
			var i = order.indexOf(li.dataset.st);
			li.className = i < cur ? 'is-done' : (i === cur ? 'is-current' : '');
			if (p.error && i === cur) { li.className = 'is-error'; }
		});
		var t = $('[data-p-title]');
		var stg = IMPORT_STAGES.filter(function (s) { return s[0] === p.stage; })[0];
		if (t && stg) { t.textContent = stg[1]; }
	}

	function startImport(name, extra) {
		S.running = true; S.cancelFlag = false; S.started = Date.now();
		render(progressView('Starting import', 2, ['Get the package', 'Review', 'Import'], stepperHtml()));
		bindCancel();
		api('import_start', { name: name, extra: extra }).then(function (p) {
			S.job = p.id;
			updateStepper(p);
			return runJob(p);
		}).catch(failGeneric);
	}

	function showImportError(p) {
		updateStepper(p);
		var e = p.error || {};
		var isDb = e.stage === 'db';
		$('[data-p-error]').innerHTML =
			'<div class="pb-alert pb-alert--err">' + I.alert + '<div><strong>' + (isDb ? 'Database statement #' + esc(e.statement) + ' failed' : 'The import stopped') + '</strong>' +
			'<p>' + esc(e.message) + '</p>' +
			(e.sql ? '<details><summary>Show SQL</summary><pre>' + esc(e.sql) + '</pre></details>' : '') +
			'<p class="pb-muted">Nothing on your live site has changed yet' + (e.stage === 'files' ? ' (except files already restored)' : '') + '.</p>' +
			'<div class="pb-btn-row"><button class="pb-btn pb-btn--primary" data-res="retry">Retry</button>' +
			(isDb ? '<button class="pb-btn" data-res="skip">Skip this statement &amp; continue</button>' : '') +
			'<button class="pb-btn pb-btn--ghost" data-res="cancel">Cancel &amp; roll back</button></div></div></div>';
		$$('[data-res]').forEach(function (b) {
			b.addEventListener('click', function () {
				var d = b.dataset.res;
				if (d === 'cancel') {
					return api('cancel', { id: S.job }).then(function () { S.job = null; toast('Import cancelled — temporary tables removed.'); S.view = 'home'; route(); });
				}
				$('[data-p-error]').innerHTML = '';
				S.running = true;
				api('import_resolve', { id: S.job, decision: d }).then(function () { return api('step', { id: S.job }); }).then(runJob).catch(failGeneric);
			});
		});
	}

	function importDone(p) {
		S.job = null;
		updateStepper(p);
		render(
			steps(['Get the package', 'Review', 'Import'], 3) +
			'<section class="pb-card pb-done">' +
				'<div class="pb-done__icon">' + I.check + '</div>' +
				'<h2>Migration complete!</h2>' +
				'<p>' + esc(p.source) + ' is now running at <strong>' + esc(C.home) + '</strong>.</p>' +
				'<div class="pb-alert pb-alert--info">' + I.key + '<div><strong>Log in with the source site\'s credentials.</strong><p>Your user accounts were imported together with the database. Then visit Settings → Permalinks and click Save once.</p></div></div>' +
				'<p><a class="pb-btn pb-btn--primary pb-btn--lg" href="' + esc(p.login) + '">Log in ' + I.arrow + '</a></p>' +
			'</section>'
		);
	}

	/* ---------- backups ---------- */
	function refreshBackups() {
		return api('backups', {}).then(function (r) {
			S.backups = r.backups;
			var tab = $('.pb-tab[data-tab="backups"]');
			if (tab) { tab.innerHTML = 'Backups' + (S.backups.length ? ' <span class="pb-count">' + S.backups.length + '</span>' : ''); }
			return r.backups;
		}).catch(function () { return S.backups; });
	}

	function viewBackups() {
		var list = S.backups;
		render(
			'<section class="pb-card">' +
				'<div class="pb-card__head"><h3>' + I.box + ' Saved backups</h3><button class="pb-btn pb-btn--primary" data-new>' + I.up + 'New export</button></div>' +
				(list.length ? '<ul class="pb-list">' + list.map(function (b) {
					return '<li><div class="pb-list__main"><strong>' + esc(b.name) + '</strong><span class="pb-muted">' + esc(b.date) + ' · ' + esc(b.human) + '</span></div>' +
						'<div class="pb-list__actions">' +
							'<a class="pb-btn pb-btn--sm" href="' + esc(C.download + encodeURIComponent(b.name)) + '">' + I.down + 'Download</a>' +
							'<button class="pb-btn pb-btn--sm" data-send="' + esc(b.name) + '">' + I.send + 'Send</button>' +
							'<button class="pb-btn pb-btn--sm" data-restore="' + esc(b.name) + '">' + I.restore + 'Restore</button>' +
							'<button class="pb-icon-btn pb-icon-btn--danger" data-del="' + esc(b.name) + '" aria-label="Delete ' + esc(b.name) + '">' + I.trash + '</button>' +
						'</div></li>';
				}).join('') + '</ul>' : '<div class="pb-empty">' + I.box + '<p>No backups yet. Create your first export to see it here.</p></div>') +
			'</section>'
		);
		$('[data-new]').addEventListener('click', function () { S.tab = 'migrate'; viewExportOptions(); });
		$$('[data-send]').forEach(function (b) { b.addEventListener('click', function () { viewCrocSend(b.dataset.send); }); });
		$$('[data-restore]').forEach(function (b) { b.addEventListener('click', function () { viewConfirm(b.dataset.restore); }); });
		$$('[data-del]').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!confirm('Delete ' + b.dataset.del + '? This cannot be undone.')) { return; }
				api('delete_backup', { name: b.dataset.del }).then(function (r) { S.backups = r.backups; toast('Backup deleted'); viewBackups(); }).catch(function (e) { toast(e.message, 'err'); });
			});
		});
	}

	/* ---------- settings ---------- */
	function viewSettings() {
		var s = S.settings;
		render(
			'<div class="pb-grid2">' +
			'<section class="pb-card"><h3>Performance</h3>' +
				'<label class="pb-field"><span>Time per step (seconds)</span><input type="number" min="3" max="60" class="pb-input" data-s="time_budget" value="' + esc(s.time_budget) + '"><small>Each request works this long, then continues in the next. Lower it if your host kills requests early.</small></label>' +
				'<label class="pb-field"><span>Keep last N backups</span><input type="number" min="0" max="100" class="pb-input" data-s="keep_backups" value="' + esc(s.keep_backups) + '"><small>0 keeps all.</small></label>' +
				'<label class="pb-check"><input type="checkbox" data-s="delete_on_remove"' + (Number(s.delete_on_remove) ? ' checked' : '') + '> Delete all backups when the plugin is uninstalled</label>' +
			'</section>' +
			'<section class="pb-card"><h3>' + I.key + ' croc transfer</h3><div data-croc-status class="pb-muted"><span class="pb-spin"></span>Checking…</div>' +
				'<label class="pb-field"><span>Custom croc binary path</span><input type="text" class="pb-input" data-s="croc_path" value="' + esc(s.croc_path) + '" placeholder="/usr/local/bin/croc"><small>Optional. Leave empty to use the auto-installed binary.</small></label>' +
				'<label class="pb-field"><span>Custom relay</span><input type="text" class="pb-input" data-s="croc_relay" value="' + esc(s.croc_relay) + '" placeholder="relay.example.com:9009"><small>Optional. Leave empty for the public croc relays. Both sites must use the same relay.</small></label>' +
				'<label class="pb-field"><span>Relay password</span><input type="password" class="pb-input" data-s="croc_pass" value="' + esc(s.croc_pass) + '" autocomplete="new-password"></label>' +
			'</section></div>' +
			'<div class="pb-actions pb-actions--end"><button class="pb-btn pb-btn--primary" data-save>Save settings</button></div>'
		);
		crocReady().then(function (info) {
			var box = $('[data-croc-status]');
			if (!box) { return; }
			box.innerHTML = !info.exec
				? '<span class="pb-badge pb-badge--err">proc_open disabled</span> croc can\'t run on this host — use the upload/download option or croc on your PC.'
				: (info.installed ? '<span class="pb-badge pb-badge--ok">Installed ' + esc(info.version) + '</span>' : '<span class="pb-badge">Not installed</span> <button class="pb-btn pb-btn--sm" data-install>Install croc</button>');
			bindInstall(viewSettings);
		});
		$('[data-save]').addEventListener('click', function () {
			var out = {};
			$$('[data-s]').forEach(function (i) { out[i.dataset.s] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; });
			api('save_settings', { settings: out }).then(function (r) { S.settings = r.settings; toast('Settings saved', 'ok'); viewSettings(); }).catch(function (e) { toast(e.message, 'err'); });
		});
	}

	/* ---------- resume unfinished job ---------- */
	function resumeBanner() {
		var r = C.resume;
		render(
			'<div class="pb-alert pb-alert--info">' + I.restore + '<div><strong>An unfinished ' + esc(r.type) + ' was found.</strong><p>It can continue exactly where it stopped.</p>' +
			'<div class="pb-btn-row"><button class="pb-btn pb-btn--primary" data-resume>Continue ' + esc(r.type) + '</button><button class="pb-btn pb-btn--ghost" data-discard>Discard it</button></div></div></div>'
		);
		$('[data-resume]').addEventListener('click', function () {
			S.job = r.id; S.running = true; S.cancelFlag = false; S.started = Date.now();
			if (r.type === 'import') {
				render(progressView('Resuming import', 2, ['Get the package', 'Review', 'Import'], stepperHtml()));
			} else {
				render(progressView('Resuming export', 1, ['Choose content', 'Create package', 'Send or download']));
			}
			bindCancel();
			api('step', { id: r.id }).then(runJob).catch(failGeneric);
		});
		$('[data-discard]').addEventListener('click', function () {
			api('cancel', { id: r.id }).then(function () { C.resume = null; route(); });
		});
	}

	if (C.resume) { resumeBanner(); } else { route(); }
})();
