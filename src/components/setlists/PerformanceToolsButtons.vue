<script setup lang="ts">
import { ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import MetronomeIcon from 'vue-material-design-icons/Metronome.vue'
import MusicNoteIcon from 'vue-material-design-icons/MusicNote.vue'
import ViewportPopover from '../ViewportPopover.vue'
import PerformanceMetronomePanel from './PerformanceMetronomePanel.vue'
import PerformancePitchPipePanel from './PerformancePitchPipePanel.vue'
import type { PitchOctave } from '../../composables/usePitchPipe'
import type { PitchWaveform } from '../../utils/audio-engine'
import type { TimeSignatureSpec } from '../../utils/music-tools'

defineProps<{
	bpm: number
	timeSignature: TimeSignatureSpec
	isMetronomeRunning: boolean
	currentBeat: number
	accentFirstBeat: boolean
	soundEnabled: boolean
	visualFlashEnabled: boolean
	songTempo?: number | null
	songTimeSignature?: TimeSignatureSpec | null
	activePitchClass: number | null
	activeNoteLabel: string
	activeFrequency: number | null
	octave: PitchOctave
	a4Hz: number
	waveform: PitchWaveform
	songKeyPitchClass?: number | null
	songKeyLabel?: string | null
}>()

const emit = defineEmits<{
	toggleMetronome: []
	'update:bpm': [bpm: number]
	adjustBpm: [delta: number]
	'update:timeSignature': [label: string]
	tapTempo: []
	'update:accentFirstBeat': [enabled: boolean]
	'update:soundEnabled': [enabled: boolean]
	'update:visualFlashEnabled': [enabled: boolean]
	syncSongTempo: []
	toggleNote: [pitchClass: number]
	stopPitch: []
	'update:octave': [octave: PitchOctave]
	'update:a4Hz': [hz: number]
	'update:waveform': [waveform: PitchWaveform]
	'update:anyOpen': [open: boolean]
	pauseAutoHide: []
	resumeAutoHide: []
}>()

const isMetronomeOpen = ref(false)
const isPitchPipeOpen = ref(false)

watch(isMetronomeOpen, (open) => {
	if (open) isPitchPipeOpen.value = false
})

watch(isPitchPipeOpen, (open) => {
	if (open) isMetronomeOpen.value = false
})

watch([isMetronomeOpen, isPitchPipeOpen], ([mOpen, pOpen]) => {
	const anyOpen = mOpen || pOpen
	emit('update:anyOpen', anyOpen)
	if (anyOpen) {
		emit('pauseAutoHide')
	} else {
		emit('resumeAutoHide')
	}
})

function closeOpenPopover(): boolean {
	if (isMetronomeOpen.value || isPitchPipeOpen.value) {
		isMetronomeOpen.value = false
		isPitchPipeOpen.value = false
		return true
	}
	return false
}

defineExpose({ closeOpenPopover })
</script>

<template>
	<ViewportPopover
		v-model:open="isMetronomeOpen"
		pane-test-id="performance-metronome-popover"
		:pane-class="$style.toolPopover"
		:gap="6">
		<template #trigger="{ toggle }">
			<NcButton
				:variant="isMetronomeOpen || isMetronomeRunning ? 'primary' : 'tertiary'"
				:title="t('sheetmusic', 'Metronome')"
				:aria-label="t('sheetmusic', 'Metronome')"
				data-test="performance-metronome-button"
				@click="toggle">
				<template #icon><MetronomeIcon :size="20" /></template>
				<template v-if="isMetronomeRunning">
					{{ bpm }}
				</template>
			</NcButton>
		</template>

		<PerformanceMetronomePanel
			:bpm="bpm"
			:time-signature="timeSignature"
			:is-running="isMetronomeRunning"
			:current-beat="currentBeat"
			:accent-first-beat="accentFirstBeat"
			:sound-enabled="soundEnabled"
			:visual-flash-enabled="visualFlashEnabled"
			:song-tempo="songTempo"
			:song-time-signature="songTimeSignature"
			@toggle="emit('toggleMetronome')"
			@update:bpm="emit('update:bpm', $event)"
			@adjust-bpm="emit('adjustBpm', $event)"
			@update:time-signature="emit('update:timeSignature', $event)"
			@tap-tempo="emit('tapTempo')"
			@update:accent-first-beat="emit('update:accentFirstBeat', $event)"
			@update:sound-enabled="emit('update:soundEnabled', $event)"
			@update:visual-flash-enabled="emit('update:visualFlashEnabled', $event)"
			@sync-song="emit('syncSongTempo')" />
	</ViewportPopover>

	<ViewportPopover
		v-model:open="isPitchPipeOpen"
		pane-test-id="performance-pitch-pipe-popover"
		:pane-class="$style.toolPopover"
		:gap="6">
		<template #trigger="{ toggle }">
			<NcButton
				:variant="isPitchPipeOpen || activePitchClass !== null ? 'primary' : 'tertiary'"
				:title="t('sheetmusic', 'Pitch pipe & tuner')"
				:aria-label="t('sheetmusic', 'Pitch pipe & tuner')"
				data-test="performance-pitch-pipe-button"
				@click="toggle">
				<template #icon><MusicNoteIcon :size="20" /></template>
				<template v-if="activePitchClass !== null">
					{{ activeNoteLabel }}
				</template>
			</NcButton>
		</template>

		<PerformancePitchPipePanel
			:active-pitch-class="activePitchClass"
			:active-note-label="activeNoteLabel"
			:active-frequency="activeFrequency"
			:octave="octave"
			:a4-hz="a4Hz"
			:waveform="waveform"
			:song-key-pitch-class="songKeyPitchClass"
			:song-key-label="songKeyLabel"
			@toggle-note="emit('toggleNote', $event)"
			@stop="emit('stopPitch')"
			@update:octave="emit('update:octave', $event)"
			@update:a4-hz="emit('update:a4Hz', $event)"
			@update:waveform="emit('update:waveform', $event)" />
	</ViewportPopover>
</template>

<style module>
.toolPopover {
	padding: 0;
}
</style>
