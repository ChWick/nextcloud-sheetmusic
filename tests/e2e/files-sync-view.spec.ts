import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Files View & Sync Tooling', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/files')
	})

	test('renders files sync card and executes library sync', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Files' })).toBeVisible()

		// Locate run sync button
		const runSyncBtn = page.getByRole('button', { name: 'Check and sync now' })
		await expect(runSyncBtn).toBeVisible()
		await runSyncBtn.click()

		// Sync report dialog should open
		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible()

		// Verify sync log entries
		await expect(page.getByText('Checked 1 file for Symphony No. 5.')).toBeVisible()
		await expect(page.getByText('Found extra file path Requiem.pdf.')).toBeVisible()
		await expect(page.getByText('Missing file on disk: Beethoven - Missing.pdf')).toBeVisible()

		// Filter log levels to "Errors only"
		await page.getByRole('button', { name: 'Errors only' }).click()

		// Info level should be hidden, error level visible
		await expect(page.getByText('Checked 1 file for Symphony No. 5.')).not.toBeVisible()
		await expect(page.getByText('Missing file on disk: Beethoven - Missing.pdf')).toBeVisible()

		// Close dialog
		const closeBtn = dialog.locator('button').filter({ hasText: /Close/i }).first()
		await closeBtn.click()
		await expect(dialog).not.toBeVisible()
	})
})
