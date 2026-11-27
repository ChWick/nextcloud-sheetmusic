<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import PracticeToolCard from './PracticeToolCard.vue'
import PerformanceMetronomePanel from '../setlists/PerformanceMetronomePanel.vue'
import { useMetronome } from '../../composables/useMetronome'

const metronome = useMetronome()
</script>

<template>
	<PracticeToolCard test-id="metronome-tool">
		<PerformanceMetronomePanel
			:bpm="metronome.bpm.value"
			:time-signature="metronome.timeSignature.value"
			:is-running="metronome.isRunning.value"
			:current-beat="metronome.currentBeat.value"
			:accent-first-beat="metronome.accentFirstBeat.value"
			:sound-enabled="metronome.soundEnabled.value"
			:visual-flash-enabled="metronome.visualFlashEnabled.value"
			@toggle="metronome.toggle()"
			@update:bpm="metronome.setBpm($event)"
			@adjust-bpm="metronome.adjustBpm($event)"
			@update:time-signature="metronome.setTimeSignature($event)"
			@tap-tempo="metronome.tapTempo()"
			@update:accent-first-beat="metronome.accentFirstBeat.value = $event"
			@update:sound-enabled="metronome.soundEnabled.value = $event"
			@update:visual-flash-enabled="metronome.visualFlashEnabled.value = $event" />
	</PracticeToolCard>
</template>
