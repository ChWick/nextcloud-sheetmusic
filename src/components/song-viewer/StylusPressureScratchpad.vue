<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import type { NormalizedPoint } from '../../types/annotation'
import { generatePressureStrokePath, generateSmoothSvgPath } from '../../utils/annotation'
import {
	applyPenTipOffset,
	computePressureCalibrationRange,
	createStrokePressureTracker,
	getCoalescedPointerEvents,
	type StrokePressureTracker,
	type StylusSettings,
} from '../../utils/stylus-settings'

const props = defineProps<{
	settings: StylusSettings
}>()

const emit = defineEmits<{
	'update-settings': [patch: Partial<StylusSettings>]
	'pen-detected': []
}>()

const PAD_VB_WIDTH = 1000
const PAD_VB_HEIGHT = 320
const PAD_BASE_WIDTH = 10

interface PadStroke {
	points: NormalizedPoint[]
	usePressure: boolean
}

const scratchpadRef = ref<SVGSVGElement | null>(null)
const strokes = ref<PadStroke[]>([])
const activeStroke = ref<PadStroke | null>(null)
const lastInputType = ref<string>('—')
const lastRawPressure = ref<number>(0)
const lastMappedPressure = ref<number>(0)
const recordedRawHardwareSamples = ref<number[]>([])
const pressureCalibrationMessage = ref('')

let padPressureTracker: StrokePressureTracker | null = null

const canAutoCalibratePressure = computed(() =>
	recordedRawHardwareSamples.value.length >= 4,
)

const allPadStrokes = computed(() =>
	activeStroke.value ? [...strokes.value, activeStroke.value] : strokes.value,
)

function padEventToNorm(ev: PointerEvent): NormalizedPoint {
	const el = scratchpadRef.value
	if (!el) return { x: 0, y: 0 }
	const rect = el.getBoundingClientRect()
	if (rect.width <= 0 || rect.height <= 0) return { x: 0, y: 0 }
	const adj = applyPenTipOffset(ev.clientX, ev.clientY, ev.pointerType, props.settings)
	const x = Math.min(1, Math.max(0, (adj.x - rect.left) / rect.width))
	const y = Math.min(1, Math.max(0, (adj.y - rect.top) / rect.height))
	return {
		x: Math.round(x * 10000) / 10000,
		y: Math.round(y * 10000) / 10000,
	}
}

function samplePadPressure(ev: PointerEvent): number | undefined {
	const result = padPressureTracker?.sample(ev, props.settings)
	lastInputType.value = ev.pointerType || 'mouse'
	lastRawPressure.value = Math.round((result?.rawPressure ?? 0.5) * 100) / 100
	if (result?.isHardwareVarying) {
		recordedRawHardwareSamples.value.push(result.rawPressure)
	}
	lastMappedPressure.value = result?.effectivePressure ?? 0.5
	return result?.effectivePressure
}

function onPadPointerDown(e: PointerEvent) {
	if (e.button !== 0) return
	if (e.pointerType === 'pen') {
		emit('pen-detected')
	}
	scratchpadRef.value?.setPointerCapture?.(e.pointerId)
	padPressureTracker = createStrokePressureTracker(e.pointerType)

	const pt = padEventToNorm(e)
	const p = samplePadPressure(e)
	activeStroke.value = {
		points: [p !== undefined ? { ...pt, p } : pt],
		usePressure: p !== undefined,
	}
}

function onPadPointerMove(e: PointerEvent) {
	const stroke = activeStroke.value
	if (!stroke) return
	for (const ev of getCoalescedPointerEvents(e)) {
		const pt = padEventToNorm(ev)
		const last = stroke.points[stroke.points.length - 1]
		if (!last || Math.hypot(pt.x - last.x, pt.y - last.y) > 0.002) {
			const p = samplePadPressure(ev)
			stroke.points.push(p !== undefined ? { ...pt, p } : pt)
		}
	}
}

function onPadPointerUp(e: PointerEvent) {
	const stroke = activeStroke.value
	if (!stroke) return
	if (scratchpadRef.value?.hasPointerCapture?.(e.pointerId)) {
		scratchpadRef.value.releasePointerCapture(e.pointerId)
	}
	strokes.value = [...strokes.value, stroke]
	activeStroke.value = null
	padPressureTracker = null
}

function clearScratchpad() {
	strokes.value = []
	activeStroke.value = null
	recordedRawHardwareSamples.value = []
	pressureCalibrationMessage.value = ''
}

