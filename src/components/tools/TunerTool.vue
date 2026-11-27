<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref } from 'vue'
import PracticeToolCard from './PracticeToolCard.vue'
import A4CalibrationControl from '../setlists/A4CalibrationControl.vue'
import PerformanceTunerSection from '../setlists/PerformanceTunerSection.vue'
import { clampA4Hz, DEFAULT_A4_HZ } from '../../utils/music-tools'

const a4Hz = ref(DEFAULT_A4_HZ)
</script>

<template>
	<PracticeToolCard test-id="tuner-tool">
		<PerformanceTunerSection :a4-hz="a4Hz" />
		<A4CalibrationControl
			:a4-hz="a4Hz"
			@update:a4-hz="a4Hz = clampA4Hz($event)" />
	</PracticeToolCard>
</template>
