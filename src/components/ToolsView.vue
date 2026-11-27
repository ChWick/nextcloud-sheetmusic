<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import Metronome from 'vue-material-design-icons/Metronome.vue'
import Microphone from 'vue-material-design-icons/Microphone.vue'
import MusicNote from 'vue-material-design-icons/MusicNote.vue'
import type { ToolDefinition } from '../types/tools'
import ViewHeader from './ViewHeader.vue'
import MetronomeTool from './tools/MetronomeTool.vue'
import PageOrderResetTool from './tools/PageOrderResetTool.vue'
import PitchPipeTool from './tools/PitchPipeTool.vue'
import TunerTool from './tools/TunerTool.vue'
import ToolsOverview from './tools/ToolsOverview.vue'
import ToolChips from './tools/ToolChips.vue'

defineProps<{
	selectedLibrary: number
}>()

const route = useRoute()
const router = useRouter()

const availableTools: ToolDefinition[] = [
	{
		id: 'metronome',
		name: t('sheetmusic', 'Metronome'),
		description: t('sheetmusic', 'Keep steady tempo with configurable BPM, time signatures, and tap tempo'),
		icon: Metronome,
	},
	{
		id: 'pitch-pipe',
		name: t('sheetmusic', 'Pitch Pipe'),
		description: t('sheetmusic', 'Sound chromatic reference tones with octave, waveform, and A4 calibration'),
		icon: MusicNote,
	},
	{
		id: 'tuner',
		name: t('sheetmusic', 'Tuner'),
		description: t('sheetmusic', 'Check intonation live using your browser microphone with A4 calibration'),
		icon: Microphone,
	},
	{
		id: 'page-order-reset',
		name: t('sheetmusic', 'Reset Page Ordering'),
		description: t('sheetmusic', 'Audit and reset page ordering for multi-page songs'),
		icon: FormatListNumbered,
	},
]

const activeToolId = ref<string | null>(null)

// Sync with route query ?tool=
watch(
	() => route.query.tool,
	(toolQuery) => {
		if (typeof toolQuery === 'string' && availableTools.some(tool => tool.id === toolQuery)) {
			activeToolId.value = toolQuery
		} else {
			activeToolId.value = null
		}
	},
	{ immediate: true },
)

function selectTool(id: string | null) {
	activeToolId.value = id
	const nextQuery = { ...route.query }
	if (id) {
		nextQuery.tool = id
	} else {
		delete nextQuery.tool
	}
	router.replace({
		query: nextQuery,
	})
}

const activeTool = computed(() => {
	return availableTools.find(t => t.id === activeToolId.value) ?? null
})
</script>

<template>
	<div :class="$style.container" data-test="tools-view">
		<ViewHeader
			:title="activeTool ? activeTool.name : t('sheetmusic', 'Library Tools')"
			:show-back="Boolean(activeTool)"
			:back-title="t('sheetmusic', 'Back to tools')"
			@back="selectTool(null)" />

		<div
			v-if="activeTool && availableTools.length > 1"
			:class="$style.toolSwitcherBar"
			data-test="tools-switcher-bar">
			<ToolChips
				:tools="availableTools"
				:active-tool-id="activeToolId"
				@select="selectTool" />
		</div>

		<div :class="$style.content">
			<ToolsOverview
				v-if="!activeTool"
				:tools="availableTools"
				@select="selectTool" />

			<MetronomeTool v-else-if="activeToolId === 'metronome'" />
			<PitchPipeTool v-else-if="activeToolId === 'pitch-pipe'" />
			<TunerTool v-else-if="activeToolId === 'tuner'" />
			<PageOrderResetTool
				v-else-if="activeToolId === 'page-order-reset'"
				:selected-library="selectedLibrary" />
		</div>
	</div>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	height: 100%;
	width: 100%;
	overflow-y: auto;
}

.toolSwitcherBar {
	display: flex;
	align-items: center;
	flex-shrink: 0;
	padding: 8px 32px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.content {
	padding: 24px 32px;
	max-width: 1400px;
	width: 100%;
	box-sizing: border-box;
	margin: 0 auto;
	flex: 1 0 auto;
}
</style>
