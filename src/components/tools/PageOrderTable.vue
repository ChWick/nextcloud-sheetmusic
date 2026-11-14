<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
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
	'edit-item': [item: PageOrderAuditItem]
	'open-song': [songId: number]
}>()

// Pagination
const pageSize = ref(25)
const currentPage = ref(1)

const totalPages = computed(() => {
	if (pageSize.value <= 0 || props.items.length === 0) return 1
	return Math.ceil(props.items.length / pageSize.value)
})

watch(() => props.items.length, () => {
	if (currentPage.value > totalPages.value) {
		currentPage.value = 1
	}
})

const paginatedItems = computed(() => {
	if (pageSize.value <= 0) return props.items
	const start = (currentPage.value - 1) * pageSize.value
	return props.items.slice(start, start + pageSize.value)
})

const startIndex = computed(() => {
	if (props.items.length === 0) return 0
	return (currentPage.value - 1) * pageSize.value + 1
})

const endIndex = computed(() => {
	if (props.items.length === 0) return 0
	if (pageSize.value <= 0) return props.items.length
	return Math.min(currentPage.value * pageSize.value, props.items.length)
})

const isAllCurrentPageSelected = computed(() => {
	if (paginatedItems.value.length === 0) return false
	return paginatedItems.value.every(item => props.selectedIds.has(item.id))
})

const isIndeterminate = computed(() => {
	if (paginatedItems.value.length === 0) return false
	const selectedCount = paginatedItems.value.filter(item => props.selectedIds.has(item.id)).length
	return selectedCount > 0 && selectedCount < paginatedItems.value.length
})

function onMasterCheckboxChange(val: boolean) {
	// If current page is partially or not selected, select current page items; otherwise deselect them
	const selectAll = val ?? !isAllCurrentPageSelected.value
	for (const item of paginatedItems.value) {
		if (selectAll && !props.selectedIds.has(item.id)) {
			emit('toggle-select', item.id)
		} else if (!selectAll && props.selectedIds.has(item.id)) {
			emit('toggle-select', item.id)
		}
	}
}

function prevPage() {
	if (currentPage.value > 1) {
		currentPage.value--
	}
}

function nextPage() {
	if (currentPage.value < totalPages.value) {
		currentPage.value++
	}
}
</script>

