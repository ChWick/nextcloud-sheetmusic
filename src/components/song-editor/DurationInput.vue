<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

type Segment = 'hours' | 'minutes' | 'seconds'

const props = withDefaults(defineProps<{
	modelValue: number | null
	id?: string
	label?: string
}>(), {
	id: 'song-duration-input',
	label: '',
})

const emit = defineEmits<{
	'update:modelValue': [value: number | null]
}>()

const rootRef = ref<HTMLElement | null>(null)
const hoursRef = ref<HTMLInputElement | null>(null)
const minutesRef = ref<HTMLInputElement | null>(null)
const secondsRef = ref<HTMLInputElement | null>(null)

const totalSeconds = computed(() => (props.modelValue && props.modelValue > 0 ? Math.round(props.modelValue) : 0))
const hours = computed(() => Math.floor(totalSeconds.value / 3600))
const minutes = computed(() => Math.floor((totalSeconds.value % 3600) / 60))
const seconds = computed(() => totalSeconds.value % 60)

function pad2(val: number): string {
	return val.toString().padStart(2, '0')
}

const focusedSegment = ref<Segment | null>(null)
const hoursText = ref(pad2(hours.value))
const minutesText = ref(pad2(minutes.value))
const secondsText = ref(pad2(seconds.value))
const isPickerOpen = ref(false)

const hourOptions = computed(() => Array.from({ length: Math.max(24, hours.value + 1) }, (_, i) => i))
const sixtyOptions = Array.from({ length: 60 }, (_, i) => i)

watch(totalSeconds, () => {
	if (focusedSegment.value !== 'hours') {
		hoursText.value = pad2(hours.value)
	}
	if (focusedSegment.value !== 'minutes') {
		minutesText.value = pad2(minutes.value)
	}
	if (focusedSegment.value !== 'seconds') {
		secondsText.value = pad2(seconds.value)
	}
})

function updatePart(nextHours: number, nextMinutes: number, nextSeconds: number) {
	const clampedHours = Math.max(0, Math.min(99, nextHours))
	const clampedMinutes = Math.max(0, Math.min(59, nextMinutes))
	const clampedSeconds = Math.max(0, Math.min(59, nextSeconds))
	const nextTotal = clampedHours * 3600 + clampedMinutes * 60 + clampedSeconds
	emit('update:modelValue', nextTotal > 0 ? nextTotal : null)
}

function focusSegment(segment: Segment) {
	const target = segment === 'hours' ? hoursRef.value : segment === 'minutes' ? minutesRef.value : secondsRef.value
	target?.focus()
	target?.select()
}

function onSegmentFocus(segment: Segment, event: FocusEvent) {
	focusedSegment.value = segment
	;(event.target as HTMLInputElement).select?.()
}

function onSegmentBlur(segment: Segment) {
	if (focusedSegment.value === segment) {
		focusedSegment.value = null
	}
	if (segment === 'hours') {
		hoursText.value = pad2(hours.value)
	} else if (segment === 'minutes') {
		minutesText.value = pad2(minutes.value)
	} else {
		secondsText.value = pad2(seconds.value)
	}
}

function onSegmentInput(segment: Segment, event: Event) {
	const input = event.target as HTMLInputElement
	const digits = input.value.replace(/\D/g, '').slice(0, 2)
	input.value = digits

	const num = digits === '' ? 0 : Number(digits)
	if (segment === 'hours') {
		hoursText.value = digits
		updatePart(num, minutes.value, seconds.value)
		if (digits.length === 2) {
			focusSegment('minutes')
		}
	} else if (segment === 'minutes') {
		const clamped = Math.min(59, num)
		minutesText.value = digits.length === 2 ? pad2(clamped) : digits
		updatePart(hours.value, clamped, seconds.value)
		if (digits.length === 2) {
			focusSegment('seconds')
		}
	} else {
		const clamped = Math.min(59, num)
		secondsText.value = digits.length === 2 ? pad2(clamped) : digits
		updatePart(hours.value, minutes.value, clamped)
	}
}

