<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import CrosshairsGps from 'vue-material-design-icons/CrosshairsGps.vue'
import { useStylusSettings } from '../../composables/useStylusSettings'
import type { NormalizedPoint } from '../../types/annotation'
import { generatePressureStrokePath, generateSmoothSvgPath } from '../../utils/annotation'
import {
	applyPressureSensitivity,
	computePressureCalibrationRange,
	computeTipCalibrationOffset,
	computeVelocityPressure,
	isVaryingHardwarePressureEvent,
	mapRawPressure,
	type CalibrationSample,
	type PressureMode,
} from '../../utils/stylus-settings'

const emit = defineEmits<{
	close: []
}>()

const {
	settings,
	penDetected,
	updateSettings,
	resetSettings,
	markPenDetected,
} = useStylusSettings()

const PRESSURE_MODES = computed<Array<{ value: PressureMode; label: string }>>(() => [
	{ value: 'auto', label: t('sheetmusic', 'Auto') },
	{ value: 'hardware', label: t('sheetmusic', 'Hardware') },
	{ value: 'velocity', label: t('sheetmusic', 'Velocity') },
	{ value: 'off', label: t('sheetmusic', 'Off') },
])

// --- Tip Offset Calibration ---
const CALIBRATION_TARGETS: ReadonlyArray<{ x: number; y: number }> = [
	{ x: 0.5, y: 0.5 },
	{ x: 0.2, y: 0.25 },
	{ x: 0.8, y: 0.25 },
	{ x: 0.8, y: 0.75 },
	{ x: 0.2, y: 0.75 },
]

const calibrationPadRef = ref<HTMLElement | null>(null)
const isCalibratingTip = ref(false)
const calibrationSamples = ref<CalibrationSample[]>([])
const calibrationStatus = ref('')

const currentTarget = computed(() =>
	CALIBRATION_TARGETS[calibrationSamples.value.length] ?? null,
)

function startTipCalibration() {
	isCalibratingTip.value = true
	calibrationSamples.value = []
	calibrationStatus.value = ''
}

function cancelTipCalibration() {
	isCalibratingTip.value = false
	calibrationSamples.value = []
}

function onCalibrationPadPointerDown(e: PointerEvent) {
	if (!isCalibratingTip.value || !currentTarget.value) return
	if (e.pointerType === 'pen') {
		markPenDetected()
	}
	const el = calibrationPadRef.value
	if (!el) return
	const rect = el.getBoundingClientRect()
	if (rect.width <= 0 || rect.height <= 0) return

	const targetX = currentTarget.value.x * rect.width
	const targetY = currentTarget.value.y * rect.height
	const tapX = e.clientX - rect.left
	const tapY = e.clientY - rect.top

	const nextSamples = [...calibrationSamples.value, { targetX, targetY, tapX, tapY }]
	calibrationSamples.value = nextSamples

	if (nextSamples.length >= CALIBRATION_TARGETS.length) {
		const { offsetX, offsetY } = computeTipCalibrationOffset(nextSamples)
		updateSettings({ offsetX, offsetY })
		isCalibratingTip.value = false
		calibrationStatus.value = t('sheetmusic', 'Calibrated: X {x} px, Y {y} px', {
			x: offsetX > 0 ? `+${offsetX}` : String(offsetX),
			y: offsetY > 0 ? `+${offsetY}` : String(offsetY),
		})
	}
}

function resetTipOffset() {
	updateSettings({ offsetX: 0, offsetY: 0 })
	calibrationStatus.value = ''
	cancelTipCalibration()
}

// --- Pressure Scratchpad ---
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

let padLastClientX = 0
let padLastClientY = 0
let padLastTime = 0
let padLastPressure = 0.5
let padHasHardwarePressure = false

const canAutoCalibratePressure = computed(() =>
	recordedRawHardwareSamples.value.length >= 4,
)

