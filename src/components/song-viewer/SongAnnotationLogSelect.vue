<script setup lang="ts">
import { computed, ref } from 'vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ViewportPopover from '../ViewportPopover.vue'

const props = withDefaults(
	defineProps<{
		modelValue: number
		presets: number[]
		inputLabel: string
		sliderLabel: string
		minValue?: number
		maxValue?: number
		allowHalfStep?: boolean
		selectTestId?: string
		sliderTestId?: string
	}>(),
	{
		minValue: 1,
		maxValue: 64,
		allowHalfStep: false,
		selectTestId: undefined,
		sliderTestId: undefined,
	},
)

const emit = defineEmits<{
	'update:modelValue': [value: number]
}>()

const open = ref(false)

const minLog = computed(() => Math.log2(Math.max(1, props.minValue)))
const maxLog = computed(() => Math.log2(Math.max(props.minValue + 1, props.maxValue)))

const sliderPos = computed(() => {
	const clamped = Math.max(props.minValue, Math.min(props.maxValue, Number(props.modelValue) || props.minValue))
	return Math.log2(clamped)
})

function pickPreset(value: number) {
	emit('update:modelValue', value)
	open.value = false
}

function onSliderInput(pos: number) {
	const clampedPos = Math.max(minLog.value, Math.min(maxLog.value, pos))
	const raw = Math.pow(2, clampedPos)
	const rounded = props.allowHalfStep && raw < 4
		? Math.round(raw * 2) / 2
		: Math.round(raw)
	emit('update:modelValue', Math.max(props.minValue, Math.min(props.maxValue, rounded)))
}
</script>

<template>
	<ViewportPopover
		v-model:open="open"
		:pane-class="$style.menuPane">
		<template #trigger="{ toggle }">
			<button
				type="button"
				:class="[$style.triggerBtn, open && $style.open]"
				:title="inputLabel"
				:aria-label="`${inputLabel}: ${modelValue}`"
				:aria-expanded="open"
				:data-test="selectTestId"
				@click="toggle">
				<span :class="$style.triggerLabel">{{ inputLabel }}</span>
				<span :class="$style.triggerValue">{{ modelValue }}</span>
				<ChevronDown :size="16" :class="$style.chevron" />
			</button>
		</template>

		<div :class="$style.optionList">
			<button
				v-for="val in presets"
				:key="val"
				type="button"
				:class="[$style.optionBtn, modelValue === val && $style.optionActive]"
				:data-test="selectTestId ? `${selectTestId}-option-${val}` : undefined"
				@click="pickPreset(val)">
				{{ val }}
			</button>
		</div>

		<div :class="$style.sliderFooter" @click.stop>
			<input
				type="range"
				:min="minLog"
				:max="maxLog"
				step="0.05"
				:value="sliderPos"
				:title="sliderLabel"
				:aria-label="sliderLabel"
				:class="$style.slider"
				:data-test="sliderTestId"
				@input="onSliderInput(Number(($event.target as HTMLInputElement).value))">
		</div>
	</ViewportPopover>
</template>

<style module>
button[type='button'].triggerBtn {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	width: auto;
	min-width: 0;
	min-height: var(--default-clickable-area, 44px);
	margin: 0;
	padding: 4px 8px 4px 10px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
	cursor: pointer;
	white-space: nowrap;
}

.triggerBtn:hover {
	background: var(--color-background-hover);
}

.triggerBtn.open {
	border-color: var(--color-primary-element, #0082c9);
}

.triggerLabel {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.triggerValue {
	font-weight: 600;
	min-width: 28px;
	text-align: right;
	font-variant-numeric: tabular-nums;
}

.chevron {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

.menuPane {
	min-width: 156px;
	padding: 4px 0;
}

.optionList {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 0 4px 4px;
}

.optionBtn {
	display: flex;
	align-items: center;
	width: 100%;
	padding: 6px 10px;
	border: none;
	border-radius: 4px;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.85rem;
	text-align: left;
	cursor: pointer;
}

.optionBtn:hover {
	background: var(--color-background-hover);
}

.optionActive {
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.sliderFooter {
	display: flex;
	align-items: center;
	padding: 6px 10px 4px;
	border-top: 1px solid var(--color-border);
}

.slider {
	width: 136px;
	min-width: 136px;
	accent-color: var(--color-primary-element, #0082c9);
	cursor: pointer;
}
</style>
