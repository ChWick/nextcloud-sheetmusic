<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import PauseIcon from 'vue-material-design-icons/Pause.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import ViewportPopover from '../ViewportPopover.vue'
import {
	COUNTDOWN_PRESET_MINUTES,
	useStageClock,
	type StageClockMode,
} from '../../composables/useStageClock'
import { formatStageDuration, formatWallClock } from '../../utils/setlist-timing'

const props = withDefaults(defineProps<{
	remainingSeconds?: number
	totalSeconds?: number
	hudVisible?: boolean
	concertMode?: boolean
}>(), {
	remainingSeconds: 0,
	totalSeconds: 0,
	hudVisible: false,
	concertMode: false,
})

const emit = defineEmits<{
	pauseAutoHide: []
	resumeAutoHide: []
}>()

const clock = useStageClock(props.remainingSeconds > 0 ? props.remainingSeconds : 1800)
const isPopoverOpen = ref(false)

watch(isPopoverOpen, (open) => {
	if (open) emit('pauseAutoHide')
	else emit('resumeAutoHide')
})

watch(() => props.concertMode, (locked) => {
	if (locked) isPopoverOpen.value = false
})

const badgeText = computed(() => {
	if (clock.mode.value === 'remaining') {
		return props.remainingSeconds > 0
			? `-${formatStageDuration(props.remainingSeconds)}`
			: formatWallClock(clock.now.value)
	}
	if (clock.mode.value === 'countdown') {
		return formatStageDuration(clock.countdownRemainingSeconds.value)
	}
	return formatWallClock(clock.now.value)
})

const estimatedEndLabel = computed(() => {
	if (props.remainingSeconds <= 0) return ''
	const endDate = new Date(clock.now.value.getTime() + props.remainingSeconds * 1000)
	return formatWallClock(endDate)
})

const MODES: Array<{ id: StageClockMode; label: () => string }> = [
	{ id: 'clock', label: () => t('sheetmusic', 'Clock') },
	{ id: 'remaining', label: () => t('sheetmusic', 'Remaining') },
	{ id: 'countdown', label: () => t('sheetmusic', 'Timer') },
]

function onBadgeClick(toggle: () => void) {
	if (props.concertMode) return
	toggle()
}
</script>

