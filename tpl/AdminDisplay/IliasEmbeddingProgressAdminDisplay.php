<?php $this->loadBricks('SourceKinds'); ?>

<div class="base3ilias-ep1">
	<div class="ep1-head">
		<h3>ILIAS Embedding Progress – by source_kind</h3>

		<div class="ep1-meta">
			<div><strong>Universe:</strong> <span class="mono">base3_embedding_seen</span> (<span class="mono">deleted_at IS NULL</span>)</div>
			<div><strong>Last update:</strong> <span id="ep1-lastupdate" class="mono">–</span></div>
		</div>

		<div class="ep1-actions">
			<button type="button" onclick="ep1Refresh()">Refresh now</button>

			<label class="ep1-autorefresh">
				<input type="checkbox" id="ep1-autorefresh" checked onchange="ep1ToggleAutoRefresh()">
				Auto-Refresh (3s)
			</label>

			<label id="ep1-loading">Loading…</label>
		</div>

		<div class="ep1-legend" id="ep1-legend" style="display:none"></div>
	</div>

	<div class="ep1-list" id="ep1-list" style="display:none"></div>

	<div class="ep1-empty" id="ep1-empty" style="display:none">
		No items found in <span class="mono">base3_embedding_seen</span>.
	</div>
</div>

<style>
.base3ilias-ep1 {
	background: #ffffff;
	border: 1px solid #d6d6d6;
	padding: 16px;
	border-radius: 4px;
	max-width: 100%;
	font-family: Arial, sans-serif;
	color: #333;
}

.base3ilias-ep1 h3 {
	margin: 0 0 10px 0;
	font-size: 1.1em;
}

.mono {
	font-family: Consolas, monospace;
}

.ep1-meta {
	display: flex;
	gap: 18px;
	flex-wrap: wrap;
	font-size: 13px;
	color: #555;
	margin-bottom: 10px;
}

.ep1-actions {
	display: flex;
	gap: 10px;
	align-items: center;
	flex-wrap: wrap;
	margin-bottom: 10px;
}

.ep1-actions button {
	padding: 8px 16px;
	border: 1px solid #ccc;
	background: #f0f0f0;
	color: #333;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
	transition: background 0.2s, border-color 0.2s;
}

.ep1-actions button:hover {
	background: #e6e6e6;
	border-color: #bbb;
}

.ep1-autorefresh {
	font-size: 13px;
	color: #555;
	display: flex;
	align-items: center;
	gap: 6px;
	user-select: none;
}

#ep1-loading {
	display: none;
	color: #666;
	align-items: center;
	font-style: italic;
	font-size: 13px;
	gap: 6px;
	user-select: none;
}

.ep1-legend {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
	margin: 10px 0 14px 0;
	align-items: center;
}

.ep1-legend-item {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 12px;
	color: #444;
	border: 1px solid #e2e2e2;
	background: #fafafa;
	padding: 4px 8px;
	border-radius: 999px;
}

.ep1-swatch {
	width: 10px;
	height: 10px;
	border-radius: 3px;
	border: 1px solid rgba(0,0,0,0.12);
}

.ep1-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ep1-row {
	border: 1px solid #ddd;
	border-radius: 8px;
	padding: 12px;
	background: #fff;
	display: grid;
	grid-template-columns: minmax(140px, 260px) 1fr minmax(120px, 170px);
	gap: 12px;
	align-items: center;
}

@media (max-width: 900px) {
	.ep1-row {
		grid-template-columns: 1fr;
	}
}

.ep1-kind {
	font-weight: bold;
	font-size: 14px;
}

.ep1-total {
	text-align: right;
	font-size: 13px;
	color: #555;
}

@media (max-width: 900px) {
	.ep1-total {
		text-align: left;
	}
}

.ep1-bar {
	width: 100%;
	height: 16px;
	border-radius: 999px;
	overflow: hidden;
	border: 1px solid #d8d8d8;
	background: #f3f3f3;
	display: flex;
}

.ep1-seg {
	height: 100%;
	min-width: 2px;
}

.ep1-sub {
	margin-top: 6px;
	font-size: 12px;
	color: #666;
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
}

.ep1-sub span {
	display: inline-flex;
	gap: 6px;
	align-items: baseline;
}

.ep1-sub code {
	font-family: Consolas, monospace;
	font-size: 12px;
	background: #f7f7f7;
	border: 1px solid #eee;
	padding: 1px 6px;
	border-radius: 999px;
}

.ep1-empty {
	border: 1px dashed #d0d0d0;
	background: #fcfcfc;
	padding: 14px;
	border-radius: 8px;
	color: #666;
	font-style: italic;
}
</style>

