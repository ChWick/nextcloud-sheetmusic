<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import PracticeToolCard from './PracticeToolCard.vue'
import A4CalibrationControl from '../setlists/A4CalibrationControl.vue'
import PerformancePitchPipeSection from '../setlists/PerformancePitchPipeSection.vue'
import { usePitchPipe } from '../../composables/usePitchPipe'

const pitchPipe = usePitchPipe()
</script>

<template>
	<PracticeToolCard test-id="pitch-pipe-tool">
		<PerformancePitchPipeSection
			:active-pitch-class="pitchPipe.activePitchClass.value"
			:active-note-label="pitchPipe.activeNoteLabel.value"
			:active-frequency="pitchPipe.activeFrequency.value"
			:octave="pitchPipe.octave.value"
			:waveform="pitchPipe.waveform.value"
			@toggle-note="pitchPipe.toggleNote($event)"
			@stop="pitchPipe.stop()"
			@update:octave="pitchPipe.setOctave($event)"
			@update:waveform="pitchPipe.setWaveform($event)" />
		<A4CalibrationControl
			:a4-hz="pitchPipe.a4Hz.value"
			@update:a4-hz="pitchPipe.setA4Hz($event)" />
	</PracticeToolCard>
</template>
