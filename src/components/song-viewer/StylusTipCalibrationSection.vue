<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import CrosshairsGps from 'vue-material-design-icons/CrosshairsGps.vue'
import { computeTipCalibrationOffset, type CalibrationSample, type StylusSettings } from '../../utils/stylus-settings'

const props = defineProps<{
	offsetX: number
	offsetY: number
}>()

const emit = defineEmits<{
	'update-settings': [patch: Partial<StylusSettings>]
	'pen-detected': []
}>()

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
		emit('pen-detected')
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
		emit('update-settings', { offsetX, offsetY })
		isCalibratingTip.value = false
		calibrationStatus.value = t('sheetmusic', 'Calibrated: X {x} px, Y {y} px', {
			x: offsetX > 0 ? `+${offsetX}` : String(offsetX),
			y: offsetY > 0 ? `+${offsetY}` : String(offsetY),
		})
	}
}

function resetTipOffset() {
	emit('update-settings', { offsetX: 0, offsetY: 0 })
	resetUiState()
}

function resetUiState() {
	calibrationStatus.value = ''
	cancelTipCalibration()
}

defineExpose({ resetUiState })
</script>

<template>
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
					:disabled="props.offsetX === 0 && props.offsetY === 0"
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
					<strong>{{ props.offsetX > 0 ? `+${props.offsetX}` : props.offsetX }} px</strong>
				</span>
				<input
					type="range"
					min="-40"
					max="40"
					step="0.5"
					:value="props.offsetX"
					data-test="offset-x-slider"
					@input="emit('update-settings', { offsetX: Number(($event.target as HTMLInputElement).value) })">
			</label>
			<label :class="$style.sliderField">
				<span :class="$style.sliderLabel">
					{{ t('sheetmusic', 'Vertical offset') }}
					<strong>{{ props.offsetY > 0 ? `+${props.offsetY}` : props.offsetY }} px</strong>
				</span>
				<input
					type="range"
					min="-40"
					max="40"
					step="0.5"
					:value="props.offsetY"
					data-test="offset-y-slider"
					@input="emit('update-settings', { offsetY: Number(($event.target as HTMLInputElement).value) })">
			</label>
		</div>
	</section>
</template>

<style module>
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
</style>
