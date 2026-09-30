import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Entities Views (Composers, Books, Collections, Artists)', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
	})

	test('renders Composers view list and opens create composer dialog', async ({ page }) => {
		await page.goto('/#/library/1/composers')
		await expect(page.getByRole('heading', { name: 'Composers' })).toBeVisible()

		// Verify list contains mock composers
		await expect(page.getByRole('button', { name: 'Ludwig van Beethoven' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Wolfgang Amadeus Mozart' })).toBeVisible()

		// Open New Composer dialog
		await page.getByRole('button', { name: /New composer/i }).click()
		await expect(page.getByPlaceholder(/Enter a name/i)).toBeVisible()

		// Fill name input
		await page.getByPlaceholder(/Enter a name/i).fill('Johann Sebastian Bach')
		await page.getByRole('button', { name: 'Cancel' }).click()
	})

	test('clicking composer item filters songs by composer in Songs view', async ({ page }) => {
		await page.goto('/#/library/1/composers')

		// Click Ludwig van Beethoven
		await page.getByRole('button', { name: 'Ludwig van Beethoven' }).click()

		// Should navigate to songs view with composer query parameter
		await expect(page).toHaveURL(/#\/library\/1\/songs\?composers=1/)
		await expect(page.getByRole('heading', { name: 'Songs' })).toBeVisible()
	})

	test('renders Books view and list items', async ({ page }) => {
		await page.goto('/#/library/1/books')
		await expect(page.getByRole('heading', { name: 'Books' })).toBeVisible()

		// Verify list contains mock books
		await expect(page.getByRole('button', { name: 'Real Book Vol 1' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Classical Masters' })).toBeVisible()
	})
})
