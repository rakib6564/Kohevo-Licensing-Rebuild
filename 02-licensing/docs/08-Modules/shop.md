# 08 — Shop Module

**Status:** Draft · **Applies to:** Slate v3.x (rebuilt on the spine)

## Purpose

E-commerce: products (with variants), categories, cart, checkout, orders,
coupons, and reports. A rebuild of today's mature `shop` plugin onto core
Identity, Payments (`Money`), and the render stack.

## Bounded context

**Commerce** ([02-Domain](../02-Domain/)).

## Consumes

| Service / capability | For |
|---|---|
| Identity | the buyer is a **Contact**; a shop profile keyed by `contact_id` |
| Payments (`PaymentGateway`) | checkout, refunds — via `Money` (fixes the DECIMAL split) |
| Shipping capability | rates at checkout (one active `ShippingRateProvider`) |
| Blocks | storefront blocks (product grid, product, cart) |
| Media | product imagery |

## Provides

- Storefront blocks; `order.placed`, `order.paid`, `order.refunded` events.
- (Optionally) a `catalog` capability other modules query.

## Owns

- `shop_products`, `_variants`, `_categories`, `_orders`, `_order_items`,
  `_coupons` (slug-prefixed).
- **Does NOT own:** the buyer (Contact + shop profile on `contact_id`, never
  `shop_customers`), payments (gateway + ledger), shipping calculation (the
  shipping module via the `ShippingRateProvider` capability).

## The two fixes this rebuild lands

1. **Money.** Product/order money moves from `DECIMAL` to the `Money` value object
   (integer minor units) — removing the float→cents conversion at every Stripe
   handoff ([ADR-0011](../14-ADR/0011-money-value-object.md)).
2. **Payment decoupling.** Checkout calls `PaymentGateway` with a generic context
   and reconciles by listening for `payment.succeeded` — the gateway no longer
   depends on the shop ([../07-API/payments.md](../07-API/payments.md)).

## Routes & admin

- Public: storefront (index, category, product, cart, checkout, order — order
  view-token protected against enumeration).
- Admin: products, categories, orders, coupons, reports, CSV import/export
  (formula-injection-safe).

## Integration events

- **Emits:** `order.paid` → receipt (Notifications), CRM activity, inventory,
  search index.
- **Subscribes:** `payment.succeeded` (reconcile order by `ref`),
  `contact.merged`.

## Shipping

Shipping is a separate module providing the `ShippingRateProvider` capability
(per-box or per-weight). Shop consumes whichever is active — exactly one — and the
conflict of two providers is resolved by the capability being singular.

---

## Related

- [../07-API/payments.md](../07-API/payments.md) · [notifications.md](notifications.md) · [search.md](search.md) · [../14-ADR/0011-money-value-object.md](../14-ADR/0011-money-value-object.md)
