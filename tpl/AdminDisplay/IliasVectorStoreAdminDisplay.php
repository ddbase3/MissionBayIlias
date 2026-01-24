<div class="base3ilias-services">
	<h3>ILIAS VectorStore Administration</h3>

	<div class="vs-meta">
		<div><strong>Resource:</strong> <span class="mono"><?php echo htmlspecialchars((string)$this->_['resourceName'], ENT_QUOTES); ?></span></div>
		<div><strong>CollectionKey:</strong> <span class="mono"><?php echo htmlspecialchars((string)$this->_['collectionKey'], ENT_QUOTES); ?></span></div>
		<div><strong>Last update:</strong> <span id="vs-lastupdate" class="mono">–</span></div>
	</div>

	<div class="vs-buttons" id="vs-buttons">
		<button type="button" data-vs-action="create" onclick="vsAction('create')">Create collection</button>
		<button type="button" data-vs-action="delete" onclick="if (confirm('Really delete the collection?')) vsAction('delete');">Delete collection</button>
		<button type="button" data-vs-action="info" onclick="vsAction('info')">Fetch info</button>
		<button type="button" data-vs-action="stats" onclick="vsAction('stats')">Show status</button>

		<label id="vs-loading">Please wait…</label>
	</div>

	<div class="vs-grid" id="vs-grid" style="display:none">
		<div class="vs-card">
			<div class="vs-card-head">
				<div class="vs-title">Health</div>
				<div class="vs-badge" id="vs-badge-health">–</div>
			</div>
			<div class="vs-kpis" id="vs-kpis-health">–</div>
			<div class="vs-foot" id="vs-foot-health">–</div>
		</div>

		<div class="vs-card">
			<div class="vs-card-head">
				<div class="vs-title">Data</div>
				<div class="vs-badge" id="vs-badge-size">–</div>
			</div>
			<div class="vs-kpis" id="vs-kpis-size">–</div>
			<div class="vs-foot" id="vs-foot-size">–</div>
		</div>

		<div class="vs-card">
			<div class="vs-card-head">
				<div class="vs-title">Schema & Payload</div>
				<div class="vs-badge" id="vs-badge-schema">–</div>
			</div>
			<div class="vs-kpis" id="vs-kpis-schema">–</div>
			<div class="vs-foot" id="vs-foot-schema">–</div>
		</div>

		<div class="vs-card">
			<div class="vs-card-head">
				<div class="vs-title">Index & Config</div>
				<div class="vs-badge" id="vs-badge-config">–</div>
			</div>
			<div class="vs-kpis" id="vs-kpis-config">–</div>
			<div class="vs-foot" id="vs-foot-config">–</div>
		</div>
	</div>

	<div id="vs-output" style="display:none">Ready.</div>
</div>

<style>
.base3ilias-services {
	background: #ffffff;
	border: 1px solid #d6d6d6;
	padding: 16px;
	border-radius: 4px;
	max-width: 100%;
	font-family: Arial, sans-serif;
	color: #333;
}

.base3ilias-services h3 {
	margin-top: 0;
	margin-bottom: 12px;
	font-size: 1.1em;
}

.vs-meta {
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

.vs-buttons {
	display: flex;
	gap: 10px;
	margin-bottom: 15px;
	align-items: center;
	flex-wrap: wrap;
}

.vs-buttons button {
	padding: 8px 16px;
	border: 1px solid #ccc;
	background: #f0f0f0;
	color: #333;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
	transition: background 0.2s, border-color 0.2s, opacity 0.2s;
}

.vs-buttons button:hover {
	background: #e6e6e6;
	border-color: #bbb;
}

.vs-buttons button:disabled {
	opacity: 0.55;
	cursor: not-allowed;
}

#vs-loading {
	display: none;
	color: #666;
	display: flex;
	align-items: center;
	font-style: italic;
	font-size: 13px;
	gap: 6px;
	user-select: none;
}

/* Cards */
.vs-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
	margin-bottom: 12px;
}

@media (max-width: 900px) {
	.vs-grid {
		grid-template-columns: 1fr;
	}
}

.vs-card {
	border: 1px solid #ddd;
	border-radius: 6px;
	background: #fff;
	padding: 12px;
	min-height: 150px;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.vs-card-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
}

.vs-title {
	font-weight: bold;
	font-size: 14px;
}

.vs-badge {
	font-size: 12px;
	padding: 3px 8px;
	border-radius: 999px;
	border: 1px solid #ccc;
	background: #f6f6f6;
	color: #333;
	white-space: nowrap;
}

.vs-badge.ok {
	border-color: #8d8;
	background: #f6fff6;
	color: #2d6a2d;
}