function padEventToNorm(ev: PointerEvent): NormalizedPoint {
	const el = scratchpadRef.value
	if (!el) return { x: 0, y: 0 }
	const rect = el.getBoundingClientRect()
	if (rect.width <= 0 || rect.height <= 0) return { x: 0, y: 0 }
	const dx = ev.pointerType === 'pen' ? settings.value.offsetX : 0
	const dy = ev.pointerType === 'pen' ? settings.value.offsetY : 0
	const x = Math.min(1, Math.max(0, (ev.clientX + dx - rect.left) / rect.width))
	const y = Math.min(1, Math.max(0, (ev.clientY + dy - rect.top) / rect.height))
	return {
		x: Math.round(x * 10000) / 10000,
		y: Math.round(y * 10000) / 10000,
	}
}

function computePadPressure(ev: PointerEvent, isStart: boolean): number | undefined {
	const cfg = settings.value
	const rawP = typeof ev.pressure === 'number' ? ev.pressure : 0.5
	lastInputType.value = ev.pointerType || 'mouse'
	lastRawPressure.value = Math.round(rawP * 100) / 100

	const isHwVarying = isVaryingHardwarePressureEvent(ev.pointerType, rawP)
	if (isHwVarying) {
		padHasHardwarePressure = true
		recordedRawHardwareSamples.value.push(rawP)
	}

	if (cfg.pressureMode === 'off' || cfg.pressureSensitivity <= 0) {
		lastMappedPressure.value = 0.5
		return undefined
	}

	const useHardware = cfg.pressureMode === 'hardware'
		|| (cfg.pressureMode === 'auto' && ev.pointerType === 'pen' && padHasHardwarePressure)

	if (useHardware) {
		if (ev.pointerType !== 'pen' && cfg.pressureMode === 'hardware') {
			lastMappedPressure.value = 0.5
			return undefined
		}
		const mapped = mapRawPressure(rawP, cfg)
		padLastPressure = mapped
		const effective = applyPressureSensitivity(mapped, cfg.pressureSensitivity)
		lastMappedPressure.value = effective
		return effective
	}

	if (cfg.pressureMode === 'hardware') {
		lastMappedPressure.value = 0.5
		return undefined
	}

	if (isStart) {
		padLastPressure = 0.5
		const effective = applyPressureSensitivity(0.5, cfg.pressureSensitivity)
		lastMappedPressure.value = effective
		return effective
	}

	const distPx = Math.hypot(ev.clientX - padLastClientX, ev.clientY - padLastClientY)
	const dtMs = Math.max(1, (ev.timeStamp || Date.now()) - padLastTime)
	const velP = computeVelocityPressure(distPx, dtMs, padLastPressure)
	padLastPressure = velP
	const effective = applyPressureSensitivity(velP, cfg.pressureSensitivity)
	lastMappedPressure.value = effective
	return effective
}

function onPadPointerDown(e: PointerEvent) {
	if (e.button !== 0) return
	if (e.pointerType === 'pen') {
		markPenDetected()
	}
	scratchpadRef.value?.setPointerCapture?.(e.pointerId)
	padHasHardwarePressure = isVaryingHardwarePressureEvent(e.pointerType, e.pressure)
	padLastClientX = e.clientX
	padLastClientY = e.clientY
	padLastTime = e.timeStamp || Date.now()
	padLastPressure = 0.5

	const pt = padEventToNorm(e)
	const p = computePadPressure(e, true)
	const stroke: PadStroke = {
		points: [p !== undefined ? { ...pt, p } : pt],
		usePressure: p !== undefined,
	}
	activeStroke.value = stroke
}

