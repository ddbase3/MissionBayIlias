<?php
	$this->loadBricks('IliasSourceKindEnqueueAdminDisplay');
	$this->loadBricks('SourceKinds');
?>

<div class="jobs-admin">
	<h3><?php echo $this->_['bricks']['iliassourcekindenqueueadmindisplay']['headline']; ?></h3>

	<div class="jobs-meta">
		<div><strong>Config group:</strong> <span class="mono"><?php echo htmlspecialchars((string)$this->_['configGroup'], ENT_QUOTES); ?></span></div>
		<div><strong>Letztes Update:</strong> <span id="ja-lastupdate" class="mono">–</span></div>
		<div id="ja-loading" class="ja-loading">Bitte warten…</div>
	</div>

	<div id="ja-output" class="ja-output" style="display:none"></div>

	<table class="jobs-table">
		<thead>
			<tr>
				<th><?php echo $this->_['bricks']['iliassourcekindenqueueadmindisplay']['source_kind']; ?></th>
				<th><?php echo $this->_['bricks']['iliassourcekindenqueueadmindisplay']['active']; ?></th>
				<th>Config</th>
			</tr>
		</thead>
		<tbody id="ja-body">
			<tr><td colspan="3" class="mono">Loading…</td></tr>
		</tbody>
	</table>
</div>

<style>
.jobs-admin {
	background: #ffffff;
	border: 1px solid #d6d6d6;
	padding: 16px;
	border-radius: 4px;
	max-width: 100%;
	font-family: Arial, sans-serif;
	color: #333;
}

.jobs-admin h3 {
	margin-top: 0;
	margin-bottom: 12px;
	font-size: 1.1em;
}

.jobs-meta {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	align-items: center;
	margin-bottom: 10px;
	font-size: 13px;
	color: #555;
}

.mono {
	font-family: Consolas, monospace;
}

.ja-loading {
	display: none;
	color: #666;
	font-style: italic;
}

.ja-output {
	background: #fff;
	border: 1px solid #ddd;
	border-radius: 4px;
	padding: 10px;
	font-family: Consolas, monospace;
	font-size: 12px;
	white-space: pre-wrap;
	max-height: 240px;
	overflow: auto;
	color: #444;
	margin-bottom: 12px;
}

.ja-output.error {
	border-color: #d88;
	background: #fff5f5;
	color: #a33;
}

.ja-output.success {
	border-color: #8d8;
	background: #f6fff6;
	color: #373;
}

.jobs-table {
	width: 100%;
	border-collapse: collapse;
}

.jobs-table th,
.jobs-table td {
	padding: 8px 10px;
	border-bottom: 1px solid #e0e0e0;
	vertical-align: middle;
	text-align: left;
}

.jobs-table th {
	background: #f5f5f5;
	font-weight: 600;
	border-bottom: 2px solid #cfcfcf;
}

.jobs-table tr:hover td {
	background: #fafafa;
}

.jobs-table td.job-col {
	font-family: Consolas, monospace;
	font-size: 12px;
	white-space: nowrap;
}

.jobs-table td.cfg-col {
	font-size: 12px;
	color: #666;
	white-space: nowrap;
}

.switch {
	position: relative;
	display: inline-block;
	width: 44px;
	height: 24px;
}

.switch input {
	opacity: 0;
	width: 0;
	height: 0;
}

.slider {
	position: absolute;
	cursor: pointer;
	top: 0; left: 0; right: 0; bottom: 0;
	background-color: #ccc;
	transition: .2s;
	border-radius: 999px;
}

.slider:before {
	position: absolute;
	content: "";
	height: 18px;
	width: 18px;
	left: 3px;
	bottom: 3px;
	background-color: white;
	transition: .2s;
	border-radius: 50%;
}

.switch input:checked + .slider {
	background-color: #6bb46b;
}

.switch input:checked + .slider:before {
	transform: translateX(20px);
}

.badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 999px;
	border: 1px solid #ccc;
	background: #f6f6f6;
	color: #333;
	font-size: 12px;
	white-space: nowrap;
}

.badge.warn {
	border-color: #d7c17a;
	background: #fff8df;
}

.badge.ok {
	border-color: #8d8;
	background: #f6fff6;
}
</style>

<script>
const JA_ENDPOINT = <?php echo json_encode((string)$this->_['endpoint']); ?>;
const SOURCE_KINDS = <?php echo json_encode($this->_['bricks']['sourcekinds'] ?? []); ?>;

function jaSetLoading(state) {
	document.getElementById("ja-loading").style.display = state ? "block" : "none";
}

function jaSetLastUpdate(ts) {
	document.getElementById("ja-lastupdate").textContent = ts || "–";
}

function jaPrint(obj, type = null) {
	const box = document.getElementById("ja-output");
	box.style.display = "block";
	box.className = "ja-output";
	if (type === "error") box.classList.add("error");
	if (type === "success") box.classList.add("success");
	box.textContent = typeof obj === "string" ? obj : JSON.stringify(obj, null, 2);
}

function jaEsc(s) {
	return String(s ?? "").replace(/[&<>"']/g, c => ({
		"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;"
	}[c]));
}

function jaConfigBadge(row) {
	const a = row.hasActiveConfig ? "A" : "a";
	const label = row.hasActiveConfig ? ("config " + a) : "default";
	const cls = row.hasActiveConfig ? "badge ok" : "badge warn";
	return "<span class='" + cls + "' title='A uppercase means stored in config'>" + jaEsc(label) + "</span>";
}

function jaRenderRows(rows) {
	const body = document.getElementById("ja-body");
	body.innerHTML = "";

	if (!Array.isArray(rows) || rows.length === 0) {
		body.innerHTML = "<tr><td colspan='3' class='mono'>No source kinds found.</td></tr>";
		return;
	}

	for (const row of rows) {
		const tr = document.createElement("tr");
		const checked = row.active ? "checked" : "";
		const kindName = SOURCE_KINDS[row.kind] || row.kind;

		tr.innerHTML =
			"<td class='job-col' title='" + jaEsc(kindName) + "'>" + jaEsc(kindName) + "</td>" +
			"<td>" +
				"<label class='switch' title='Toggle active'>" +
					"<input type='checkbox' data-action='active' data-kind='" + jaEsc(row.kind) + "' " + checked + ">" +
					"<span class='slider'></span>" +
				"</label>" +
			"</td>" +
			"<td class='cfg-col'>" + jaConfigBadge(row) + "</td>";

		body.appendChild(tr);
	}

	jaWireActions();
}

function jaWireActions() {
	document.querySelectorAll("input[type=checkbox][data-action='active']").forEach(el => {
		el.onchange = async function() {
			const kind = this.getAttribute("data-kind") || "";
			const value = this.checked ? 1 : 0;

			const res = await jaCall("set_active&kind=" + encodeURIComponent(kind) + "&value=" + encodeURIComponent(value));
			if (res && res.status === "ok") {
				await jaLoadList();
			}
		};
	});
}

async function jaCall(qs) {
	jaSetLoading(true);

	try {
		const res = await fetch(JA_ENDPOINT + qs, { method: "GET", headers: { "Accept": "application/json" } });
		const json = await res.json();

		jaSetLastUpdate(json.timestamp);

		if (json.status !== "ok") {
			jaPrint(json, "error");
			jaSetLoading(false);
			return null;
		}

		return json;

	} catch (e) {
		jaPrint("Request failed:\n" + e, "error");
		return null;
	} finally {
		jaSetLoading(false);
	}
}

async function jaLoadList() {
	const json = await jaCall("list");
	if (!json) return;

	const rows = (json.data && json.data.kinds) ? json.data.kinds : [];
	jaRenderRows(rows);
}

jaLoadList();
</script>
