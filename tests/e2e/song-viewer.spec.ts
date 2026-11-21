import { expect, test } from '@playwright/test'
import { PDFDocument } from 'pdf-lib'
import { setupMockApi } from './helpers/mock-api'

test.describe('Song Viewer UI & Icon Buttons', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/songs?song=5')
	})

	test('renders song details and icon-only action buttons with proper tooltips', async ({ page }) => {
		// Verify song title is visible
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()

		// Locate directly visible header & file toolbar icon buttons by aria-label
		const backBtn = page.getByRole('button', { name: 'Back to songs' })
		const editBtn = page.getByRole('button', { name: 'Edit song' })
		const fullscreenBtn = page.getByRole('button', { name: 'Fullscreen' })
		const moreSongActionsBtn = page.getByRole('button', { name: 'More song actions' })

		await expect(backBtn).toBeVisible()
		await expect(editBtn).toBeVisible()
		await expect(fullscreenBtn).toBeVisible()
		await expect(moreSongActionsBtn).toBeVisible()

		// Verify title attributes for hover tooltips
		await expect(backBtn).toHaveAttribute('title', 'Back to songs')
		await expect(editBtn).toHaveAttribute('title', 'Edit song')
		await expect(fullscreenBtn).toHaveAttribute('title', 'Fullscreen')

		// Verify SVG icons exist inside the buttons
		await expect(backBtn.locator('svg')).toBeVisible()
		await expect(editBtn.locator('svg')).toBeVisible()
		await expect(fullscreenBtn.locator('svg')).toBeVisible()

		// Verify overflow menu contains Delete song
		await moreSongActionsBtn.click()
		await expect(page.getByRole('button', { name: 'Delete song' })).toBeVisible()
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
		await expect(page.locator('form').getByRole('button', { name: 'Save and Close' })).toBeVisible()
	})

	test('renders bookmark badges and jumps to bookmark page when clicked', async ({ page }) => {
		// Bookmarks bar displays both bookmarks
		const mvt1 = page.getByRole('button', { name: /I\. Allegro con brio/i })
		const mvt2 = page.getByRole('button', { name: /II\. Andante con moto/i })
		await expect(mvt1).toBeVisible()
		await expect(mvt2).toBeVisible()

		// Clicking movement 1 jumps to start page
		await mvt1.click()
		await expect(page.getByText(/Page 1–1 of 1|Page 1 of 1/)).toBeVisible()
	})
})

