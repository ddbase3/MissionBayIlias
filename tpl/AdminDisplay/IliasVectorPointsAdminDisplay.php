<div class="vs-points">
	<h3>ILIAS Points Inspector</h3>

	<div class="vs-meta">
		<div><strong>CollectionKey:</strong> <span class="mono"><?php echo htmlspecialchars((string)$this->_['collectionKey'], ENT_QUOTES); ?></span></div>
		<div><strong>Backend:</strong> <span class="mono"><?php echo htmlspecialchars((string)$this->_['backendCollection'], ENT_QUOTES); ?></span></div>
		<div><strong>Letztes Update:</strong> <span id="vp-lastupdate" class="mono">–</span></div>
	</div>

	<div class="vp-controls">
		<label>
			<span class="lbl">source_kind</span>
			<select id="vp-sourcekind">
				<option value="">(alle)</option>
			</select>
		</label>

		<label>
			<span class="lbl">Exemplare</span>
			<select id="vp-limit">
				<option value="1">1</option>
				<option value="2">2</option>
				<option value="3" selected>3</option>
				<option value="4">4</option>
				<option value="5">5</option>
				<option value="6">6</option>
				<option value="7">7</option>
				<option value="8">8</option>
				<option value="9">9</option>
				<option value="10">10</option>
				<option value="11">11</option>
				<option value="12">12</option>
			</select>
		</label>

		<button type="button" onclick="vpLoadSamples()">Samples laden</button>
		<label id="vp-loading">Bitte warten…</label>
	</div>

	<div id="vp-hint" class="vp-hint">
		Die Dropdown-Optionen werden live aus der VectorDB gelesen (unique <span class="mono">payload.source_kind</span>).
	</div>

	<div id="vp-output" class="vp-output" style="display:none"></div>

	<div id="vp-grid" class="vp-grid" style="display:none"></div>
</div>

<style>
.vs-points {
	background: #ffffff;
	border: 1px solid #d6d6d6;
	padding: 16px;
	border-radius: 4px;
	max-width: 100%;
	font-family: Arial, sans-serif;
	color: #333;
}

.vs-points h3 {
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

.vp-controls {
	display: flex;
	gap: 10px;
	margin-bottom: 10px;
	align-items: end;
	flex-wrap: wrap;
}

.vp-controls label {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 12px;
	color: #444;
}

.vp-controls .lbl {
	font-weight: bold;
	color: #555;
}

.vp-controls select {
	min-width: 220px;
	padding: 6px 8px;
	border: 1px solid #ccc;
	border-radius: 4px;
	background: #fff;
}

.vp-controls button {
	padding: 8px 16px;
	border: 1px solid #ccc;
	background: #f0f0f0;
	color: #333;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
	transition: background 0.2s, border-color 0.2s;
}

.vp-controls button:hover {
	background: #e6e6e6;
	border-color: #bbb;
}

#vp-loading {
	display: none;
	color: #666;
	align-items: center;
	font-style: italic;
	font-size: 13px;
	gap: 6px;
	user-select: none;
}

.vp-hint {
	font-size: 12px;
	color: #666;
	margin-bottom: 12px;
}

.vp-output {
	background: #fff;
	border: 1px solid #ddd;
	border-radius: 4px;
	padding: 12px;
	font-family: Consolas, monospace;
	font-size: 13px;
	white-space: pre-wrap;
	max-height: 240px;
	overflow-y: auto;
	color: #444;
	margin-bottom: 12px;
}

.vp-output.error {
	border-color: #d88;
	background: #fff5f5;
	color: #a33;
}

.vp-output.success {
	border-color: #8d8;
	background: #f6fff6;
	color: #373;
}

/* Sample cards */
.vp-grid {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 12px;
}

@media (max-width: 1100px) {
	.vp-grid {
		grid-template-columns: 1fr;
	}
}

.vp-card {
	border: 1px solid #ddd;
	border-radius: 8px;
	background: #fff;
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 10px;
	min-width: 0;
}

.vp-head {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 10px;
}

.vp-title {
	font-weight: bold;
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.vp-badge {
	font-size: 12px;
	padding: 3px 8px;
	border-radius: 999px;
	border: 1px solid #ccc;
	background: #f6f6f6;
	color: #333;
	white-space: nowrap;
}

.vp-kv {
	font-family: Consolas, monospace;
	font-size: 12px;
	white-space: pre-wrap;
	line-height: 1.35;
	color: #222;
}

.vp-text {
	border-top: 1px dashed #ddd;
	padding-top: 10px;
	font-size: 12px;
	color: #333;
	white-space: pre-wrap;
	line-height: 1.35;
	max-height: 220px;
	overflow: auto;
}

details summary {
	cursor: pointer;
	user-select: none;
	font-weight: bold;
	color: #555;
	margin-top: 6px;
}
</style>

<script>
const VP_ENDPOINT = <?php echo json_encode((string)$this->_['endpoint']); ?>;

function vpSetLoading(state) {
	document.getElementById("vp-loading").style.display = state ? "flex" : "none";
}

function vpPrint(obj, type = null) {
	const box = document.getElementById("vp-output");
	box.style.display = "block";
	box.className = "vp-output";
	if (type === "error") box.classList.add("error");
	if (type === "success") box.classList.add("success");
	box.textContent = typeof obj === "string" ? obj : JSON.stringify(obj, null, 2);
}

function vpSetLastUpdate(ts) {
	document.getElementById("vp-lastupdate").textContent = ts || "–";
}

function vpEsc(s) {
	return String(s ?? "").replace(/[&<>"']/g, c => ({
		"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
	}[c]));
}

