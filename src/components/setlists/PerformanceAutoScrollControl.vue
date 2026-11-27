<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import PauseIcon from 'vue-material-design-icons/Pause.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import SkipPreviousIcon from 'vue-material-design-icons/SkipPrevious.vue'
import LogRangeSlider from '../LogRangeSlider.vue'
import ViewportPopover from '../ViewportPopover.vue'
import type { AutoScrollMode } from '../../composables/useAutoScroll'
import {
	AUTO_SCROLL_SPEED_MAX,
	AUTO_SCROLL_SPEED_MIN,
} from '../../utils/performance-settings'
import { formatDuration } from '../../utils/song-formatter'

const DELAY_PRESETS = [0, 3, 5, 10] as const

const props = withDefaults(defineProps<{
	mode: AutoScrollMode
	speedPxPerSec: number
	startDelaySeconds: number
	isRunning: boolean
	isPaused: boolean
	countdownRemaining: number
	songDurationSeconds?: number | null
	open?: boolean
}>(), {
	songDurationSeconds: null,
	open: undefined,
})

const emit = defineEmits<{
	toggle: []
	resetToTop: []
	'update:mode': [mode: AutoScrollMode]
	'update:speedPxPerSec': [speed: number]
	adjustSpeed: [delta: number]
	'update:startDelaySeconds': [delay: number]
	'update:open': [open: boolean]
}>()

const internalOpen = ref(false)
const isOpen = computed({
	get: () => (props.open !== undefined ? props.open : internalOpen.value),
	set: (val: boolean) => {
		internalOpen.value = val
		emit('update:open', val)
	},
})

const isActive = computed(() => props.isRunning || props.countdownRemaining > 0)
const hasSongDuration = computed(() => Boolean(props.songDurationSeconds && props.songDurationSeconds > 0))
const formattedDuration = computed(() =>
	hasSongDuration.value ? formatDuration(props.songDurationSeconds) : '',
)
const toggleLabel = computed(() => {
	if (props.countdownRemaining > 0) return t('sheetmusic', 'Cancel auto-scroll countdown')
	if (props.isRunning) return t('sheetmusic', 'Pause auto-scroll')
	if (props.isPaused) return t('sheetmusic', 'Resume auto-scroll')
	return t('sheetmusic', 'Start auto-scroll')
})
</script>

