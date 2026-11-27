import { describe, expect, it, vi } from 'vitest'
import { useScoreAppearance } from '../../../src/composables/useScoreAppearance'

describe('useScoreAppearance', () => {
	it('manages mode, night toggle, sepia coupling, brightness clamping, and change callbacks', () => {
		const onChange = vi.fn()
		const state = useScoreAppearance({ onChange })

		expect(state.scoreColorMode.value).toBe('normal')
		expect(state.scoreBrightness.value).toBe(100)
		expect(state.scoreSepia.value).toBe(0)
		expect(state.isNightMode.value).toBe(false)

		state.toggleNight()
		expect(state.scoreColorMode.value).toBe('night')
		expect(state.scoreSepia.value).toBe(0)
		expect(state.isNightMode.value).toBe(true)

		// Raising sepia in night mode switches to night-sepia
		state.setSepia(55)
		expect(state.scoreColorMode.value).toBe('night-sepia')
		expect(state.scoreSepia.value).toBe(55)

		// Zeroing sepia returns to plain night
		state.setSepia(0)
		expect(state.scoreColorMode.value).toBe('night')

		// Toggling night off returns to normal; raising sepia switches to sepia
		state.toggleNight()
		expect(state.scoreColorMode.value).toBe('normal')
		state.setSepia(40)
		expect(state.scoreColorMode.value).toBe('sepia')

		// Brightness clamps to [40..100]
		state.setBrightness(20)
		expect(state.scoreBrightness.value).toBe(40)

		expect(onChange).toHaveBeenCalledTimes(6)

		// apply() updates without firing onChange
		state.apply({ scoreColorMode: 'night-sepia', scoreBrightness: 85, scoreSepia: 35 })
		expect(state.scoreColorMode.value).toBe('night-sepia')
		expect(state.scoreBrightness.value).toBe(85)
		expect(state.scoreSepia.value).toBe(35)
		expect(onChange).toHaveBeenCalledTimes(6)
	})
})
