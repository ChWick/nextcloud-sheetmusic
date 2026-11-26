import { onUnmounted, ref } from 'vue'
import {
	clampNormalizedCoord,
	type LinkArrivalBeacon,
	type LinkJumpBackEntry,
	type LinkJumpPayload,
} from '../utils/annotationLinks'

const BEACON_DURATION_MS = 2200
const JUMP_BACK_DURATION_MS = 8000

export interface UseLinkJumpNavigationOptions {
	goToPage: (targetOneIndexed: number) => void
	beaconDurationMs?: number
	jumpBackDurationMs?: number
}

/**
 * Coordinates interactive annotation link jumps: navigates to the destination page,
 * flashes an arrival beacon at the target spot, and offers a temporary return chip.
 */
export function useLinkJumpNavigation(opts: UseLinkJumpNavigationOptions) {
	const activeBeacon = ref<LinkArrivalBeacon | null>(null)
	const jumpBackEntry = ref<LinkJumpBackEntry | null>(null)

	let beaconTimer: ReturnType<typeof setTimeout> | null = null
	let jumpBackTimer: ReturnType<typeof setTimeout> | null = null
	let beaconToken = 0

	function clearBeaconTimer(): void {
		if (beaconTimer !== null) {
			clearTimeout(beaconTimer)
			beaconTimer = null
		}
	}

	function clearJumpBackTimer(): void {
		if (jumpBackTimer !== null) {
			clearTimeout(jumpBackTimer)
			jumpBackTimer = null
		}
	}

	function showBeacon(pageOneIndexed: number, x = 0.5, y = 0.18): void {
		clearBeaconTimer()
		activeBeacon.value = {
			pageNumber: pageOneIndexed,
			x: clampNormalizedCoord(x) ?? 0.5,
			y: clampNormalizedCoord(y) ?? 0.18,
			token: ++beaconToken,
		}
		beaconTimer = setTimeout(() => {
			activeBeacon.value = null
			beaconTimer = null
		}, opts.beaconDurationMs ?? BEACON_DURATION_MS)
	}

	function dismissJumpBack(): void {
		clearJumpBackTimer()
		jumpBackEntry.value = null
	}

	function jumpFromLink(payload: LinkJumpPayload): void {
		const targetOneIndexed = Math.max(1, Math.round(payload.targetPage) + 1)
		const sourceOneIndexed = Math.max(1, Math.round(payload.sourcePage) + 1)

		opts.goToPage(targetOneIndexed)
		const tx = clampNormalizedCoord(payload.targetX)
		const ty = clampNormalizedCoord(payload.targetY)
		if (tx !== undefined && ty !== undefined) {
			showBeacon(targetOneIndexed, tx, ty)
		} else {
			clearBeaconTimer()
			activeBeacon.value = null
		}

		if (sourceOneIndexed !== targetOneIndexed) {
			clearJumpBackTimer()
			jumpBackEntry.value = {
				returnPage: sourceOneIndexed,
				fromLabel: payload.label,
			}
			jumpBackTimer = setTimeout(() => {
				jumpBackEntry.value = null
				jumpBackTimer = null
			}, opts.jumpBackDurationMs ?? JUMP_BACK_DURATION_MS)
		} else {
			dismissJumpBack()
		}
	}

	function jumpBack(): void {
		const entry = jumpBackEntry.value
		if (!entry) return
		dismissJumpBack()
		opts.goToPage(entry.returnPage)
	}

	onUnmounted(() => {
		clearBeaconTimer()
		clearJumpBackTimer()
	})

	return {
		activeBeacon,
		jumpBackEntry,
		jumpFromLink,
		jumpBack,
		dismissJumpBack,
	}
}
