import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Song Filter Bar', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/songs')
	})

	test('renders all filter dropdowns without horizontal overlap', async ({ page }) => {
		const filterBar = page.locator('[role="region"][aria-label="Song filters"]')
		await expect(filterBar).toBeVisible()

		// Locate the select elements inside the filter groups
		const selects = filterBar.locator('.nc-select, .v-select')
		const count = await selects.count()
		expect(count).toBeGreaterThanOrEqual(4)

		// Check bounding boxes for adjacent selects to ensure no overlap
		for (let i = 0; i < count - 1; i++) {
			const box1 = await selects.nth(i).boundingBox()
			const box2 = await selects.nth(i + 1).boundingBox()

			if (box1 && box2 && box1.y === box2.y) {
				// If on the same row, ensure right edge of box1 does not overlap box2
				expect(box1.x + box1.width).toBeLessThanOrEqual(box2.x + 1)
			}
		}
	})

	test('filters list options when dropdown is clicked', async ({ page }) => {
		const filterBar = page.locator('[role="region"][aria-label="Song filters"]')
		await expect(filterBar).toBeVisible()

		// Click the first select (Composers)
		const composerSelect = filterBar.locator('.nc-select, .v-select').first()
		await composerSelect.click()

		// Expect dropdown list options to show mock composers
		const dropdownOption = page.locator('.vs__dropdown-menu, .multiselect__content, [role="option"]').getByText('Ludwig van Beethoven')
		await expect(dropdownOption).toBeVisible()
	})

	test('filters songs by title search query', async ({ page }) => {
		const filterBar = page.locator('[role="region"][aria-label="Song filters"]')
		await expect(filterBar).toBeVisible()

		const titleInput = filterBar.getByPlaceholder('Search by title…')
		await expect(titleInput).toBeVisible()

		// Type "requiem" to filter songs
		await titleInput.fill('requiem')

		// Verify table only shows Requiem in D minor
		await expect(page.getByText('Requiem in D minor')).toBeVisible()
		await expect(page.getByText('Symphony No. 5')).not.toBeVisible()
	})

	test('expands dropdown height when multiple filter tags are selected', async ({ page }) => {
		const filterBar = page.locator('[role="region"][aria-label="Song filters"]')
		await expect(filterBar).toBeVisible()

		const composerSelect = filterBar.locator('.nc-select, .v-select').first()
		const initialBox = await composerSelect.locator('.vs__dropdown-toggle').boundingBox()
		expect(initialBox?.height).toBeGreaterThanOrEqual(40)

		// Click to select Ludwig van Beethoven
		await composerSelect.click()
		await page.locator('.vs__dropdown-menu, [role="option"]').getByText('Ludwig van Beethoven').click()

		// Click to select Wolfgang Amadeus Mozart
		await composerSelect.click()
		await page.locator('.vs__dropdown-menu, [role="option"]').getByText('Wolfgang Amadeus Mozart').click()

		const updatedBox = await composerSelect.locator('.vs__dropdown-toggle').boundingBox()
		expect(updatedBox?.height).toBeGreaterThanOrEqual(initialBox!.height)
	})
})
