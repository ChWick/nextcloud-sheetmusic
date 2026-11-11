<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import Wrench from 'vue-material-design-icons/Wrench.vue'
import type { ToolDefinition } from '../../types/tools'

const props = defineProps<{
	tool: ToolDefinition
}>()

const emit = defineEmits<{
	select: [toolId: string]
}>()

function onOpen() {
	emit('select', props.tool.id)
}
</script>

<template>
	<div
		:class="$style.toolCard"
		role="button"
		tabindex="0"
		:aria-label="tool.name"
		:data-test="`tool-card-${tool.id}`"
		@click="onOpen"
		@keydown.enter="onOpen"
		@keydown.space.prevent="onOpen">
		<div :class="$style.cardHeader">
			<div :class="$style.iconWrapper">
				<component :is="tool.icon || Wrench" :size="24" :class="$style.toolIcon" />
			</div>
			<div :class="$style.headerContent">
				<h3 :class="$style.toolTitle">{{ tool.name }}</h3>
				<span v-if="tool.badge" :class="$style.badge">{{ tool.badge }}</span>
			</div>
		</div>

		<p :class="$style.toolDescription">{{ tool.description }}</p>

		<div :class="$style.cardFooter">
			<NcButton
				variant="primary"
				size="small"
				:aria-label="t('sheetmusic', 'Open {name}', { name: tool.name })"
				@click.stop="onOpen">
				<template #icon>
					<ArrowRight :size="16" />
				</template>
				{{ t('sheetmusic', 'Open tool') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.toolCard {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
	cursor: pointer;
	transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
	outline: none;
}

.toolCard:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
	transform: translateY(-2px);
}

.toolCard:focus-visible {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element);
}

.cardHeader {
	display: flex;
	align-items: center;
	gap: 14px;
}

.iconWrapper {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border-radius: var(--border-radius, 8px);
	background-color: var(--color-background-hover);
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.toolIcon {
	color: var(--color-primary-element);
}

.headerContent {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.toolTitle {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
	line-height: 1.3;
}

.badge {
	align-self: flex-start;
	font-size: 11px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 10px);
	background-color: var(--color-primary-light, rgba(0, 130, 201, 0.12));
	color: var(--color-primary-element);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.toolDescription {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	line-height: 1.5;
	flex: 1;
}

.cardFooter {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