function onPadPointerMove(e: PointerEvent) {
	const stroke = activeStroke.value
	if (!stroke) return
	const coalesced = typeof e.getCoalescedEvents === 'function' ? e.getCoalescedEvents() : []
	const events = coalesced.length > 0 ? coalesced : [e]
	for (const ev of events) {
		const pt = padEventToNorm(ev)
		const last = stroke.points[stroke.points.length - 1]
		if (!last || Math.hypot(pt.x - last.x, pt.y - last.y) > 0.002) {
			const p = computePadPressure(ev, false)
			stroke.points.push(p !== undefined ? { ...pt, p } : pt)
			padLastClientX = ev.clientX
			padLastClientY = ev.clientY
			padLastTime = ev.timeStamp || Date.now()
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
	updateSettings(range)
	pressureCalibrationMessage.value = t('sheetmusic', 'Pressure range set to {min}–{max}', {
		min: range.pressureMin.toFixed(2),
		max: range.pressureMax.toFixed(2),
	})
}

const allPadStrokes = computed(() =>
	activeStroke.value ? [...strokes.value, activeStroke.value] : strokes.value,
)

function renderPadStroke(stroke: PadStroke): string {
	if (stroke.usePressure) {
		return generatePressureStrokePath(stroke.points, PAD_BASE_WIDTH, PAD_VB_HEIGHT)
	}
	return generateSmoothSvgPath(stroke.points, true, PAD_VB_HEIGHT)
}

const gammaLabel = computed(() => {
	const g = settings.value.pressureGamma
	if (g < 0.85) return t('sheetmusic', 'Soft ({val})', { val: g.toFixed(2) })
	if (g > 1.15) return t('sheetmusic', 'Firm ({val})', { val: g.toFixed(2) })
	return t('sheetmusic', 'Linear ({val})', { val: g.toFixed(2) })
})

function handleResetAll() {
	resetSettings()
	calibrationStatus.value = ''
	pressureCalibrationMessage.value = ''
	cancelTipCalibration()
}
</script>

<template>
	<NcDialog
		:name="t('sheetmusic', 'Stylus & Pressure Calibration')"
		size="normal"
		:close-on-click-outside="true"
		data-test="stylus-calibration-dialog"
		@closing="emit('close')">
		<div :class="$style.dialogBody">
			<!-- Section 1: Input & Palm Rejection -->
			<section :class="$style.section">
				<h3 :class="$style.sectionTitle">
					{{ t('sheetmusic', 'Input & Palm Rejection') }}
				</h3>
				<div :class="$style.toggles">
					<NcCheckboxRadioSwitch
						type="switch"
						:checked="settings.stylusOnlyDrawing"
						data-test="stylus-only-switch"
						@update:checked="updateSettings({ stylusOnlyDrawing: $event })">
						{{ t('sheetmusic', 'Stylus-only drawing (ignore finger touches so you can scroll & zoom)') }}
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						type="switch"
						:checked="settings.autoPalmRejection"
						data-test="auto-palm-switch"
						@update:checked="updateSettings({ autoPalmRejection: $event })">
						<span>
							{{ t('sheetmusic', 'Auto-enable palm rejection when a stylus is detected') }}
							<span v-if="penDetected" :class="$style.badge">
								{{ t('sheetmusic', 'Stylus active') }}
							</span>
						</span>
					</NcCheckboxRadioSwitch>

					<NcCheckboxRadioSwitch
						type="switch"
						:checked="settings.hardwareEraser"
						data-test="hardware-eraser-switch"
						@update:checked="updateSettings({ hardwareEraser: $event })">
						{{ t('sheetmusic', 'Use stylus eraser end to erase annotations') }}
					</NcCheckboxRadioSwitch>
				</div>
			</section>

			<!-- Section 2: Tip Offset Calibration -->
			<section :class="$style.section">
				<div :class="$style.sectionHeader">
					<h3 :class="$style.sectionTitle">
						{{ t('sheetmusic', 'Tip Offset Calibration') }}
					</h3>
					<div :class="$style.inlineActions">
						<NcButton
							v-if="!isCalibratingTip"
							variant="secondary"
							data-test="start-tip-calibration"
							@click="startTipCalibration">
							<template #icon>
								<CrosshairsGps :size="18" />
							</template>
							{{ t('sheetmusic', 'Calibrate tip') }}
						</NcButton>
						<NcButton
							v-else
							variant="secondary"
							data-test="cancel-tip-calibration"
							@click="cancelTipCalibration">
							{{ t('sheetmusic', 'Cancel') }}
						</NcButton>
						<NcButton
							variant="tertiary"
							:disabled="settings.offsetX === 0 && settings.offsetY === 0"
							data-test="reset-tip-offset"
							@click="resetTipOffset">
							{{ t('sheetmusic', 'Reset') }}
						</NcButton>
					</div>
				</div>

				<div
					v-if="isCalibratingTip && currentTarget"
					ref="calibrationPadRef"
					:class="$style.calibrationPad"
					data-test="tip-calibration-pad"
					@pointerdown="onCalibrationPadPointerDown">
					<span :class="$style.calibrationHint">
						{{ t('sheetmusic', 'Tap the center of the crosshair with your stylus ({step}/{total})', {
							step: calibrationSamples.length + 1,
							total: CALIBRATION_TARGETS.length,
						}) }}
					</span>
					<div
						:class="$style.targetCrosshair"
						data-test="tip-calibration-target"
						:style="{
							left: `${currentTarget.x * 100}%`,
							top: `${currentTarget.y * 100}%`,
						}">
						<span :class="$style.crosshairH" />
						<span :class="$style.crosshairV" />
						<span :class="$style.crosshairRing" />
					</div>
				</div>

				<p v-if="calibrationStatus" :class="$style.statusMsg" data-test="tip-calibration-status">
					{{ calibrationStatus }}
				</p>

				<div :class="$style.sliderGrid">
					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Horizontal offset') }}
							<strong>{{ settings.offsetX > 0 ? `+${settings.offsetX}` : settings.offsetX }} px</strong>
						</span>
						<input
							type="range"
							min="-40"
							max="40"
							step="0.5"
							:value="settings.offsetX"
							data-test="offset-x-slider"
							@input="updateSettings({ offsetX: Number(($event.target as HTMLInputElement).value) })">
					</label>
					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Vertical offset') }}
							<strong>{{ settings.offsetY > 0 ? `+${settings.offsetY}` : settings.offsetY }} px</strong>
						</span>
						<input
							type="range"
							min="-40"
							max="40"
							step="0.5"
							:value="settings.offsetY"
							data-test="offset-y-slider"
							@input="updateSettings({ offsetY: Number(($event.target as HTMLInputElement).value) })">
					</label>
				</div>
			</section>

			<!-- Section 3: Pressure & Feel -->
			<section :class="$style.section">
				<h3 :class="$style.sectionTitle">
					{{ t('sheetmusic', 'Pressure Sensitivity') }}
				</h3>

				<div :class="$style.modeRow" role="radiogroup" :aria-label="t('sheetmusic', 'Pressure mode')">
					<button
						v-for="mode in PRESSURE_MODES"
						:key="mode.value"
						type="button"
						:class="[$style.modeBtn, settings.pressureMode === mode.value && $style.modeActive]"
						:data-test="`pressure-mode-${mode.value}`"
						@click="updateSettings({ pressureMode: mode.value })">
						{{ mode.label }}
					</button>
				</div>

				<div v-if="settings.pressureMode !== 'off'" :class="$style.sliderGrid">
					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Width variation') }}
							<strong>{{ Math.round(settings.pressureSensitivity * 100) }}%</strong>
						</span>
						<input
							type="range"
							min="0"
							max="1"
							step="0.05"
							:value="settings.pressureSensitivity"
							data-test="pressure-sensitivity-slider"
							@input="updateSettings({ pressureSensitivity: Number(($event.target as HTMLInputElement).value) })">
					</label>

					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Response curve') }}
							<strong>{{ gammaLabel }}</strong>
						</span>
						<input
							type="range"
							min="0.3"
							max="3"
							step="0.05"
							:value="settings.pressureGamma"
							data-test="pressure-gamma-slider"
							@input="updateSettings({ pressureGamma: Number(($event.target as HTMLInputElement).value) })">
					</label>

					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Min pressure') }}
							<strong>{{ settings.pressureMin.toFixed(2) }}</strong>
						</span>
						<input
							type="range"
							min="0"
							max="0.8"
							step="0.02"
							:value="settings.pressureMin"
							data-test="pressure-min-slider"
							@input="updateSettings({ pressureMin: Number(($event.target as HTMLInputElement).value) })">
					</label>

					<label :class="$style.sliderField">
						<span :class="$style.sliderLabel">
							{{ t('sheetmusic', 'Max pressure') }}
							<strong>{{ settings.pressureMax.toFixed(2) }}</strong>
						</span>
						<input
							type="range"
							min="0.2"
							max="1"
							step="0.02"
							:value="settings.pressureMax"
							data-test="pressure-max-slider"
							@input="updateSettings({ pressureMax: Number(($event.target as HTMLInputElement).value) })">
					</label>
				</div>

				<!-- Live Pressure Scratchpad -->
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
			</section>
		</div>

		<template #actions>
			<NcButton
				variant="tertiary"
				data-test="stylus-reset-all"
				@click="handleResetAll">
				{{ t('sheetmusic', 'Reset defaults') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-test="stylus-dialog-done"
				@click="emit('close')">
				{{ t('sheetmusic', 'Done') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.dialogBody {
	display: flex;
	flex-direction: column;
	gap: 18px;
	padding: 4px 2px;
}

.section {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.sectionHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: wrap;
}

.sectionTitle {
	margin: 0;
	font-size: 0.95rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.toggles {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.badge {
	display: inline-block;
	margin-left: 6px;
	padding: 1px 6px;
	border-radius: 10px;
	font-size: 0.72rem;
	font-weight: 600;
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-primary-element, #0082c9);
}

.inlineActions {
	display: flex;
	align-items: center;
	gap: 6px;
}

.calibrationPad {
	position: relative;
	width: 100%;
	height: 170px;
	border: 2px dashed var(--color-primary-element, #0082c9);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	touch-action: none;
	user-select: none;
	cursor: crosshair;
	overflow: hidden;
}

.calibrationHint {
	position: absolute;
	top: 8px;
	left: 12px;
	right: 12px;
	text-align: center;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	pointer-events: none;
}

.targetCrosshair {
	position: absolute;
	width: 32px;
	height: 32px;
	transform: translate(-50%, -50%);
	pointer-events: none;
}

.crosshairH,
.crosshairV {
	position: absolute;
	background: var(--color-primary-element, #0082c9);
}

.crosshairH {
	top: 50%;
	left: 0;
	right: 0;
	height: 2px;
	transform: translateY(-50%);
}

.crosshairV {
	left: 50%;
	top: 0;
	bottom: 0;
	width: 2px;
	transform: translateX(-50%);
}

.crosshairRing {
	position: absolute;
	inset: 6px;
	border: 2px solid var(--color-primary-element, #0082c9);
	border-radius: 50%;
}

.statusMsg {
	margin: 0;
	font-size: 0.82rem;
	color: var(--color-success, #2e7d32);
}

.sliderGrid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
	gap: 10px 16px;
}

.sliderField {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.82rem;
	color: var(--color-main-text);
}

.sliderLabel {
	display: flex;
	justify-content: space-between;
	gap: 8px;
}

.modeRow {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.modeBtn {
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
	cursor: pointer;
}

.modeBtn:hover {
	background: var(--color-background-hover);
}

.modeActive {
	background: var(--color-primary-light, #e6f4fa);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

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
</style>