function onSegmentKeydown(segment: Segment, event: KeyboardEvent) {
	if (event.key === ':') {
		event.preventDefault()
		if (segment === 'hours') focusSegment('minutes')
		else if (segment === 'minutes') focusSegment('seconds')
		return
	}

	if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
		event.preventDefault()
		const delta = event.key === 'ArrowUp' ? 1 : -1
		if (segment === 'hours') {
			const next = (hours.value + delta + 100) % 100
			hoursText.value = pad2(next)
			updatePart(next, minutes.value, seconds.value)
		} else if (segment === 'minutes') {
			const next = (minutes.value + delta + 60) % 60
			minutesText.value = pad2(next)
			updatePart(hours.value, next, seconds.value)
		} else {
			const next = (seconds.value + delta + 60) % 60
			secondsText.value = pad2(next)
			updatePart(hours.value, minutes.value, next)
		}
		return
	}

	if (event.key === 'Backspace') {
		const input = event.target as HTMLInputElement
		if (input.value === '') {
			event.preventDefault()
			if (segment === 'seconds') focusSegment('minutes')
			else if (segment === 'minutes') focusSegment('hours')
		}
	}

	if (event.key === 'Escape' && isPickerOpen.value) {
		isPickerOpen.value = false
	}
}

function selectFromPicker(segment: Segment, val: number) {
	if (segment === 'hours') {
		hoursText.value = pad2(val)
		updatePart(val, minutes.value, seconds.value)
	} else if (segment === 'minutes') {
		minutesText.value = pad2(val)
		updatePart(hours.value, val, seconds.value)
	} else {
		secondsText.value = pad2(val)
		updatePart(hours.value, minutes.value, val)
	}
}

function clearDuration() {
	hoursText.value = '00'
	minutesText.value = '00'
	secondsText.value = '00'
	emit('update:modelValue', null)
}

function handleDocumentClick(event: MouseEvent) {
	if (!isPickerOpen.value) return
	if (rootRef.value && !rootRef.value.contains(event.target as Node)) {
		isPickerOpen.value = false
	}
}

onMounted(() => {
	document.addEventListener('mousedown', handleDocumentClick)
})

onBeforeUnmount(() => {
	document.removeEventListener('mousedown', handleDocumentClick)
})
</script>

<template>
	<div ref="rootRef" :class="$style.wrapper">
		<label v-if="label" :for="id" :class="$style.label">
			{{ label }}
		</label>
		<div :class="$style.control">
			<ClockOutlineIcon :size="18" :class="$style.icon" />
			<div :class="$style.segments">
				<input
					:id="id"
					ref="hoursRef"
					type="text"
					inputmode="numeric"
					maxlength="2"
					:value="hoursText"
					:class="$style.segmentInput"
					:aria-label="t('sheetmusic', 'Hours')"
					data-test="duration-hours"
					@focus="onSegmentFocus('hours', $event)"
					@blur="onSegmentBlur('hours')"
					@input="onSegmentInput('hours', $event)"
					@keydown="onSegmentKeydown('hours', $event)">
				<span :class="$style.separator" aria-hidden="true">:</span>
				<input
					ref="minutesRef"
					type="text"
					inputmode="numeric"
					maxlength="2"
					:value="minutesText"
					:class="$style.segmentInput"
					:aria-label="t('sheetmusic', 'Minutes')"
					data-test="duration-minutes"
					@focus="onSegmentFocus('minutes', $event)"
					@blur="onSegmentBlur('minutes')"
					@input="onSegmentInput('minutes', $event)"
					@keydown="onSegmentKeydown('minutes', $event)">
				<span :class="$style.separator" aria-hidden="true">:</span>
				<input
					ref="secondsRef"
					type="text"
					inputmode="numeric"
					maxlength="2"
					:value="secondsText"
					:class="$style.segmentInput"
					:aria-label="t('sheetmusic', 'Seconds')"
					data-test="duration-seconds"
					@focus="onSegmentFocus('seconds', $event)"
					@blur="onSegmentBlur('seconds')"
					@input="onSegmentInput('seconds', $event)"
					@keydown="onSegmentKeydown('seconds', $event)">
			</div>
			<button
				v-if="totalSeconds > 0"
				type="button"
				:class="$style.iconButton"
				:title="t('sheetmusic', 'Clear duration')"
				:aria-label="t('sheetmusic', 'Clear duration')"
				data-test="duration-clear"
				@click="clearDuration">
				<CloseIcon :size="16" />
			</button>
			<button
				type="button"
				:class="$style.iconButton"
				:title="t('sheetmusic', 'Select duration')"
				:aria-label="t('sheetmusic', 'Select duration')"
				:aria-expanded="isPickerOpen"
				data-test="duration-picker-toggle"
				@click="isPickerOpen = !isPickerOpen">
				<ChevronDownIcon :size="18" />
			</button>
		</div>

		<div v-if="isPickerOpen" :class="$style.popover" data-test="duration-picker-popover">
			<div :class="$style.column">
				<div :class="$style.columnHeader">{{ t('sheetmusic', 'HH') }}</div>
				<div :class="$style.columnList">
					<button
						v-for="h in hourOptions"
						:key="h"
						type="button"
						:class="[$style.optionButton, h === hours && $style.optionActive]"
						:data-test="`duration-pick-hours-${h}`"
						@click="selectFromPicker('hours', h)">
						{{ pad2(h) }}
					</button>
				</div>
			</div>
			<div :class="$style.column">
				<div :class="$style.columnHeader">{{ t('sheetmusic', 'MM') }}</div>
				<div :class="$style.columnList">
					<button
						v-for="m in sixtyOptions"
						:key="m"
						type="button"
						:class="[$style.optionButton, m === minutes && $style.optionActive]"
						:data-test="`duration-pick-minutes-${m}`"
						@click="selectFromPicker('minutes', m)">
						{{ pad2(m) }}
					</button>
				</div>
			</div>
			<div :class="$style.column">
				<div :class="$style.columnHeader">{{ t('sheetmusic', 'SS') }}</div>
				<div :class="$style.columnList">
					<button
						v-for="s in sixtyOptions"
						:key="s"
						type="button"
						:class="[$style.optionButton, s === seconds && $style.optionActive]"
						:data-test="`duration-pick-seconds-${s}`"
						@click="selectFromPicker('seconds', s)">
						{{ pad2(s) }}
					</button>
				</div>
			</div>
		</div>
	</div>
