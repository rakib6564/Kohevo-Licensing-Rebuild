Synthetic Deliverect-shaped order webhooks for RestaurantDeliverectTest.

They follow the field names in Deliverect's public POS order docs
(developers.deliverect.com, "Glossary - POS Orders", checked 2 Nov 2026).
Every name, phone, address and ID here is made up; none comes from a real
Deliverect account or a real customer.

- order-delivery.json: a paid delivery order, courier from the marketplace,
  channel fees, tips and a discount (the food total is 44.00).
- order-pickup.json: an unpaid pickup order with a nested sub-item ordered
  twice, which our lines can hold only once (so the totals differ).
- order-unmapped.json: a pickup order with a PLU nobody has mapped.
- cancel.json: the channel cancelling order-delivery.json (status 100).