test.describe('Two-page mixed-size & cropped PDF layout', () => {
	test('keeps mixed-size and cropped horizontal pages strictly 50/50 and stable across turns in two-page mode', async ({ page }) => {
		await setupMockApi(page)

		// Build a real 4-page PDF with mixed photographed dimensions and a cropped horizontal page
		const doc = await PDFDocument.create()
		doc.addPage([3000, 4000]) // Page 1: large photographed portrait
		doc.addPage([1500, 2100]) // Page 2: smaller photographed portrait
		const page3 = doc.addPage([2400, 3200]) // Page 3: cropped to horizontal (2400x1000)
		page3.setCropBox(0, 1800, 2400, 1000)
		doc.addPage([1000, 2000]) // Page 4: narrow portrait
		const pdfBytes = Buffer.from(await doc.save())

		await page.route('**/*.pdf*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/pdf',
				body: pdfBytes,
			})
		})

		await page.route('**/api/songs/*/annotations*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({ ocs: { meta: { status: 'ok', statuscode: 200 }, data: [] } }),
			})
		})

		await page.addInitScript(() => {
			localStorage.setItem(
				'sheetmusic:performance-viewer:library:1',
				JSON.stringify({
					layoutChoice: 'double',
					doublePageTurnMode: 'half',
					zoomChoice: 'fit-page',
					showAnnotations: true,
					interleaveSongs: false,
				}),
			)
		})

		await page.setViewportSize({ width: 1400, height: 900 })
		await page.goto('/#/library/1/songs?song=5')
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()

		// Open Performance Viewer ("Play song") — uses fixed 100vw x 100vh overlay
		await page.getByRole('button', { name: 'Play song' }).click()
		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible()
		await expect(dialog.locator('.sheetmusic-double-slot')).toHaveCount(2)
		await expect(dialog.locator('[data-page-num="1"] canvas.sheetmusic-pdf-page')).toBeVisible()
		await expect(dialog.locator('[data-page-num="2"] canvas.sheetmusic-pdf-page')).toBeVisible()

		const measureSpread = async () => {
			return dialog.evaluate((root) => {
				const slots = Array.from(root.querySelectorAll<HTMLElement>('.sheetmusic-double-slot'))
				const stage = slots[0]?.parentElement as HTMLElement
				const container = stage?.parentElement as HTMLElement
				const containerRect = container.getBoundingClientRect()
				const midX = containerRect.left + containerRect.width / 2
				const slotRects = slots.map((s) => {
					const r = s.getBoundingClientRect()
					const placeholder = s.querySelector<HTMLElement>('[data-page-num]')
					const canvas = s.querySelector<HTMLCanvasElement>('canvas.sheetmusic-pdf-page')
					const cr = canvas?.getBoundingClientRect()
					return {
						pageNum: Number(placeholder?.dataset.pageNum || 0),
						slotLeft: r.left,
						slotRight: r.right,
						slotWidth: r.width,
						canvasLeft: cr?.left ?? 0,
						canvasRight: cr?.right ?? 0,
						canvasTop: cr?.top ?? 0,
						canvasWidth: cr?.width ?? 0,
						canvasHeight: cr?.height ?? 0,
					}
				})
				return { midX, containerWidth: containerRect.width, slotRects }
			})
		}

		// Spread [1, 2]: both slots must be strictly 50/50 and canvases must stay within their own half
		const spread12 = await measureSpread()
		expect(spread12.slotRects).toHaveLength(2)
		expect(Math.abs(spread12.slotRects[0].slotWidth - spread12.slotRects[1].slotWidth)).toBeLessThanOrEqual(2)
		expect(spread12.slotRects[0].canvasRight).toBeLessThanOrEqual(spread12.midX + 2)
		expect(spread12.slotRects[1].canvasLeft).toBeGreaterThanOrEqual(spread12.midX - 2)
		const page2Before = spread12.slotRects[1]

		// Turn page (half mode: [1, 2] -> [3, 2], where page 3 is cropped horizontal)
		await page.keyboard.press('ArrowRight')
		await expect(dialog.locator('[data-page-num="3"] canvas.sheetmusic-pdf-page')).toBeVisible()
		await expect(dialog.locator('[data-page-num="2"] canvas.sheetmusic-pdf-page')).toBeVisible()

		const spread32 = await measureSpread()
		expect(spread32.slotRects[0].pageNum).toBe(3)
		expect(spread32.slotRects[1].pageNum).toBe(2)
		// Strictly 50/50 columns even with cropped horizontal page 3
		expect(Math.abs(spread32.slotRects[0].slotWidth - spread32.slotRects[1].slotWidth)).toBeLessThanOrEqual(2)
		expect(spread32.slotRects[0].canvasRight).toBeLessThanOrEqual(spread32.midX + 2)
		expect(spread32.slotRects[1].canvasLeft).toBeGreaterThanOrEqual(spread32.midX - 2)
		// Page 2 must NOT change size or position when turning to [3, 2]
		expect(Math.abs(spread32.slotRects[1].canvasWidth - page2Before.canvasWidth)).toBeLessThanOrEqual(1)
		expect(Math.abs(spread32.slotRects[1].canvasHeight - page2Before.canvasHeight)).toBeLessThanOrEqual(1)
		expect(Math.abs(spread32.slotRects[1].canvasLeft - page2Before.canvasLeft)).toBeLessThanOrEqual(1)

		const page3Before = spread32.slotRects[0]

		// Turn page again ([3, 2] -> [3, 4], cropped horizontal page 3 + narrow portrait page 4)
		await page.keyboard.press('ArrowRight')
		await expect(dialog.locator('[data-page-num="3"] canvas.sheetmusic-pdf-page')).toBeVisible()
		await expect(dialog.locator('[data-page-num="4"] canvas.sheetmusic-pdf-page')).toBeVisible()

		const spread34 = await measureSpread()
		expect(spread34.slotRects[0].pageNum).toBe(3)
		expect(spread34.slotRects[1].pageNum).toBe(4)
		expect(Math.abs(spread34.slotRects[0].slotWidth - spread34.slotRects[1].slotWidth)).toBeLessThanOrEqual(2)
		expect(spread34.slotRects[0].canvasRight).toBeLessThanOrEqual(spread34.midX + 2)
		expect(spread34.slotRects[1].canvasLeft).toBeGreaterThanOrEqual(spread34.midX - 2)
		// Page 3 must NOT change size when paired with page 4
		expect(Math.abs(spread34.slotRects[0].canvasWidth - page3Before.canvasWidth)).toBeLessThanOrEqual(1)
		expect(Math.abs(spread34.slotRects[0].canvasHeight - page3Before.canvasHeight)).toBeLessThanOrEqual(1)
	})
})
