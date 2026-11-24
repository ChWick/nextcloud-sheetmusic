<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<script setup lang="ts">
import type { EditorTool } from '../../composables/useAnnotationEditor'
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ShapeOutline from 'vue-material-design-icons/ShapeOutline.vue'
import ViewportPopover from '../ViewportPopover.vue'

const props = defineProps<{
	activeTool: EditorTool
	open: boolean
}>()

const emit = defineEmits<{
	'update:open': [open: boolean]
	toggle: []
	select: [tool: EditorTool]
}>()

const SHAPE_TOOLS: Array<{ id: EditorTool; label: string; glyph: string }> = [
	{ id: 'line', label: 'Line', glyph: '╱' },
	{ id: 'arrow', label: 'Arrow', glyph: '↗' },
	{ id: 'crescendo', label: 'Crescendo (<)', glyph: '<' },
	{ id: 'decrescendo', label: 'Decrescendo (>)', glyph: '>' },
	{ id: 'slur', label: 'Slur / Tie', glyph: '⌒' },
	{ id: 'bracket', label: 'Bracket', glyph: '┌┐' },
	{ id: 'rect', label: 'Rectangle', glyph: '□' },
	{ id: 'ellipse', label: 'Circle / Ellipse', glyph: '○' },
]

const activeShapeOption = computed(() => SHAPE_TOOLS.find((s) => s.id === props.activeTool) ?? null)
const activeShapeLabel = computed(() =>
	activeShapeOption.value
		? `${activeShapeOption.value.label} (S)`
		: t('sheetmusic', 'Shapes & Hairpins (S)'),
)
</script>

<template>
	<ViewportPopover
		:open="props.open"
		pane-test-id="shapes-menu"
		:pane-class="$style.dropdownMenu"
		@update:open="emit('update:open', $event)">
		<template #trigger>
			<button
				type="button"
				:class="[$style.toolBtn, activeShapeOption && $style.active]"
				:title="activeShapeLabel"
				:aria-label="activeShapeLabel"
				data-test="tool-shapes"
				@click="emit('toggle')">
				<span v-if="activeShapeOption" :class="$style.shapeGlyph">{{ activeShapeOption.glyph }}</span>
				<ShapeOutline v-else :size="18" />
			</button>
		</template>
		<button
			v-for="shape in SHAPE_TOOLS"
			:key="shape.id"
			type="button"
			:class="[$style.menuItem, props.activeTool === shape.id && $style.active]"
			:data-test="`tool-${shape.id}`"
			@click="emit('select', shape.id)">
			<span :class="$style.shapeGlyph">{{ shape.glyph }}</span>
			<span>{{ shape.label }}</span>
		</button>
	</ViewportPopover>
</template>

<style module>
button[type='button'].toolBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 5px;
	width: auto;
	min-width: var(--default-clickable-area, 44px);
	min-height: var(--default-clickable-area, 44px);
	margin: 0;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	cursor: pointer;
	transition: background-color 0.12s, border-color 0.12s;
}

.toolBtn:hover {
	background: var(--color-background-hover);
}

.toolBtn.active {
	background: var(--color-primary-light, #e6f4fa);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.dropdownMenu {
	min-width: 170px;
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.menuItem {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 10px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	border-radius: 4px;
	font-size: 0.85rem;
	text-align: left;
	cursor: pointer;
}

.menuItem:hover {
	background: var(--color-background-hover);
}

.menuItem.active {
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.shapeGlyph {
	width: 20px;
	text-align: center;
	font-weight: 600;
}
</style>
