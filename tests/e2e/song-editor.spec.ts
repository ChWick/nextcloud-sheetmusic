import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Song Editor UI & Action Buttons', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/songs?song=5')
		// Open editor
		await page.getByRole('button', { name: 'Edit song' }).click()
	})

	test('renders icon-only action buttons for file attachments and form actions', async ({ page }) => {
		// File attachment action buttons
		const uploadBtn = page.getByRole('button', { name: 'Upload file from computer' })
		const chooseNcBtn = page.getByRole('button', { name: 'Choose file from Nextcloud' })

		await expect(uploadBtn).toBeVisible()
		await expect(chooseNcBtn).toBeVisible()
		await expect(uploadBtn).toHaveAttribute('title', 'Upload file from computer')
		await expect(chooseNcBtn).toHaveAttribute('title', 'Choose file from Nextcloud')
		await expect(uploadBtn.locator('svg')).toBeVisible()
		await expect(chooseNcBtn.locator('svg')).toBeVisible()

		// Attached file item buttons (SongFileListItem)
		const fileItemOpenBtn = page.getByRole('button', { name: /Open .* in external viewer/i })
		const fileItemDeleteBtn = page.getByRole('button', { name: /Delete file .*/i })

		await expect(fileItemOpenBtn).toBeVisible()
		await expect(fileItemDeleteBtn).toBeVisible()
		await expect(fileItemOpenBtn).toHaveAttribute('title', 'Open in external viewer')
		await expect(fileItemDeleteBtn).toHaveAttribute('title', 'Delete file from storage')
		await expect(fileItemOpenBtn.locator('svg')).toBeVisible()
		await expect(fileItemDeleteBtn.locator('svg')).toBeVisible()

		// Form bottom action buttons
		const formActions = page.locator('form')
		const cancelBtn = formActions.getByRole('button', { name: 'Cancel' })
		const saveKeepOpenBtn = formActions.getByRole('button', { name: 'Save without closing' })
		const saveCloseBtn = formActions.getByRole('button', { name: 'Save and Close' })

		await expect(cancelBtn).toBeVisible()
		await expect(saveKeepOpenBtn).toBeVisible()
		await expect(saveCloseBtn).toBeVisible()

		await expect(cancelBtn).toHaveAttribute('title', 'Cancel')
		await expect(saveKeepOpenBtn).toHaveAttribute('title', 'Save without closing')
		await expect(saveCloseBtn).toHaveAttribute('title', 'Save and Close')

		await expect(cancelBtn.locator('svg')).toBeVisible()
		await expect(saveKeepOpenBtn.locator('svg')).toBeVisible()
		await expect(saveCloseBtn.locator('svg')).toBeVisible()
	})

	test('cancels song editor and returns to viewer', async ({ page }) => {
		const cancelBtn = page.getByRole('button', { name: 'Cancel' })
		await cancelBtn.dispatchEvent('click')

		// Should exit editor back to song viewer
		await expect(page.getByRole('button', { name: 'Edit song' })).toBeVisible()
	})
})
