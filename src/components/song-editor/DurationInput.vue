<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { computed } from 'vue'

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

const totalSeconds = computed(() => (props.modelValue && props.modelValue > 0 ? Math.round(props.modelValue) : 0))
const hours = computed(() => Math.floor(totalSeconds.value / 3600))
const minutes = computed(() => Math.floor((totalSeconds.value % 3600) / 60))
const seconds = computed(() => totalSeconds.value % 60)

const hourOptions = computed(() => Array.from({ length: Math.max(24, hours.value + 1) }, (_, i) => i))
const sixtyOptions = Array.from({ length: 60 }, (_, i) => i)

function pad2(val: number): string {
	return val.toString().padStart(2, '0')
}

function updatePart(nextHours: number, nextMinutes: number, nextSeconds: number) {
	const nextTotal = nextHours * 3600 + nextMinutes * 60 + nextSeconds
	emit('update:modelValue', nextTotal > 0 ? nextTotal : null)
}

function onHoursChange(event: Event) {
	const val = Number((event.target as HTMLSelectElement).value)
	updatePart(Number.isFinite(val) ? val : 0, minutes.value, seconds.value)
}

function onMinutesChange(event: Event) {
	const val = Number((event.target as HTMLSelectElement).value)
	updatePart(hours.value, Number.isFinite(val) ? val : 0, seconds.value)
}

function onSecondsChange(event: Event) {
	const val = Number((event.target as HTMLSelectElement).value)
	updatePart(hours.value, minutes.value, Number.isFinite(val) ? val : 0)
}

function clearDuration() {
	emit('update:modelValue', null)
}
</script>

<template>
	<div :class="$style.wrapper">
		<label v-if="label" :for="id" :class="$style.label">
			{{ label }}
		</label>
		<div :class="$style.control">
			<ClockOutlineIcon :size="18" :class="$style.icon" />
			<div :class="$style.segments">
				<select
					:id="id"
					:value="hours"
					:class="$style.select"
					:aria-label="t('sheetmusic', 'Hours')"
					data-test="duration-hours"
					@change="onHoursChange">
					<option v-for="h in hourOptions" :key="h" :value="h">
						{{ pad2(h) }}
					</option>
				</select>
				<span :class="$style.separator" aria-hidden="true">:</span>
				<select
					:value="minutes"
					:class="$style.select"
					:aria-label="t('sheetmusic', 'Minutes')"
					data-test="duration-minutes"
					@change="onMinutesChange">
					<option v-for="m in sixtyOptions" :key="m" :value="m">
						{{ pad2(m) }}
					</option>
				</select>
				<span :class="$style.separator" aria-hidden="true">:</span>
				<select
					:value="seconds"
					:class="$style.select"
					:aria-label="t('sheetmusic', 'Seconds')"
					data-test="duration-seconds"
					@change="onSecondsChange">
					<option v-for="s in sixtyOptions" :key="s" :value="s">
						{{ pad2(s) }}
					</option>
				</select>
			</div>
			<button
				v-if="totalSeconds > 0"
				type="button"
				:class="$style.clearButton"
				:title="t('sheetmusic', 'Clear duration')"
				:aria-label="t('sheetmusic', 'Clear duration')"
				data-test="duration-clear"
				@click="clearDuration">
				<CloseIcon :size="16" />
			</button>
		</div>
	</div>
</template>

<style module>
.wrapper {
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
	height: 44px;
	padding: 0 10px;
	background: var(--color-main-background);
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius-large, 8px);
	box-sizing: border-box;
	transition: border-color 0.15s ease;
}

.control:hover {
	border-color: var(--color-primary-element);
}

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
	flex: 1;
	min-width: 0;
}

.select {
	appearance: none;
	-webkit-appearance: none;
	background: transparent;
	border: 1px solid transparent;
	border-radius: var(--border-radius, 4px);
	color: var(--color-main-text);
	font-family: inherit;
	font-size: 0.95rem;
	font-variant-numeric: tabular-nums;
	padding: 4px 6px;
	cursor: pointer;
	text-align: center;
}

.select:hover,
.select:focus {
	background: var(--color-background-hover);
	outline: none;
}

.separator {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	user-select: none;
}

.clearButton {
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

.clearButton:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}
</style>
