<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import {
	SCORE_BRIGHTNESS_MAX,
	SCORE_BRIGHTNESS_MIN,
	SCORE_COLOR_PRESETS,
	SCORE_SEPIA_MAX,
	SCORE_SEPIA_MIN,
	type ScoreColorMode,
} from '../../utils/performance-settings'

withDefaults(defineProps<{
	scoreColorMode?: ScoreColorMode
	scoreBrightness?: number
	scoreSepia?: number
	testIdPrefix?: string
}>(), {
	scoreColorMode: 'normal',
	scoreBrightness: SCORE_BRIGHTNESS_MAX,
	scoreSepia: SCORE_SEPIA_MIN,
	testIdPrefix: 'performance-score',
})

const emit = defineEmits<{
	'update:scoreColorMode': [mode: ScoreColorMode]
	'update:scoreBrightness': [brightness: number]
	'update:scoreSepia': [sepia: number]
}>()
</script>

<template>
	<div :class="$style.controls">
		<div :class="$style.swatchGrid">
			<button
				v-for="preset in SCORE_COLOR_PRESETS"
				:key="preset.value"
				type="button"
				:class="[$style.swatchBtn, scoreColorMode === preset.value && $style.swatchBtnActive]"
				:aria-pressed="scoreColorMode === preset.value"
				:data-test="`${testIdPrefix}-mode-${preset.value}`"
				@click="emit('update:scoreColorMode', preset.value)">
				<span
					:class="$style.scoreChip"
					:style="{ backgroundColor: preset.previewBg, color: preset.previewInk }"
					aria-hidden="true">
					♪
				</span>
				<span :class="$style.swatchLabel">{{ preset.label }}</span>
			</button>
		</div>

		<label :class="$style.sliderRow">
			<span>{{ t('sheetmusic', 'Brightness') }}</span>
			<div :class="$style.sliderControls">
				<input
					type="range"
					:min="SCORE_BRIGHTNESS_MIN"
					:max="SCORE_BRIGHTNESS_MAX"
					step="5"
					:value="scoreBrightness"
					:aria-label="t('sheetmusic', 'Score brightness')"
					:data-test="`${testIdPrefix}-brightness`"
					@input="emit('update:scoreBrightness', Number(($event.target as HTMLInputElement).value))">
				<span :class="$style.sliderValue" :data-test="`${testIdPrefix}-brightness-value`">
					{{ scoreBrightness }}%
				</span>
			</div>
		</label>

		<label :class="$style.sliderRow">
			<span>{{ t('sheetmusic', 'Sepia tint') }}</span>
			<div :class="$style.sliderControls">
				<input
					type="range"
					:min="SCORE_SEPIA_MIN"
					:max="SCORE_SEPIA_MAX"
					step="5"
					:value="scoreSepia"
					:aria-label="t('sheetmusic', 'Sepia tint')"
					:data-test="`${testIdPrefix}-sepia`"
					@input="emit('update:scoreSepia', Number(($event.target as HTMLInputElement).value))">
				<span :class="$style.sliderValue" :data-test="`${testIdPrefix}-sepia-value`">
					{{ scoreSepia }}%
				</span>
			</div>
		</label>
	</div>
</template>

<style module>
.controls {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.swatchGrid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 6px;
}

.swatchBtn {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
	cursor: pointer;
	text-align: left;
}

.swatchBtn:hover {
	background: var(--color-background-hover);
}

.swatchBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	font-weight: 600;
}

.scoreChip {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
	border-radius: 4px;
	border: 1px solid var(--color-border-dark, rgba(0, 0, 0, 0.25));
	font-size: 0.78rem;
	font-weight: 700;
	line-height: 1;
	flex-shrink: 0;
}

.swatchLabel {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sliderRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	font-size: 0.84rem;
	padding: 2px 4px;
}

.sliderControls {
	display: flex;
	align-items: center;
	gap: 8px;
}

.sliderValue {
	min-width: 38px;
	text-align: right;
	font-variant-numeric: tabular-nums;
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
