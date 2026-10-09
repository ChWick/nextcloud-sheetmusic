import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Integrity Audit Flow', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/integrity')
	})

	test('renders integrity audit and auto-fixes a single invalid naming rule issue with toast status', async ({ page }) => {
		// Verify heading and group
		await expect(page.getByRole('heading', { name: 'Integrity Audit' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Invalid Naming Rules' })).toBeVisible()
		await expect(page.getByText('Symphony No. 5')).toBeVisible()

		// Locate Auto-Fix Issue button
		const autoFixBtn = page.getByRole('button', { name: 'Auto-Fix Issue' })
		await expect(autoFixBtn).toBeVisible()
		await autoFixBtn.click()

		// Toast notification with song title should be visible
		await expect(page.getByText(/Naming rule applied for "Symphony No. 5"/i)).toBeVisible()

		// Issue should be removed and clean notice displayed
		await expect(page.getByText('All System Checks Passed!')).toBeVisible()
	})

	test('renders batch fix in group and shows status toast', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Integrity Audit' })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Invalid Naming Rules' })).toBeVisible()

		const fixAllBtn = page.getByRole('button', { name: /Fix All in Group/i })
		await expect(fixAllBtn).toBeVisible()
		await fixAllBtn.click()

		// Toast notification should appear
		await expect(page.getByText(/Fixed 1 integrity issue/i).first()).toBeVisible()

		// Clean notice displayed
		await expect(page.getByText('All System Checks Passed!')).toBeVisible()
	})
})
