<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import type { PageOrderAuditItem } from '../../types/tools'

const props = defineProps<{
	items: PageOrderAuditItem[]
	selectedIds: Set<number>
	resettingId?: number | null
	disabled?: boolean
}>()

const emit = defineEmits<{
	'toggle-select': [id: number]
	'select-all': [all: boolean]
	'reset-item': [item: PageOrderAuditItem]
	'open-song': [songId: number]
}>()

const isAllSelected = computed(() => {
	if (props.items.length === 0) return false
	return props.items.every(item => props.selectedIds.has(item.id))
})

const isIndeterminate = computed(() => {
	if (props.items.length === 0) return false
	const selectedCount = props.items.filter(item => props.selectedIds.has(item.id)).length
	return selectedCount > 0 && selectedCount < props.items.length
})

function onMasterCheckboxChange(val: boolean) {
	emit('select-all', val)
}
</script>

<template>
	<div :class="$style.tableWrapper" data-test="page-order-table">
		<table v-if="items.length > 0" :class="$style.table">
			<thead>
				<tr>
					<th :class="$style.colCheck">
						<NcCheckboxRadioSwitch
							type="checkbox"
							:checked="isAllSelected"
							:indeterminate="isIndeterminate"
							:aria-label="t('sheetmusic', 'Select all files in current view')"
							@update:checked="onMasterCheckboxChange" />
					</th>
					<th :class="$style.colSong">{{ t('sheetmusic', 'Song Title') }}</th>
					<th :class="$style.colFile">{{ t('sheetmusic', 'File Name') }}</th>
					<th :class="$style.colOrder">{{ t('sheetmusic', 'Current Order') }}</th>
					<th :class="$style.colPages">{{ t('sheetmusic', 'Pages') }}</th>
					<th :class="$style.colSuggested">{{ t('sheetmusic', 'Suggested Order') }}</th>
					<th :class="$style.colStatus">{{ t('sheetmusic', 'Status') }}</th>
					<th :class="$style.colActions">{{ t('sheetmusic', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="item in items"
					:key="item.id"
					:class="[
						$style.row,
						item.isStuckOneToOne && $style.rowWarning,
						selectedIds.has(item.id) && $style.rowSelected,
					]"
					:data-test="`page-order-row-${item.id}`">
					<td :class="$style.colCheck">
						<NcCheckboxRadioSwitch
							type="checkbox"
							:checked="selectedIds.has(item.id)"
							:aria-label="t('sheetmusic', 'Select {title}', { title: item.songTitle })"
							@update:checked="emit('toggle-select', item.id)" />
					</td>
					<td :class="$style.colSong">
						<button
							type="button"
							:class="$style.songLink"
							:title="t('sheetmusic', 'Open song')"
							@click="emit('open-song', item.songId)">
							{{ item.songTitle }}
						</button>
					</td>
					<td :class="$style.colFile" :title="item.path">
						<span :class="$style.fileName">{{ item.fileName }}</span>
					</td>
					<td :class="$style.colOrder">
						<span :class="[$style.codeBadge, item.isStuckOneToOne && $style.codeWarning]">
							{{ item.pageOrder }}
						</span>
					</td>
					<td :class="$style.colPages">
						<span :class="$style.pagesCount">{{ item.actualPageCount }}</span>
					</td>
					<td :class="$style.colSuggested">
						<div :class="$style.suggestedBox">
							<ArrowRight :size="16" :class="$style.suggestedArrow" />
							<span :class="$style.codeSuggested">{{ item.suggestedPageOrder }}</span>
						</div>
					</td>
					<td :class="$style.colStatus">
						<span v-if="item.isStuckOneToOne" :class="[$style.badge, $style.badgeWarning]" data-test="status-stuck">
							{{ t('sheetmusic', 'Stuck at 1-1') }}
						</span>
						<span v-else-if="item.isCustom" :class="[$style.badge, $style.badgeInfo]" data-test="status-custom">
							{{ t('sheetmusic', 'Custom') }}
						</span>
						<span v-else :class="[$style.badge, $style.badgeSuccess]" data-test="status-correct">
							{{ t('sheetmusic', 'Default') }}
						</span>
					</td>
					<td :class="$style.colActions">
						<NcButton
							variant="secondary"
							size="small"
							:disabled="disabled || resettingId === item.id || (!item.isStuckOneToOne && !item.isCustom)"
							:data-test="`reset-row-btn-${item.id}`"
							@click="emit('reset-item', item)">
							<template #icon>
								<RotateLeft :size="16" />
							</template>
							{{ resettingId === item.id ? t('sheetmusic', 'Resetting…') : t('sheetmusic', 'Reset') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<div v-else :class="$style.emptyState" data-test="page-order-empty">
			<p>{{ t('sheetmusic', 'No files match the selected filter.') }}</p>
		</div>
	</div>
</template>

<style module>
.tableWrapper {
	width: 100%;
	overflow-x: auto;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
}

.table {
	width: 100%;
	border-collapse: collapse;
	text-align: left;
	font-size: 0.9rem;
}

.table th {
	padding: 12px 14px;
	background: var(--color-background-hover, rgba(0, 0, 0, 0.03));
	border-bottom: 2px solid var(--color-border, #e0e0e0);
	font-weight: 600;
	color: var(--color-text-maxcontrast, #555);
	white-space: nowrap;
}

.table td {
	padding: 12px 14px;
	border-bottom: 1px solid var(--color-border, #f0f0f0);
	vertical-align: middle;
}

.row:hover {
	background: var(--color-background-hover, rgba(0, 0, 0, 0.02));
}

.rowWarning {
	background: rgba(230, 126, 34, 0.04);
}

.rowWarning:hover {
	background: rgba(230, 126, 34, 0.08);
}

.rowSelected {
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.08));
}

.colCheck {
	width: 44px;
	text-align: center;
}

.colSong {
	font-weight: 600;
	min-width: 180px;
}

.songLink {
	background: none;
	border: none;
	padding: 0;
	font-size: inherit;
	font-weight: inherit;
	color: var(--color-primary-element, #0082c9);
	cursor: pointer;
	text-align: left;
	text-decoration: underline;
	text-decoration-color: transparent;
	transition: text-decoration-color 0.2s ease;
}

.songLink:hover {
	text-decoration-color: currentColor;
}

.colFile {
	min-width: 160px;
}

.fileName {
	color: var(--color-text-maxcontrast, #666);
	font-family: monospace;
	font-size: 0.85rem;
}

.codeBadge {
	display: inline-block;
	padding: 2px 8px;
	font-family: monospace;
	font-size: 0.85rem;
	border-radius: 4px;
	background: var(--color-background-hover, rgba(0, 0, 0, 0.05));
	color: var(--color-main-text, #333);
}

.codeWarning {
	background: rgba(230, 126, 34, 0.2);
	color: var(--color-warning, #d35400);
	font-weight: 700;
}

.pagesCount {
	font-weight: 600;
}

.suggestedBox {
	display: flex;
	align-items: center;
	gap: 6px;
}

.suggestedArrow {
	color: var(--color-text-maxcontrast, #888);
}

.codeSuggested {
	font-family: monospace;
	font-weight: 600;
	color: var(--color-success, #27ae60);
}

.badge {
	display: inline-flex;
	align-items: center;
	padding: 3px 8px;
	font-size: 0.78rem;
	font-weight: 600;
	border-radius: 12px;
	white-space: nowrap;
}

.badgeWarning {
	background: rgba(230, 126, 34, 0.15);
	color: var(--color-warning, #d35400);
}

.badgeInfo {
	background: rgba(0, 130, 201, 0.15);
	color: var(--color-primary-element, #0082c9);
}

.badgeSuccess {
	background: rgba(46, 204, 113, 0.15);
	color: var(--color-success, #27ae60);
}

.emptyState {
	padding: 40px 20px;
	text-align: center;
	color: var(--color-text-maxcontrast, #777);
}
</style>
