<div class="base3ilias-services">
	<h3>ILIAS VectorStore Administration</h3>

	<div class="vs-meta">
		<div><strong>Resource:</strong> <span class="mono"><?php echo htmlspecialchars((string)$this->_['resourceName'], ENT_QUOTES); ?></span></div>
		<div><strong>CollectionKey:</strong> <span class="mono"><?php echo htmlspecialchars((string)$this->_['collectionKey'], ENT_QUOTES); ?></span></div>
	</div>

	<div id="vs-loading">Bitte warten…</div>

	<div class="vs-buttons">
		<button type="button" onclick="vsAction('create')">Collection erstellen</button>
		<button type="button" onclick="if (confirm('Collection wirklich löschen?')) vsAction('delete');">Collection löschen</button>
		<button type="button" onclick="vsAction('info')">Info abrufen</button>
	</div>

	<div id="vs-output">Bereit.</div>
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
}

.mono {
	font-family: Consolas, monospace;
}

.vs-buttons {
	display: flex;
	gap: 10px;
	margin-bottom: 15px;
}

.vs-buttons button {
	padding: 8px 16px;
	border: 1px solid #ccc;
	background: #f0f0f0;
	color: #333;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
	transition: background 0.2s, border-color 0.2s;
}

.vs-buttons button:hover {
	background: #e6e6e6;
	border-color: #bbb;
}

#vs-loading {
	display: none;
	margin-bottom: 10px;
	color: #666;
	font-style: italic;
	font-size: 13px;
}

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
		document.getElementById("vs-loading").style.display = state ? "block" : "none";
	}

	function vsPrint(msg, type = null) {
		const box = document.getElementById("vs-output");
		box.className = "";

		if (type === "error") box.classList.add("error");
		if (type === "success") box.classList.add("success");

		box.textContent = msg;
	}

	async function vsAction(action) {
		vsSetLoading(true);
		vsPrint("Rufe " + action + "…");

		try {
			const response = await fetch(VS_ENDPOINT + encodeURIComponent(action), {
				method: "GET",
				headers: { "Accept": "application/json" }
			});

			const text = await response.text();
			let json;

			try {
				json = JSON.parse(text);
			} catch (e) {
				vsPrint("Ungültige Antwort:\n\n" + text, "error");
				vsSetLoading(false);
				return;
			}

			if (json.status === "error") {
				vsPrint("Fehler:\n" + JSON.stringify(json, null, 2), "error");
			} else {
				vsPrint(JSON.stringify(json, null, 2), "success");
			}

		} catch (err) {
			vsPrint("Anfrage fehlgeschlagen:\n" + err, "error");
		}

		vsSetLoading(false);
	}
</script>
