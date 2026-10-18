<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import Rotate360 from 'vue-material-design-icons/Rotate360.vue'

const props = defineProps<{
	angle: number
	cropToOriginalSize: boolean
}>()

const emit = defineEmits<{
	'update:angle': [val: number]
	'update:cropToOriginalSize': [val: boolean]
	'adjustAngle': [delta: number]
	'resetAngle': []
}>()

const sliderExpanded = ref(false)

const sliderMin = computed(() => (sliderExpanded.value ? -180 : -45))
const sliderMax = computed(() => (sliderExpanded.value ? 180 : 45))

function onNumberInput(e: Event) {
	const val = Number((e.target as HTMLInputElement).value) || 0
	emit('update:angle', Math.round(val * 10) / 10)
}

function onSliderInput(e: Event) {
	const val = Number((e.target as HTMLInputElement).value) || 0
	emit('update:angle', Math.round(val * 10) / 10)
}

function onCheckboxChange(e: Event) {
	emit('update:cropToOriginalSize', (e.target as HTMLInputElement).checked)
}
</script>

<template>
	<div :class="$style.controlsCard">
		<div :class="$style.angleDisplayRow">
			<span :class="$style.angleLabel">
				<Rotate360 :size="20" />
				<strong>{{ t('sheetmusic', 'Rotation Angle:') }}</strong>
			</span>
			<div :class="$style.angleInputWrapper">
				<input
					:value="angle"
					type="number"
					step="0.1"
					min="-360"
					max="360"
					:class="$style.numberInput"
					:aria-label="t('sheetmusic', 'Rotation angle in degrees')"
					data-test="angle-number-input"
					@input="onNumberInput">
				<span :class="$style.degreeSign">°</span>
			</div>
			<span :class="$style.directionHint">
				{{ angle > 0 ? t('sheetmusic', '(Clockwise)') : angle < 0 ? t('sheetmusic', '(Counter-Clockwise)') : '' }}
			</span>
		</div>

		<!-- Range Slider -->
		<div :class="$style.sliderRow">
			<span :class="$style.sliderEdgeLabel">{{ sliderMin }}°</span>
			<input
				:value="angle"
				type="range"
				:min="sliderMin"
				:max="sliderMax"
				step="0.1"
				:class="$style.angleSlider"
				:aria-label="t('sheetmusic', 'Angle slider')"
				data-test="angle-slider"
				@input="onSliderInput">
			<span :class="$style.sliderEdgeLabel">+{{ sliderMax }}°</span>
			<button
				type="button"
				:class="$style.expandRangeBtn"
				:title="sliderExpanded ? t('sheetmusic', 'Switch to fine slider range (±45°)') : t('sheetmusic', 'Expand slider range to full ±180°')"
				data-test="expand-range-btn"
				@click="sliderExpanded = !sliderExpanded">
				{{ sliderExpanded ? '±45°' : '±180°' }}
			</button>
		</div>

		<!-- Quick Step Fine-Tuning Buttons -->
		<div :class="$style.quickButtonsGroup">
			<div :class="$style.fineTuneSubgroup">
				<span :class="$style.subgroupTitle">{{ t('sheetmusic', 'Fine deskew:') }}</span>
				<NcButton size="small" variant="secondary" data-test="fine-btn-m5" @click="emit('adjustAngle', -5)">-5°</NcButton>
				<NcButton size="small" variant="secondary" data-test="fine-btn-m1" @click="emit('adjustAngle', -1)">-1°</NcButton>
				<NcButton size="small" variant="secondary" data-test="fine-btn-m05" @click="emit('adjustAngle', -0.5)">-0.5°</NcButton>
				<NcButton size="small" variant="secondary" data-test="fine-btn-m01" @click="emit('adjustAngle', -0.1)">-0.1°</NcButton>
				<NcButton size="small" variant="tertiary" :disabled="angle === 0" data-test="reset-angle-btn" @click="emit('resetAngle')">0°</NcButton>
				<NcButton size="small" variant="secondary" data-test="fine-btn-p01" @click="emit('adjustAngle', 0.1)">+0.1°</NcButton>
				<NcButton size="small" variant="secondary" data-test="fine-btn-p05" @click="emit('adjustAngle', 0.5)">+0.5°</NcButton>
				<NcButton size="small" variant="secondary" data-test="fine-btn-p1" @click="emit('adjustAngle', 1)">+1°</NcButton>
				<NcButton size="small" variant="secondary" data-test="fine-btn-p5" @click="emit('adjustAngle', 5)">+5°</NcButton>
			</div>

			<div :class="$style.standardTurnsSubgroup">
				<span :class="$style.subgroupTitle">{{ t('sheetmusic', '90° turns:') }}</span>
				<NcButton size="small" variant="secondary" data-test="turn-m90" @click="emit('adjustAngle', -90)">
					<template #icon>
						<RotateLeft :size="16" />
					</template>
					-90°
				</NcButton>
				<NcButton size="small" variant="secondary" data-test="turn-p90" @click="emit('adjustAngle', 90)">
					<template #icon>
						<RotateRight :size="16" />
					</template>
					+90°
				</NcButton>
				<NcButton size="small" variant="secondary" data-test="turn-180" @click="emit('adjustAngle', 180)">
					180°
				</NcButton>
			</div>
		</div>

		<!-- Crop to original page size option -->
		<div :class="$style.optionRow">
			<label :class="$style.checkboxLabel">
				<input
					:checked="cropToOriginalSize"
					type="checkbox"
					:class="$style.checkbox"
					data-test="crop-to-original-checkbox"
					@change="onCheckboxChange">
				<span>{{ t('sheetmusic', 'Crop page when rotating (keep original size)') }}</span>
			</label>
			<span :class="$style.optionHint">
				{{ cropToOriginalSize
					? t('sheetmusic', 'Keeps original page dimensions. Rotated corners outside the page bounds will be cropped.')
					: t('sheetmusic', 'Expands page boundaries so no content is clipped.') }}
			</span>
		</div>
	</div>
</template>

<style module>
.controlsCard {
	display: flex;
	flex-direction: column;
	gap: 10px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 6px);
	padding: 12px 16px;
}

.angleDisplayRow {
	display: flex;
	align-items: center;
	gap: 12px;
}

.angleLabel {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 14px;
}

.angleInputWrapper {
	display: flex;
	align-items: center;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	padding: 2px 8px;
}

.numberInput {
	width: 70px;
	border: none;
	background: transparent;
	font-size: 15px;
	font-weight: 600;
	text-align: right;
	color: var(--color-main-text);
	outline: none;
}

.degreeSign {
	font-size: 15px;
	font-weight: 600;
	margin-left: 2px;
	color: var(--color-text-maxcontrast);
}

.directionHint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.sliderRow {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
}

.sliderEdgeLabel {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	min-width: 32px;
	text-align: center;
}

.angleSlider {
	flex: 1;
	cursor: pointer;
	height: 6px;
	accent-color: var(--color-primary-element, #0082c9);
}

.expandRangeBtn {
	font-size: 11px;
	padding: 2px 6px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 3px;
	cursor: pointer;
	color: var(--color-main-text);
}

.quickButtonsGroup {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	padding-top: 4px;
	border-top: 1px solid var(--color-border);
}

.fineTuneSubgroup {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
}

.standardTurnsSubgroup {
	display: flex;
	align-items: center;
	gap: 6px;
}

.subgroupTitle {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-right: 2px;
}

.optionRow {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.checkboxLabel {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	font-weight: 500;
	cursor: pointer;
}

.checkbox {
	cursor: pointer;
}

.optionHint {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-left: 24px;
}
</style>
