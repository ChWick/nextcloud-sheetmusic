import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

const viewports = [
	{ name: 'Desktop', width: 1280, height: 800 },
	{ name: 'Tablet', width: 768, height: 1024 },
	{ name: 'Mobile', width: 375, height: 667 },
]

test.describe('Cross-Browser & Multi-Viewport Layout Integrity', () => {
	viewports.forEach(({ name, width, height }) => {
		test(`layout integrity at ${name} (${width}x${height})`, async ({ page }) => {
			await page.setViewportSize({ width, height })
			await setupMockApi(page)
			await page.goto('/#/library/1/songs')

			// Filter bar should be visible and maintain container boundaries
			const showFiltersBtn = page.getByRole('button', { name: 'Show filters' })
			if (await showFiltersBtn.isVisible()) {
				await showFiltersBtn.click()
			}
			const filterBar = page.locator('[role="region"][aria-label="Song filters"]')
			await expect(filterBar).toBeVisible()

			const filterBox = await filterBar.boundingBox()
			expect(filterBox).not.toBeNull()
			if (filterBox) {
				// Filter bar should fit within the page viewport width with padding
				expect(filterBox.x).toBeGreaterThanOrEqual(0)
				expect(filterBox.width).toBeLessThanOrEqual(width)
			}

			// Open song editor to verify form controls layout
			await page.goto('/#/library/1/songs?song=5')
			await page.getByRole('button', { name: 'Edit song' }).click()

			const titleInput = page.getByLabel('Song Title')
			await expect(titleInput).toBeVisible()
			const titleBox = await titleInput.boundingBox()
			expect(titleBox).not.toBeNull()
			if (titleBox) {
				expect(titleBox.width).toBeGreaterThan(0)
				expect(titleBox.x + titleBox.width).toBeLessThanOrEqual(width)
			}
		})
	})
})
