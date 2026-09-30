import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Song Viewer UI & Icon Buttons', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/songs?song=5')
	})

	test('renders song details and icon-only action buttons with proper tooltips', async ({ page }) => {
		// Verify song title is visible
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()

		// Locate icon-only header buttons by aria-label
		const backBtn = page.getByRole('button', { name: 'Back to songs' })
		const externalBtn = page.getByRole('button', { name: 'Open in external viewer' })
		const editBtn = page.getByRole('button', { name: 'Edit song' })
		const deleteBtn = page.getByRole('button', { name: 'Delete song' })

		await expect(backBtn).toBeVisible()
		await expect(externalBtn).toBeVisible()
		await expect(editBtn).toBeVisible()
		await expect(deleteBtn).toBeVisible()

		// Verify title attributes for hover tooltips
		await expect(backBtn).toHaveAttribute('title', 'Back to songs')
		await expect(externalBtn).toHaveAttribute('title', 'Open in external viewer')
		await expect(editBtn).toHaveAttribute('title', 'Edit song')
		await expect(deleteBtn).toHaveAttribute('title', 'Delete song')

		// Verify SVG icons exist inside the buttons
		await expect(backBtn.locator('svg')).toBeVisible()
		await expect(externalBtn.locator('svg')).toBeVisible()
		await expect(editBtn.locator('svg')).toBeVisible()
		await expect(deleteBtn.locator('svg')).toBeVisible()
	})

	test('opens song editor when Edit button is clicked', async ({ page }) => {
		const editBtn = page.getByRole('button', { name: 'Edit song' })
		await editBtn.click()

		// Verify editor title or form field is visible
		await expect(page.getByLabel('Title')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Save and Close' })).toBeVisible()
	})
})
