import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Navigation & Library Management', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/songs')
	})

	test('navigates between all app section views from sidebar', async ({ page }) => {
		const nav = page.locator('.app-navigation, [role="navigation"]')
		await expect(nav).toBeVisible()

		// Navigate to Composers
		await nav.locator('.app-navigation-entry__name').getByText('Composers', { exact: true }).click()
		await expect(page).toHaveURL(/#\/library\/1\/composers/)
		await expect(page.getByRole('heading', { name: 'Composers' })).toBeVisible()

		// Navigate to Books
		await nav.locator('.app-navigation-entry__name').getByText('Books', { exact: true }).click()
		await expect(page).toHaveURL(/#\/library\/1\/books/)
		await expect(page.getByRole('heading', { name: 'Books' })).toBeVisible()

		// Navigate to Collections
		await nav.locator('.app-navigation-entry__name').getByText('Collections', { exact: true }).click()
		await expect(page).toHaveURL(/#\/library\/1\/collections/)
		await expect(page.getByRole('heading', { name: 'Collections' })).toBeVisible()

		// Navigate to Artists
		await nav.locator('.app-navigation-entry__name').getByText('Artists', { exact: true }).click()
		await expect(page).toHaveURL(/#\/library\/1\/artists/)
		await expect(page.getByRole('heading', { name: 'Artists' })).toBeVisible()

		// Navigate to Files Sync
		await nav.locator('.app-navigation-entry__name').getByText('Files', { exact: true }).click()
		await expect(page).toHaveURL(/#\/library\/1\/files/)
		await expect(page.getByRole('heading', { name: 'Files' })).toBeVisible()

		// Navigate back to Songs
		await nav.locator('.app-navigation-entry__name').getByText('Songs', { exact: true }).click()
		await expect(page).toHaveURL(/#\/library\/1\/songs/)
		await expect(page.getByRole('heading', { name: 'Songs' })).toBeVisible()
	})

	test('opens and interacts with Add Library dialog', async ({ page }) => {
		page.on('console', msg => console.log('BROWSER LOG:', msg.text()))
		// Click the Add MobileSheets Library footer item
		await page.getByText('Add MobileSheets Library').click()

		// Verify dialog appears
		await expect(page.getByText('Select a MobileSheets .db file from your Nextcloud files.')).toBeVisible()

		// Close dialog
		await page.getByRole('button', { name: 'Cancel' }).click()
		await expect(page.getByText('Select a MobileSheets .db file from your Nextcloud files.')).not.toBeVisible()
	})
})