function applyPressureRangeCalibration() {
	const range = computePressureCalibrationRange(recordedRawHardwareSamples.value)
	if (!range) return
	emit('update-settings', range)
	pressureCalibrationMessage.value = t('sheetmusic', 'Pressure range set to {min}–{max}', {
		min: range.pressureMin.toFixed(2),
		max: range.pressureMax.toFixed(2),
	})
}

function renderPadStroke(stroke: PadStroke): string {
	if (stroke.usePressure) {
		return generatePressureStrokePath(stroke.points, PAD_BASE_WIDTH, PAD_VB_HEIGHT)
	}
	return generateSmoothSvgPath(stroke.points, true, PAD_VB_HEIGHT)
}

function resetUiState() {
	pressureCalibrationMessage.value = ''
}

defineExpose({ resetUiState })
</script>

<template>
	<div :class="$style.scratchpadWrap">
		<div :class="$style.telemetryBar">
			<span>
				{{ t('sheetmusic', 'Input:') }}
				<strong data-test="pad-input-type">{{ lastInputType }}</strong>
			</span>
			<span>
				{{ t('sheetmusic', 'Raw:') }}
				<strong data-test="pad-raw-pressure">{{ lastRawPressure.toFixed(2) }}</strong>
			</span>
			<span>
				{{ t('sheetmusic', 'Mapped:') }}
				<strong data-test="pad-mapped-pressure">{{ lastMappedPressure.toFixed(2) }}</strong>
			</span>
			<span :class="$style.pressureMeter">
				<span
					:class="$style.pressureMeterFill"
					:style="{ width: `${Math.round(lastMappedPressure * 100)}%` }" />
			</span>
		</div>

		<svg
			ref="scratchpadRef"
			:class="$style.scratchpad"
			:viewBox="`0 0 ${PAD_VB_WIDTH} ${PAD_VB_HEIGHT}`"
			preserveAspectRatio="none"
			data-test="pressure-scratchpad"
			@pointerdown="onPadPointerDown"
			@pointermove="onPadPointerMove"
			@pointerup="onPadPointerUp"
			@pointercancel="onPadPointerUp">
			<text
				v-if="allPadStrokes.length === 0"
				x="500"
				y="165"
				text-anchor="middle"
				:class="$style.padPlaceholder">
				{{ t('sheetmusic', 'Draw here with light and heavy strokes to test pressure') }}
			</text>
			<template v-for="(stroke, idx) in allPadStrokes" :key="idx">
				<path
					v-if="stroke.usePressure"
					:d="renderPadStroke(stroke)"
					fill="var(--color-primary-element, #0082c9)"
					stroke="none" />
				<path
					v-else
					:d="renderPadStroke(stroke)"
					fill="none"
					stroke="var(--color-primary-element, #0082c9)"
					:stroke-width="PAD_BASE_WIDTH"
					stroke-linecap="round"
					stroke-linejoin="round" />
			</template>
		</svg>

		<div :class="$style.padActions">
			<NcButton
				variant="secondary"
				:disabled="!canAutoCalibratePressure"
				data-test="calibrate-pressure-range"
				@click="applyPressureRangeCalibration">
				{{ t('sheetmusic', 'Auto-calibrate pressure range') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				:disabled="allPadStrokes.length === 0"
				data-test="clear-scratchpad"
				@click="clearScratchpad">
				{{ t('sheetmusic', 'Clear pad') }}
			</NcButton>
		</div>

		<p v-if="pressureCalibrationMessage" :class="$style.statusMsg" data-test="pressure-calibration-status">
			{{ pressureCalibrationMessage }}
		</p>
	</div>
</template>

<style module>
.scratchpadWrap {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.telemetryBar {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
}

.pressureMeter {
	flex: 1;
	min-width: 80px;
	height: 8px;
	border-radius: 4px;
	background: var(--color-background-dark);
	overflow: hidden;
}

.pressureMeterFill {
	display: block;
	height: 100%;
	background: var(--color-primary-element, #0082c9);
	transition: width 0.05s linear;
}

.scratchpad {
	width: 100%;
	height: 140px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
	touch-action: none;
	user-select: none;
	cursor: crosshair;
}

.padPlaceholder {
	fill: var(--color-text-maxcontrast);
	font-size: 28px;
	pointer-events: none;
}

.padActions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.statusMsg {
	margin: 0;
	font-size: 0.82rem;
	color: var(--color-success, #2e7d32);
}
</style>
