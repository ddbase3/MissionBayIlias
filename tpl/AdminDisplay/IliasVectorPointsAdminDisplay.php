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
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 12px;
}

@media (max-width: 1100px) {
	.vp-grid {
		grid-template-columns: 1fr;
	}
}

.vp-card {
	border: 1px solid #ddd;
	border-radius: 10px;
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
	min-width: 0;
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

.vp-section-title {
	font-size: 12px;
	font-weight: bold;
	color: #555;
	margin-bottom: 6px;
}

/* Collapsible section header (top/bot only) */
.vp-sec-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	margin-bottom: 6px;
}

.vp-sec-title {
	font-size: 12px;
	font-weight: bold;
	color: #555;
	min-width: 0;
}

.vp-toggle {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	line-height: 18px;
	font-size: 12px;
	color: #555;
	cursor: pointer;
	user-select: none;
	border-radius: 6px;
}

.vp-toggle:hover {
	background: #f0f0f0;
}

.vp-kv {
	font-family: Consolas, monospace;
	font-size: 12px;
	white-space: pre-wrap;
	line-height: 1.35;
	color: #222;
}

.vp-box {
	border: 1px solid #eee;
	border-radius: 8px;
	padding: 10px;
	background: #fafafa;
	min-width: 0;
	overflow-x: hidden;
}

.vp-top {
	height: 40px; /* minimal when collapsed */
	overflow: hidden;
}

.vp-mid {
	height: 300px;
	background: #fff;
}

.vp-bot {
	height: 40px; /* minimal when collapsed */
	overflow: hidden;
}

/* Expand on demand */
.vp-box.is-open {
	height: auto;
	overflow: visible;
}

/* Make collapsed boxes look like "header bars" */
.vp-box.is-collapsed .vp-kv {
	opacity: 0.95;
}

/* Prevent layout jumps in header line */
.vp-sec-head .vp-sec-title {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
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

function vpPretty(obj) {
	try {
		return JSON.stringify(obj, null, 2);
	} catch (e) {
		return String(obj ?? "");
	}
}

function vpPayloadTop(payload) {
	if (!payload || typeof payload !== "object") return {};
	const out = {};
	for (const k of Object.keys(payload)) {
		if (k === "text") continue;
		if (k === "meta") continue;
		out[k] = payload[k];
	}
	return out;
}

function vpPayloadMeta(payload) {
	const meta = payload && typeof payload === "object" ? payload.meta : null;
	return meta && typeof meta === "object" ? meta : {};
}

function vpPayloadText(payload) {
	const t = payload && typeof payload === "object" ? payload.text : "";
	return String(t ?? "");
}

function vpToggleBox(toggleEl) {
	const box = toggleEl.closest(".vp-box");
	if (!box) return;

	const open = !box.classList.contains("is-open");
	box.classList.toggle("is-open", open);
	box.classList.toggle("is-collapsed", !open);

	// caret: closed ▶, open ▼
	toggleEl.textContent = open ? "▼" : "▶";
	toggleEl.setAttribute("aria-expanded", open ? "true" : "false");
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
		const sk = payload.source_kind || "–";

		const top = vpPayloadTop(payload);
		top.id = id;

		const text = vpPayloadText(payload);
		const meta = vpPayloadMeta(payload);

		const el = document.createElement("div");
		el.className = "vp-card";
		el.innerHTML =
			"<div class='vp-head'>" +
				"<div class='vp-title' title='" + vpEsc(title) + "'>" + vpEsc(title) + "</div>" +
				"<div class='vp-badge'>" + vpEsc(sk) + "</div>" +
			"</div>" +

			"<div class='vp-box vp-top is-collapsed'>" +
				"<div class='vp-sec-head'>" +
					"<div class='vp-sec-title'>payload (root, without text/meta)</div>" +
					"<span class='vp-toggle' role='button' tabindex='0' aria-label='Toggle payload root' aria-expanded='false'>▶</span>" +
				"</div>" +
				"<div class='vp-kv'>" + vpEsc(vpPretty(top)) + "</div>" +
			"</div>" +

			"<div class='vp-box vp-mid'>" +
				"<div class='vp-section-title'>text (full)</div>" +
				"<div class='vp-kv'>" + vpEsc(text) + "</div>" +
			"</div>" +

			"<div class='vp-box vp-bot is-collapsed'>" +
				"<div class='vp-sec-head'>" +
					"<div class='vp-sec-title'>payload.meta (full)</div>" +
					"<span class='vp-toggle' role='button' tabindex='0' aria-label='Toggle payload meta' aria-expanded='false'>▶</span>" +
				"</div>" +
				"<div class='vp-kv'>" + vpEsc(vpPretty(meta)) + "</div>" +
			"</div>";

		grid.appendChild(el);
	}

	// bind toggle handlers (click + keyboard)
	for (const t of grid.querySelectorAll(".vp-toggle")) {
		t.addEventListener("click", (e) => {
			e.preventDefault();
			vpToggleBox(t);
		});
		t.addEventListener("keydown", (e) => {
			if (e.key === "Enter" || e.key === " ") {
				e.preventDefault();
				vpToggleBox(t);
			}
		});
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

		while (sel.options.length > 1) sel.remove(1);

		for (const k of kinds) {
			const opt = document.createElement("option");
			opt.value = k;
			opt.textContent = k;
			sel.appendChild(opt);
		}

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

	} catch (e) {
		vpPrint("Anfrage fehlgeschlagen:\n" + e, "error");
	}

	vpSetLoading(false);
}

// Init
vpLoadKinds();
</script>
