<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { A4_PRESETS, MAX_A4_HZ, MIN_A4_HZ } from '../../utils/music-tools'

defineProps<{
	a4Hz: number
}>()

const emit = defineEmits<{
	'update:a4Hz': [hz: number]
}>()
</script>

<template>
	<div :class="$style.calibrationRow">
		<span :class="$style.controlLabel">{{ t('sheetmusic', 'A4 Reference') }}</span>
		<div :class="$style.a4Controls">
			<button
				type="button"
				:class="$style.a4StepBtn"
				:disabled="a4Hz <= MIN_A4_HZ"
				:aria-label="t('sheetmusic', 'Decrease A4 reference frequency')"
				data-test="pitch-a4-minus"
				@click="emit('update:a4Hz', a4Hz - 1)">
				-
			</button>
			<span :class="$style.a4Value" data-test="pitch-a4-value">{{ a4Hz }} Hz</span>
			<button
				type="button"
				:class="$style.a4StepBtn"
				:disabled="a4Hz >= MAX_A4_HZ"
				:aria-label="t('sheetmusic', 'Increase A4 reference frequency')"
				data-test="pitch-a4-plus"
				@click="emit('update:a4Hz', a4Hz + 1)">
				+
			</button>
		</div>
		<div :class="$style.presetRow">
			<button
				v-for="preset in A4_PRESETS"
				:key="preset"
				type="button"
				:class="[$style.presetBtn, a4Hz === preset && $style.presetBtnActive]"
				:data-test="`pitch-a4-preset-${preset}`"
				@click="emit('update:a4Hz', preset)">
				{{ preset }}
			</button>
		</div>
	</div>
</template>

<style module>
.calibrationRow {
	display: flex;
	flex-direction: column;
	gap: 5px;
}

.controlLabel {
	font-size: 0.74rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.a4Controls {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
}

button[type='button'].a4StepBtn {
	margin: 0;
	padding: 2px 10px;
	min-height: 28px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 700;
	cursor: pointer;
}

.a4Value {
	font-size: 0.85rem;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.presetRow {
	display: grid;
	grid-template-columns: repeat(5, minmax(0, 1fr));
	gap: 4px;
}

button[type='button'].presetBtn {
	margin: 0;
	padding: 3px 4px;
	min-height: 26px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.74rem;
	cursor: pointer;
}

button[type='button'].presetBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	font-weight: 600;
}
</style>