<template>
	<div :class="$style.tableWrapper" data-test="page-order-table">
		<table v-if="items.length > 0" :class="$style.table">
			<thead>
				<tr>
					<th :class="$style.colCheck">
						<div @click.stop>
							<NcCheckboxRadioSwitch
								type="checkbox"
								:model-value="isAllCurrentPageSelected"
								:indeterminate="isIndeterminate"
								:aria-label="t('sheetmusic', 'Select all files on this page')"
								data-test="master-checkbox"
								@update:model-value="onMasterCheckboxChange" />
						</div>
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
					v-for="item in paginatedItems"
					:key="item.id"
					:class="[
						$style.row,
						item.isStuckOneToOne && $style.rowWarning,
						selectedIds.has(item.id) && $style.rowSelected,
					]"
					:data-test="`page-order-row-${item.id}`"
					@click="emit('toggle-select', item.id)">
					<td :class="$style.colCheck">
						<div @click.stop>
							<NcCheckboxRadioSwitch
								type="checkbox"
								:model-value="selectedIds.has(item.id)"
								:aria-label="t('sheetmusic', 'Select {title}', { title: item.songTitle }, { escape: false })"
								:data-test="`row-checkbox-${item.id}`"
								@update:model-value="emit('toggle-select', item.id)" />
						</div>
					</td>
					<td :class="$style.colSong">
						<button
							type="button"
							:class="$style.songLink"
							:title="t('sheetmusic', 'Open song')"
							@click.stop="emit('open-song', item.songId)">
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
							{{ t('sheetmusic', '1-1 (>1 Pages)') }}
						</span>
						<span v-else-if="item.isCustom" :class="[$style.badge, $style.badgeInfo]" data-test="status-custom">
							{{ t('sheetmusic', 'Custom') }}
						</span>
						<span v-else :class="[$style.badge, $style.badgeSuccess]" data-test="status-correct">
							{{ t('sheetmusic', 'Default') }}
						</span>
					</td>
					<td :class="$style.colActions">
						<div :class="$style.actionButtons" @click.stop>
							<NcButton
								variant="secondary"
								size="small"
								:title="t('sheetmusic', 'Edit page order')"
								:aria-label="t('sheetmusic', 'Edit page order')"
								:data-test="`edit-row-btn-${item.id}`"
								@click="emit('edit-item', item)">
								<template #icon>
									<FormatListNumbered :size="16" />
								</template>
								{{ t('sheetmusic', 'Edit') }}
							</NcButton>
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
						</div>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Table Pagination Toolbar -->
		<div v-if="items.length > 0" :class="$style.paginationBar" data-test="table-pagination">
			<div :class="$style.paginationInfo">
				<span>{{ t('sheetmusic', 'Showing {start}–{end} of {total}', { start: startIndex, end: endIndex, total: items.length }, { escape: false }) }}</span>
			</div>

			<div :class="$style.paginationControls">
				<label :class="$style.pageSizeLabel">
					<span>{{ t('sheetmusic', 'Rows:') }}</span>
					<select
						v-model.number="pageSize"
						:class="$style.pageSizeSelect"
						data-test="page-size-select"
						@change="currentPage = 1">
						<option :value="10">10</option>
						<option :value="25">25</option>
						<option :value="50">50</option>
						<option :value="100">100</option>
						<option :value="-1">{{ t('sheetmusic', 'All') }}</option>
					</select>
				</label>

				<div v-if="totalPages > 1" :class="$style.pageNav">
					<NcButton
						variant="secondary"
						size="small"
						:disabled="currentPage <= 1"
						data-test="prev-page-btn"
						:aria-label="t('sheetmusic', 'Previous page')"
						@click="prevPage">
						<template #icon>
							<ChevronLeft :size="18" />
						</template>
					</NcButton>

					<span :class="$style.pageIndicator" data-test="page-indicator">
						{{ t('sheetmusic', 'Page {current} of {total}', { current: currentPage, total: totalPages }, { escape: false }) }}
					</span>

					<NcButton
						variant="secondary"
						size="small"
						:disabled="currentPage >= totalPages"
						data-test="next-page-btn"
						:aria-label="t('sheetmusic', 'Next page')"
						@click="nextPage">
						<template #icon>
							<ChevronRight :size="18" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>

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

.row {
	cursor: pointer;
	user-select: none;
	transition: background-color 0.1s ease;
}

.row:hover {
	background: var(--color-background-hover, rgba(0, 0, 0, 0.03));
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

.rowSelected.rowWarning {
	background: rgba(230, 126, 34, 0.12);
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

.colActions {
	white-space: nowrap;
}

.actionButtons {
	display: flex;
	align-items: center;
	gap: 8px;
}

.paginationBar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border-top: 1px solid var(--color-border, #e0e0e0);
	background: var(--color-background-hover, rgba(0, 0, 0, 0.02));
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #666);
	flex-wrap: wrap;
	gap: 12px;
}

.paginationInfo {
	font-weight: 500;
}

.paginationControls {
	display: flex;
	align-items: center;
	gap: 16px;
}

.pageSizeLabel {
	display: flex;
	align-items: center;
	gap: 6px;
	cursor: pointer;
}

.pageSizeSelect {
	padding: 4px 8px;
	border-radius: var(--border-radius, 6px);
	border: 1px solid var(--color-border, #ccc);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	font-size: 0.85rem;
}

.pageNav {
	display: flex;
	align-items: center;
	gap: 8px;
}

.pageIndicator {
	min-width: 90px;
	text-align: center;
	font-weight: 500;
}

.emptyState {
	padding: 40px 20px;
	text-align: center;
	color: var(--color-text-maxcontrast, #777);
}
</style>
