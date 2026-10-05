import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Files View', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/files')
	})

	test('renders without the legacy sync tool section', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Files' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Check and sync now' })).toHaveCount(0)
		await expect(page.getByText('Sync file paths and metadata')).toHaveCount(0)
	})
})
