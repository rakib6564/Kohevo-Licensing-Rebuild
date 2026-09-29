# Kohevo Studio — Open Questions & Approval Gates

**Status:** Open / approval required  
**Date:** 2026-09-30

This document deliberately separates questions that must be answered by the project owner from choices that can be made safely during implementation.

## MUST ASK — product/architecture decisions

### Q1. Commercial module identity

**Recommendation:**

- product: `Kohevo Studio`
- module key: `studio-builder`
- plugin slug: `studio-builder`

**Why:** avoids reusing the legacy/future `editor` key and avoids collision with the existing dance/arts Studio domain.

**Approval:** Required before licensing implementation.

### Q2. Site cardinality

Should the first Studio release support:

A. one default website/site context per tenant; or  
B. multiple websites/sites per tenant from day one?

**Recommendation:** A for MVP unless a current customer/product requirement already demands B.

This affects page addressing, domains, templates, navigation, and cache keys.

### Q3. Canonical document version

Should the target canonical document be ratified as a new schema-2 contract derived from the old V2 prototype, or should the project intentionally evolve the old schema-1 contract while adding only approved extensions?

**Recommendation:** a new production schema-2 contract, with explicit v1 → v2 upconversion where legacy data must be preserved.

Reason: the old v1 shape is too limited for nested blocks, responsive data, bindings, visibility, and richer layout semantics; the old v2 code was not fully wired into persistence/validation.

### Q4. Dormant content tables

Do the current LIVE snapshots / production databases contain any real content rows in:

- `content_pages`
- `content_revisions`
- `contentbuilder_*`
- `document_templates`
- `content_compilations`
- `content_dependencies`

that must be preserved?

**Why it matters:** this determines whether Studio can cleanly evolve the dormant schema or needs a compatibility adapter/migration plan.

### Q5. Reusable section semantics

Should a reusable section be:

A. copied into a page and then independently editable; or  
B. linked to a global source so one edit affects all consumers?

**Recommendation:** support both concepts eventually but name them differently. Use **Pattern/Template** for copy semantics and **Global Component** for reference semantics.

### Q6. Global component scope

Should global components be:

A. tenant-wide;  
B. site-specific; or  
C. both, with explicit scope?

This impacts permission, caching, and invalidation.

### Q7. Raw HTML/CSS/JS policy

Should Studio offer any raw-code escape hatch in V1?

**Recommendation:** raw HTML may be a privileged feature later; raw JavaScript should not be part of normal V1 authoring. Arbitrary CSS should be restricted.

### Q8. AI publish policy

Should AI be allowed to publish directly when the actor has `studio.publish`, or must AI-created changes always require explicit human approval before publication?

**Recommendation:** require an explicit approval step for the initial release.

### Q9. Initial import scope

Which import should be commercial MVP?

**Recommendation:** Kohevo Studio JSON/template import first, then constrained HTML, then React/Next/v0/Figma adapters.

### Q10. Third-party editor choice

**Recommendation:** custom React shell + dnd-kit + Lexical.  
**Fallback:** Puck adapter.  
**Avoid as canonical model:** GrapesJS project JSON.

Approval is required before UI implementation.

---

## SHOULD ASK — important but can be decided during design review

### Q11. Document ID format

Use UUID-like opaque IDs, ULIDs, or another stable format?

### Q12. Autosave semantics

Continuous debounced draft snapshot vs explicit save events?

### Q13. Revision granularity

Every meaningful command vs periodic/coalesced snapshots?

### Q14. Publishing model

Single published revision per page vs separately versioned locale/site releases?

### Q15. SEO field scope

Start with title/description/canonical/robots/social image or include structured data and sitemap settings immediately?

### Q16. Localization model

Independent revision per locale or translation groups with a shared source-locale graph?

### Q17. Preview architecture

Same-origin iframe vs isolated origin/subdomain for stronger containment?

### Q18. Dynamic data caching

Per-request resolution only in MVP vs dependency-aware cache for known providers?

### Q19. Theme token ownership

Which tokens are core platform tokens vs tenant-editable design tokens?

### Q20. Page templates vs site templates

Do header/footer/navigation belong to the template system, site theme, or dedicated global components?

---

## CAN DECIDE during implementation

### Q21. Internal class/file layout

Exact PHP service names and namespaces can follow the existing architecture after the domain contract is approved.

### Q22. React state library

A small local store, Zustand, or another established approach can be selected after the canonical API contract is frozen. The choice must not change the persisted document model.

### Q23. UI component library

The editor can reuse an existing Kohevo UI vocabulary or add a small scoped component layer. This is visual implementation detail, not domain architecture.

### Q24. Cache implementation details

Exact cache keys, invalidation helpers, and storage adapters can be chosen after the public render contract is fixed.

### Q25. Test fixture format

JSON fixtures are preferred for canonical documents, but the exact directory/fixture naming can be decided during implementation.

## Known conflicts to resolve before coding

| Conflict | Evidence | Resolution needed |
|---|---|---|
| Old editor removed vs old snapshot contains editor | GitHub `8089080` vs LIVE snapshot | Treat snapshot implementation as historical, not current runtime |
| `editor`/`content` future vs new commercial Studio | Central `ModuleCatalog` | Create distinct commercial key |
| v1 canonical model vs historical v2 prototype | Snapshot `DocumentValidator` / `DocumentSchemaV2` | Ratify one production schema |
| Dormant content migrations vs no active runtime | `0019`, `0020`, `0018` remain; runtime removed | Decide reuse/evolution/compatibility |
| Legacy block hooks vs new Studio block contract | Booking/Forms/Membership `renderContentBlock()` | Create adapter/provider boundary |
| Missing SEO runtime | `.gitkeep` contract/service paths | Define Studio SEO service |
| AI/MCP already exists but Studio does not | MCP Gateway + business MCP handlers | Route all Studio mutation through commands |

## Approval checklist

Before Phase 1 implementation, approval should cover at minimum:

- [ ] `studio-builder` module key and slug
- [ ] one-site vs multi-site
- [ ] production document schema strategy
- [ ] dormant content data migration/reuse strategy
- [ ] global component semantics
- [ ] third-party editor stack
- [ ] AI publish policy
- [ ] MVP import scope

