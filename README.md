# MissionBayIlias

> **License:** GNU GPL v3
>
> **Namespace:** `MissionBayIlias`
>
> **Runtime:** BASE3 Framework (ILIAS integration)

MissionBayIlias provides the **ILIAS-specific extraction, normalization, and embedding integration** for the MissionBay ecosystem.

The project **directly feeds ILIAS content into the MissionBay / BASE3 agent-based embedding pipeline**, without intermediate indexing layers.

This repository documents the **current, effective architecture** as it is implemented and operated.

---

## Core Goals

* Reliable **incremental embedding of ILIAS content**
* Minimal and deterministic processing pipeline
* Clear separation between:

  * content discovery
  * embedding execution
  * vector storage

---

## Architectural Position

```
ILIAS Database
      │
      ▼
MissionBayIlias Content Providers
      │
      ▼
MissionBay Agent Flow
      │
      ▼
Vector Store (e.g. Qdrant)
```

MissionBayIlias is responsible exclusively for **ILIAS-aware content discovery and normalization**.

---

## Content Providers

Each ILIAS content type is handled by a **dedicated provider** implementing `IContentProvider`.

Providers:

* operate directly on ILIAS schemas
* detect changes via database-level indicators
* emit embedding jobs directly

Implemented providers include:

* `WikiPageContentProvider`

  * Extracts individual wiki pages
  * Page-level change detection

* `WikiContentProvider`

  * Extracts the wiki object itself (parent)
  * Low textual volume, structurally relevant

Providers are:

* incremental (cursor-based)
* independent and toggleable
* embedding-agnostic

---

## Queueing & Change Detection

Content synchronization is handled **directly against the embedding queue**.

Detection logic:

* new or changed content → `upsert`
* missing or deleted content → `delete`

State is persisted via:

* `base3_embedding_seen`
* BASE3 `IStateStore`

This ensures deterministic replay and safe restarts.

---

## Embedding Flow Integration

MissionBayIlias integrates natively with the existing **MissionBay agent-based embedding flow**.

Key components:

* `IliasEmbeddingQueueExtractorAgentResource`

  * Claims embedding jobs
  * Loads content on demand
  * Resolves ACLs and tree placement

* `IliasAgentRagPayloadNormalizer`

  * Produces Qdrant-ready payloads
  * Adds filterable metadata:

    * roles
    * ancestor ref IDs
    * collection keys

Collection configuration:

* Logical key: `ilias`
* Physical collection: `base3ilias_content_v1`

---

## ILIAS Tree Awareness

ILIAS objects may be mounted multiple times in the tree.

MissionBayIlias provides:

* `IObjectTreeResolver`
* `IliasObjectTreeResolver`

Capabilities:

* resolve all `ref_id`s for an `obj_id`
* compute merged ancestor ref sets

Enables:

* subtree-scoped retrieval
* container-based filtering

---

## Design Principles

* Single, direct embedding pipeline
* No redundant lifecycle tracking
* Explicit state handling
* Replayable embedding jobs
* Minimal surface area

The embedding queue is the **single operational backbone**.

---

## Relationship to MissionBay XRM

MissionBayIlias is a **sibling implementation** of MissionBay XRM.

Shared:

* agent-based embedding flow
* queue semantics
* vector storage model

Different:

* content discovery
* ACL and tree resolution

---

## Current Status

✅ Direct embedding workflow active
✅ Wiki page + wiki parent providers live
✅ Qdrant payloads verified
✅ Tree-aware filtering functional

The system is **lean, deterministic, and production-ready**.

---

## Open Follow-ups

* Additional ILIAS content types (Glossary, Files, SCORM)
* Performance tuning for large wiki trees
* Optional batching strategies
* ACL caching strategies

All follow-ups are **explicitly optional**.

---

## License

GNU General Public License v3

This project is free software: you can redistribute it and/or modify it under the terms of GPLv3.

Derivative works must remain GPLv3-compatible.
