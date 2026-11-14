<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { splitDurationHms } from '../../utils/song-formatter'

type Segment = 'hours' | 'minutes' | 'seconds'

const SEGMENTS: Segment[] = ['hours', 'minutes', 'seconds']

const SEGMENT_CONFIG: Record<Segment, {
	max: number
	label: () => string
	header: () => string
	dataTest: string
	pickPrefix: string
}> = {
	hours: {
		max: 99,
		label: () => t('sheetmusic', 'Hours'),
		header: () => t('sheetmusic', 'HH'),
		dataTest: 'duration-hours',
		pickPrefix: 'duration-pick-hours',
	},
	minutes: {
		max: 59,
		label: () => t('sheetmusic', 'Minutes'),
		header: () => t('sheetmusic', 'MM'),
		dataTest: 'duration-minutes',
		pickPrefix: 'duration-pick-minutes',
	},
	seconds: {
		max: 59,
		label: () => t('sheetmusic', 'Seconds'),
		header: () => t('sheetmusic', 'SS'),
		dataTest: 'duration-seconds',
		pickPrefix: 'duration-pick-seconds',
	},
}

const props = withDefaults(defineProps<{
	modelValue: number | null
	id?: string
	label?: string
	compact?: boolean
}>(), {
	id: 'song-duration-input',
	label: '',
	compact: false,
})

const emit = defineEmits<{
	'update:modelValue': [value: number | null]
}>()

const rootRef = ref<HTMLElement | null>(null)
const controlRef = ref<HTMLElement | null>(null)
const popoverRef = ref<HTMLElement | null>(null)
const segmentRefs = reactive<Record<Segment, HTMLInputElement | null>>({
	hours: null,
	minutes: null,
	seconds: null,
})

const totalSeconds = computed(() => (props.modelValue && props.modelValue > 0 ? Math.round(props.modelValue) : 0))
const parts = computed(() => splitDurationHms(totalSeconds.value))

function pad2(val: number): string {
	return val.toString().padStart(2, '0')
}

const focusedSegment = ref<Segment | null>(null)
const segmentTexts = reactive<Record<Segment, string>>({
	hours: pad2(parts.value.hours),
	minutes: pad2(parts.value.minutes),
	seconds: pad2(parts.value.seconds),
})

const isPickerOpen = ref(false)
const popoverStyle = ref<{ top: string, left: string }>({ top: '0px', left: '0px' })

const sixtyOptions = Array.from({ length: 60 }, (_, i) => i)
const segmentOptions = computed<Record<Segment, number[]>>(() => ({
	hours: Array.from({ length: Math.max(24, parts.value.hours + 1) }, (_, i) => i),
	minutes: sixtyOptions,
	seconds: sixtyOptions,
}))

watch(parts, (nextParts) => {
	for (const seg of SEGMENTS) {
		if (focusedSegment.value !== seg) {
			segmentTexts[seg] = pad2(nextParts[seg])
		}
	}
})

function setSegmentRef(seg: Segment, el: unknown) {
	segmentRefs[seg] = (el as HTMLInputElement) || null
}

function updatePopoverPosition() {
	const rect = controlRef.value?.getBoundingClientRect()
	if (!rect) return
	const popoverWidth = 210
	const maxLeft = Math.max(8, window.innerWidth - popoverWidth - 8)
	popoverStyle.value = {
		top: `${rect.bottom + 4}px`,
		left: `${Math.min(rect.left, maxLeft)}px`,
	}
}

async function togglePicker() {
	isPickerOpen.value = !isPickerOpen.value
	if (isPickerOpen.value) {
		await nextTick()
		updatePopoverPosition()
	}
}

function updateSegment(seg: Segment, nextVal: number) {
	const next = {
		hours: parts.value.hours,
		minutes: parts.value.minutes,
		seconds: parts.value.seconds,
		[seg]: Math.max(0, Math.min(SEGMENT_CONFIG[seg].max, nextVal)),
	}
	const nextTotal = next.hours * 3600 + next.minutes * 60 + next.seconds
	emit('update:modelValue', nextTotal > 0 ? nextTotal : null)
}

function focusSegment(seg: Segment) {
	const target = segmentRefs[seg]
	target?.focus()
	target?.select()
}

function onSegmentFocus(seg: Segment, event: FocusEvent) {
	focusedSegment.value = seg
	;(event.target as HTMLInputElement).select?.()
}

function onSegmentBlur(seg: Segment) {
	if (focusedSegment.value === seg) {
		focusedSegment.value = null
	}
	segmentTexts[seg] = pad2(parts.value[seg])
}

function onSegmentInput(seg: Segment, event: Event) {
	const input = event.target as HTMLInputElement
	const digits = input.value.replace(/\D/g, '').slice(0, 2)
	input.value = digits

	const num = digits === '' ? 0 : Number(digits)
	const clamped = Math.min(SEGMENT_CONFIG[seg].max, num)
	segmentTexts[seg] = digits.length === 2 ? pad2(clamped) : digits
	updateSegment(seg, clamped)

	if (digits.length === 2) {
		const idx = SEGMENTS.indexOf(seg)
		if (idx < SEGMENTS.length - 1) {
			focusSegment(SEGMENTS[idx + 1])
		}
	}
}