.vs-badge.warn {
	border-color: #e3c07a;
	background: #fffaf0;
	color: #8a5a00;
}

.vs-badge.err {
	border-color: #d88;
	background: #fff5f5;
	color: #a33;
}

.vs-kpis {
	font-family: Consolas, monospace;
	font-size: 13px;
	white-space: pre-wrap;
	line-height: 1.35;
	color: #222;
}

.vs-foot {
	font-size: 12px;
	color: #666;
	margin-top: auto;
}

/* Legacy output (hidden by default) */
#vs-output {
	background: #fff;
	border: 1px solid #ddd;
	border-radius: 4px;
	padding: 12px;
	font-family: Consolas, monospace;
	font-size: 13px;
	white-space: pre-wrap;
	max-height: 300px;
	overflow-y: auto;
	color: #444;
}

#vs-output.error {
	border-color: #d88;
	background: #fff5f5;
	color: #a33;
}

#vs-output.success {
	border-color: #8d8;
	background: #f6fff6;
	color: #373;
}
</style>

<script>
const VS_ENDPOINT = <?php echo json_encode((string)$this->_['endpoint']); ?>;

function vsSetLoading(state) {
	document.getElementById("vs-loading").style.display = state ? "flex" : "none";
}

function vsSetButtonsEnabled(enabled) {
	const root = document.getElementById("vs-buttons");
	const buttons = root.querySelectorAll("button[data-vs-action]");
	for (const b of buttons) {
		b.disabled = !enabled;
	}
}

function vsPrint(msg, type = null) {
	const box = document.getElementById("vs-output");
	box.className = "";

	if (type === "error") box.classList.add("error");
	if (type === "success") box.classList.add("success");

	box.textContent = msg;
}

function vsSetBadge(id, state, label) {
	const el = document.getElementById(id);
	el.className = "vs-badge";
	if (state === "ok") el.classList.add("ok");
	if (state === "warn") el.classList.add("warn");
	if (state === "err") el.classList.add("err");
	el.textContent = label;
}

function vsFmt(n) {
	if (n === null || typeof n === "undefined") return "–";
	return String(n);
}

function vsClearUi(note) {
	document.getElementById("vs-grid").style.display = "grid";
	document.getElementById("vs-lastupdate").textContent = "–";

	vsSetBadge("vs-badge-health", "warn", "Missing");
	document.getElementById("vs-kpis-health").textContent =
		"status: –\noptimizer: –\nsegments: 0\ninfo_time: 0 ms";
	document.getElementById("vs-foot-health").textContent = note || "Collection missing.";

	vsSetBadge("vs-badge-size", "warn", "Empty");
	document.getElementById("vs-kpis-size").textContent =
		"points: 0\nindexed_vectors: 0\npayload_on_disk: –\nshards: –  repl: –";
	document.getElementById("vs-foot-size").textContent = "collection: –";

	vsSetBadge("vs-badge-schema", "warn", "Missing");
	document.getElementById("vs-kpis-schema").textContent =
		"fields: 0\nfields with 0 points: 0\nexpected missing: 0\ntop keys: –";
	document.getElementById("vs-foot-schema").textContent = "No schema available.";

	vsSetBadge("vs-badge-config", "warn", "Missing");
	document.getElementById("vs-kpis-config").textContent =
		"vector: – / –\nhnsw m: –  ef: –\nfull_scan_threshold: –\nstrict_mode: –";
	document.getElementById("vs-foot-config").textContent = "No config available.";
}