<template>
	<ViewportPopover
		v-model:open="isOpen"
		:title="t('sheetmusic', 'Auto-scroll')"
		pane-test-id="performance-autoscroll-popover"
		:pane-class="$style.popoverPane"
		:gap="6">
		<template #trigger="{ toggle }">
			<div :class="$style.splitGroup">
				<NcButton
					:variant="isActive || isPaused ? 'primary' : 'secondary'"
					size="small"
					:class="$style.mainBtn"
					:aria-pressed="isActive"
					:aria-label="toggleLabel"
					:title="toggleLabel"
					data-test="performance-autoscroll-toggle"
					@click="emit('toggle')">
					<template #icon>
						<PauseIcon v-if="isActive" :size="18" />
						<PlayIcon v-else :size="18" />
					</template>
					<template v-if="countdownRemaining > 0">
						{{ countdownRemaining }}s
					</template>
					<template v-else-if="isRunning">
						{{ mode === 'duration' && formattedDuration ? formattedDuration : `${speedPxPerSec}` }}
					</template>
				</NcButton>
				<NcButton
					:variant="isOpen || isActive || isPaused ? 'primary' : 'secondary'"
					size="small"
					:class="$style.arrowBtn"
					:aria-expanded="isOpen"
					:aria-label="t('sheetmusic', 'Auto-scroll options')"
					:title="t('sheetmusic', 'Auto-scroll options')"
					data-test="performance-autoscroll-menu"
					@click="toggle">
					<template #icon>
						<ChevronDownIcon :size="16" />
					</template>
				</NcButton>
			</div>
		</template>

		<div :class="$style.panel">
			<div :class="$style.modeTabs" role="group" :aria-label="t('sheetmusic', 'Auto-scroll calibration mode')">
				<button
					type="button"
					:class="[$style.chip, mode === 'speed' && $style.chipActive]"
					:aria-pressed="mode === 'speed'"
					data-test="autoscroll-mode-speed"
					@click="emit('update:mode', 'speed')">
					{{ t('sheetmusic', 'Custom speed') }}
				</button>
				<button
					type="button"
					:class="[$style.chip, mode === 'duration' && $style.chipActive]"
					:disabled="!hasSongDuration"
					:aria-pressed="mode === 'duration'"
					data-test="autoscroll-mode-duration"
					@click="emit('update:mode', 'duration')">
					{{ hasSongDuration ? t('sheetmusic', 'Song ({duration})', { duration: formattedDuration }) : t('sheetmusic', 'No duration') }}
				</button>
			</div>

			<div v-if="mode === 'speed'" :class="$style.speedSection">
				<div :class="$style.rowHeader">
					<span>{{ t('sheetmusic', 'Speed') }}</span>
					<span :class="$style.valueLabel" data-test="autoscroll-speed-value">{{ speedPxPerSec }} px/s</span>
				</div>
				<div :class="$style.speedControls">
					<button
						type="button"
						:class="$style.stepBtn"
						:aria-label="t('sheetmusic', 'Decrease scroll speed')"
						data-test="autoscroll-speed-minus"
						@click="emit('adjustSpeed', -5)">
						−5
					</button>
					<LogRangeSlider
						:model-value="speedPxPerSec"
						:min-value="AUTO_SCROLL_SPEED_MIN"
						:max-value="AUTO_SCROLL_SPEED_MAX"
						:step="0.02"
						:label="t('sheetmusic', 'Auto-scroll speed')"
						test-id="autoscroll-speed-slider"
						:class="$style.slider"
						@update:model-value="emit('update:speedPxPerSec', $event)" />
					<button
						type="button"
						:class="$style.stepBtn"
						:aria-label="t('sheetmusic', 'Increase scroll speed')"
						data-test="autoscroll-speed-plus"
						@click="emit('adjustSpeed', 5)">
						+5
					</button>
				</div>
			</div>
			<p v-else :class="$style.durationHint" data-test="autoscroll-duration-hint">
				{{ t('sheetmusic', 'Calibrated to scroll from top to bottom over {duration}.', { duration: formattedDuration }) }}
			</p>

			<div :class="$style.delaySection">
				<span :class="$style.rowLabel">{{ t('sheetmusic', 'Start delay') }}</span>
				<div :class="$style.delayChips">
					<button
						v-for="sec in DELAY_PRESETS"
						:key="sec"
						type="button"
						:class="[$style.chip, startDelaySeconds === sec && $style.chipActive]"
						:aria-pressed="startDelaySeconds === sec"
						:data-test="`autoscroll-delay-${sec}`"
						@click="emit('update:startDelaySeconds', sec)">
						{{ sec === 0 ? t('sheetmusic', 'Off') : `${sec}s` }}
					</button>
				</div>
			</div>

			<div :class="$style.actionsRow">
				<button
					type="button"
					:class="$style.resetBtn"
					data-test="autoscroll-reset-top"
					@click="emit('resetToTop')">
					<SkipPreviousIcon :size="16" />
					<span>{{ t('sheetmusic', 'Back to top') }}</span>
				</button>
				<span :class="$style.shortcutHint">{{ t('sheetmusic', 'Space: pause/resume') }}</span>
			</div>
		</div>
	</ViewportPopover>
</template>

<style module>
.splitGroup {
	display: inline-flex;
	align-items: center;
}

.mainBtn {
	border-top-right-radius: 0 !important;
	border-bottom-right-radius: 0 !important;
	margin-right: 0 !important;
}

.arrowBtn {
	border-top-left-radius: 0 !important;
	border-bottom-left-radius: 0 !important;
	margin-left: 1px !important;
	min-width: 24px !important;
	padding-left: 2px !important;
	padding-right: 2px !important;
}

.popoverPane {
	width: 280px;
}

.panel {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.modeTabs,
.delayChips {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}

.chip {
	flex: 1 1 auto;
	margin: 0;
	padding: 4px 8px;
	min-height: 28px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.78rem;
	cursor: pointer;
}

.chip:disabled {
	opacity: 0.45;
	cursor: not-allowed;
}

.chipActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text, #ffffff);
	font-weight: 600;
}

.speedSection,
.delaySection {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.rowHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	font-size: 0.82rem;
}

.rowLabel {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.valueLabel {
	font-variant-numeric: tabular-nums;
	font-weight: 600;
}

.speedControls {
	display: flex;
	align-items: center;
	gap: 6px;
}

.slider {
	flex: 1;
	min-width: 0;
}

.stepBtn {
	margin: 0;
	padding: 2px 6px;
	min-height: 26px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 0.76rem;
	font-weight: 600;
	cursor: pointer;
}

.durationHint {
	margin: 0;
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
	line-height: 1.35;
}

.actionsRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding-top: 4px;
	border-top: 1px solid var(--color-border);
}

.resetBtn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin: 0;
	padding: 4px 8px;
	min-height: 28px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.78rem;
	cursor: pointer;
}

.resetBtn:hover {
	background: var(--color-background-hover);
}

.shortcutHint {
	font-size: 0.72rem;
	color: var(--color-text-maxcontrast);
}
</style>