function onSegmentKeydown(seg: Segment, event: KeyboardEvent) {
	const idx = SEGMENTS.indexOf(seg)

	if (event.key === ':' && idx < SEGMENTS.length - 1) {
		event.preventDefault()
		focusSegment(SEGMENTS[idx + 1])
		return
	}

	if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
		event.preventDefault()
		const delta = event.key === 'ArrowUp' ? 1 : -1
		const mod = SEGMENT_CONFIG[seg].max + 1
		const next = (parts.value[seg] + delta + mod) % mod
		segmentTexts[seg] = pad2(next)
		updateSegment(seg, next)
		return
	}

	if (event.key === 'Backspace' && (event.target as HTMLInputElement).value === '' && idx > 0) {
		event.preventDefault()
		focusSegment(SEGMENTS[idx - 1])
		return
	}

	if (event.key === 'Escape' && isPickerOpen.value) {
		event.preventDefault()
		event.stopPropagation()
		isPickerOpen.value = false
	}
}

function selectFromPicker(seg: Segment, val: number) {
	segmentTexts[seg] = pad2(val)
	updateSegment(seg, val)
}

function clearDuration() {
	for (const seg of SEGMENTS) {
		segmentTexts[seg] = '00'
	}
	emit('update:modelValue', null)
}

function handleDocumentClick(event: MouseEvent) {
	if (!isPickerOpen.value) return
	const target = event.target as Node
	if (!rootRef.value?.contains(target) && !popoverRef.value?.contains(target)) {
		isPickerOpen.value = false
	}
}

function handleViewportChange() {
	if (isPickerOpen.value) {
		updatePopoverPosition()
	}
}

onMounted(() => {
	document.addEventListener('mousedown', handleDocumentClick)
	window.addEventListener('resize', handleViewportChange)
	window.addEventListener('scroll', handleViewportChange, true)
})

onBeforeUnmount(() => {
	document.removeEventListener('mousedown', handleDocumentClick)
	window.removeEventListener('resize', handleViewportChange)
	window.removeEventListener('scroll', handleViewportChange, true)
})

defineExpose({
	focus: () => focusSegment('hours'),
})
</script>

<template>
	<div ref="rootRef" :class="$style.wrapper">
		<label v-if="label" :for="id" :class="$style.label">
			{{ label }}
		</label>
		<div ref="controlRef" :class="[$style.control, compact && $style.controlCompact]">
			<ClockOutlineIcon :size="18" :class="$style.icon" />
			<div :class="$style.segments">
				<template v-for="(seg, idx) in SEGMENTS" :key="seg">
					<span v-if="idx > 0" :class="$style.separator" aria-hidden="true">:</span>
					<input
						:id="idx === 0 ? id : undefined"
						:ref="(el) => setSegmentRef(seg, el)"
						type="text"
						inputmode="numeric"
						maxlength="2"
						:value="segmentTexts[seg]"
						:class="$style.segmentInput"
						:aria-label="SEGMENT_CONFIG[seg].label()"
						:data-test="SEGMENT_CONFIG[seg].dataTest"
						@focus="onSegmentFocus(seg, $event)"
						@blur="onSegmentBlur(seg)"
						@input="onSegmentInput(seg, $event)"
						@keydown="onSegmentKeydown(seg, $event)">
				</template>
			</div>
			<button
				type="button"
				:class="[$style.iconButton, totalSeconds === 0 && $style.iconButtonHidden]"
				:disabled="totalSeconds === 0"
				:tabindex="totalSeconds === 0 ? -1 : 0"
				:aria-hidden="totalSeconds === 0"
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
				@click="togglePicker">
				<ChevronDownIcon :size="18" />
			</button>
		</div>

		<Teleport to="body">
			<div
				v-if="isPickerOpen"
				ref="popoverRef"
				:class="$style.popover"
				:style="popoverStyle"
				data-test="duration-picker-popover"
				@click.stop>
				<div v-for="seg in SEGMENTS" :key="seg" :class="$style.column">
					<div :class="$style.columnHeader">{{ SEGMENT_CONFIG[seg].header() }}</div>
					<div :class="$style.columnList">
						<button
							v-for="val in segmentOptions[seg]"
							:key="val"
							type="button"
							:class="[$style.optionButton, val === parts[seg] && $style.optionActive]"
							:data-test="`${SEGMENT_CONFIG[seg].pickPrefix}-${val}`"
							@click="selectFromPicker(seg, val)">
							{{ pad2(val) }}
						</button>
					</div>
				</div>
			</div>
		</Teleport>
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
	gap: 4px;
	width: 100%;
	height: 44px;
	padding: 0 6px 0 8px;
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

.controlCompact {
	height: 32px;
	border-width: 1px;
	border-radius: var(--border-radius, 4px);
}

.icon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.segments {
	display: flex;
	align-items: center;
	gap: 1px;
	flex: 1;
	min-width: 0;
}

.segments .segmentInput {
	width: 2.6em;
	min-width: 2.6em;
	box-sizing: border-box;
	margin: 0;
	padding: 2px;
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
	padding: 2px;
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

.iconButtonHidden {
	visibility: hidden;
	pointer-events: none;
}

.popover {
	position: fixed;
	z-index: 10000;
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
