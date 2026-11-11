<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'
import Wrench from 'vue-material-design-icons/Wrench.vue'
import type { ToolDefinition } from '../../types/tools'
import ToolChips from './ToolChips.vue'
import ToolCard from './ToolCard.vue'

defineProps<{
	tools: ToolDefinition[]
}>()

const emit = defineEmits<{
	select: [toolId: string]
}>()

function onSelect(toolId: string) {
	emit('select', toolId)
}
</script>

<template>
	<div :class="$style.overview" data-test="tools-overview">
		<div :class="$style.introBanner">
			<div :class="$style.introIcon">
				<Wrench :size="28" />
			</div>
			<div :class="$style.introText">
				<h3 :class="$style.introTitle">{{ t('sheetmusic', 'Library Tools') }}</h3>
				<p :class="$style.introSubtitle">
					{{ t('sheetmusic', 'Select a maintenance or diagnostic tool below to inspect and manage your sheet music library.') }}
				</p>
			</div>
		</div>

		<div v-if="tools.length > 0" :class="$style.chipsSection">
			<span :class="$style.sectionLabel">{{ t('sheetmusic', 'Quick select:') }}</span>
			<ToolChips
				:tools="tools"
				:active-tool-id="null"
				@select="onSelect" />
		</div>

		<div :class="$style.grid" data-test="tools-grid">
			<ToolCard
				v-for="tool in tools"
				:key="tool.id"
				:tool="tool"
				@select="onSelect" />
		</div>
	</div>
</template>

<style module>
.overview {
	display: flex;
	flex-direction: column;
	gap: 24px;
	width: 100%;
}

.introBanner {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 20px 24px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.introIcon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 48px;
	height: 48px;
	border-radius: var(--border-radius-pill, 24px);
	background-color: var(--color-primary-light, rgba(0, 130, 201, 0.1));
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.introText {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.introTitle {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

.introSubtitle {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.chipsSection {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.sectionLabel {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
	gap: 16px;
}
</style>