</template>

<style module>
.wrapper {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 4px;
	width: 100%;
}

.label {
	font-size: 0.85rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.control {
	display: flex;
	align-items: center;
	gap: 6px;
	width: 100%;
	min-width: max-content;
	height: 44px;
	padding: 0 8px 0 10px;
	background: var(--color-main-background);
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	box-sizing: border-box;
	transition: border-color 0.15s ease;
}

.control:hover,
.control:focus-within {
	border-color: var(--color-primary-element);
}

.icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.segments {
	display: flex;
	align-items: center;
	gap: 2px;
	flex: 1 0 auto;
}

.segments .segmentInput {
	width: 3.2em;
	min-width: 3.2em;
	box-sizing: border-box;
	margin: 0;
	padding: 4px 2px;
	border: 1px solid transparent;
	border-radius: var(--border-radius, 4px);
	background: transparent;
	color: var(--color-main-text);
	font-family: inherit;
	font-size: 0.95rem;
	font-variant-numeric: tabular-nums;
	text-align: center;
	text-overflow: clip;
}

.segments .segmentInput:hover,
.segments .segmentInput:focus {
	background: var(--color-background-hover);
	outline: none;
}

.separator {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	user-select: none;
}

.iconButton {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 4px;
	background: transparent;
	border: none;
	border-radius: var(--border-radius-rounded, 50%);
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	flex-shrink: 0;
}

.iconButton:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.popover {
	position: absolute;
	top: calc(100% + 4px);
	left: 0;
	z-index: 100;
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 4px;
	width: 210px;
	padding: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: 0 4px 16px var(--color-box-shadow, rgba(0, 0, 0, 0.16));
}

.column {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.columnHeader {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding-bottom: 4px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 4px;
}

.columnList {
	display: flex;
	flex-direction: column;
	max-height: 180px;
	overflow-y: auto;
	gap: 2px;
}

.optionButton {
	padding: 4px 6px;
	border: none;
	border-radius: var(--border-radius, 4px);
	background: transparent;
	color: var(--color-main-text);
	font-family: inherit;
	font-size: 0.85rem;
	font-variant-numeric: tabular-nums;
	text-align: center;
	cursor: pointer;
}

.optionButton:hover {
	background: var(--color-background-hover);
}

.optionActive {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.optionActive:hover {
	background: var(--color-primary-element-hover, var(--color-primary-element));
}
</style>