function vpShortText(text, max = 600) {
	text = String(text ?? "");
	if (text.length <= max) return text;
	return text.slice(0, max) + " …";
}

function vpRenderPoints(points) {
	const grid = document.getElementById("vp-grid");
	grid.innerHTML = "";
	grid.style.display = "grid";

	if (!Array.isArray(points) || points.length === 0) {
		grid.innerHTML = "<div class='vp-output'>Keine Points gefunden.</div>";
		return;
	}

	for (const p of points) {
		const payload = (p && p.payload) ? p.payload : {};
		const id = p && p.id ? p.id : "–";

		const title = payload.title || payload.source_locator || payload.content_uuid || id;

		const meta = payload.meta || {};
		const sk = payload.source_kind || "–";
		const cu = payload.content_uuid || "–";
		const loc = payload.source_locator || "–";
		const cont = payload.container_obj_id ?? "–";
		const idx = payload.chunk_index ?? "–";
		const tok = payload.chunktoken || "–";

		const text = payload.text || "";

		const el = document.createElement("div");
		el.className = "vp-card";
		el.innerHTML =
			"<div class='vp-head'>" +
				"<div class='vp-title' title='" + vpEsc(title) + "'>" + vpEsc(title) + "</div>" +
				"<div class='vp-badge'>" + vpEsc(sk) + "</div>" +
			"</div>" +
			"<div class='vp-kv'>" +
				"id: " + vpEsc(id) + "\n" +
				"content_uuid: " + vpEsc(cu) + "\n" +
				"source_locator: " + vpEsc(loc) + "\n" +
				"container_obj_id: " + vpEsc(cont) + "\n" +
				"chunk_index: " + vpEsc(idx) + "\n" +
				"chunktoken: " + vpEsc(tok) +
			"</div>" +
			"<div class='vp-text'>" + vpEsc(vpShortText(text)) + "</div>" +
			"<details>" +
				"<summary>payload.meta</summary>" +
				"<div class='vp-kv'>" + vpEsc(JSON.stringify(meta, null, 2)) + "</div>" +
			"</details>";

		grid.appendChild(el);
	}
}

async function vpLoadKinds() {
	vpSetLoading(true);

	try {
		const res = await fetch(VP_ENDPOINT + "kinds", { method: "GET", headers: { "Accept": "application/json" } });
		const json = await res.json();

		vpSetLastUpdate(json.timestamp);

		if (json.status !== "ok") {
			vpPrint(json, "error");
			vpSetLoading(false);
			return;
		}

		const kinds = (json.data && json.data.kinds) ? json.data.kinds : [];
		const sel = document.getElementById("vp-sourcekind");

		// keep first "(alle)"
		while (sel.options.length > 1) sel.remove(1);

		for (const k of kinds) {
			const opt = document.createElement("option");
			opt.value = k;
			opt.textContent = k;
			sel.appendChild(opt);
		}

		// auto load initial samples
		await vpLoadSamples();

	} catch (e) {
		vpPrint("Anfrage fehlgeschlagen:\n" + e, "error");
	}

	vpSetLoading(false);
}

async function vpLoadSamples() {
	vpSetLoading(true);

	const sk = document.getElementById("vp-sourcekind").value || "";
	const limit = document.getElementById("vp-limit").value || "3";

	try {
		const url = VP_ENDPOINT + "sample&source_kind=" + encodeURIComponent(sk) + "&limit=" + encodeURIComponent(limit);
		const res = await fetch(url, { method: "GET", headers: { "Accept": "application/json" } });
		const json = await res.json();

		vpSetLastUpdate(json.timestamp);

		if (json.status !== "ok") {
			vpPrint(json, "error");
			vpSetLoading(false);
			return;
		}

		const points = (json.data && json.data.points) ? json.data.points : [];
		vpRenderPoints(points);

		// debug output hidden by default; enable if needed:
		// vpPrint(json, "success");

	} catch (e) {
		vpPrint("Anfrage fehlgeschlagen:\n" + e, "error");
	}

	vpSetLoading(false);
}

// init
vpLoadKinds();
</script>
