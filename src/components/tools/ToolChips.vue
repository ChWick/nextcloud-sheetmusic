<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ToolDefinition } from '../../types/tools'

withDefaults(
	defineProps<{
		tools: ToolDefinition[]
		activeToolId?: string | null
	}>(),
	{
		activeToolId: null,
	},
)

const emit = defineEmits<{
	select: [toolId: string]
}>()
</script>

<template>
	<div :class="$style.chipsContainer" role="toolbar" data-test="tool-chips">
		<button
			v-for="tool in tools"
			:key="tool.id"
			type="button"
			:class="[$style.chip, activeToolId === tool.id && $style.chipActive]"
			:aria-pressed="activeToolId === tool.id"
			:data-test="`tool-chip-${tool.id}`"
			@click="emit('select', tool.id)">
			<component :is="tool.icon" v-if="tool.icon" :size="16" :class="$style.chipIcon" />
			<span :class="$style.chipLabel">{{ tool.name }}</span>
		</button>
	</div>
</template>

<style module>
.chipsContainer {
	display: inline-flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
}

.chip {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 5px 12px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 0.85rem;
	font-weight: 500;
	transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
	line-height: 1.2;
}

.chip:hover {
	background: var(--color-background-hover);
	border-color: var(--color-border-dark, var(--color-border));
}

.chip:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.chipActive {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.chipActive:hover {
	background: var(--color-primary-element-hover, var(--color-primary-element));
	border-color: var(--color-primary-element-hover, var(--color-primary-element));
	color: var(--color-primary-element-text);
}

.chipIcon {
	flex-shrink: 0;
}

.chipLabel {
	white-space: nowrap;
}
</style>
