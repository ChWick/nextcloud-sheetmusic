import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Song PDF Modifier & Cropping E2E Flow', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		const fs = await import('node:fs')
		const samplePath = new URL('../fixtures/sample.pdf', import.meta.url)
		const pdfBytes = fs.readFileSync(samplePath)
		await page.route('**/*.pdf*', async (route) => {
			await route.fulfill({
				status: 200,
				contentType: 'application/pdf',
				body: pdfBytes,
			})
		})
		await page.goto('/#/library/1/songs?song=5')
	})

	test('navigates from song viewer to PDF modifier via action button', async ({ page }) => {
		// Locate the Modify PDF button in the viewer header
		const modifyBtn = page.getByRole('button', { name: /Modify PDF/i })
		await expect(modifyBtn).toBeVisible()
		await modifyBtn.click()

		// Verify PDF Modifier header and tabs are rendered
		await expect(page.getByRole('heading', { name: /Modify PDF/i })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Rotate Pages' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Crop Pages' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Split PDF' })).toBeVisible()
	})

	test('switches to Crop mode, interacts with crop modal, applies crop, and saves', async ({ page }) => {
		// Navigate directly to modifier
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Switch to Crop Pages tab
		const cropTab = page.getByRole('button', { name: 'Crop Pages' })
		await expect(cropTab).toBeVisible()
		await cropTab.click()

		// Verify toolbar controls
		await expect(page.getByText(/Click on any page or "Crop" button to adjust crop margins/i)).toBeVisible()
		const cropAllBtn = page.getByRole('button', { name: 'Crop All Pages…' })
		const resetAllBtn = page.getByRole('button', { name: 'Reset All Crops' })
		const saveCropsBtn = page.getByRole('button', { name: 'Save Crops' })

		await expect(cropAllBtn).toBeVisible()
		await expect(resetAllBtn).toBeVisible()
		await expect(saveCropsBtn).toBeVisible()

		// Before any cropping, Save Crops and Reset All should be disabled
		await expect(saveCropsBtn).toBeDisabled()
		await expect(resetAllBtn).toBeDisabled()

		// Click Crop button on page 1
		const page1CropBtn = page.getByTitle('Crop page 1').first()
		await expect(page1CropBtn).toBeVisible()
		await page1CropBtn.click()

		// Verify Crop Modal is opened
		const cropDialog = page.getByRole('dialog')
		await expect(cropDialog).toBeVisible()
		await expect(cropDialog.getByRole('button', { name: 'Apply Crop' })).toBeVisible()
		await expect(cropDialog.getByRole('button', { name: 'Reset to full page' })).toBeVisible()

		// Click Trim 5% preset button in crop modal toolbar
		const trim5Btn = cropDialog.getByRole('button', { name: 'Trim 5%' })
		await expect(trim5Btn).toBeVisible()
		await trim5Btn.click()

		// Click Apply Crop button
		await cropDialog.getByRole('button', { name: 'Apply Crop' }).click()

		// Verify modal closed
		await expect(cropDialog).toBeHidden()

		// Verify page 1 now shows the "Cropped" badge
		await expect(page.getByText('Cropped').first()).toBeVisible()

		// Now Save Crops and Reset All buttons should be enabled
		await expect(saveCropsBtn).toBeEnabled()
		await expect(resetAllBtn).toBeEnabled()

		// Click Save Crops button
		await saveCropsBtn.click()

		// Verify success notification or return to song viewer
		await expect(page.getByText(/Cropped PDF saved successfully/i).first()).toBeVisible()
	})

	test('supports resetting all crops across pages', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Switch to Crop Pages tab
		await page.getByRole('button', { name: 'Crop Pages' }).click()

		// Open crop modal on page 1 and apply Trim 5%
		const page1CropBtn = page.getByTitle('Crop page 1').first()
		await page1CropBtn.click()

		const cropDialog = page.getByRole('dialog')
		await expect(cropDialog).toBeVisible()

		await cropDialog.getByRole('button', { name: 'Trim 5%' }).click()
		await cropDialog.getByRole('button', { name: 'Apply Crop' }).click()
		await expect(cropDialog).toBeHidden()

		// Verify cropped badge exists
		await expect(page.getByText('Cropped').first()).toBeVisible()

		// Click Reset All Crops
		const resetAllBtn = page.getByRole('button', { name: 'Reset All Crops' })
		await expect(resetAllBtn).toBeEnabled()
		await resetAllBtn.click()

		// Cropped badge should be gone
		await expect(page.getByText('Cropped')).toHaveCount(0)

		// Save Crops button should be disabled again
		await expect(page.getByRole('button', { name: 'Save Crops' })).toBeDisabled()
	})

	test('supports crop all pages modal and applying to all pages', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Switch to Crop Pages tab
		await page.getByRole('button', { name: 'Crop Pages' }).click()

		// Click Crop All Pages toolbar button
		const cropAllBtn = page.getByRole('button', { name: 'Crop All Pages…' })
		await cropAllBtn.click()

		const cropDialog = page.getByRole('dialog')
		await expect(cropDialog).toBeVisible()

		// Click Trim 10%
		await cropDialog.getByRole('button', { name: 'Trim 10%' }).click()

		// Choose "All pages" radio option
		const allPagesRadio = cropDialog.locator('input[type="radio"][value="all"]')
		await allPagesRadio.check()

		// Apply crop
		await cropDialog.getByRole('button', { name: 'Apply Crop' }).click()
		await expect(cropDialog).toBeHidden()

		// All pages should now display the Cropped badge
		const croppedBadges = page.getByText('Cropped')
		await expect(croppedBadges.first()).toBeVisible()
		await expect(croppedBadges).toHaveCount(8)

		// Header save button is active
		await expect(page.getByRole('button', { name: 'Save Crops' })).toBeEnabled()
	})

	test('supports rotating and cropping simultaneously in one step and saves both', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// 1. In Rotate mode: rotate page 1 clockwise 90°
		const rotateCwBtn = page.getByTitle('Rotate page 90° CW').first()
		await expect(rotateCwBtn).toBeVisible()
		await rotateCwBtn.click()

		// Verify 90° rotation badge is visible on page 1
		await expect(page.getByText('90°').first()).toBeVisible()

		// 2. Switch to Crop Pages tab
		const cropTab = page.getByRole('button', { name: 'Crop Pages' })
		await cropTab.click()

		// 3. Open Crop modal for page 1
		const page1CropBtn = page.getByTitle('Crop page 1').first()
		await page1CropBtn.click()

		const cropDialog = page.getByRole('dialog')
		await expect(cropDialog).toBeVisible()

		// Apply Trim 5% preset
		await cropDialog.getByRole('button', { name: 'Trim 5%' }).click()
		await cropDialog.getByRole('button', { name: 'Apply Crop' }).click()
		await expect(cropDialog).toBeHidden()

		// Verify both badges ("90°" and "Cropped") are visible on page 1
		await expect(page.getByText('90°').first()).toBeVisible()
		await expect(page.getByText('Cropped').first()).toBeVisible()

		// 4. Save modifications
		const saveBtn = page.getByRole('button', { name: /Save Crops|Save Changes/i })
		await expect(saveBtn).toBeEnabled()
		await saveBtn.click()

		// 5. Verify success toast
		await expect(page.getByText(/saved successfully/i).first()).toBeVisible()
	})

	test('supports deleting a single page, displaying deleted indicators, and saving updated PDF', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Header initially shows 8 pages
		await expect(page.getByText(/8 pages/i)).toBeVisible()

		// Delete page 2
		const delPage2Btn = page.getByTitle('Delete page 2').first()
		await expect(delPage2Btn).toBeVisible()
		await delPage2Btn.click()

		// Page 2 card should show Deleted indicator
		await expect(page.getByText('Deleted').first()).toBeVisible()

		// Header reflects 7 / 8 pages
		await expect(page.getByText(/7 \/ 8 pages/i)).toBeVisible()

		// Save button is now enabled
		const saveBtn = page.getByRole('button', { name: /Save Changes|Save Rotations/i })
		await expect(saveBtn).toBeEnabled()
		await saveBtn.click()

		// Verify success notification
		await expect(page.getByText(/saved successfully/i).first()).toBeVisible()
	})

	test('supports restoring deleted pages via card button and toolbar', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Delete page 2
		const delPage2Btn = page.getByTitle('Delete page 2').first()
		await delPage2Btn.click()

		await expect(page.getByText('Deleted').first()).toBeVisible()
		await expect(page.getByRole('button', { name: /Restore Deleted \(1\)/i })).toBeVisible()

		// Restore page 2 using the card restore button
		const restorePage2Btn = page.getByTitle('Restore page 2').first()
		await expect(restorePage2Btn).toBeVisible()
		await restorePage2Btn.click()

		// Deleted badge and toolbar restore button should disappear
		await expect(page.getByText('Deleted')).toBeHidden()
		await expect(page.getByRole('button', { name: /Restore Deleted/i })).toBeHidden()
		await expect(page.getByText(/8 pages/i)).toBeVisible()

		// Delete pages 2 and 3
		await page.getByTitle('Delete page 2').first().click()
		await page.getByTitle('Delete page 3').first().click()

		const toolbarRestoreBtn = page.getByRole('button', { name: /Restore Deleted \(2\)/i })
		await expect(toolbarRestoreBtn).toBeVisible()

		// Click Restore in toolbar
		await toolbarRestoreBtn.click()

		await expect(page.getByText('Deleted')).toBeHidden()
		await expect(page.getByRole('button', { name: /Restore Deleted/i })).toBeHidden()
		await expect(page.getByText(/8 pages/i)).toBeVisible()
	})

	test('supports joining two pages into a single page and saving the updated PDF', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Header initially shows 8 pages
		await expect(page.getByText(/8 pages/i)).toBeVisible()

		// 1. Optionally crop page 1 first to test the combined crop + join workflow
		const cropTab = page.getByRole('button', { name: 'Crop Pages' })
		await cropTab.click()

		const page1CropBtn = page.getByTitle('Crop page 1').first()
		await expect(page1CropBtn).toBeVisible()
		await page1CropBtn.click()

		const cropDialog = page.getByRole('dialog')
		await expect(cropDialog).toBeVisible()
		await cropDialog.getByRole('button', { name: 'Trim 5%' }).click()
		await cropDialog.getByRole('button', { name: 'Apply Crop' }).click()
		await expect(cropDialog).toBeHidden()
		await expect(page.getByText('Cropped').first()).toBeVisible()

		// 2. Open Join modal via toolbar or card button
		const joinPage1Btn = page.getByTitle('Join with page 2').first()
		await expect(joinPage1Btn).toBeVisible()
		await joinPage1Btn.click()

		// Join dialog opens
		const joinDialog = page.getByRole('dialog')
		await expect(joinDialog).toBeVisible()
		await expect(joinDialog.getByRole('heading', { name: /Join Two Pages/i })).toBeVisible()

		// Verify layout buttons, alignment controls, and fit indicator
		await expect(joinDialog.getByRole('button', { name: /Top \/ Bottom/i })).toBeVisible()
		await expect(joinDialog.getByRole('button', { name: /Align Widths/i })).toBeVisible()
		await expect(joinDialog.locator('[data-test="scale-a-slider"]')).toBeVisible()
		await expect(joinDialog.locator('[data-test="scale-b-slider"]')).toBeVisible()
		await expect(joinDialog.getByRole('button', { name: /Join Pages/i })).toBeVisible()

		// 3. Confirm Join
		const confirmJoinBtn = joinDialog.getByRole('button', { name: 'Join Pages' })
		await confirmJoinBtn.click()

		// Verify join dialog closed
		await expect(joinDialog).toBeHidden()

		// Document now has 7 pages
		await expect(page.getByText(/7 pages/i)).toBeVisible()

		// 4. Save modifications
		const saveBtn = page.getByRole('button', { name: /Save Changes|Save Crops|Save Rotations/i })
		await expect(saveBtn).toBeEnabled()
		await saveBtn.click()

		// 5. Verify success notification
		await expect(page.getByText(/saved successfully/i).first()).toBeVisible()
	})

	test('supports rotating a page by any arbitrary angle (fine deskew) and saving the updated PDF', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// 1. In Rotate mode, click Rotate by Angle… in toolbar or card
		const rotateCustomBtn = page.getByRole('button', { name: /Rotate by Angle…/i })
		await expect(rotateCustomBtn).toBeVisible()
		await rotateCustomBtn.click()

		// Verify Rotate modal is open
		const rotateDialog = page.getByRole('dialog')
		await expect(rotateDialog).toBeVisible()
		await expect(rotateDialog.getByRole('heading', { name: /Rotate Page 1/i })).toBeVisible()

		// Verify alignment grid button is present and toggles
		const gridBtn = rotateDialog.getByRole('button', { name: /Alignment Grid/i })
		await expect(gridBtn).toBeVisible()

		// Click +1° twice and +0.5° once -> 2.5°
		const plus1Btn = rotateDialog.getByRole('button', { name: '+1°' })
		await plus1Btn.click()
		await plus1Btn.click()
		const plusHalfBtn = rotateDialog.getByRole('button', { name: '+0.5°' })
		await plusHalfBtn.click()

		// Verify number input reflects 2.5°
		const angleInput = rotateDialog.locator('input[type="number"]')
		await expect(angleInput).toHaveValue('2.5')

		// Check "Crop page when rotating (keep original size)"
		const cropCheckbox = rotateDialog.locator('input[data-test="crop-to-original-checkbox"]')
		await expect(cropCheckbox).toBeVisible()
		await cropCheckbox.check()
		await expect(cropCheckbox).toBeChecked()

		// 2. Click Apply Rotation
		const applyBtn = rotateDialog.getByRole('button', { name: 'Apply Rotation' })
		await applyBtn.click()
		await expect(rotateDialog).toBeHidden()

		// 3. Page 1 now displays 2.5° badge
		await expect(page.getByText('2.5°').first()).toBeVisible()

		// 4. Save modifications
		const saveBtn = page.getByRole('button', { name: /Save Rotations|Save Changes/i })
		await expect(saveBtn).toBeEnabled()
		await saveBtn.click()

		// 5. Verify success toast
		await expect(page.getByText(/saved successfully/i).first()).toBeVisible()
	})

	test('supports zoom, pan, native DPI resolution, and uncut operation stack for landscape pages in Enhance Scans dialog', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		// Switch to Enhance Scans tab
		const enhanceTab = page.getByRole('button', { name: 'Enhance Scans' })
		await expect(enhanceTab).toBeVisible()
		await enhanceTab.click()

		// Open Enhance modal on page 1
		const enhanceAllBtn = page.getByRole('button', { name: 'Enhance All Pages…' })
		await expect(enhanceAllBtn).toBeVisible()
		await enhanceAllBtn.click()

		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible()

		// Verify native resolution on Page 1 (829 x 1173 px in sample.pdf)
		const canvas = dialog.locator('canvas')
		await expect(canvas).toBeVisible()
		await expect(canvas).toHaveAttribute('data-native-width', '829')
		await expect(canvas).toHaveAttribute('data-native-height', '1173')

		// Test Zoom In and Pan
		const zoomLevel = dialog.locator('[data-test="enhance-zoom-level"]')
		const zoomInBtn = dialog.locator('[data-test="enhance-zoom-in"]')
		const zoomResetBtn = dialog.locator('[data-test="enhance-zoom-reset"]')
		const previewPane = dialog.locator('[data-test="enhance-preview-pane"]')
		const canvasWrapper = dialog.locator('[data-test="enhance-canvas-wrapper"]')

		await expect(zoomLevel).toHaveText('100%')
		await zoomInBtn.click()
		await zoomInBtn.click()
		await expect(zoomLevel).toHaveText('150%')

		// Drag to pan on preview pane
		const paneBox = await previewPane.boundingBox()
		expect(paneBox).not.toBeNull()
		const startX = paneBox!.x + paneBox!.width / 2
		const startY = paneBox!.y + paneBox!.height / 2
		await page.mouse.move(startX, startY)
		await page.mouse.down()
		await page.mouse.move(startX + 45, startY - 30)
		await page.mouse.up()

		await expect(canvasWrapper).toHaveAttribute('style', /translate\(45px, -30px\)/)

		// Reset zoom and pan
		await zoomResetBtn.click()
		await expect(zoomLevel).toHaveText('100%')
		await expect(canvasWrapper).toHaveAttribute('style', /translate\(0px(?:, 0px)?\)/)

		// Navigate to Page 2 (which is a 1190.55 x 841.89 landscape page in sample.pdf)
		const nextBtn = dialog.getByTitle('Next page')
		await nextBtn.click()
		await expect(dialog.getByText('Page 2 of 8', { exact: true })).toBeVisible()

		// Wait for landscape page 2 to render at full native resolution
		await expect.poll(async () => Number(await canvas.getAttribute('data-native-width'))).toBeGreaterThan(1600)

		// Verify the Operation Stack is NOT cut off for landscape PDFs
		const dialogBox = await dialog.boundingBox()
		const addThresholdBtn = dialog.locator('[data-test="add-threshold"]')
		await expect(addThresholdBtn).toBeVisible()
		const btnBox = await addThresholdBtn.boundingBox()
		expect(dialogBox).not.toBeNull()
		expect(btnBox).not.toBeNull()
		expect(btnBox!.x + btnBox!.width).toBeLessThanOrEqual(dialogBox!.x + dialogBox!.width)
	})

	test('switches to Bookmarks mode, views bookmarks sidebar, and allows adding a bookmark', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		const bookmarksTab = page.getByRole('button', { name: 'Bookmarks' })
		await expect(bookmarksTab).toBeVisible()
		await bookmarksTab.click()

		// Verify bookmarks sidebar is visible
		await expect(page.getByRole('region', { name: 'Bookmarks management sidebar' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Import from PDF Outline' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Add Bookmark' })).toBeVisible()

		// Click Add Bookmark
		await page.getByRole('button', { name: 'Add Bookmark' }).click()
		await expect(page.getByPlaceholder('e.g. Intro or Allegro').first()).toBeVisible()
	})

	test('allows selecting PDF pages to create bookmarks and assign page ranges', async ({ page }) => {
		await page.goto('/#/library/1/songs?song=5&modifyPdf=1')

		const bookmarksTab = page.getByRole('button', { name: 'Bookmarks' })
		await expect(bookmarksTab).toBeVisible()
		await bookmarksTab.click()

		// Verify bookmarks sidebar is visible
		await expect(page.getByRole('region', { name: 'Bookmarks management sidebar' })).toBeVisible()

		// Click the first page in the grid to select it
		const firstPageCard = page.locator('[data-test="pdf-page-grid"] > div').first()
		await expect(firstPageCard).toBeVisible()
		await firstPageCard.click()

		// Toolbar shows selection count
		await expect(page.getByText('1 pages selected')).toBeVisible()

		// Sidebar displays "Add from Selection" button
		const addFromSelectionBtn = page.getByRole('button', { name: /Add from Selection/ })
		await expect(addFromSelectionBtn).toBeVisible()
		await addFromSelectionBtn.click()

		// A bookmark card is created and bookmark indicator is displayed
		await expect(page.getByPlaceholder('e.g. Intro or Allegro').first()).toBeVisible()
		await expect(firstPageCard).toContainText('#1')

		// Now select page 2 in grid
		const secondPageCard = page.locator('[data-test="pdf-page-grid"] > div').nth(1)
		await secondPageCard.click()
		await expect(page.getByText('1 pages selected')).toBeVisible()

		// Assign selected page 2 to bookmark 1
		const assignBtn = page.locator('[data-test="bookmark-card-0"]').getByRole('button', { name: /Pages 2/ })
		await expect(assignBtn).toBeVisible()
		await assignBtn.click()

		// Page 2 now has bookmark indicator #1
		await expect(secondPageCard).toContainText('#1')

		// Save bookmarks
		const saveBtn = page.getByRole('button', { name: 'Save Bookmarks' })
		await expect(saveBtn).toBeVisible()
		await saveBtn.click()

		// Toast or modal confirms saved
		await expect(page.getByText(/Saved bookmarks for/).first()).toBeVisible({ timeout: 10000 })
	})
})