function vsRender(stats) {
	if (!stats || stats.exists === false) {
		vsClearUi((stats && stats.health && stats.health.note) ? stats.health.note : "Collection does not exist.");
		return;
	}

	document.getElementById("vs-grid").style.display = "grid";
	document.getElementById("vs-lastupdate").textContent = stats.timestamp || "–";

	// 1) Health
	vsSetBadge("vs-badge-health", stats.badges.health.state, stats.badges.health.label);
	document.getElementById("vs-kpis-health").textContent =
		"status: " + vsFmt(stats.health.status) + "\n" +
		"optimizer: " + vsFmt(stats.health.optimizer_status) + "\n" +
		"segments: " + vsFmt(stats.health.segments_count) + "\n" +
		"info_time: " + vsFmt(stats.health.info_time_ms) + " ms";
	document.getElementById("vs-foot-health").textContent = stats.health.note || "–";

	// 2) Data
	vsSetBadge("vs-badge-size", stats.badges.size.state, stats.badges.size.label);
	document.getElementById("vs-kpis-size").textContent =
		"points: " + vsFmt(stats.size.points_count) + "\n" +
		"indexed_vectors: " + vsFmt(stats.size.indexed_vectors_count) + "\n" +
		"payload_on_disk: " + vsFmt(stats.size.on_disk_payload) + "\n" +
		"shards: " + vsFmt(stats.size.shard_number) + "  repl: " + vsFmt(stats.size.replication_factor);
	document.getElementById("vs-foot-size").textContent = "collection: " + vsFmt(stats.size.collection);

	// 3) Schema & Payload
	vsSetBadge("vs-badge-schema", stats.badges.schema.state, stats.badges.schema.label);
	document.getElementById("vs-kpis-schema").textContent =
		"fields: " + vsFmt(stats.schema.field_count) + "\n" +
		"fields with 0 points: " + vsFmt(stats.schema.zero_point_fields_count) + "\n" +
		"expected missing: " + vsFmt(stats.schema.expected_missing_count) + "\n" +
		"top keys: " + vsFmt(stats.schema.top_keys_preview);
	document.getElementById("vs-foot-schema").textContent = stats.schema.note || "–";

	// 4) Index & Config
	vsSetBadge("vs-badge-config", stats.badges.config.state, stats.badges.config.label);
	document.getElementById("vs-kpis-config").textContent =
		"vector: " + vsFmt(stats.config.vector_size) + " / " + vsFmt(stats.config.distance) + "\n" +
		"hnsw m: " + vsFmt(stats.config.hnsw_m) + "  ef: " + vsFmt(stats.config.hnsw_ef_construct) + "\n" +
		"full_scan_threshold: " + vsFmt(stats.config.full_scan_threshold) + "\n" +
		"strict_mode: " + vsFmt(stats.config.strict_mode_enabled);
	document.getElementById("vs-foot-config").textContent = stats.config.note || "–";
}

async function vsFetch(action) {
	const response = await fetch(VS_ENDPOINT + encodeURIComponent(action), {
		method: "GET",
		headers: { "Accept": "application/json" }
	});

	const text = await response.text();
	let json;

	try {
		json = JSON.parse(text);
	} catch (e) {
		throw new Error("Invalid JSON response");
	}

	return json;
}

function vsSleep(ms) {
	return new Promise(resolve => setTimeout(resolve, ms));
}

function vsIsHealthy(stats) {
	if (!stats || stats.exists === false) return false;
	const s = (stats.health && stats.health.status) ? String(stats.health.status) : "";
	const o = (stats.health && stats.health.optimizer_status) ? String(stats.health.optimizer_status) : "";
	return (s === "green" && o === "ok");
}

async function vsPollStats(maxMs, intervalMs) {
	const started = Date.now();
	let lastOk = null;

	while (Date.now() - started < maxMs) {
		try {
			const json = await vsFetch("stats");
			if (json.status === "ok" && json.data && json.data.stats) {
				lastOk = json.data.stats;
				vsRender(lastOk);

				// Stop early if "healthy" (best effort)
				if (vsIsHealthy(lastOk)) {
					return lastOk;
				}
			}
		} catch (e) {
			// Ignore and keep polling briefly
		}

		await vsSleep(intervalMs);
	}

	return lastOk;
}

async function vsAction(action) {
	vsSetLoading(true);
	vsSetButtonsEnabled(false);

	try {
		const json = await vsFetch(action);

		if (json.status === "error") {
			vsPrint("Error:\n" + JSON.stringify(json, null, 2), "error");
			vsSetLoading(false);
			vsSetButtonsEnabled(true);
			return;
		}

		// If endpoint returns stats (create/delete), render immediately
		if (json.data && json.data.stats) {
			vsRender(json.data.stats);
		}

		// Always refresh cards after mutating actions (create/delete)
		if (action === "create") {
			// Poll up to 2s to let Qdrant settle (status/optimizer)
			await vsPollStats(2000, 250);
		} else if (action === "delete") {
			// One immediate refresh is enough (collection should be gone)
			const fresh = await vsFetch("stats");
			if (fresh.status === "ok" && fresh.data && fresh.data.stats) {
				vsRender(fresh.data.stats);
			}
		} else if (action === "stats") {
			if (json.data && json.data.stats) {
				vsRender(json.data.stats);
			}
		}

		// Keep output hidden by default, but still update its content for debugging
		vsPrint(JSON.stringify(json, null, 2), "success");

	} catch (err) {
		vsPrint("Request failed:\n" + err, "error");
	}

	vsSetLoading(false);
	vsSetButtonsEnabled(true);
}

// Init: load status immediately
vsAction("stats");
</script>
