/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { onMounted, onUnmounted, type Ref } from 'vue'
import { watchDevicePixelRatio } from '../utils/pdfjs'

export interface UsePdfResizeObserverOptions {
	containerRef: Ref<HTMLElement | undefined>
	shouldRerenderOnResize: () => boolean
	canRerender: () => boolean
	onRerender: () => void
	debounceMs?: number
}

/**
 * Watches container resize, window resize, and devicePixelRatio changes,
 * debouncing re-render callbacks and ignoring no-op size notifications.
 */
export function usePdfResizeObserver(options: UsePdfResizeObserverOptions) {
	const {
		containerRef,
		shouldRerenderOnResize,
		canRerender,
		onRerender,
		debounceMs = 150,
	} = options

	let resizeObserver: ResizeObserver | null = null
	let resizeDebounceTimer: ReturnType<typeof setTimeout> | null = null
	let stopWatchingDpr: (() => void) | null = null
	let lastObservedWidth = 0
	let lastObservedHeight = 0
	let forceNextResizeRerender = false

	function syncObservedSize() {
		const el = containerRef.value
		if (el) {
			lastObservedWidth = el.clientWidth
			lastObservedHeight = el.clientHeight
		}
	}

	function scheduleRerender(force = false) {
		if (!force && !shouldRerenderOnResize()) {
			return
		}
		if (force) {
			forceNextResizeRerender = true
		}
		if (resizeDebounceTimer !== null) {
			clearTimeout(resizeDebounceTimer)
		}
		resizeDebounceTimer = setTimeout(() => {
			const shouldForce = forceNextResizeRerender
			forceNextResizeRerender = false
			resizeDebounceTimer = null
			const el = containerRef.value
			if (el) {
				const w = el.clientWidth
				const h = el.clientHeight
				if (!shouldForce && w === lastObservedWidth && h === lastObservedHeight) {
					return
				}
				lastObservedWidth = w
				lastObservedHeight = h
			}
			if (canRerender()) {
				onRerender()
			}
		}, debounceMs)
	}

	function handleResize() {
		scheduleRerender(false)
	}

	onMounted(() => {
		window.addEventListener('resize', handleResize)
		stopWatchingDpr = watchDevicePixelRatio(() => {
			scheduleRerender(true)
		})
		if (typeof ResizeObserver !== 'undefined' && containerRef.value) {
			syncObservedSize()
			resizeObserver = new ResizeObserver(() => {
				handleResize()
			})
			resizeObserver.observe(containerRef.value)
		}
	})

	onUnmounted(() => {
		stopWatchingDpr?.()
		stopWatchingDpr = null
		window.removeEventListener('resize', handleResize)
		if (resizeDebounceTimer !== null) {
			clearTimeout(resizeDebounceTimer)
			resizeDebounceTimer = null
		}
		if (resizeObserver) {
			resizeObserver.disconnect()
			resizeObserver = null
		}
	})

	return {
		syncObservedSize,
		scheduleRerender,
	}
}
