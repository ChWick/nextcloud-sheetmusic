<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<script setup lang="ts">
import { ref, watch } from 'vue'

export interface XlsxSheet {
	name: string
	html: string
}

const props = defineProps<{
	sheets: XlsxSheet[]
}>()

const activeSheetIndex = ref(0)

watch(
	() => props.sheets,
	() => {
		activeSheetIndex.value = 0
	},
)
</script>

<template>
	<div :class="$style.xlsxContainer">
		<div v-if="props.sheets.length > 1" :class="$style.xlsxTabs" role="tablist">
			<button
				v-for="(sheet, idx) in props.sheets"
				:key="sheet.name"
				type="button"
				role="tab"
				:aria-selected="activeSheetIndex === idx"
				:class="[$style.xlsxTab, activeSheetIndex === idx && $style.xlsxTabActive]"
				@click="activeSheetIndex = idx">
				{{ sheet.name }}
			</button>
		</div>
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div
			v-if="props.sheets[activeSheetIndex]"
			:class="$style.xlsxSheetContent"
			v-html="props.sheets[activeSheetIndex].html" />
	</div>
</template>

<style module>
.xlsxContainer {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-height: 0;
	overflow: hidden;
	background-color: var(--color-main-background);
}

.xlsxTabs {
	display: flex;
	gap: 4px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	overflow-x: auto;
	flex-shrink: 0;
}

.xlsxTab {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 4px 12px;
	font-size: 0.85rem;
	cursor: pointer;
	white-space: nowrap;
}

.xlsxTabActive {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
	font-weight: 600;
}

.xlsxSheetContent {
	flex: 1;
	min-height: 0;
	overflow: auto;
	padding: 16px;
}

.xlsxSheetContent :global(table) {
	border-collapse: collapse;
	width: 100%;
	font-size: 0.9rem;
}

.xlsxSheetContent :global(td),
.xlsxSheetContent :global(th) {
	border: 1px solid var(--color-border);
	padding: 6px 10px;
	text-align: left;
	vertical-align: top;
}

.xlsxSheetContent :global(tr:nth-child(even)) {
	background-color: var(--color-background-hover);
}
</style>
