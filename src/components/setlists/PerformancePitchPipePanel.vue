<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import A4CalibrationControl from './A4CalibrationControl.vue'
import PerformancePitchPipeSection from './PerformancePitchPipeSection.vue'
import PerformanceTunerSection from './PerformanceTunerSection.vue'
import type { PitchOctave } from '../../composables/usePitchPipe'
import type { PitchWaveform } from '../../utils/audio-engine'

withDefaults(defineProps<{
	activePitchClass: number | null
	activeNoteLabel: string
	activeFrequency: number | null
	octave: PitchOctave
	a4Hz: number
	waveform: PitchWaveform
	songKeyPitchClass?: number | null
	songKeyLabel?: string | null
}>(), {
	songKeyPitchClass: null,
	songKeyLabel: null,
})

const emit = defineEmits<{
	toggleNote: [pitchClass: number]
	stop: []
	'update:octave': [octave: PitchOctave]
	'update:a4Hz': [hz: number]
	'update:waveform': [waveform: PitchWaveform]
}>()

const mode = ref<'pipe' | 'tuner'>('pipe')
</script>

<template>
	<div :class="$style.panel" data-test="pitch-pipe-panel">
		<div :class="$style.headerRow">
			<div :class="$style.segmented" role="tablist" :aria-label="t('sheetmusic', 'Pitch tool mode')">
				<button
					type="button"
					role="tab"
					:aria-selected="mode === 'pipe'"
					:class="[$style.segBtn, mode === 'pipe' && $style.segBtnActive]"
					data-test="pitch-mode-pipe"
					@click="mode = 'pipe'">
					{{ t('sheetmusic', 'Pitch Pipe') }}
				</button>
				<button
					type="button"
					role="tab"
					:aria-selected="mode === 'tuner'"
					:class="[$style.segBtn, mode === 'tuner' && $style.segBtnActive]"
					data-test="pitch-mode-tuner"
					@click="mode = 'tuner'">
					{{ t('sheetmusic', 'Tuner') }}
				</button>
			</div>
			<span
				v-if="songKeyLabel"
				:class="$style.songKeyBadge"
				data-test="pitch-pipe-song-key">
				{{ t('sheetmusic', 'Key: {key}', { key: songKeyLabel }, { escape: false }) }}
			</span>
		</div>

		<PerformancePitchPipeSection
			v-if="mode === 'pipe'"
			:active-pitch-class="activePitchClass"
			:active-note-label="activeNoteLabel"
			:active-frequency="activeFrequency"
			:octave="octave"
			:waveform="waveform"
			:song-key-pitch-class="songKeyPitchClass"
			@toggle-note="emit('toggleNote', $event)"
			@stop="emit('stop')"
			@update:octave="emit('update:octave', $event)"
			@update:waveform="emit('update:waveform', $event)" />

		<PerformanceTunerSection
			v-else
			:a4-hz="a4Hz"
			@start-listening="emit('stop')" />

		<A4CalibrationControl
			:a4-hz="a4Hz"
			@update:a4-hz="emit('update:a4Hz', $event)" />
	</div>
</template>

<style module>
.panel {
	display: flex;
	flex-direction: column;
	gap: 10px;
	width: min(300px, calc(100vw - 24px));
	padding: 10px;
}

.headerRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.songKeyBadge {
	padding: 2px 8px;
	border-radius: 999px;
	background: var(--color-primary-light);
	color: var(--color-main-text);
	font-size: 0.75rem;
	font-weight: 600;
	white-space: nowrap;
}

.segmented {
	display: flex;
	gap: 3px;
}

button[type='button'].segBtn {
	flex: 1;
	margin: 0;
	padding: 4px 8px;
	min-height: 30px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.78rem;
	cursor: pointer;
	white-space: nowrap;
}

button[type='button'].segBtn:hover {
	background: var(--color-background-hover);
}

button[type='button'].segBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-text, #ffffff);
	font-weight: 600;
}
</style>
