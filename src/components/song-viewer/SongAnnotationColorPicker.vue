<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ViewportPopover from '../ViewportPopover.vue'

export interface ColorSwatch {
	color: string
	label: string
}

const props = withDefaults(
	defineProps<{
		modelValue: string
		opacity?: number
		swatches: ColorSwatch[]
	}>(),
	{
		opacity: 1,
	},
)

const emit = defineEmits<{
	'update:modelValue': [color: string]
	'update:opacity': [opacity: number]
}>()

const isOpen = ref(false)

function selectSwatch(color: string) {
	emit('update:modelValue', color)
	isOpen.value = false
}

function onCustomInput(e: Event) {
	const value = (e.target as HTMLInputElement).value
	emit('update:modelValue', value)
}

function onOpacityInput(e: Event) {
	const value = Number((e.target as HTMLInputElement).value)
	emit('update:opacity', Math.round(value * 100) / 100)
}
</script>

<template>
	<ViewportPopover
		v-model:open="isOpen"
		pane-test-id="annotation-color-popover"
		:pane-class="$style.popover"
		:gap="4">
		<template #trigger="{ toggle }">
			<button
				type="button"
				:class="$style.triggerBtn"
				:title="t('sheetmusic', 'Color: {color}', { color: props.modelValue })"
				:aria-label="t('sheetmusic', 'Choose color')"
				:aria-expanded="isOpen"
				data-test="annotation-color-trigger"
				@click="toggle">
				<span :class="$style.triggerSwatchWrap">
					<span :class="$style.triggerSwatch" :style="{ backgroundColor: props.modelValue, opacity: props.opacity }" />
				</span>
				<ChevronDown :size="16" :class="$style.chevron" />
			</button>
		</template>
			<div :class="$style.swatchGrid">
				<button
					v-for="s in props.swatches"
					:key="s.color"
					type="button"
					:class="[$style.swatchBtn, props.modelValue.toLowerCase() === s.color.toLowerCase() && $style.activeSwatch]"
					:style="{ backgroundColor: s.color }"
					:title="s.label"
					:aria-label="s.label"
					:data-test="`color-swatch-${s.color}`"
					@click="selectSwatch(s.color)" />
			</div>

			<label :class="$style.customRow">
				<input
					type="color"
					:value="props.modelValue"
					:class="$style.nativeColorInput"
					data-test="annotation-custom-color-input"
					@input="onCustomInput" />
				<span :class="$style.customLabel">{{ t('sheetmusic', 'Custom') }}</span>
				<span :class="$style.hexLabel">{{ props.modelValue.toUpperCase() }}</span>
			</label>

			<label :class="$style.opacityRow">
				<span :class="$style.customLabel">{{ t('sheetmusic', 'Opacity') }}</span>
				<input
					type="range"
					min="0.1"
					max="1"
					step="0.05"
					:value="props.opacity"
					:class="$style.opacitySlider"
					data-test="annotation-opacity-slider"
					@input="onOpacityInput" />
				<span :class="$style.opacityVal">{{ Math.round(props.opacity * 100) }}%</span>
			</label>
	</ViewportPopover>
</template>

<style module>
.triggerBtn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	height: 32px;
	padding: 0 6px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	transition: border-color 0.12s;
}

.triggerBtn:hover {
	border-color: var(--color-primary-element, #0082c9);
}

.triggerSwatchWrap {
	width: 18px;
	height: 18px;
	border-radius: 50%;
	border: 1px solid rgba(0, 0, 0, 0.2);
	flex-shrink: 0;
	overflow: hidden;
	background-image:
		linear-gradient(45deg, #ccc 25%, transparent 25%),
		linear-gradient(-45deg, #ccc 25%, transparent 25%),
		linear-gradient(45deg, transparent 75%, #ccc 75%),
		linear-gradient(-45deg, transparent 75%, #ccc 75%);
	background-size: 8px 8px;
	background-position: 0 0, 0 4px, 4px -4px, -4px 0;
}

.triggerSwatch {
	display: block;
	width: 100%;
	height: 100%;
}

.chevron {
	color: var(--color-text-maxcontrast);
	display: inline-flex;
}

.popover {
	min-width: 176px;
	padding: 10px;
}

.swatchGrid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 8px;
}

.swatchBtn {
	width: 26px;
	height: 26px;
	border-radius: 50%;
	border: 2px solid rgba(0, 0, 0, 0.15);
	cursor: pointer;
	padding: 0;
	transition: transform 0.1s, border-color 0.1s;
}

.swatchBtn:hover {
	transform: scale(1.1);
}

.activeSwatch {
	border-color: var(--color-primary-element, #0082c9);
	box-shadow: 0 0 0 2px var(--color-main-background), 0 0 0 4px var(--color-primary-element, #0082c9);
}

.customRow {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 10px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
	cursor: pointer;
}

.nativeColorInput {
	width: 26px;
	height: 24px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background: transparent;
	cursor: pointer;
}

.customLabel {
	font-size: 0.82rem;
	color: var(--color-main-text);
}

.hexLabel {
	margin-left: auto;
	font-size: 0.75rem;
	font-family: monospace;
	color: var(--color-text-maxcontrast);
}

.opacityRow {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.opacitySlider {
	flex: 1;
	min-width: 80px;
	accent-color: var(--color-primary-element, #0082c9);
	cursor: pointer;
}

.opacityVal {
	font-size: 0.75rem;
	font-family: monospace;
	min-width: 34px;
	text-align: right;
	color: var(--color-text-maxcontrast);
}
</style>
