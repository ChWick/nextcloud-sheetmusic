<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import type { PageOrderFilterMode } from '../../types/tools'

defineProps<{
	filterMode: PageOrderFilterMode
	searchQuery: string
	stuckCount: number
	totalFiles: number
	disabled?: boolean
}>()

const emit = defineEmits<{
	'update:filterMode': [mode: PageOrderFilterMode]
	'update:searchQuery': [query: string]
	refresh: []
}>()
</script>

<template>
	<div :class="$style.filterBar" data-test="page-order-filter-bar">
		<div :class="$style.filterTabs" role="tablist">
			<button
				type="button"
				role="tab"
				:aria-selected="filterMode === 'stuck'"
				:class="[$style.tabButton, filterMode === 'stuck' && $style.tabActive, filterMode === 'stuck' && stuckCount > 0 && $style.tabWarning]"
				:title="t('sheetmusic', 'Files with 2+ pages locked to only the first page (1-1). Resetting restores the full page range.')"
				data-test="filter-stuck"
				@click="emit('update:filterMode', 'stuck')">
				{{ t('sheetmusic', 'Stuck at 1-1') }}
				<span v-if="stuckCount > 0" :class="$style.badge">{{ stuckCount }}</span>
			</button>

			<button
				type="button"
				role="tab"
				:aria-selected="filterMode === 'all-1-1'"
				:class="[$style.tabButton, filterMode === 'all-1-1' && $style.tabActive]"
				:title="t('sheetmusic', 'All files whose page order is 1-1, including valid 1-page lead sheets as well as stuck files.')"
				data-test="filter-all-1-1"
				@click="emit('update:filterMode', 'all-1-1')">
				{{ t('sheetmusic', 'All with 1-1') }}
			</button>

			<button
				type="button"
				role="tab"
				:aria-selected="filterMode === 'custom'"
				:class="[$style.tabButton, filterMode === 'custom' && $style.tabActive]"
				:title="t('sheetmusic', 'Files with custom ordering (e.g. repeated or rearranged pages).')"
				data-test="filter-custom"
				@click="emit('update:filterMode', 'custom')">
				{{ t('sheetmusic', 'Custom Order') }}
			</button>

			<button
				type="button"
				role="tab"
				:aria-selected="filterMode === 'all'"
				:class="[$style.tabButton, filterMode === 'all' && $style.tabActive]"
				:title="t('sheetmusic', 'All PDF files in this library.')"
				data-test="filter-all"
				@click="emit('update:filterMode', 'all')">
				{{ t('sheetmusic', 'All Files') }}
				<span :class="$style.badgeMuted">{{ totalFiles }}</span>
			</button>
		</div>

		<div :class="$style.actionsArea">
			<div :class="$style.searchWrapper">
				<NcTextField
					:value="searchQuery"
					:placeholder="t('sheetmusic', 'Search song or file…')"
					data-test="page-order-search"
					@update:value="emit('update:searchQuery', $event)" />
			</div>

			<NcButton
				variant="secondary"
				:disabled="disabled"
				data-test="refresh-audit-btn"
				@click="emit('refresh')">
				<template #icon>
					<RefreshIcon :size="20" />
				</template>
				{{ t('sheetmusic', 'Refresh') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.filterBar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 16px;
	padding: 12px 16px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
}

.filterTabs {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.tabButton {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 8px 14px;
	border: 1px solid var(--color-border, #ccc);
	border-radius: var(--border-radius-pill, 20px);
	background: transparent;
	color: var(--color-main-text, #333);
	font-size: 0.88rem;
	cursor: pointer;
	transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.tabButton:hover {
	background: var(--color-background-hover, rgba(0, 0, 0, 0.05));
}

.tabActive {
	background: var(--color-primary-element, #0082c9);
	border-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.tabActive:hover {
	background: var(--color-primary-element-hover, #0070ad);
}

.tabWarning.tabActive {
	background: var(--color-warning, #e67e22);
	border-color: var(--color-warning, #e67e22);
	color: #fff;
}

.badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px 7px;
	font-size: 0.75rem;
	font-weight: 700;
	border-radius: 10px;
	background: var(--color-warning, #e67e22);
	color: #fff;
}

.tabActive .badge {
	background: #fff;
	color: var(--color-warning, #d35400);
}

.badgeMuted {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 2px 7px;
	font-size: 0.75rem;
	font-weight: 600;
	border-radius: 10px;
	background: var(--color-background-hover, rgba(0, 0, 0, 0.1));
	color: var(--color-text-maxcontrast, #555);
}

.tabActive .badgeMuted {
	background: rgba(255, 255, 255, 0.25);
	color: #fff;
}

.actionsArea {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1;
	justify-content: flex-end;
	min-width: 260px;
}

.searchWrapper {
	max-width: 260px;
	flex: 1;
}
</style>
