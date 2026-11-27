import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ConcertModeUnlockBadge from '../../../src/components/setlists/ConcertModeUnlockBadge.vue'

function waitMs(ms: number): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, ms))
}

describe('ConcertModeUnlockBadge (real timers, no mocks)', () => {
	it('ignores brief accidental taps and flashes hint, then unlocks on deliberate hold', async () => {
		const wrapper = mount(ConcertModeUnlockBadge, {
			props: {
				holdMs: 80,
			},
		})

		const btn = wrapper.find('[data-test="concert-mode-unlock-button"]')
		expect(btn.exists()).toBe(true)

		// Brief accidental tap (< 80ms) does not unlock and shows hint
		await btn.trigger('pointerdown')
		await waitMs(20)
		await btn.trigger('pointerup')
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('unlock')).toBeUndefined()
		expect(wrapper.find('[data-test="concert-mode-hint"]').exists()).toBe(true)

		// Deliberate hold (>= 80ms) unlocks cleanly
		await btn.trigger('pointerdown')
		await waitMs(110)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('unlock')).toHaveLength(1)
		await btn.trigger('pointerup')
	})

	it('cancels hold cleanly on pointerleave without unlocking', async () => {
		const wrapper = mount(ConcertModeUnlockBadge, {
			props: {
				holdMs: 80,
			},
		})

		const btn = wrapper.find('[data-test="concert-mode-unlock-button"]')
		await btn.trigger('pointerdown')
		await waitMs(30)
		await btn.trigger('pointerleave')
		await waitMs(80)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('unlock')).toBeUndefined()
	})

	it('ignores secondary mouse button and supports keyboard hold-to-unlock', async () => {
		const wrapper = mount(ConcertModeUnlockBadge, {
			props: {
				holdMs: 80,
			},
		})

		const btn = wrapper.find('[data-test="concert-mode-unlock-button"]')

		// Right-click (button: 2) should not start a hold or unlock
		await btn.trigger('pointerdown', { pointerType: 'mouse', button: 2 })
		await waitMs(110)
		await btn.trigger('pointerup', { pointerType: 'mouse', button: 2 })
		expect(wrapper.emitted('unlock')).toBeUndefined()

		// Keyboard Space hold unlocks cleanly
		await btn.trigger('keydown', { key: ' ' })
		await waitMs(110)
		await btn.trigger('keyup', { key: ' ' })
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('unlock')).toHaveLength(1)
	})
})
