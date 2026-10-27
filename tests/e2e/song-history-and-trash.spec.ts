import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Song History Timeline & Trash Restoration', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
	})

	test('opens song history modal from song viewer, views revisions, and reverts revision', async ({ page }) => {
		// Auto-accept confirm dialog for revert
		page.on('dialog', async (dialog) => {
			expect(dialog.message()).toContain('Are you sure you want to revert this song')
			await dialog.accept()
		})

		await page.goto('/#/library/1/songs?song=5')

		// Wait for song viewer to load Symphony No. 5
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()

		// Click the "Song history & revisions" button in the viewer header
		const historyBtn = page.getByRole('button', { name: 'Song history & revisions' }).first()
		await expect(historyBtn).toBeVisible()
		await historyBtn.click()

		// Verify the Song History modal dialog opens
		await expect(page.getByText('Song History & Revisions: Symphony No. 5')).toBeVisible()

		// Verify timeline items are present
		await expect(page.getByText('Current', { exact: true })).toBeVisible()
		await expect(page.getByText(/Title: "Symphony No. 5 in C Minor"/i)).toBeVisible()
		await expect(page.getByText('Song created')).toBeVisible()

		// Click "Show details" on the older revision
		const toggleDetailsBtn = page.getByRole('button', { name: 'Show details' }).first()
		await expect(toggleDetailsBtn).toBeVisible()
		await toggleDetailsBtn.click()

		// Verify snapshot details are displayed
		await expect(page.getByText('Symphony No. 5 in C Minor')).toBeVisible()

		// Click "Revert" on the older revision
		const revertBtn = page.getByRole('button', { name: 'Revert' }).first()
		await expect(revertBtn).toBeVisible()
		await revertBtn.click()

		// Modal should close upon successful revert
		await expect(page.getByText('Song History & Revisions: Symphony No. 5')).not.toBeVisible()
	})

	test('opens song history modal from song editor', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5')

		// Enter song editor
		await page.getByRole('button', { name: 'Edit song' }).click()
		await expect(page.locator('#song-title-input')).toBeVisible()

		// Click "Song history & revisions" button in editor header
		const historyBtn = page.getByRole('button', { name: 'Song history & revisions' }).first()
		await expect(historyBtn).toBeVisible()
		await historyBtn.click()

		// Modal should open
		await expect(page.getByText('Song History & Revisions: Symphony No. 5')).toBeVisible()
		await expect(page.getByText(/Title: "Symphony No. 5 in C Minor"/i)).toBeVisible()

		// Close modal by pressing Escape
		await page.keyboard.press('Escape')
		await expect(page.getByText('Song History & Revisions: Symphony No. 5')).not.toBeVisible()

		// Still in editor
		await expect(page.locator('#song-title-input')).toBeVisible()
	})

	test('navigates to Trash, views deleted songs, and restores a deleted song', async ({ page }) => {
		await page.goto('/#/library/1/songs')

		// Find and click Trash in sidebar navigation
		const nav = page.locator('.app-navigation, [role="navigation"]')
		await expect(nav).toBeVisible()
		await nav.locator('.app-navigation-entry__name').getByText('Trash', { exact: true }).click()

		// Verify navigation to trash route
		await expect(page).toHaveURL(/#\/library\/1\/trash/)
		await expect(page.getByRole('heading', { name: 'Trash / Deleted Songs' })).toBeVisible()

		// Verify Moonlight Sonata appears in deleted songs table
		const tableBody = page.locator('tbody')
		await expect(tableBody.getByText('Moonlight Sonata')).toBeVisible()
		await expect(tableBody.getByText('Ludwig van Beethoven')).toBeVisible()

		// Click Restore button
		const restoreBtn = page.getByRole('button', { name: 'Restore' })
		await expect(restoreBtn).toBeVisible()
		await restoreBtn.click()

		// Song should be removed from deleted songs table, showing empty state
		await expect(tableBody.getByText('Moonlight Sonata')).not.toBeVisible()
		await expect(page.getByText('Trash is empty')).toBeVisible()
	})
})