<template>
	<div
		:class="[$style.stageClockWrap, hudVisible && !concertMode && $style.belowTopBar]"
		data-test="stage-clock-wrap">
		<ViewportPopover
			v-model:open="isPopoverOpen"
			pane-test-id="stage-clock-popover"
			:pane-class="$style.popoverPane"
			:gap="6">
			<template #trigger="{ toggle }">
				<button
					type="button"
					:class="[
						$style.badge,
						clock.mode.value === 'countdown' && clock.countdownStatus.value === 'warning' && $style.badgeWarning,
						clock.mode.value === 'countdown' && clock.countdownStatus.value === 'expired' && $style.badgeExpired,
					]"
					:title="t('sheetmusic', 'Stage clock & countdown')"
					:aria-label="t('sheetmusic', 'Stage clock & countdown')"
					:data-clock-mode="clock.mode.value"
					data-test="stage-clock-badge"
					@click.stop="onBadgeClick(toggle)">
					<ClockOutlineIcon :size="14" />
					<span>{{ badgeText }}</span>
				</button>
			</template>

			<div :class="$style.panel">
				<div :class="$style.segmented" role="tablist">
					<button
						v-for="m in MODES"
						:key="m.id"
						type="button"
						role="tab"
						:aria-selected="clock.mode.value === m.id"
						:class="[$style.segBtn, clock.mode.value === m.id && $style.segBtnActive]"
						:data-test="`stage-clock-mode-${m.id}`"
						@click="clock.setMode(m.id)">
						{{ m.label() }}
					</button>
				</div>

				<div v-if="clock.mode.value === 'clock'" :class="$style.infoBox">
					<strong :class="$style.bigTime" data-test="stage-clock-wall-time">{{ formatWallClock(clock.now.value) }}</strong>
					<span v-if="totalSeconds > 0" :class="$style.subMeta">
						{{ t('sheetmusic', 'Setlist total: {dur}', { dur: formatStageDuration(totalSeconds) }, { escape: false }) }}
					</span>
				</div>

				<div v-else-if="clock.mode.value === 'remaining'" :class="$style.infoBox">
					<strong :class="$style.bigTime" data-test="stage-clock-remaining-time">
						{{ remainingSeconds > 0 ? formatStageDuration(remainingSeconds) : '—' }}
					</strong>
					<span v-if="estimatedEndLabel" :class="$style.subMeta" data-test="stage-clock-finish-time">
						{{ t('sheetmusic', 'Est. finish: {time}', { time: estimatedEndLabel }, { escape: false }) }}
					</span>
					<span v-else :class="$style.subMeta">
						{{ t('sheetmusic', 'Add song durations to see remaining time') }}
					</span>
				</div>

				<div v-else :class="$style.countdownBox">
					<strong :class="$style.bigTime" data-test="stage-clock-countdown-time">
						{{ formatStageDuration(clock.countdownRemainingSeconds.value) }}
					</strong>
					<div :class="$style.countdownActions">
						<NcButton
							:variant="clock.isCountdownRunning.value ? 'secondary' : 'primary'"
							size="small"
							data-test="stage-clock-countdown-toggle"
							@click.stop="clock.toggleCountdown()">
							<template #icon>
								<PauseIcon v-if="clock.isCountdownRunning.value" :size="16" />
								<PlayIcon v-else :size="16" />
							</template>
							{{ clock.isCountdownRunning.value ? t('sheetmusic', 'Pause') : t('sheetmusic', 'Start') }}
						</NcButton>
						<NcButton
							variant="tertiary"
							size="small"
							:aria-label="t('sheetmusic', 'Reset countdown')"
							data-test="stage-clock-countdown-reset"
							@click.stop="clock.resetCountdown()">
							<template #icon><RestoreIcon :size="16" /></template>
						</NcButton>
					</div>
					<div :class="$style.presetRow">
						<button
							v-for="mins in COUNTDOWN_PRESET_MINUTES"
							:key="mins"
							type="button"
							:class="[$style.presetBtn, clock.countdownTargetSeconds.value === mins * 60 && $style.presetBtnActive]"
							:data-test="`stage-clock-preset-${mins}`"
							@click="clock.resetCountdown(mins * 60)">
							{{ mins }}m
						</button>
						<button
							v-if="remainingSeconds > 0"
							type="button"
							:class="$style.presetBtn"
							data-test="stage-clock-preset-setlist"
							@click="clock.resetCountdown(remainingSeconds)">
							{{ t('sheetmusic', 'Set') }}
						</button>
					</div>
				</div>
			</div>
		</ViewportPopover>
	</div>
</template>

<style module>
.stageClockWrap {
	position: fixed;
	top: 10px;
	right: 12px;
	z-index: 100000;
	transition: top 0.15s ease;
}

.belowTopBar {
	top: 62px;
}

button[type='button'].badge {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	margin: 0;
	padding: 4px 10px;
	min-height: 28px;
	border: 1px solid rgba(128, 128, 128, 0.28);
	border-radius: 999px;
	background: rgba(24, 24, 28, 0.58);
	color: #f4f4f6;
	font-size: 0.78rem;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
	backdrop-filter: blur(6px);
	cursor: pointer;
}

button[type='button'].badge:hover {
	background: rgba(24, 24, 28, 0.78);
}

button[type='button'].badgeWarning {
	background: rgba(180, 83, 9, 0.82);
	border-color: rgba(251, 191, 36, 0.5);
}

button[type='button'].badgeExpired {
	background: rgba(185, 28, 28, 0.88);
	border-color: rgba(248, 113, 113, 0.6);
}

.popoverPane {
	padding: 0;
}

.panel {
	display: flex;
	flex-direction: column;
	gap: 10px;
	width: 240px;
	padding: 10px;
}

.segmented {
	display: flex;
	gap: 3px;
}

button[type='button'].segBtn {
	flex: 1;
	margin: 0;
	padding: 4px 6px;
	min-height: 28px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.74rem;
	cursor: pointer;
}

button[type='button'].segBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-text, #ffffff);
	font-weight: 600;
}

.infoBox,
.countdownBox {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 6px;
	padding: 6px;
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-hover);
}

.bigTime {
	font-size: 1.45rem;
	font-variant-numeric: tabular-nums;
}

.subMeta {
	font-size: 0.76rem;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.countdownActions {
	display: flex;
	align-items: center;
	gap: 6px;
}

.presetRow {
	display: flex;
	flex-wrap: wrap;
	justify-content: center;
	gap: 4px;
	width: 100%;
}

button[type='button'].presetBtn {
	margin: 0;
	padding: 2px 8px;
	min-height: 24px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.72rem;
	cursor: pointer;
}

button[type='button'].presetBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	font-weight: 600;
}
</style>
