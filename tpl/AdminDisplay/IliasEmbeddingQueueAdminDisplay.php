<div class="base3ilias-queue">
	<h3>ILIAS Embedding Queue – Überblick</h3>

	<div class="queue-meta">
		<div><strong>Queue:</strong> <span class="mono">base3_embedding_job</span> / <span class="mono">base3_embedding_seen</span></div>
		<div><strong>Letztes Update:</strong> <span id="q-lastupdate" class="mono">–</span></div>
	</div>

	<div class="queue-actions">
		<button type="button" onclick="qRefresh()">Jetzt aktualisieren</button>

		<label class="q-autorefresh">
			<input type="checkbox" id="q-autorefresh" checked onchange="qToggleAutoRefresh()">
			Auto-Refresh (3s)
		</label>

		<label id="q-loading">Bitte warten…</label>
	</div>

	<div class="queue-grid" id="q-grid" style="display:none">
		<div class="queue-card" id="q-card-backlog">
			<div class="queue-card-head">
				<div class="queue-title">Backlog</div>
				<div class="queue-badge" id="q-badge-backlog">–</div>
			</div>
			<div class="queue-kpis" id="q-kpis-backlog">–</div>
			<div class="queue-foot" id="q-foot-backlog">–</div>
		</div>

		<div class="queue-card" id="q-card-running">
			<div class="queue-card-head">
				<div class="queue-title">Running & Locks</div>
				<div class="queue-badge" id="q-badge-running">–</div>
			</div>
			<div class="queue-kpis" id="q-kpis-running">–</div>
			<div class="queue-foot" id="q-foot-running">–</div>
		</div>

		<div class="queue-card" id="q-card-throughput">
			<div class="queue-card-head">
				<div class="queue-title">Durchsatz</div>
				<div class="queue-badge" id="q-badge-throughput">–</div>
			</div>
			<div class="queue-kpis" id="q-kpis-throughput">–</div>
			<div class="queue-foot" id="q-foot-throughput">–</div>
		</div>

		<div class="queue-card" id="q-card-errors">
			<div class="queue-card-head">
				<div class="queue-title">Fehler & Retries</div>
				<div class="queue-badge" id="q-badge-errors">–</div>
			</div>
			<div class="queue-kpis" id="q-kpis-errors">–</div>
			<div class="queue-foot" id="q-foot-errors">–</div>
		</div>
	</div>

	<div class="queue-section" id="q-recent" style="display:none">
		<div class="queue-section-head">
			<div class="queue-section-title">Letzte 10 Jobs</div>
			<div class="queue-section-hint">Neueste zuerst (updated_at DESC)</div>
		</div>

		<div class="queue-tablewrap">
			<table class="queue-table">
				<thead>
					<tr>
						<th>job_id</th>
						<th>state</th>
						<th>type</th>
						<th>attempts</th>
						<th>priority</th>
						<th>source</th>
						<th>updated_at</th>
						<th>error</th>
					</tr>
				</thead>
				<tbody id="q-recent-body">
					<tr><td colspan="8" class="queue-muted">–</td></tr>
				</tbody>
			</table>
		</div>
	</div>
</div>

<style>
.base3ilias-queue {
	background: #ffffff;
	border: 1px solid #d6d6d6;
	padding: 16px;
	border-radius: 4px;
	max-width: 100%;
	font-family: Arial, sans-serif;
	color: #333;
}

.base3ilias-queue h3 {
	margin-top: 0;
	margin-bottom: 12px;
	font-size: 1.1em;
}

.queue-meta {
	margin-bottom: 12px;
	font-size: 13px;
	color: #555;
	display: flex;
	gap: 18px;
	flex-wrap: wrap;
}

.mono {
	font-family: Consolas, monospace;
}

.queue-actions {
	display: flex;
	gap: 10px;
	align-items: center;
	margin-bottom: 15px;
	flex-wrap: wrap;
}

.queue-actions button {
	padding: 8px 16px;
	border: 1px solid #ccc;
	background: #f0f0f0;
	color: #333;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
	transition: background 0.2s, border-color 0.2s;
}

.queue-actions button:hover {
	background: #e6e6e6;
	border-color: #bbb;
}

.q-autorefresh {
	font-size: 13px;
	color: #555;
	display: flex;
	align-items: center;
	gap: 6px;
	user-select: none;
}

#q-loading {
	display: none;
	color: #666;
	display: flex;
	align-items: center;
	font-style: italic;
	font-size: 13px;
	gap: 6px;
	user-select: none;
}

.queue-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
	margin-bottom: 12px;
}

@media (max-width: 900px) {
	.queue-grid {
		grid-template-columns: 1fr;
	}
}

