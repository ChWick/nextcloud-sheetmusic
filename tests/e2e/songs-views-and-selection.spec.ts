import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Songs View, View Modes & Batch Selection', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/songs')
	})

	test('renders song list in table view and allows filter bar toggling', async ({ page }) => {
		// Table view should list songs
		await expect(page.getByText('Symphony No. 5')).toBeVisible()
		await expect(page.getByText('Requiem in D minor')).toBeVisible()

		// Filter bar should be visible by default
		const filterBar = page.locator('[role="region"][aria-label="Song filters"]')
		await expect(filterBar).toBeVisible()

		// Toggle filter bar off
		const filterToggleBtn = page.getByRole('button', { name: 'Hide filters' })
		await expect(filterToggleBtn).toBeVisible()
		await filterToggleBtn.click()

		// Filter bar should be hidden
		await expect(filterBar).not.toBeVisible()
	})

	test('switches view mode between table, cards, and list', async ({ page }) => {
		// Default is table view
		await expect(page.locator('table')).toBeVisible()

		// Open view options popover inside header
		const toggleBtn = page.locator('[data-test="song-list-display-trigger"]')
		await toggleBtn.click()

		// Select Card view
		await page.getByRole('button', { name: 'Cards' }).click()
		await expect(page.locator('table')).not.toBeVisible()

		// Select List view
		await page.getByRole('button', { name: 'List' }).click()
		await expect(page.locator('table')).not.toBeVisible()
	})

	test('selects songs and opens batch song editor dialog', async ({ page }) => {
		// Select all songs via table header checkbox
		const selectAllCheckbox = page.locator('table th input[type="checkbox"]').first()
		await selectAllCheckbox.click()

		// Selection banner should display "2 songs selected"
		await expect(page.getByText(/2 songs selected/i)).toBeVisible()

		// Click Edit selected (2) button
		const editSelectedBtn = page.getByRole('button', { name: /Edit selected \(2\)/i })
		await expect(editSelectedBtn).toBeVisible()
		await editSelectedBtn.click()

		// Batch song editor dialog should open
		const dialog = page.locator('.modal-container')
		await expect(dialog).toBeVisible()
		await expect(dialog.getByText(/Edit 2 songs/i)).toBeVisible()

		// Cancel batch editor
		const cancelBtn = dialog.getByRole('button', { name: 'Cancel' })
		await cancelBtn.dispatchEvent('click')
		await expect(dialog).not.toBeVisible()

		// Clear selection
		await page.getByRole('button', { name: 'Clear selection' }).click()
		await expect(page.getByText(/2 songs selected/i)).not.toBeVisible()
	})
})
