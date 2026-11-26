<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import FlashOutlineIcon from 'vue-material-design-icons/FlashOutline.vue'
import GestureTapIcon from 'vue-material-design-icons/GestureTap.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import StopIcon from 'vue-material-design-icons/Stop.vue'
import VolumeHighIcon from 'vue-material-design-icons/VolumeHigh.vue'
import VolumeOffIcon from 'vue-material-design-icons/VolumeOff.vue'
import {
	MAX_BPM,
	MIN_BPM,
	TIME_SIGNATURE_PRESETS,
	type TimeSignatureSpec,
} from '../../utils/music-tools'

const props = withDefaults(defineProps<{
	bpm: number
	timeSignature: TimeSignatureSpec
	isRunning: boolean
	currentBeat: number
	accentFirstBeat: boolean
	soundEnabled: boolean
	visualFlashEnabled: boolean
	songTempo?: number | null
	songTimeSignature?: TimeSignatureSpec | null
}>(), {
	songTempo: null,
	songTimeSignature: null,
})

const emit = defineEmits<{
	toggle: []
	'update:bpm': [bpm: number]
	adjustBpm: [delta: number]
	'update:timeSignature': [label: string]
	tapTempo: []
	'update:accentFirstBeat': [enabled: boolean]
	'update:soundEnabled': [enabled: boolean]
	'update:visualFlashEnabled': [enabled: boolean]
	syncSong: []
}>()

const signatureChoices = computed(() => {
	const set = new Set<string>(TIME_SIGNATURE_PRESETS)
	if (props.songTimeSignature?.label) {
		set.add(props.songTimeSignature.label)
	}
	set.add(props.timeSignature.label)
	return Array.from(set)
})

const hasSongMeta = computed(() =>
	props.songTempo !== null || props.songTimeSignature !== null,
)

const isSyncedWithSong = computed(() => {
	const tempoMatches = props.songTempo === null || props.bpm === props.songTempo
	const sigMatches = props.songTimeSignature === null
		|| props.timeSignature.label === props.songTimeSignature.label
	return tempoMatches && sigMatches
})

const songMetaSummary = computed(() => {
	const parts: string[] = []
	if (props.songTempo !== null) parts.push(`${props.songTempo} BPM`)
	if (props.songTimeSignature !== null) parts.push(props.songTimeSignature.label)
	return parts.join(' · ')
})

const beatIndices = computed(() =>
	Array.from({ length: props.timeSignature.beats }, (_, i) => i),
)
</script>