.queue-card {
	border: 1px solid #ddd;
	border-radius: 6px;
	background: #fff;
	padding: 12px;
	min-height: 150px;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.queue-card-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
}

.queue-title {
	font-weight: bold;
	font-size: 14px;
}

.queue-badge {
	font-size: 12px;
	padding: 3px 8px;
	border-radius: 999px;
	border: 1px solid #ccc;
	background: #f6f6f6;
	color: #333;
	white-space: nowrap;
}

.queue-badge.ok {
	border-color: #8d8;
	background: #f6fff6;
	color: #2d6a2d;
}

.queue-badge.warn {
	border-color: #e3c07a;
	background: #fffaf0;
	color: #8a5a00;
}

.queue-badge.err {
	border-color: #d88;
	background: #fff5f5;
	color: #a33;
}

.queue-kpis {
	font-family: Consolas, monospace;
	font-size: 13px;
	white-space: pre-wrap;
	line-height: 1.35;
	color: #222;
}

.queue-foot {
	font-size: 12px;
	color: #666;
	margin-top: auto;
}

.queue-section {
	border: 1px solid #ddd;
	border-radius: 6px;
	background: #fff;
	padding: 12px;
}

.queue-section-head {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 10px;
	margin-bottom: 10px;
	flex-wrap: wrap;
}

.queue-section-title {
	font-weight: bold;
	font-size: 14px;
}

.queue-section-hint {
	font-size: 12px;
	color: #666;
}

.queue-tablewrap {
	overflow-x: auto;
	-webkit-overflow-scrolling: touch;
}

.queue-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.queue-table th,
.queue-table td {
	border-top: 1px solid #eee;
	padding: 8px 10px;
	vertical-align: top;
	text-align: left;
}

.queue-table thead th {
	border-top: 0;
	border-bottom: 1px solid #ddd;
	font-weight: bold;
	white-space: nowrap;
}

.queue-muted {
	color: #777;
	font-style: italic;
}

.queue-pill {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 999px;
	border: 1px solid #ccc;
	background: #f6f6f6;
	font-size: 12px;
	white-space: nowrap;
}

.queue-pill.ok {
	border-color: #8d8;
	background: #f6fff6;
	color: #2d6a2d;
}

.queue-pill.warn {
	border-color: #e3c07a;
	background: #fffaf0;
	color: #8a5a00;
}

.queue-pill.err {
	border-color: #d88;
	background: #fff5f5;
	color: #a33;
}

.queue-cell-mono {
	font-family: Consolas, monospace;
}

.queue-cell-wrap {
	white-space: normal;
	word-break: break-word;
}

.queue-cell-clip {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 100%;
}
</style>

