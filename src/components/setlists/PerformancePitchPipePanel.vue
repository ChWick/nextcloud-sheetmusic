<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import StopIcon from 'vue-material-design-icons/Stop.vue'
import type { PitchOctave } from '../../composables/usePitchPipe'
import type { PitchWaveform } from '../../utils/audio-engine'
import {
	A4_PRESETS,
	CHROMATIC_NOTES,
	MAX_A4_HZ,
	MIN_A4_HZ,
} from '../../utils/music-tools'

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

const OCTAVES: readonly PitchOctave[] = [3, 4, 5]
</script>

<template>
	<div :class="$style.panel" data-test="pitch-pipe-panel">
		<div :class="$style.headerRow">
			<strong :class="$style.title">{{ t('sheetmusic', 'Pitch Pipe') }}</strong>
			<span
				v-if="songKeyLabel"
				:class="$style.songKeyBadge"
				data-test="pitch-pipe-song-key">
				{{ t('sheetmusic', 'Key: {key}', { key: songKeyLabel }, { escape: false }) }}
			</span>
		</div>

		<!-- Active tone readout -->
		<div :class="$style.statusRow" role="status" data-test="pitch-pipe-status">
			<template v-if="activePitchClass !== null && activeFrequency !== null">
				<span :class="$style.activeNote">{{ activeNoteLabel }}</span>
				<span :class="$style.activeFreq">{{ activeFrequency.toFixed(1) }} Hz</span>
				<NcButton
					variant="error"
					size="small"
					:aria-label="t('sheetmusic', 'Stop pitch pipe')"
					data-test="pitch-pipe-stop-button"
					@click.stop="emit('stop')">
					<template #icon><StopIcon :size="16" /></template>
					{{ t('sheetmusic', 'Stop') }}
				</NcButton>
			</template>
			<span v-else :class="$style.idleHint">
				{{ t('sheetmusic', 'Tap a note to sound a reference pitch') }}
			</span>
		</div>

		<!-- 12-note chromatic grid -->
		<div :class="$style.noteGrid" data-test="pitch-pipe-note-grid">
			<button
				v-for="note in CHROMATIC_NOTES"
				:key="note.pitchClass"
				type="button"
				:class="[
					$style.noteBtn,
					note.isAccidental && $style.noteBtnAccidental,
					songKeyPitchClass === note.pitchClass && $style.noteBtnSongKey,
					activePitchClass === note.pitchClass && $style.noteBtnActive,
				]"
				:aria-pressed="activePitchClass === note.pitchClass"
				:aria-label="`${note.label}${octave}`"
				:data-song-key="songKeyPitchClass === note.pitchClass ? 'true' : 'false'"
				:data-test="`pitch-note-${note.pitchClass}`"
				@click="emit('toggleNote', note.pitchClass)">
				{{ note.shortLabel }}
			</button>
		</div>

		<!-- Octave & Waveform -->
		<div :class="$style.controlsRow">
			<div :class="$style.controlGroup">
				<span :class="$style.controlLabel">{{ t('sheetmusic', 'Octave') }}</span>
				<div :class="$style.segmented">
					<button
						v-for="oct in OCTAVES"
						:key="oct"
						type="button"
						:class="[$style.segBtn, octave === oct && $style.segBtnActive]"
						:aria-pressed="octave === oct"
						:data-test="`pitch-octave-${oct}`"
						@click="emit('update:octave', oct)">
						{{ oct }}
					</button>
				</div>
			</div>

			<div :class="$style.controlGroup">
				<span :class="$style.controlLabel">{{ t('sheetmusic', 'Tone') }}</span>
				<div :class="$style.segmented">
					<button
						type="button"
						:class="[$style.segBtn, waveform === 'triangle' && $style.segBtnActive]"
						:aria-pressed="waveform === 'triangle'"
						data-test="pitch-waveform-triangle"
						@click="emit('update:waveform', 'triangle')">
						{{ t('sheetmusic', 'Warm') }}
					</button>
					<button
						type="button"
						:class="[$style.segBtn, waveform === 'sine' && $style.segBtnActive]"
						:aria-pressed="waveform === 'sine'"
						data-test="pitch-waveform-sine"
						@click="emit('update:waveform', 'sine')">
						{{ t('sheetmusic', 'Pure') }}
					</button>
				</div>
			</div>
		</div>

		<!-- A4 calibration -->
		<div :class="$style.calibrationRow">
			<span :class="$style.controlLabel">{{ t('sheetmusic', 'A4 Reference') }}</span>
			<div :class="$style.a4Controls">
				<button
					type="button"
					:class="$style.a4StepBtn"
					:disabled="a4Hz <= MIN_A4_HZ"
					:aria-label="t('sheetmusic', 'Decrease A4 reference frequency')"
					data-test="pitch-a4-minus"
					@click="emit('update:a4Hz', a4Hz - 1)">
					-
				</button>
				<span :class="$style.a4Value" data-test="pitch-a4-value">{{ a4Hz }} Hz</span>
				<button
					type="button"
					:class="$style.a4StepBtn"
					:disabled="a4Hz >= MAX_A4_HZ"
					:aria-label="t('sheetmusic', 'Increase A4 reference frequency')"
					data-test="pitch-a4-plus"
					@click="emit('update:a4Hz', a4Hz + 1)">
					+
				</button>
			</div>
			<div :class="$style.presetRow">
				<button
					v-for="preset in A4_PRESETS"
					:key="preset"
					type="button"
					:class="[$style.presetBtn, a4Hz === preset && $style.presetBtnActive]"
					:data-test="`pitch-a4-preset-${preset}`"
					@click="emit('update:a4Hz', preset)">
					{{ preset }}
				</button>
			</div>
		</div>
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

.title {
	font-size: 0.9rem;
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

.statusRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	min-height: 36px;
	padding: 4px 8px;
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-hover);
}

.activeNote {
	font-size: 1.05rem;
	font-weight: 700;
}

.activeFreq {
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	margin-right: auto;
}

.idleHint {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.noteGrid {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 5px;
}

button[type='button'].noteBtn {
	margin: 0;
	padding: 6px 4px;
	min-height: 38px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.88rem;
	font-weight: 600;
	cursor: pointer;
	transition: background-color 0.1s ease, border-color 0.1s ease;
}

button[type='button'].noteBtn:hover {
	background: var(--color-background-hover);
}

button[type='button'].noteBtnAccidental {
	background: var(--color-background-dark);
}

button[type='button'].noteBtnSongKey {
	border-color: var(--color-primary-element);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

button[type='button'].noteBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-text, #ffffff);
}

.controlsRow {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
}

.controlGroup {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.controlLabel {
	font-size: 0.74rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.segmented {
	display: flex;
	gap: 3px;
}

button[type='button'].segBtn {
	flex: 1;
	margin: 0;
	padding: 4px 6px;
	min-height: 30px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.78rem;
	cursor: pointer;
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

.calibrationRow {
	display: flex;
	flex-direction: column;
	gap: 5px;
}

.a4Controls {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
}

button[type='button'].a4StepBtn {
	margin: 0;
	padding: 2px 10px;
	min-height: 28px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 700;
	cursor: pointer;
}

.a4Value {
	font-size: 0.85rem;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.presetRow {
	display: grid;
	grid-template-columns: repeat(5, minmax(0, 1fr));
	gap: 4px;
}

button[type='button'].presetBtn {
	margin: 0;
	padding: 3px 4px;
	min-height: 26px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.74rem;
	cursor: pointer;
}

button[type='button'].presetBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	font-weight: 600;
}
</style>
