import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Performance Mode Metronome & Pitch Pipe', () => {
	test.beforeEach(async ({ page }) => {
		await setupMockApi(page)
		// Song 5 (Symphony No. 5) has tempo=120, signature=4/4, key=C Major
		await page.goto('/#/library/1/songs?song=5')
		await expect(page.getByRole('heading', { name: 'Symphony No. 5' })).toBeVisible()
		await page.locator('[data-test="play-song-button"]').click()
		await expect(page.locator('[data-test="performance-metronome-button"]')).toBeVisible()
	})

	test('auto-populates song tempo & time signature, adjusts BPM, starts/stops visual pulse, and syncs back', async ({ page }) => {
		const metronomeBtn = page.locator('[data-test="performance-metronome-button"]')
		await metronomeBtn.click()

		const panel = page.locator('[data-test="metronome-panel"]')
		await expect(panel).toBeVisible()
		await expect(page.locator('[data-test="metronome-bpm-value"]')).toContainText('120')
		await expect(page.locator('[data-test="metronome-song-sync"]')).toContainText('120 BPM · 4/4')
		await expect(page.locator('[data-test="metronome-beat-dots"] span')).toHaveCount(4)

		// Adjust BPM and time signature
		await page.locator('[data-test="metronome-bpm-plus-5"]').click()
		await page.locator('[data-test="metronome-bpm-minus-1"]').click()
		await expect(page.locator('[data-test="metronome-bpm-value"]')).toContainText('124')

		await page.locator('[data-test="metronome-sig-3-4"]').click()
		await expect(page.locator('[data-test="metronome-beat-dots"] span')).toHaveCount(3)

		// Sync back to song metadata
		await page.locator('[data-test="metronome-song-sync"]').click()
		await expect(page.locator('[data-test="metronome-bpm-value"]')).toContainText('120')
		await expect(page.locator('[data-test="metronome-beat-dots"] span')).toHaveCount(4)

		// Start metronome: shows BPM on top-bar trigger and visual beat pulse overlay
		await page.locator('[data-test="metronome-start-stop-button"]').click()
		await expect(metronomeBtn).toContainText('120')
		const pulseOverlay = page.locator('[data-test="performance-metronome-pulse"]')
		await expect(pulseOverlay).toBeVisible()
		await expect(pulseOverlay.locator('span')).toHaveCount(4)

		// Toggle visual pulse off hides the overlay while metronome stays running
		await page.locator('[data-test="metronome-flash-toggle"]').click()
		await expect(pulseOverlay).toBeHidden()
		await page.locator('[data-test="metronome-flash-toggle"]').click()
		await expect(pulseOverlay).toBeVisible()

		// Stop metronome
		await page.locator('[data-test="metronome-start-stop-button"]').click()
		await expect(pulseOverlay).toBeHidden()
	})

	test('plays pitch pipe notes, highlights song key, updates octave & A4 calibration, and handles Escape', async ({ page }) => {
		// Open metronome first, then open pitch pipe to verify mutual exclusion
		await page.locator('[data-test="performance-metronome-button"]').click()
		await expect(page.locator('[data-test="metronome-panel"]')).toBeVisible()

		const pitchBtn = page.locator('[data-test="performance-pitch-pipe-button"]')
		await pitchBtn.click()
		await expect(page.locator('[data-test="pitch-pipe-panel"]')).toBeVisible()
		await expect(page.locator('[data-test="metronome-panel"]')).toBeHidden()

		// Verify song key C Major is displayed and C note is highlighted
		await expect(page.locator('[data-test="pitch-pipe-song-key"]')).toContainText('C Major')
		await expect(page.locator('[data-test="pitch-note-0"]')).toHaveAttribute('data-song-key', 'true')

		// Play A4 (440.0 Hz)
		await page.locator('[data-test="pitch-note-9"]').click()
		await expect(page.locator('[data-test="pitch-pipe-status"]')).toContainText('A4')
		await expect(page.locator('[data-test="pitch-pipe-status"]')).toContainText('440.0 Hz')
		await expect(pitchBtn).toContainText('A4')

		// Calibrate A4 to 442 Hz and switch to octave 5 -> 884.0 Hz
		await page.locator('[data-test="pitch-a4-preset-442"]').click()
		await expect(page.locator('[data-test="pitch-pipe-status"]')).toContainText('442.0 Hz')

		await page.locator('[data-test="pitch-octave-5"]').click()
		await expect(page.locator('[data-test="pitch-pipe-status"]')).toContainText('A5')
		await expect(page.locator('[data-test="pitch-pipe-status"]')).toContainText('884.0 Hz')

		// Stop pitch pipe
		await page.locator('[data-test="pitch-pipe-stop-button"]').click()
		await expect(page.locator('[data-test="pitch-pipe-status"]')).toContainText('Tap a note')

		// Pressing Escape closes the open popover without closing performance mode
		await page.keyboard.press('Escape')
		await expect(page.locator('[data-test="pitch-pipe-panel"]')).toBeHidden()
		await expect(page.locator('[data-test="stop-performance-button"]')).toBeVisible()
	})

	test('toggles night score inversion, warm night tint, and dimming brightness with persistence', async ({ page }) => {
		const dialog = page.getByRole('dialog', { name: 'Symphony No. 5' })
		const pdfContainer = dialog.locator('[data-test="pdf-viewer-container"]')
		await expect(pdfContainer).toHaveAttribute('data-score-color-mode', 'normal')

		// 1-tap Night Mode toggle in bottom bar activates dark HUD chrome + inverted score
		const nightToggle = dialog.locator('[data-test="performance-bottom-night-toggle"]')
		await nightToggle.click()
		await expect(nightToggle).toHaveAttribute('aria-pressed', 'true')
		await expect(dialog).toHaveAttribute('data-night-chrome', 'true')
		await expect(pdfContainer).toHaveAttribute('data-score-color-mode', 'night')

		// Open appearance popover right from the Night Mode split button arrow (no need for full Viewer Settings!)
		await dialog.locator('[data-test="performance-bottom-night-toggle-menu"]').click()
		const appearancePopover = dialog.locator('[data-test="score-appearance-popover"]')
		await expect(appearancePopover).toBeVisible()
		await appearancePopover.locator('[data-test="popover-score-mode-night-sepia"]').click()
		await expect(pdfContainer).toHaveAttribute('data-score-color-mode', 'night-sepia')

		await appearancePopover.locator('[data-test="popover-score-brightness"]').fill('70')
		await expect(appearancePopover.locator('[data-test="popover-score-brightness-value"]')).toHaveText('70%')
		await expect(pdfContainer).toHaveAttribute('data-score-brightness', '70')

		await appearancePopover.locator('[data-test="popover-score-sepia"]').fill('60')
		await expect(appearancePopover.locator('[data-test="popover-score-sepia-value"]')).toHaveText('60%')
		await expect(pdfContainer).toHaveAttribute('data-score-sepia', '60')

		// Close popover and exit -> regular Song Viewer reflects persisted night appearance and exposes split menu too
		await appearancePopover.getByRole('button', { name: 'Close' }).click()
		await dialog.locator('[data-test="stop-performance-button"]').click()
		const viewerNightBtn = page.locator('[data-test="viewer-night-mode-button"]')
		await expect(viewerNightBtn).toHaveAttribute('aria-pressed', 'true')
		await page.locator('[data-test="viewer-night-mode-button-menu"]').click()
		await expect(page.locator('[data-test="score-appearance-popover"]')).toBeVisible()
		await page.locator('[data-test="score-appearance-popover"]').getByRole('button', { name: 'Close' }).click()

		// Reopen performance mode -> settings persist
		await page.locator('[data-test="play-song-button"]').click()
		const reopenedDialog = page.getByRole('dialog', { name: 'Symphony No. 5' })
		const reopenedContainer = reopenedDialog.locator('[data-test="pdf-viewer-container"]')
		await expect(reopenedDialog).toHaveAttribute('data-night-chrome', 'true')
		await expect(reopenedContainer).toHaveAttribute('data-score-color-mode', 'night-sepia')
		await expect(reopenedContainer).toHaveAttribute('data-score-brightness', '70')
		await expect(reopenedContainer).toHaveAttribute('data-score-sepia', '60')
	})
})
