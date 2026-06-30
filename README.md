# AIKO

## AI Knowledge Observatory Markup (AIKO-UP)

> A lightweight knowledge markup for AI agents.

The web already has markup for browsers.

HTML describes presentation.

Markdown simplifies writing.

Schema.org describes entities.

**AIKO describes knowledge structure.**

It does not replace HTML.

It helps AI understand a document **before parsing it**.

---

## Philosophy

AIKO is **not** another sitemap.

AIKO is **not** another RSS feed.

AIKO is **not** another search index.

AIKO publishes **knowledge structure**, not content.

Its purpose is simple:

> Allow an AI agent to decide, within milliseconds, whether a document is worth reading further.

---

## Design Principles

AIKO is intentionally minimal.

Every field must answer one question:

> "Would an AI have to download and parse the full HTML if this field were missing?"

If the answer is **No**, the field does **not** belong in AIKO.

> AIKO-UP intentionally exposes very few extension points. If an extension cannot be achieved through aiko_document, it probably belongs in the AI agent rather than the protocol.

---

## What AIKO Contains

AIKO intentionally exposes only a lightweight **Knowledge Skeleton**, including:

- Document title
- Description
- Canonical URL
- Structural outline (H1/H2/H3)
- Semantic sections
- Knowledge components (diagram, table, math, canvas, code, FAQ, video, etc.)

It never publishes article content.

It only publishes the information an AI needs to decide **whether to continue reading**.

---

## Design Goal

AIKO does **not** optimize search engines.

AIKO does **not** replace HTML.

AIKO exists to reduce unnecessary parsing, bandwidth, and token consumption for AI agents.

In short:

> HTML tells browsers how to render a page.

> AIKO tells AI how knowledge is organized inside it.

> HTML is markup for browsers.

> AIKO is markup for AI.
