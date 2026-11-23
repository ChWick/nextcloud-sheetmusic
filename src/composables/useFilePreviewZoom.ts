/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ComputedRef, Ref } from 'vue'
import { computed, ref } from 'vue'

export type ZoomMode = 'fit-width' | 'fit-page' | 'custom'

const ZOOM_STEPS = [0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0, 2.5, 3.0, 4.0]
const ZOOM_SELECT_PRESETS = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0]

export interface UseFilePreviewZoomOptions {
	initialZoomMode: ZoomMode
	pdfContainer: Ref<HTMLElement | undefined>
	isPdfFile: ComputedRef<boolean>
	isImageFile: ComputedRef<boolean>
	onResetPan: () => void
	onRerender: () => void
}

/**
 * Manages zoom mode, scale stepping, and rotation state for `FilePreviewer`.
 */
export function useFilePreviewZoom(opts: UseFilePreviewZoomOptions) {
	const zoomMode = ref<ZoomMode>(opts.initialZoomMode)
	const customScale = ref(1.0)
	const effectiveScale = ref(1.0)
	const rotation = ref(0)
	const imageScale = ref(1.0)

	const matchesZoomPreset = computed(() =>
		ZOOM_SELECT_PRESETS.some((val) => Math.abs(val - customScale.value) < 0.01),
	)

	const zoomSelectValue = computed(() => {
		if (zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page') {
			return zoomMode.value
		}
		return matchesZoomPreset.value ? String(customScale.value) : 'custom'
	})

	const isCustomZoom = computed(() => {
		if (zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page') return false
		return !matchesZoomPreset.value
	})

	const imageStyle = computed(() => ({
		transform: `rotate(${rotation.value}deg) scale(${imageScale.value})`,
		transformOrigin: 'center center',
		transition: 'transform 0.15s ease-out',
	}))

	function setZoomMode(mode: ZoomMode) {
		zoomMode.value = mode
		if (mode === 'fit-width' || mode === 'fit-page') {
			opts.onResetPan()
			if (opts.pdfContainer.value) {
				opts.pdfContainer.value.scrollLeft = 0
				opts.pdfContainer.value.scrollTop = 0
			}
		}
		if (opts.isImageFile.value) {
			imageScale.value = mode === 'fit-width' ? 1.0 : 0.75
			return
		}
		opts.onRerender()
	}

	function onZoomSelect(event: Event) {
		const val = (event.target as HTMLSelectElement).value
		if (val === 'fit-width' || val === 'fit-page') {
			setZoomMode(val)
		} else if (val !== 'custom') {
			const scale = parseFloat(val)
			if (!Number.isNaN(scale) && scale > 0) {
				zoomMode.value = 'custom'
				customScale.value = scale
				if (opts.isImageFile.value) {
					imageScale.value = scale
				} else {
					opts.onRerender()
				}
			}
		}
	}

	function zoomIn() {
		if (opts.isImageFile.value) {
			imageScale.value = Math.min(4.0, +(imageScale.value + 0.25).toFixed(2))
			return
		}
		const current = effectiveScale.value
		const next = ZOOM_STEPS.find((s) => s > current + 0.05) ?? Math.min(4.0, +(current + 0.25).toFixed(2))
		zoomMode.value = 'custom'
		customScale.value = next
		opts.onRerender()
	}

	function zoomOut() {
		if (opts.isImageFile.value) {
			imageScale.value = Math.max(0.25, +(imageScale.value - 0.25).toFixed(2))
			return
		}
		const current = effectiveScale.value
		const prev = [...ZOOM_STEPS].reverse().find((s) => s < current - 0.05) ?? Math.max(0.25, +(current - 0.25).toFixed(2))
		zoomMode.value = 'custom'
		customScale.value = prev
		opts.onRerender()
	}

	function rotateClockwise() {
		rotation.value = (rotation.value + 90) % 360
		if (opts.isPdfFile.value) {
			opts.onRerender()
		}
	}

	return {
		zoomMode,
		customScale,
		effectiveScale,
		rotation,
		imageScale,
		zoomSelectValue,
		isCustomZoom,
		imageStyle,
		setZoomMode,
		onZoomSelect,
		zoomIn,
		zoomOut,
		rotateClockwise,
	}
}
