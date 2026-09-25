# ADR-0014 — Structured Builder Contracts and Derived HTML

**Status:** Accepted  
**Date:** 2026-09-13

## Context

Slate is adding a React drag-and-drop builder to the existing `DocumentSchema` v1 content model. The builder must not create a second persistence format, leak Craft.js/dnd-kit state into documents, or create a second rendering path. Global header/footer blocks also need tenant-scoped references and dependency invalidation. Public delivery requires a derived `content_html` artifact without making that artifact the source of truth.

The repository discipline requires additive, contract-preserving changes, PSR-4 classes under `src/`, pure value transformations, tenant-safe boundaries, and an ADR for structural decisions.

## Decision

1. `DocumentSchema` v1 remains the only persisted document format: `Page → Section → Block`.
2. The React bridge serializes editor state into canonical document arrays and strips transient editor properties before validation.
3. Immutable document operations use section/block paths that match validator error paths.
4. The block registry exposes a transport-safe metadata projection built from `Block::type()` and `FieldSchema`; PHP render logic is never serialized to the client.
5. `content_html` is a derived, fingerprinted artifact compiled after canonical normalization during save/publish. It is served for public requests only when its dependency fingerprint matches; it is never accepted as authoritative input.
6. Global block references remain tenant-scoped and are dependencies of affected compiled artifacts.
7. Persistence, revisions, authorization, tenant resolution, and cache invalidation remain application-service responsibilities. Presentation value objects and pure contracts do not run SQL or mutate global state.

## Alternatives

### Store a React/Craft.js document

Rejected because it would create a second source of truth and make server rendering dependent on editor implementation details.

### Persist compiled HTML as canonical content

Rejected because HTML cannot safely represent typed fields, revisions, responsive tokens, or block migrations.

### Let the client enforce block schemas

Rejected because browser validation is bypassable and cannot enforce tenant or permission boundaries.

### Add database writes directly to the presentation layer

Rejected because it violates the repository’s service/repository ownership rules and makes pure render/test contracts non-deterministic.

## Consequences

Positive consequences are a single document contract, deterministic previews, safe transport metadata, exact error paths, and precise invalidation dependencies. The accepted cost is that save/publish services must orchestrate normalization, compilation, revision storage, and invalidation as one transaction-aware workflow. The React UI still needs an adapter, but it can be implemented without changing the stored document model.

## Related

- [ADR-0007 — Section/Block before Page Builder](0007-section-block-before-page-builder.md)
- [ADR-0008 — One design-token vocabulary](0008-one-design-token-vocabulary.md)
- [Phase 2 structured builder specification](../PHASE-2-STRUCTURED-REACT-BUILDER-SCHEMA.md)
- [DocumentSchema](../../src/Presentation/DocumentSchema.php)