<template>
	<div :class="$style.panel" data-test="metronome-panel">
		<div :class="$style.headerRow">
			<strong :class="$style.title">{{ t('sheetmusic', 'Metronome') }}</strong>
			<button
				v-if="hasSongMeta"
				type="button"
				:class="[$style.songSyncBadge, isSyncedWithSong && $style.songSyncBadgeActive]"
				:title="t('sheetmusic', 'Apply tempo and time signature from current song')"
				data-test="metronome-song-sync"
				@click="emit('syncSong')">
				{{ t('sheetmusic', 'Song: {summary}', { summary: songMetaSummary }, { escape: false }) }}
			</button>
		</div>

		<!-- Visual beat dots -->
		<div
			:class="$style.beatDots"
			role="status"
			:aria-label="t('sheetmusic', 'Beat indicator')"
			data-test="metronome-beat-dots">
			<span
				v-for="idx in beatIndices"
				:key="idx"
				:class="[
					$style.beatDot,
					idx === 0 && accentFirstBeat && $style.beatDotAccent,
					isRunning && currentBeat === idx && $style.beatDotActive,
				]"
				:data-active="isRunning && currentBeat === idx ? 'true' : 'false'" />
		</div>

		<!-- BPM readout & steppers -->
		<div :class="$style.bpmRow">
			<div :class="$style.stepperGroup">
				<button
					type="button"
					:class="$style.stepBtn"
					:aria-label="t('sheetmusic', 'Decrease tempo by 5 BPM')"
					data-test="metronome-bpm-minus-5"
					@click="emit('adjustBpm', -5)">
					-5
				</button>
				<button
					type="button"
					:class="$style.stepBtn"
					:aria-label="t('sheetmusic', 'Decrease tempo by 1 BPM')"
					data-test="metronome-bpm-minus-1"
					@click="emit('adjustBpm', -1)">
					-1
				</button>
			</div>

			<div :class="$style.bpmDisplay" data-test="metronome-bpm-value">
				<span :class="$style.bpmNumber">{{ bpm }}</span>
				<span :class="$style.bpmUnit">BPM</span>
			</div>

			<div :class="$style.stepperGroup">
				<button
					type="button"
					:class="$style.stepBtn"
					:aria-label="t('sheetmusic', 'Increase tempo by 1 BPM')"
					data-test="metronome-bpm-plus-1"
					@click="emit('adjustBpm', 1)">
					+1
				</button>
				<button
					type="button"
					:class="$style.stepBtn"
					:aria-label="t('sheetmusic', 'Increase tempo by 5 BPM')"
					data-test="metronome-bpm-plus-5"
					@click="emit('adjustBpm', 5)">
					+5
				</button>
			</div>
		</div>

		<input
			type="range"
			:min="MIN_BPM"
			:max="MAX_BPM"
			:value="bpm"
			:class="$style.slider"
			:aria-label="t('sheetmusic', 'Tempo (BPM)')"
			data-test="metronome-bpm-slider"
			@input="emit('update:bpm', Number(($event.target as HTMLInputElement).value))">

		<!-- Time signature presets -->
		<div :class="$style.section">
			<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Time signature') }}</span>
			<div :class="$style.sigGrid">
				<button
					v-for="sig in signatureChoices"
					:key="sig"
					type="button"
					:class="[$style.pillBtn, timeSignature.label === sig && $style.pillBtnActive]"
					:aria-pressed="timeSignature.label === sig"
					:data-test="`metronome-sig-${sig.replace('/', '-')}`"
					@click="emit('update:timeSignature', sig)">
					{{ sig }}
				</button>
			</div>
		</div>

		<!-- Options row -->
		<div :class="$style.optionsRow">
			<button
				type="button"
				:class="[$style.optionBtn, accentFirstBeat && $style.optionBtnActive]"
				:aria-pressed="accentFirstBeat"
				data-test="metronome-accent-toggle"
				@click="emit('update:accentFirstBeat', !accentFirstBeat)">
				{{ t('sheetmusic', 'Accent 1') }}
			</button>
			<button
				type="button"
				:class="[$style.optionBtn, soundEnabled && $style.optionBtnActive]"
				:aria-pressed="soundEnabled"
				:aria-label="t('sheetmusic', 'Toggle metronome sound')"
				data-test="metronome-sound-toggle"
				@click="emit('update:soundEnabled', !soundEnabled)">
				<VolumeHighIcon v-if="soundEnabled" :size="16" />
				<VolumeOffIcon v-else :size="16" />
				<span>{{ t('sheetmusic', 'Sound') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.optionBtn, visualFlashEnabled && $style.optionBtnActive]"
				:aria-pressed="visualFlashEnabled"
				:aria-label="t('sheetmusic', 'Toggle visual beat pulse')"
				data-test="metronome-flash-toggle"
				@click="emit('update:visualFlashEnabled', !visualFlashEnabled)">
				<FlashOutlineIcon :size="16" />
				<span>{{ t('sheetmusic', 'Pulse') }}</span>
			</button>
		</div>

		<!-- Primary actions -->
		<div :class="$style.actionsRow">
			<NcButton
				variant="secondary"
				data-test="metronome-tap-button"
				@click="emit('tapTempo')">
				<template #icon><GestureTapIcon :size="18" /></template>
				{{ t('sheetmusic', 'Tap') }}
			</NcButton>
			<NcButton
				:variant="isRunning ? 'error' : 'primary'"
				data-test="metronome-start-stop-button"
				@click="emit('toggle')">
				<template #icon>
					<StopIcon v-if="isRunning" :size="18" />
					<PlayIcon v-else :size="18" />
				</template>
				{{ isRunning ? t('sheetmusic', 'Stop') : t('sheetmusic', 'Start') }}
			</NcButton>
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

button[type='button'].songSyncBadge {
	margin: 0;
	padding: 2px 8px;
	min-height: 24px;
	border: 1px solid var(--color-border);
	border-radius: 999px;
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 0.75rem;
	cursor: pointer;
	white-space: nowrap;
}

button[type='button'].songSyncBadgeActive {
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-text, var(--color-main-text));
}

.beatDots {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-wrap: wrap;
	gap: 8px;
	padding: 6px 4px;
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-hover);
}

.beatDot {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background: var(--color-border-dark, rgba(128, 128, 128, 0.35));
	transition: transform 0.08s ease, background-color 0.08s ease;
}

.beatDotAccent {
	width: 14px;
	height: 14px;
	border: 2px solid var(--color-primary-element);
	box-sizing: border-box;
}

.beatDotActive {
	background: var(--color-primary-element);
	transform: scale(1.25);
}

.bpmRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
}

.stepperGroup {
	display: flex;
	align-items: center;
	gap: 4px;
}

button[type='button'].stepBtn {
	margin: 0;
	padding: 4px 7px;
	min-width: 32px;
	min-height: 32px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8rem;
	font-weight: 600;
	cursor: pointer;
}

button[type='button'].stepBtn:hover {
	background: var(--color-background-hover);
}

.bpmDisplay {
	display: flex;
	align-items: baseline;
	gap: 4px;
}

.bpmNumber {
	font-size: 1.5rem;
	font-weight: 700;
	line-height: 1;
	font-variant-numeric: tabular-nums;
}

.bpmUnit {
	font-size: 0.72rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.slider {
	width: 100%;
	margin: 0;
	accent-color: var(--color-primary-element);
}

.section {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sectionLabel {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.sigGrid {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 4px;
}

button[type='button'].pillBtn {
	margin: 0;
	padding: 4px 6px;
	min-height: 30px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8rem;
	cursor: pointer;
}

button[type='button'].pillBtn:hover {
	background: var(--color-background-hover);
}

button[type='button'].pillBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element);
	color: var(--color-primary-text, #ffffff);
	font-weight: 600;
}

.optionsRow {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 4px;
}

button[type='button'].optionBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
	margin: 0;
	padding: 4px 6px;
	min-height: 32px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.76rem;
	cursor: pointer;
}

button[type='button'].optionBtn:hover {
	background: var(--color-background-hover);
}

button[type='button'].optionBtnActive {
	border-color: var(--color-primary-element);
	background: var(--color-primary-light);
	font-weight: 600;
}

.actionsRow {
	display: grid;
	grid-template-columns: 1fr 1.3fr;
	gap: 6px;
}
</style>
