import { expect, test } from '@playwright/test'
import { setupMockApi } from './helpers/mock-api'

test.describe('Library Tools: Metronome, Pitch Pipe, and Tuner', () => {
	test('opens Metronome, Pitch Pipe, and Tuner from the main Tools section and runs each tool', async ({ page }) => {
		await setupMockApi(page)
		await page.goto('/#/library/1/tools')
		await expect(page.locator('[data-test="tools-overview"]')).toBeVisible()

		// 1. Metronome tool
		await page.locator('[data-test="tool-card-metronome"]').click()
		await expect(page.locator('[data-test="metronome-tool"]')).toBeVisible()
		const metronomeHeaderBox = await page.locator('[data-test="tools-view"] header').boundingBox()
		expect(metronomeHeaderBox).not.toBeNull()
		await expect(page.locator('[data-test="metronome-bpm-value"]')).toContainText('120')
		await page.locator('[data-test="metronome-bpm-plus-5"]').click()
		await expect(page.locator('[data-test="metronome-bpm-value"]')).toContainText('125')

		// 2. Switch to Pitch Pipe tool via tool chips
		await page.locator('[data-test="tool-chip-pitch-pipe"]').click()
		await expect(page.locator('[data-test="pitch-pipe-tool"]')).toBeVisible()
		await page.locator('[data-test="pitch-note-9"]').click()
		await expect(page.locator('[data-test="pitch-pipe-status"]')).toContainText('A4')
		await expect(page.locator('[data-test="pitch-pipe-status"]')).toContainText('440.0 Hz')
		await page.locator('[data-test="pitch-a4-preset-442"]').click()
		await expect(page.locator('[data-test="pitch-pipe-status"]')).toContainText('442.0 Hz')
		await page.locator('[data-test="pitch-pipe-stop-button"]').click()

		// 3. Switch to Tuner tool and verify live 440 Hz detection
		await page.evaluate(() => {
			let activeOsc: OscillatorNode | null = null
			const fakeTrack = {
				kind: 'audio',
				stop() {
					try { activeOsc?.stop() } catch { /* ignore */ }
					activeOsc = null
				},
			}
			const fakeStream = { getTracks: () => [fakeTrack] } as unknown as MediaStream
			Object.defineProperty(navigator, 'mediaDevices', {
				configurable: true,
				value: { getUserMedia: async () => fakeStream },
			})
			const origCreate = AudioContext.prototype.createMediaStreamSource
			AudioContext.prototype.createMediaStreamSource = function (stream: MediaStream) {
				if (stream === fakeStream) {
					const osc = this.createOscillator()
					osc.type = 'sine'
					osc.frequency.value = 440
					osc.start()
					activeOsc = osc
					return osc as unknown as MediaStreamAudioSourceNode
				}
				return origCreate.call(this, stream)
			}
		})

		await page.locator('[data-test="tool-chip-tuner"]').click()
		await expect(page.locator('[data-test="tuner-tool"]')).toBeVisible()
		await page.locator('[data-test="tuner-toggle-button"]').click()
		await expect(page.locator('[data-test="tuner-note"]')).toHaveText('A4', { timeout: 5000 })
		await expect(page.locator('[data-test="tuner-section"]')).toHaveAttribute('data-tuning-status', 'in-tune')
		await page.locator('[data-test="tuner-toggle-button"]').click()
		await expect(page.locator('[data-test="tuner-note"]')).toHaveText('--')

		// 4. Switch to Reset Page Ordering and verify tool switcher bar sits cleanly below the header without overlapping
		await page.locator('[data-test="tool-chip-page-order-reset"]').click()
		await expect(page.locator('[data-test="page-order-reset-tool"]')).toBeVisible()
		const headerBox = await page.locator('[data-test="tools-view"] header').boundingBox()
		const switcherBox = await page.locator('[data-test="tools-switcher-bar"]').boundingBox()
		const toolBox = await page.locator('[data-test="page-order-reset-tool"]').boundingBox()
		expect(headerBox).not.toBeNull()
		expect(switcherBox).not.toBeNull()
		expect(toolBox).not.toBeNull()
		expect(switcherBox!.y).toBeGreaterThanOrEqual(headerBox!.y + headerBox!.height - 1)
		expect(toolBox!.y).toBeGreaterThanOrEqual(switcherBox!.y + switcherBox!.height - 1)
		expect(Math.abs(headerBox!.height - metronomeHeaderBox!.height)).toBeLessThanOrEqual(1)
	})
})
