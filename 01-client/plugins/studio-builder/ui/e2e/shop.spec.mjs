// The Shop storefront in Kohevo Studio: a visitor adds products to the cart on a product page (Single template with
// the Products condition), changes the cart, checks out as a guest and pays (through the sandbox's fake Stripe, which
// sends the browser straight back as paid), and lands on the order confirmation; the admin sees the order, and the
// stock has gone down. The sandbox provisioning (e2e/provision.php) made the products, the shop pages and the template.
import { test, expect } from '@playwright/test';
import { openBuilder, settled } from './helpers.mjs';

test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop', 'one viewport is enough; run once');
});

test('a guest adds to cart, checks out, pays and sees the order; the admin sees it and the stock went down', async ({ page, browser }) => {
  const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const v = await visitor.newPage();
  try {
    // A product page: its widgets show the product; Add to cart answers in place.
    await v.goto('/product/club-t-shirt');
    await expect(v.locator('h1.sb-shop-title')).toHaveText('Club T-shirt');
    await expect(v.locator('.sb-shop-price__sale')).toContainText('19.90');
    await expect(v.locator('.sb-shop-price__regular')).toContainText('25.00');
    await v.locator('.sb-shop-add__qty').fill('2');
    await v.getByRole('button', { name: 'Add to cart' }).click();
    await expect(v.locator('.sb-shop-add__message')).toHaveText('Added to your cart.');

    // Stock caps what can be added; a sold-out product cannot be added at all.
    await v.goto('/product/training-gloves');
    await expect(v.locator('.sb-shop-stock')).toHaveText('Only 2 left');
    await v.locator('.sb-shop-add__qty').fill('1');
    await v.getByRole('button', { name: 'Add to cart' }).click();
    await expect(v.locator('.sb-shop-add__message')).toHaveText('Added to your cart.');
    await v.goto('/product/sold-out-cap');
    await expect(v.locator('.sb-shop-add--sold-out')).toContainText('Sold out');
    await expect(v.getByRole('button', { name: 'Add to cart' })).toHaveCount(0);

    // The Cart page fills itself with this visitor's cart; a quantity changes in place.
    await v.goto('/cart');
    const cart = v.locator('.sb-shop-cart-block');
    await expect(cart.locator('.sb-shop-cart__line')).toHaveCount(2);
    await expect(cart.locator('.sb-shop-cart__subtotal')).toContainText('88.80');
    const tee = cart.locator('.sb-shop-cart__line', { hasText: 'Club T-shirt' });
    await tee.locator('.sb-shop-cart__qty-input').fill('3');
    await tee.getByRole('button', { name: 'Update' }).click();
    await expect(cart.locator('.sb-shop-notice')).toHaveText('Your cart was updated.');
    await expect(cart.locator('.sb-shop-cart__subtotal')).toContainText('108.70');
    await expect(cart.locator('.sb-shop-cart__free')).toHaveCount(0);

    // Checkout: errors come back beside the fields; then the guest's details and a delivery address.
    await cart.getByRole('link', { name: 'Proceed to checkout' }).click();
    await expect(v).toHaveURL(/\/checkout$/);
    const checkout = v.locator('.sb-shop-checkout-block');
    await expect(checkout.locator('.sb-shop-summary__total')).toContainText('108.70');
    await checkout.getByRole('button', { name: 'Continue to payment' }).click();
    await expect(checkout.locator('#sb-shop-err-email')).toHaveText('Enter a valid email address.');
    await checkout.getByLabel('Full name').fill('Ana Dupont');
    await checkout.getByLabel('Email').fill('ana@example.test');
    await checkout.getByLabel(/Ship to my address/).check();
    await checkout.getByLabel('Street and number').fill('Rue Haute 1');
    await checkout.getByLabel('Postal code').fill('1000');
    await checkout.getByLabel('Town or city').fill('Bruxelles');
    await checkout.getByLabel('Country').selectOption('BE');
    await checkout.getByRole('button', { name: 'Continue to payment' }).click();

    // Stripe (the fake) sends the visitor back paid; the order-received page shows the order.
    await expect(v).toHaveURL(/\/order-received\?order=KS-[A-Z0-9]{6}&key=[a-f0-9]{40}$/, { timeout: 20_000 });
    const order = v.locator('.sb-shop-order-block');
    await expect(order.locator('.sb-shop-order__status')).toHaveText('Paid');
    await expect(order.locator('.sb-shop-order__lead')).toContainText('Thank you, Ana Dupont');
    await expect(order.locator('.sb-shop-order__total')).toContainText('108.70');
    const ref = (await order.locator('.sb-shop-order__ref').textContent()).trim();

    // A wrong key shows nothing of the order.
    const wrong = new URL(v.url());
    wrong.searchParams.set('key', '0'.repeat(40));
    await v.goto(wrong.toString());
    await expect(v.locator('.sb-shop-order--missing')).toHaveText('This order link is not valid.');

    // The cart is empty again.
    await v.goto('/cart');
    await expect(v.locator('.sb-shop-cart__empty')).toHaveText('Your cart is empty.');

    // The admin sees the paid order, and one pair of gloves is left.
    await page.goto('/plugins/shop/admin/orders.php?status=paid');
    await expect(page.getByText(`${ref} · Ana Dupont`)).toBeVisible();
    await v.goto('/product/training-gloves');
    await expect(v.locator('.sb-shop-stock')).toHaveText('Only 1 left');
  } finally {
    await visitor.close();
  }
});

test('the Shop widgets are in Add › Components and Products is a display condition', async ({ page }) => {
  await openBuilder(page);
  await settled(page);
  const boot = await page.evaluate(() => JSON.parse(document.getElementById('sb-builder-boot').textContent));
  expect(boot.contentTypes).toEqual(expect.arrayContaining([{ key: 'product', label: 'Products' }]));
  const manifest = await page.evaluate(async () => {
    const b = JSON.parse(document.getElementById('sb-builder-boot').textContent);
    const u = new URL(b.apiUrl, location.href);
    u.searchParams.set('action', 'manifest');
    const r = await fetch(u, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return (await r.json()).data.manifest;
  });
  const shop = manifest.blocks.filter((b) => b.type.startsWith('shop.'));
  expect(shop.map((b) => b.type).sort()).toEqual(['shop.cart', 'shop.checkout', 'shop.mini_cart', 'shop.order_confirmation', 'shop.product_add_to_cart',
    'shop.product_description', 'shop.product_image', 'shop.product_meta', 'shop.product_price', 'shop.product_stock', 'shop.product_title', 'shop.products']);
  expect(shop.every((b) => !b.locked), 'licensed in the sandbox, so none is locked').toBe(true);
});
