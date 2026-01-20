# MissionBay ILIAS Embedding Pipeline (Planned)

> **License:** GNU GPL v3 (see **License** section)

This project provides a **lightweight embedding and synchronization pipeline for ILIAS**, built on top of the **BASE3 Framework**.
It is conceptually aligned with the existing MissionBay XRM embedding pipeline, but deliberately reduced in scope and adapted to the structural characteristics of ILIAS.

The pipeline focuses on **reliable content synchronization**, **incremental updates**, and **clear separation of concerns**, forming a stable foundation for future embedding, retrieval, and assistive use cases.

---

## Motivation

ILIAS content is distributed across heterogeneous modules with differing data models, update semantics, and storage locations. While this structure is suitable for an LMS, it complicates downstream processes such as indexing, synchronization, and semantic processing.

The goal of the MissionBay ILIAS Embedding Pipeline is **not** to normalize or redesign ILIAS itself, but to introduce a **parallel, canonical content layer** that can be kept in sync with ILIAS and consumed by downstream systems.

Embeddings and retrieval are treated as **subsequent consumers** of this content layer, not as its primary responsibility.

---

## Scope (Initial / Small Variant)

The initial ILIAS variant intentionally limits its scope:

* Content is processed at **content-unit level** (e.g. wiki pages, glossary terms, files, SCORM packages), not at object-container level.
* Updates are detected using **database-level change indicators** only.
* When a change is detected, the corresponding content unit is **fully reprocessed**.
* No attempt is made to detect partial changes within complex objects (e.g. SCORM internals).

This keeps the pipeline predictable, robust, and easy to operate.

---

## Architecture Overview

The pipeline follows the same high-level pattern as the XRM embedding pipeline, but with ILIAS-specific extractors and providers.

### 1. Enqueue Job (ILIAS)

* Scans ILIAS content sources incrementally
* Operates on **content units**, not on `object_data` alone
* Detects:

  * new or changed content units → `upsert`
  * removed content units → `delete`
* Persists cursors and runtime state via the BASE3 `IStateStore`

Each content type is handled by a dedicated provider, allowing operators to enable or disable specific sources independently.

---

### 2. Embedding Flow (BASE3 Agent System)

The actual embedding process reuses the existing **BASE3 agent-based embedding flow**:

* Queue extraction
* Content loading and normalization
* Chunking
* Embedding generation
* Vector store synchronization
* Acknowledgement and error handling

The same agent concepts (extractors, parsers, chunkers, embedders, vector stores) are used, ensuring conceptual and technical consistency with the XRM pipeline.

---

### 3. Data Model

As with the XRM pipeline, the ILIAS variant uses two central tables:

* `base3_embedding_job`
  Queue table containing `upsert` and `delete` jobs with explicit state handling.

* `base3_embedding_seen`
  Tracks which content units were last observed, including version markers and deletion state.

In addition, the ILIAS integration introduces a **canonical content identification scheme**, decoupling embedding units from ILIAS object IDs.

---

## Relationship to BASE3 and XRM

* The ILIAS embedding pipeline is a **sibling implementation** of the XRM embedding pipeline.
* Both share:

  * the same queue semantics
  * the same agent-based embedding flow
  * the same operational principles
* Differences are isolated to:

  * content discovery
  * change detection
  * content loading

This allows improvements in one pipeline to inform the other without tight coupling.

---

## Outlook

The initial implementation focuses on **correctness, stability, and synchronization fidelity**.
Once a reliable canonical content layer is established, additional capabilities can be layered on top, such as:

* advanced retrieval strategies
* assistive or conversational interfaces
* domain-specific enrichment
* alternative vector stores or embedding models

These extensions remain optional and are intentionally not part of the initial scope.

---

## License (GPL v3)

This project is licensed under the **GNU General Public License, Version 3**.

You may use, modify, and redistribute it under the terms of GPLv3.
Derivative works must remain licensed under GPLv3 and provide source code accordingly.
