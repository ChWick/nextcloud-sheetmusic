<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
	defineProps<{
		modelValue: number
		minValue?: number
		maxValue?: number
		step?: number
		allowHalfStep?: boolean
		label: string
		testId?: string
	}>(),
	{
		minValue: 1,
		maxValue: 64,
		step: 0.05,
		allowHalfStep: false,
		testId: undefined,
	},
)

const emit = defineEmits<{
	'update:modelValue': [value: number]
}>()

const minLog = computed(() => Math.log2(Math.max(1, props.minValue)))
const maxLog = computed(() => Math.log2(Math.max(props.minValue + 1, props.maxValue)))

const sliderPos = computed(() => {
	const clamped = Math.max(props.minValue, Math.min(props.maxValue, Number(props.modelValue) || props.minValue))
	return Math.log2(clamped)
})

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
	<input
		type="range"
		:min="minLog"
		:max="maxLog"
		:step="step"
		:value="sliderPos"
		:title="label"
		:aria-label="label"
		:class="$style.slider"
		:data-test="testId"
		@input="onSliderInput(Number(($event.target as HTMLInputElement).value))">
</template>

<style module>
.slider {
	accent-color: var(--color-primary-element, #0082c9);
	cursor: pointer;
}
</style>
