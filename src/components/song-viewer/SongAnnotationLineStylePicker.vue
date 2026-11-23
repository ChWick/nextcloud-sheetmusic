<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<script setup lang="ts">
import type { LineStyle } from '../../types/annotation'
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ViewportPopover from '../ViewportPopover.vue'

const props = defineProps<{
	modelValue: LineStyle
}>()

const emit = defineEmits<{
	'update:modelValue': [style: LineStyle]
}>()

const isOpen = ref(false)

const lineStyleOptions = computed(() => [
	{ value: 'solid' as LineStyle, label: t('sheetmusic', 'Solid') },
	{ value: 'dashed' as LineStyle, label: t('sheetmusic', 'Dashed') },
	{ value: 'dotted' as LineStyle, label: t('sheetmusic', 'Dotted') },
])

const currentOption = computed(() =>
	lineStyleOptions.value.find((o) => o.value === props.modelValue) ?? lineStyleOptions.value[0],
)

function lineDashArray(style: LineStyle): string | undefined {
	return style === 'dashed' ? '5, 3' : style === 'dotted' ? '1, 4' : undefined
}

function selectStyle(style: LineStyle) {
	isOpen.value = false
	emit('update:modelValue', style)
}
</script>

<template>
	<ViewportPopover
		v-model:open="isOpen"
		:pane-class="$style.lineMenuPane">
		<template #trigger="{ toggle }">
			<button
				type="button"
				:class="[$style.selectTriggerBtn, isOpen && $style.selectTriggerOpen]"
				:title="t('sheetmusic', 'Line')"
				:aria-label="`${t('sheetmusic', 'Line')}: ${currentOption.label}`"
				:aria-expanded="isOpen"
				data-test="annotation-line-style-select"
				@click="toggle">
				<span :class="$style.lineOption">
					<svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true">
						<line
							x1="1"
							y1="5"
							x2="21"
							y2="5"
							stroke="currentColor"
							stroke-width="2"
							stroke-linecap="round"
							:stroke-dasharray="lineDashArray(currentOption.value)" />
					</svg>
					<span>{{ currentOption.label }}</span>
				</span>
				<ChevronDown :size="16" :class="$style.chevron" />
			</button>
		</template>
		<button
			v-for="opt in lineStyleOptions"
			:key="opt.value"
			type="button"
			:class="[$style.lineMenuItem, currentOption.value === opt.value && $style.lineMenuItemActive]"
			:data-test="`annotation-line-style-option-${opt.value}`"
			@click="selectStyle(opt.value)">
			<span :class="$style.lineOption">
				<svg width="22" height="10" viewBox="0 0 22 10" aria-hidden="true">
					<line
						x1="1"
						y1="5"
						x2="21"
						y2="5"
						stroke="currentColor"
						stroke-width="2"
						stroke-linecap="round"
						:stroke-dasharray="lineDashArray(opt.value)" />
				</svg>
				<span>{{ opt.label }}</span>
			</span>
		</button>
	</ViewportPopover>
</template>

<style module>
.selectTriggerBtn {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-height: var(--default-clickable-area, 44px);
	padding: 4px 8px 4px 10px;
	border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.82rem;
	cursor: pointer;
	white-space: nowrap;
}

.selectTriggerBtn:hover {
	background: var(--color-background-hover);
}

.selectTriggerOpen {
	border-color: var(--color-primary-element, #0082c9);
}

.chevron {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

.lineMenuPane {
	min-width: 132px;
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.lineMenuItem {
	display: flex;
	align-items: center;
	width: 100%;
	padding: 6px 10px;
	border: none;
	border-radius: 4px;
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.85rem;
	text-align: left;
	cursor: pointer;
}

.lineMenuItem:hover {
	background: var(--color-background-hover);
}

.lineMenuItemActive {
	background: var(--color-primary-light, #e6f4fa);
	color: var(--color-primary-element, #0082c9);
	font-weight: 600;
}

.lineOption {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}
</style>
