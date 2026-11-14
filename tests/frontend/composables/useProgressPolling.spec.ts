import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { useProgressPolling } from '../../../src/composables/useProgressPolling'

function mountPolling(options: Parameters<typeof useProgressPolling>[0] = {}) {
	let api!: ReturnType<typeof useProgressPolling>
	const Comp = defineComponent({
		setup() {
			api = useProgressPolling(options)
			return api
		},
		template: '<div />',
	})
	const wrapper = mount(Comp)
	return { wrapper, ...api }
}

describe('useProgressPolling', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('polls progress and stops automatically on completion', async () => {
		const fetchProgress = vi
			.fn()
			.mockResolvedValueOnce({ status: 'running', stage: 'syncing', current: 1, total: 2, percent: 50, message: 'Halfway' })
			.mockResolvedValueOnce({ status: 'completed', stage: 'done', current: 2, total: 2, percent: 100, message: 'Finished' })

		const { wrapper, progress, start } = mountPolling({ intervalMs: 300, fetchProgress })
		start('job-1', 'Starting...')

		expect(progress.value).toEqual({
			status: 'running',
			stage: 'starting',
			current: 0,
			total: 0,
			percent: 0,
			message: 'Starting...',
		})

		await vi.advanceTimersByTimeAsync(300)
		expect(progress.value?.percent).toBe(50)
		expect(progress.value?.message).toBe('Halfway')

		await vi.advanceTimersByTimeAsync(300)
		expect(progress.value?.status).toBe('completed')
		expect(progress.value?.percent).toBe(100)

		await vi.advanceTimersByTimeAsync(900)
		expect(fetchProgress).toHaveBeenCalledTimes(2)
		wrapper.unmount()
	})

	it('preserves previous message when preserveEmptyMessage is true and tracks elapsed seconds', async () => {
		const fetchProgress = vi.fn().mockResolvedValue({
			status: 'running',
			stage: 'working',
			current: 1,
			total: 4,
			percent: 25,
			message: '',
		})

		const { wrapper, progress, elapsedSeconds, start, stop } = mountPolling({
			intervalMs: 500,
			trackElapsed: true,
			preserveEmptyMessage: true,
			fetchProgress,
		})

		start('job-2', 'Updating 4 songs…')
		await vi.advanceTimersByTimeAsync(1000)

		expect(progress.value?.message).toBe('Updating 4 songs…')
		expect(elapsedSeconds.value).toBe(1)

		stop()
		wrapper.unmount()
	})

	it('clears timers on unmount', async () => {
		const fetchProgress = vi.fn().mockResolvedValue({ status: 'running', percent: 10, message: 'Working' })
		const { wrapper, start } = mountPolling({ intervalMs: 200, fetchProgress })

		start('job-3')
		wrapper.unmount()

		await vi.advanceTimersByTimeAsync(1000)
		expect(fetchProgress).not.toHaveBeenCalled()
	})
})