<script>
	const Q_ENDPOINT = <?php echo json_encode((string)$this->_['endpoint']); ?>;

	let qTimer = null;

	function qSetLoading(state) {
		document.getElementById("q-loading").style.display = state ? "flex" : "none";
	}

	function qSetBadge(id, state, label) {
		const el = document.getElementById(id);
		el.className = "queue-badge";
		if (state === "ok") el.classList.add("ok");
		if (state === "warn") el.classList.add("warn");
		if (state === "err") el.classList.add("err");
		el.textContent = label;
	}

	function qFmt(n) {
		if (n === null || typeof n === "undefined") return "–";
		return String(n);
	}

	function qEsc(s) {
		const div = document.createElement("div");
		div.textContent = String(s ?? "");
		return div.innerHTML;
	}

	function qPill(state, label) {
		let cls = "queue-pill";
		if (state === "ok") cls += " ok";
		if (state === "warn") cls += " warn";
		if (state === "err") cls += " err";
		return '<span class="' + cls + '">' + qEsc(label) + '</span>';
	}

	function qRenderRecent(rows) {
		const body = document.getElementById("q-recent-body");
		const wrap = document.getElementById("q-recent");

		if (!rows || rows.length === 0) {
			body.innerHTML = '<tr><td colspan="8" class="queue-muted">Keine Jobs gefunden.</td></tr>';
			wrap.style.display = "block";
			return;
		}

		let html = "";
		for (const r of rows) {
			const state = String(r.state || "");
			const pillState =
				state === "done" ? "ok" :
				(state === "pending" || state === "running" || state === "superseded") ? "warn" :
				(state === "error") ? "err" : "warn";

			const source = (r.source_kind || "–") + "  " + (r.source_locator || "–");
			const err = r.error_message ? String(r.error_message) : "";

			html += "<tr>" +
				'<td class="queue-cell-mono">' + qEsc(r.job_id) + "</td>" +
				"<td>" + qPill(pillState, state) + "</td>" +
				'<td class="queue-cell-mono">' + qEsc(r.job_type || "–") + "</td>" +
				'<td class="queue-cell-mono">' + qEsc(r.attempts ?? "–") + "</td>" +
				'<td class="queue-cell-mono">' + qEsc(r.priority ?? "–") + "</td>" +
				'<td class="queue-cell-wrap">' + qEsc(source) + "</td>" +
				'<td class="queue-cell-mono queue-cell-clip" title="' + qEsc(r.updated_at || "") + '">' + qEsc(r.updated_at || "–") + "</td>" +
				'<td class="queue-cell-wrap">' + qEsc(err) + "</td>" +
			"</tr>";
		}

		body.innerHTML = html;
		wrap.style.display = "block";
	}

	function qRender(stats) {
		document.getElementById("q-grid").style.display = "grid";
		document.getElementById("q-lastupdate").textContent = stats.timestamp || "–";

		// Card 1: Backlog
		qSetBadge("q-badge-backlog", stats.badges.backlog.state, stats.badges.backlog.label);
		document.getElementById("q-kpis-backlog").textContent =
			"pending: " + qFmt(stats.jobs.pending_total) + "\n" +
			"  upsert: " + qFmt(stats.jobs.pending_upsert) + "  delete: " + qFmt(stats.jobs.pending_delete) + "\n" +
			"oldest pending: " + qFmt(stats.jobs.oldest_pending_age) + "\n" +
			"high prio pending: " + qFmt(stats.jobs.pending_high_prio);
		document.getElementById("q-foot-backlog").textContent =
			"seen missing_since: " + qFmt(stats.seen.missing_total) +
			"  (delete jobs linked: " + qFmt(stats.seen.missing_with_delete_job) + ")";

		// Card 2: Running & Locks
		qSetBadge("q-badge-running", stats.badges.running.state, stats.badges.running.label);
		document.getElementById("q-kpis-running").textContent =
			"running: " + qFmt(stats.jobs.running_total) + "\n" +
			"locked (active): " + qFmt(stats.jobs.locked_active) + "\n" +
			"stuck locks (expired): " + qFmt(stats.jobs.locked_expired) + "\n" +
			"oldest running: " + qFmt(stats.jobs.oldest_running_age);
		document.getElementById("q-foot-running").textContent =
			"claimed last 15m: " + qFmt(stats.jobs.claimed_15m) +
			"  avg attempts (running): " + qFmt(stats.jobs.avg_attempts_running);

		// Card 3: Durchsatz
		qSetBadge("q-badge-throughput", stats.badges.throughput.state, stats.badges.throughput.label);
		document.getElementById("q-kpis-throughput").textContent =
			"done 15m: " + qFmt(stats.jobs.done_15m) + "\n" +
			"done 24h: " + qFmt(stats.jobs.done_24h) + "\n" +
			"superseded 24h: " + qFmt(stats.jobs.superseded_24h) + "\n" +
			"created 15m: " + qFmt(stats.jobs.created_15m);
		document.getElementById("q-foot-throughput").textContent =
			"Signal: created vs done (15m) zeigt, ob Backlog wächst oder schrumpft.";

		// Card 4: Errors & Retries
		qSetBadge("q-badge-errors", stats.badges.errors.state, stats.badges.errors.label);
		document.getElementById("q-kpis-errors").textContent =
			"error total: " + qFmt(stats.jobs.error_total) + "\n" +
			"error 24h: " + qFmt(stats.jobs.error_24h) + "\n" +
			"retry pending (attempts>0): " + qFmt(stats.jobs.pending_retries) + "\n" +
			"max attempts (errors): " + qFmt(stats.jobs.error_max_attempts);
		document.getElementById("q-foot-errors").textContent =
			"last error: " + qFmt(stats.jobs.last_error_at) +
			(stats.jobs.last_error_message ? "  |  " + stats.jobs.last_error_message : "");

		// Recent
		qRenderRecent(stats.recent_jobs || []);
	}

	async function qRefresh() {
		qSetLoading(true);

		try {
			const response = await fetch(Q_ENDPOINT + encodeURIComponent("stats"), {
				method: "GET",
				headers: { "Accept": "application/json" }
			});

			const text = await response.text();
			let json;

			try {
				json = JSON.parse(text);
			} catch (e) {
				qSetLoading(false);
				return;
			}

			if (json.status !== "ok") {
				qSetLoading(false);
				return;
			}

			qRender(json.data);

		} catch (err) {
			// silent: user wanted less technical output
		}

		qSetLoading(false);
	}

	function qToggleAutoRefresh() {
		const enabled = document.getElementById("q-autorefresh").checked;

		if (qTimer) {
			clearInterval(qTimer);
			qTimer = null;
		}

		if (enabled) {
			qTimer = setInterval(qRefresh, 3000);
		}
	}

	// init
	qToggleAutoRefresh();
	qRefresh();
</script>

