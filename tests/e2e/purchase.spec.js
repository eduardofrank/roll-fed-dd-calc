// Golden-path smokes: configure → price → add to cart → cart line.
const { test, expect } = require('@playwright/test');

async function expectInCart(page, productName) {
  await page.waitForURL(/\/(cart|carrito)\/?/, { timeout: 20000 });
  await expect(page.getByText(productName).first()).toBeVisible({ timeout: 20000 });
  await expect(page.getByText(/\$\d+\.\d{2}/).first()).toBeVisible();
}

async function addToCartWhenPriced(page) {
  const btn = page.locator('#add-to-cart-primary-btn');
  await expect(btn).toContainText('$', { timeout: 15000 });
  await btn.click();
}

test('archival quote → cart', async ({ page }) => {
  await page.goto('/fine-art-calculator/');
  const root = page.locator('#root .fac');
  await expect(root).toBeVisible({ timeout: 20000 });

  await page.locator('#roll-btn-44').click();
  await page.getByRole('button', { name: /Hahnemühle/ }).first().click();
  await page.getByRole('button', { name: /Matt Smooth/ }).first().click();
  await page.locator('#paper-option-photo_rag').click();
  await page.locator('#dimension-width-input').fill('20');
  await page.locator('#dimension-height-input').fill('30');

  await addToCartWhenPriced(page);
  await expectInCart(page, 'Archival Print');
});

test('inkjet quote → cart', async ({ page }) => {
  await page.goto('/inkjet-calculator/');
  const root = page.locator('#root .fac');
  await expect(root).toBeVisible({ timeout: 20000 });

  await page.locator('#roll-btn-44').click();
  await page.locator('[id^="inkjet-category-btn-"]').first().click();
  const select = page.locator('#inkjet-paper-select');
  await select.selectOption({ index: 1 });
  await page.locator('#dimension-width-input').fill('16');
  await page.locator('#dimension-height-input').fill('20');

  await addToCartWhenPriced(page);
  await expectInCart(page, 'Inkjet Print');
});
