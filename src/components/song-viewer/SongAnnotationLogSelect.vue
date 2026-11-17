<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { clampNcSelectDropdown } from '../../composables/useViewportPopoverClamp'

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

const selectRef = ref<InstanceType<typeof NcSelect> | null>(null)
const isDragging = ref(false)

const minLog = computed(() => Math.log2(Math.max(1, props.minValue)))
const maxLog = computed(() => Math.log2(Math.max(props.minValue + 1, props.maxValue)))

const options = computed(() => props.presets.map((value) => ({ value, label: String(value) })))

const currentOption = computed(() => {
	return options.value.find((o) => o.value === props.modelValue) ?? {
		value: props.modelValue,
		label: String(props.modelValue),
	}
})

const sliderPos = computed(() => {
	const clamped = Math.max(props.minValue, Math.min(props.maxValue, Number(props.modelValue) || props.minValue))
	return Math.log2(clamped)
})

function dropdownShouldOpen({ noDrop, open }: { noDrop: boolean; open: boolean }): boolean {
	return (open || isDragging.value) && !noDrop
}

function finishDrag() {
	if (!isDragging.value) return
	isDragging.value = false
	window.removeEventListener('pointerup', finishDrag)
	window.removeEventListener('mouseup', finishDrag)
	const input = selectRef.value?.$el?.querySelector?.('input') as HTMLInputElement | null
	input?.focus()
}

function onSliderStart() {
	isDragging.value = true
	window.addEventListener('pointerup', finishDrag, { once: true })
	window.addEventListener('mouseup', finishDrag, { once: true })
}

function onSliderInput(pos: number) {
	const clampedPos = Math.max(minLog.value, Math.min(maxLog.value, pos))
	const raw = Math.pow(2, clampedPos)
	const rounded = props.allowHalfStep && raw < 4
		? Math.round(raw * 2) / 2
		: Math.round(raw)
	emit('update:modelValue', Math.max(props.minValue, Math.min(props.maxValue, rounded)))
}

onUnmounted(() => {
	window.removeEventListener('pointerup', finishDrag)
	window.removeEventListener('mouseup', finishDrag)
})
</script>

<template>
	<NcSelect
		ref="selectRef"
		:model-value="currentOption"
		:options="options"
		:clearable="false"
		:searchable="false"
		:append-to-body="false"
		:dropdown-should-open="dropdownShouldOpen"
		:input-label="inputLabel"
		label="label"
		track-by="value"
		:class="$style.ncSelect"
		:data-test="selectTestId"
		@open="clampNcSelectDropdown(selectRef)"
		@update:model-value="$event && emit('update:modelValue', $event.value)">
		<template #list-footer>
			<div
				:class="$style.sliderFooter"
				@mousedown.stop="onSliderStart"
				@pointerdown.stop="onSliderStart"
				@click.stop>
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
		</template>
	</NcSelect>
</template>

<style module>
.ncSelect:global(.nc-select.v-select.select) {
	width: 84px;
	min-width: 84px;
	max-width: 84px;
}

.ncSelect:global(.nc-select.v-select.select) :global(.vs__search) {
	--input-padding-end: 26px;
	--input-padding-start: 10px;
}

.ncSelect:global(.nc-select.v-select.select.vs--single) :global(.vs__selected) {
	padding-inline: 10px 26px;
}

.ncSelect:global(.nc-select.v-select.select) :global(.vs__actions) {
	--vs-actions-padding: 0 4px 0 0;
}

.ncSelect :global(.vs__dropdown-menu) {
	width: max-content;
	min-width: 0;
}

.sliderFooter {
	display: flex;
	align-items: center;
	padding: 6px 10px;
	border-top: 1px solid var(--color-border);
}

.slider {
	width: 132px;
	min-width: 132px;
	accent-color: var(--color-primary-element, #0082c9);
	cursor: pointer;
}
</style>
