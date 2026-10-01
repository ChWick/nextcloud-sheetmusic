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

	test('renders PDF viewer toolbar with navigation, layout modes, zoom and rotate controls', async ({ page }) => {
		// Wait for PDF toolbar to appear
		const zoomInBtn = page.getByRole('button', { name: 'Zoom in' })
		const zoomOutBtn = page.getByRole('button', { name: 'Zoom out' })
		const fitWidthBtn = page.getByRole('button', { name: 'Fit to width' })
		const rotateBtn = page.getByRole('button', { name: 'Rotate clockwise' })
		const continuousBtn = page.getByRole('button', { name: 'Continuous scroll (All pages)' })
		const singlePageBtn = page.getByRole('button', { name: 'Single page' })
		const doublePageBtn = page.getByRole('button', { name: 'Two pages side-by-side' })
		const zoomSelect = page.getByRole('combobox', { name: 'Zoom level' })

		await expect(zoomInBtn).toBeVisible()
		await expect(zoomOutBtn).toBeVisible()
		await expect(fitWidthBtn).toBeVisible()
		await expect(rotateBtn).toBeVisible()
		await expect(continuousBtn).toBeVisible()
		await expect(singlePageBtn).toBeVisible()
		await expect(doublePageBtn).toBeVisible()
		await expect(zoomSelect).toBeVisible()

		// Verify page indicator text
		await expect(page.getByText(/Page 1–1 of 1|Page 1 of 1/)).toBeVisible()
	})

	test('allows switching layout modes and interacting with zoom dropdown and buttons', async ({ page }) => {
		// Switch to single page mode
		const singlePageBtn = page.getByRole('button', { name: 'Single page' })
		await singlePageBtn.click()

		// Switch to two-page mode
		const doublePageBtn = page.getByRole('button', { name: 'Two pages side-by-side' })
		await doublePageBtn.click()

		// Click zoom in and rotate
		const zoomInBtn = page.getByRole('button', { name: 'Zoom in' })
		await zoomInBtn.click()

		const rotateBtn = page.getByRole('button', { name: 'Rotate clockwise' })
		await rotateBtn.click()

		// Select zoom level from dropdown
		const zoomSelect = page.getByRole('combobox', { name: 'Zoom level' })
		await zoomSelect.selectOption('1.5')
		await expect(zoomSelect).toHaveValue('1.5')

		// Click Fit to width
		const fitWidthBtn = page.getByRole('button', { name: 'Fit to width' })
		await fitWidthBtn.click()
		await expect(zoomSelect).toHaveValue('fit-width')
	})

	test('opens song editor when Edit button is clicked', async ({ page }) => {
		const editBtn = page.getByRole('button', { name: 'Edit song' })
		await editBtn.click()

		// Verify editor title input or form action is visible
		await expect(page.getByLabel('Song Title')).toBeVisible()
		await expect(page.getByRole('button', { name: 'Save and Close' })).toBeVisible()
	})
})
