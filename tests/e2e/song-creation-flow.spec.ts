import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Song Creation & End-to-End Editing Flow', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/songs')
	})

	test('opens new song form, fills details, and creates a song', async ({ page }) => {
		// Open Add or import song dropdown in ViewHeader
		const actionMenu = page.locator('[aria-label="Add or import song"], [title="Add or import song"]').first()
		await expect(actionMenu).toBeVisible()
		await actionMenu.click()

		// Click "New song" action item
		await page.getByText('New song').click()

		// Verify editor opens in creation mode
		const titleInput = page.locator('#song-title-input')
		await expect(titleInput).toBeVisible()
		await expect(page.getByRole('button', { name: 'Create song' })).toBeVisible()

		// Fill in title
		await titleInput.fill('Piano Sonata No. 14')

		// Save new song
		const createBtn = page.getByRole('button', { name: 'Create song' })
		await createBtn.dispatchEvent('click')

		// Should exit editor back to songs view
		await expect(page.getByRole('heading', { name: 'Songs' })).toBeVisible()
	})

	test('stages file deletion and restores it via pending notice', async ({ page }) => {
		// Open existing song in editor
		await page.goto('/#/library/1/songs?song=5')
		await page.getByRole('button', { name: 'Edit song' }).click()

		// Locate attached file list item delete button
		const deleteFileBtn = page.getByRole('button', { name: /Delete file .*/i })
		await deleteFileBtn.dispatchEvent('click')

		// Pending notice should appear with text "marked for removal"
		await expect(page.getByText(/marked for removal/i)).toBeVisible()

		// Click Restore button on pending notice
		const restoreBtn = page.getByRole('button', { name: 'Restore' })
		await expect(restoreBtn).toBeVisible()
		await restoreBtn.click()

		// File item delete button should be visible again
		await expect(deleteFileBtn).toBeVisible()
	})
})
