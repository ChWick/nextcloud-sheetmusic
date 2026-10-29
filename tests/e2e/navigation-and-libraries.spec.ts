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

		// Navigate to Integrity Audit
		await nav.locator('.app-navigation-entry__name').getByText('Integrity Audit', { exact: true }).click()
		await expect(page).toHaveURL(/#\/library\/1\/integrity/)
		await expect(page.getByRole('heading', { name: 'Integrity Audit' })).toBeVisible()

		// Navigate back to Songs
		await nav.locator('.app-navigation-entry__name').getByText('Songs', { exact: true }).click()
		await expect(page).toHaveURL(/#\/library\/1\/songs/)
		await expect(page.getByRole('heading', { name: 'Songs' })).toBeVisible()
	})

	test('opens and interacts with Add Library dialogs', async ({ page }) => {
		// Open footer actions menu
		await page.getByRole('button', { name: 'Add library' }).click()

		// Click Import MobileSheets library
		await page.getByText('Import MobileSheets library').click()

		// Verify dialog appears
		await expect(page.getByText('Select a mobilesheets.db file from your Nextcloud files')).toBeVisible()

		// Close dialog
		await page.getByRole('button', { name: 'Cancel' }).click()
		await expect(page.getByText('Select a mobilesheets.db file from your Nextcloud files')).not.toBeVisible()

		// Open footer actions menu again
		await page.getByRole('button', { name: 'Add library' }).click()

		// Click Add Sheet Music library
		await page.getByText('Add Sheet Music library').click()

		// Verify folder add dialog appears
		await expect(page.getByText('Select a folder from your Nextcloud files. Sheet music files will be imported.')).toBeVisible()

		// Close dialog
		await page.getByRole('button', { name: 'Cancel' }).click()
		await expect(page.getByText('Select a folder from your Nextcloud files. Sheet music files will be imported.')).not.toBeVisible()
	})

	test('switches library via select dropdown and directly opens it', async ({ page }) => {
		// Verify currently on library 1
		await expect(page).toHaveURL(/#\/library\/1\/songs/)

		// Select dropdown should have Secondary.db (id: 2)
		const select = page.locator('select')
		await expect(select).toBeVisible()
		await select.selectOption('2')

		// Should immediately navigate and open library 2
		await expect(page).toHaveURL(/#\/library\/2\/songs/)
	})
})