<script>
	const EP1_ENDPOINT = <?php echo json_encode((string)$this->_['endpoint']); ?>;
	const SOURCE_KINDS = <?php echo json_encode($this->_['bricks']['sourcekinds'] ?? []); ?>;

	let ep1Timer = null;

	function ep1SetLoading(state) {
		document.getElementById("ep1-loading").style.display = state ? "inline-flex" : "none";
	}

	function ep1Esc(s) {
		const div = document.createElement("div");
		div.textContent = String(s ?? "");
		return div.innerHTML;
	}

	function ep1Color(bucket, legend) {
		if (Array.isArray(legend)) {
			for (const l of legend) {
				if (l && l.bucket === bucket) return String(l.color || "#999");
			}
		}
		return "#999";
	}

	function ep1RenderLegend(legend) {
		const el = document.getElementById("ep1-legend");

		if (!legend || legend.length === 0) {
			el.style.display = "none";
			el.innerHTML = "";
			return;
		}

		let html = "";
		for (const l of legend) {
			const col = String(l.color || "#999");
			const label = String(l.label || l.bucket || "");
			html += '<div class="ep1-legend-item">' +
				'<span class="ep1-swatch" style="background:' + ep1Esc(col) + '"></span>' +
				'<span>' + ep1Esc(label) + '</span>' +
			'</div>';
		}

		el.innerHTML = html;
		el.style.display = "flex";
	}

	function ep1RenderBars(items, legend) {
		const list = document.getElementById("ep1-list");
		const empty = document.getElementById("ep1-empty");

		if (!items || items.length === 0) {
			list.style.display = "none";
			list.innerHTML = "";
			empty.style.display = "block";
			return;
		}

		empty.style.display = "none";
		list.style.display = "flex";

		let html = "";

		for (const it of items) {
			const kind = String(SOURCE_KINDS[it.source_kind] || it.source_kind || "–");
			const total = Number(it.total || 0);
			const segs = Array.isArray(it.segments) ? it.segments : [];

			let barHtml = "";
			let subHtml = "";

			for (const seg of segs) {
				const bucket = String(seg.bucket || "");
				const count = Number(seg.count || 0);
				const percent = Number(seg.percent || 0);
				const col = ep1Color(bucket, legend);

				barHtml += '<div class="ep1-seg" ' +
					'style="width:' + ep1Esc(percent) + '%; background:' + ep1Esc(col) + ';" ' +
					'title="' + ep1Esc(bucket) + ': ' + ep1Esc(count) + ' (' + ep1Esc(percent) + '%)">' +
				'</div>';

				subHtml += '<span>' +
					'<code>' + ep1Esc(bucket) + '</code>' +
					'<span>' + ep1Esc(count) + ' (' + ep1Esc(percent) + '%)</span>' +
				'</span>';
			}

			if (barHtml === "") {
				barHtml = '<div class="ep1-seg" style="width:100%; background:#e9ecef"></div>';
			}

			html += '<div class="ep1-row">' +
				'<div class="ep1-kind">' + ep1Esc(kind) + '</div>' +
				'<div>' +
					'<div class="ep1-bar">' + barHtml + '</div>' +
					'<div class="ep1-sub">' + subHtml + '</div>' +
				'</div>' +
				'<div class="ep1-total"><strong>Total:</strong> <span class="mono">' + ep1Esc(total) + '</span></div>' +
			'</div>';
		}

		list.innerHTML = html;
	}

	function ep1Render(data) {
		document.getElementById("ep1-lastupdate").textContent = data.timestamp || "–";
		ep1RenderLegend(data.legend || []);
		ep1RenderBars(data.items || [], data.legend || []);
	}

	async function ep1Refresh() {
		ep1SetLoading(true);

		try {
			const response = await fetch(EP1_ENDPOINT + encodeURIComponent("progress"), {
				method: "GET",
				headers: { "Accept": "application/json" }
			});

			const text = await response.text();
			let json;

			try {
				json = JSON.parse(text);
			} catch (e) {
				ep1SetLoading(false);
				return;
			}

			if (json.status !== "ok" || !json.data) {
				ep1SetLoading(false);
				return;
			}

			ep1Render(json.data);

		} catch (err) {
			// silent by design
		}

		ep1SetLoading(false);
	}

	function ep1ToggleAutoRefresh() {
		const enabled = document.getElementById("ep1-autorefresh").checked;

		if (ep1Timer) {
			clearInterval(ep1Timer);
			ep1Timer = null;
		}

		if (enabled) {
			ep1Timer = setInterval(ep1Refresh, 3000);
		}
	}

	// init
	ep1ToggleAutoRefresh();
	ep1Refresh();
</script>
