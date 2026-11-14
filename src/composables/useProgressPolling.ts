import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { onBeforeUnmount, ref, type Ref } from 'vue'

export interface ProgressSnapshot {
	status: 'running' | 'completed' | 'error'
	stage: string
	current: number
	total: number
	percent: number
	message: string
}

export interface UseProgressPollingOptions {
	intervalMs?: number
	trackElapsed?: boolean
	preserveEmptyMessage?: boolean
	fetchProgress?: (progressId: string) => Promise<Record<string, unknown> | undefined>
}

export interface UseProgressPollingReturn {
	progress: Ref<ProgressSnapshot | null>
	elapsedSeconds: Ref<number>
	start: (progressId: string, initialMessage?: string, initialStage?: string) => void
	stop: () => void
}

async function defaultFetchProgress(progressId: string): Promise<Record<string, unknown> | undefined> {
	const url = generateOcsUrl('/apps/sheetmusic/api/libraries/progress/' + encodeURIComponent(progressId))
	const res = await axios.get(url)
	const data = res?.data?.ocs?.data
	return data && typeof data === 'object' ? data as Record<string, unknown> : undefined
}

/**
 * Poll a backend library progress endpoint until it reaches 'completed' or 'error'.
 * Automatically clears active timers when stopped or when the component unmounts.
 */
export function useProgressPolling(options: UseProgressPollingOptions = {}): UseProgressPollingReturn {
	const {
		intervalMs = 300,
		trackElapsed = false,
		preserveEmptyMessage = false,
		fetchProgress = defaultFetchProgress,
	} = options

	const progress = ref<ProgressSnapshot | null>(null)
	const elapsedSeconds = ref(0)
	let pollingTimer: ReturnType<typeof setInterval> | null = null
	let elapsedTimer: ReturnType<typeof setInterval> | null = null

	function stop() {
		if (pollingTimer !== null) {
			clearInterval(pollingTimer)
			pollingTimer = null
		}
		if (elapsedTimer !== null) {
			clearInterval(elapsedTimer)
			elapsedTimer = null
		}
	}

	function start(progressId: string, initialMessage = '', initialStage = 'starting') {
		stop()
		if (trackElapsed) {
			elapsedSeconds.value = 0
			const startTime = Date.now()
			elapsedTimer = setInterval(() => {
				elapsedSeconds.value = Math.floor((Date.now() - startTime) / 1000)
			}, 1000)
		}

		progress.value = {
			status: 'running',
			stage: initialStage,
			current: 0,
			total: 0,
			percent: 0,
			message: initialMessage,
		}

		pollingTimer = setInterval(async () => {
			try {
				const data = await fetchProgress(progressId)
				if (!data || typeof data !== 'object') return

				const incomingMessage = data.message ? String(data.message) : ''
				const nextMessage = preserveEmptyMessage && !incomingMessage
					? (progress.value?.message ?? '')
					: String(data.message ?? '')

				const status = data.status === 'completed' || data.status === 'error' ? data.status : 'running'
				progress.value = {
					status,
					stage: String(data.stage ?? ''),
					current: Number(data.current ?? 0),
					total: Number(data.total ?? 0),
					percent: Number(data.percent ?? 0),
					message: nextMessage,
				}
				if (status === 'completed' || status === 'error') {
					stop()
				}
			} catch {
				// Ignore transient polling errors
			}
		}, intervalMs)
	}

	onBeforeUnmount(stop)

	return {
		progress,
		elapsedSeconds,
		start,
		stop,
	}
}
