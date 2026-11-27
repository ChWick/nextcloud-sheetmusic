<script setup lang="ts">
import { computed, toRef } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import MicrophoneIcon from 'vue-material-design-icons/Microphone.vue'
import MicrophoneOffIcon from 'vue-material-design-icons/MicrophoneOff.vue'
import TunerMeter, { type TuningStatus } from './TunerMeter.vue'
import { useTuner } from '../../composables/useTuner'
import { formatCentsOffset } from '../../utils/pitch-detector'

const props = defineProps<{
	a4Hz: number
}>()

const emit = defineEmits<{
	startListening: []
}>()

const tuner = useTuner(toRef(props, 'a4Hz'))

const tuningStatus = computed<TuningStatus>(() => {
	const d = tuner.detected.value
	if (!d) return 'idle'
	if (d.inTune) return 'in-tune'
	return Math.abs(d.cents) <= 15 ? 'close' : 'off'
})

const centsDisplay = computed(() => {
	const d = tuner.detected.value
	if (!d) return tuner.isListening.value ? t('sheetmusic', 'Listening…') : t('sheetmusic', 'Tap Start to tune')
	if (d.inTune) return `${t('sheetmusic', 'In tune')} (${formatCentsOffset(d.cents)})`
	return formatCentsOffset(d.cents)
})

async function handleToggle() {
	if (!tuner.isListening.value) {
		emit('startListening')
	}
	await tuner.toggle()
}
</script>

<template>
	<div :class="$style.tuner" data-test="tuner-section" :data-tuning-status="tuningStatus">
		<div :class="$style.readout">
			<span :class="[$style.note, tuningStatus === 'in-tune' && $style.noteInTune]" data-test="tuner-note">
				{{ tuner.detected.value?.noteLabel ?? '--' }}
			</span>
			<span :class="$style.freq" data-test="tuner-frequency">
				{{ tuner.detected.value ? `${tuner.detected.value.frequency.toFixed(1)} Hz` : '— Hz' }}
			</span>
			<span :class="[$style.cents, tuningStatus === 'in-tune' && $style.centsInTune]" data-test="tuner-cents">
				{{ centsDisplay }}
			</span>
		</div>

		<TunerMeter
			:cents="tuner.detected.value?.cents ?? null"
			:status="tuningStatus" />

		<div v-if="tuner.isListening.value" :class="$style.levelTrack" aria-hidden="true" data-test="tuner-level">
			<div
				:class="$style.levelFill"
				:style="{ width: `${Math.round(tuner.inputLevel.value * 100)}%` }" />
		</div>

		<p v-if="tuner.error.value" :class="$style.errorMsg" role="alert" data-test="tuner-error">
			{{ tuner.error.value }}
		</p>

		<NcButton
			:variant="tuner.isListening.value ? 'error' : 'primary'"
			size="small"
			:wide="true"
			:disabled="tuner.isStarting.value"
			data-test="tuner-toggle-button"
			@click.stop="handleToggle">
			<template #icon>
				<MicrophoneOffIcon v-if="tuner.isListening.value" :size="16" />
				<MicrophoneIcon v-else :size="16" />
			</template>
			{{
				tuner.isStarting.value
					? t('sheetmusic', 'Requesting microphone…')
					: tuner.isListening.value
						? t('sheetmusic', 'Stop microphone')
						: t('sheetmusic', 'Start microphone')
			}}
		</NcButton>
	</div>
</template>

<style module>
.tuner {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.readout {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 2px;
	padding: 8px;
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-hover);
}

.note {
	font-size: 1.75rem;
	font-weight: 700;
	line-height: 1.15;
	font-variant-numeric: tabular-nums;
}

.noteInTune {
	color: var(--color-success, #2e7d32);
}

.freq {
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.cents {
	font-size: 0.8rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.centsInTune {
	color: var(--color-success, #2e7d32);
}

.levelTrack {
	height: 4px;
	border-radius: 999px;
	background: var(--color-background-dark, #e5e5e5);
	overflow: hidden;
}

.levelFill {
	height: 100%;
	background: var(--color-primary-element, #0082c9);
	transition: width 0.06s linear;
}

.errorMsg {
	margin: 0;
	padding: 6px 8px;
	border-radius: var(--border-radius, 6px);
	background: var(--color-error-light, rgba(211, 47, 47, 0.12));
	color: var(--color-error, #d32f2f);
	font-size: 0.78rem;
}
</style>
