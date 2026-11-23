<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { showError, showSuccess } from '@nextcloud/dialogs'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import CheckAll from 'vue-material-design-icons/CheckAll.vue'
import { apiGet, apiPost } from '../../api/client'
import { useProgressPolling } from '../../composables/useProgressPolling'
import { describeRequestFailure } from '../../utils/api'
import type { PageOrderAuditItem, PageOrderAuditResult, PageOrderFilterMode } from '../../types/tools'
import type { SongFile } from '../../types/song'
import PageOrderAuditSummary from './PageOrderAuditSummary.vue'
import PageOrderFilterBar from './PageOrderFilterBar.vue'
import PageOrderTable from './PageOrderTable.vue'
import ProgressDialog from '../ProgressDialog.vue'
import SongPageOrderModal from '../song-pdf/SongPageOrderModal.vue'

const props = defineProps<{
	selectedLibrary: number
}>()

const router = useRouter()

const isLoading = ref(false)
const isBatchResetting = ref(false)
const resettingId = ref<number | null>(null)
const auditResult = ref<PageOrderAuditResult>({
	totalFiles: 0,
	stuckCount: 0,
	customCount: 0,
	normalCount: 0,
	items: [],
})

const filterMode = ref<PageOrderFilterMode>('stuck')
const searchQuery = ref('')
const onlyMultiPage = ref(true)
const selectedIds = ref<Set<number>>(new Set())

const editingItem = ref<PageOrderAuditItem | null>(null)
const isEditModalOpen = ref(false)
const fixedIds = ref<Set<number>>(new Set())
let hasInitialLoaded = false

const editingFile = computed<SongFile | null>(() => {
	if (!editingItem.value) return null
	return {
		id: editingItem.value.id,
		path: editingItem.value.path,
		pageOrder: editingItem.value.pageOrder,
		pageCount: editingItem.value.actualPageCount,
	}
})

function openEditItem(item: PageOrderAuditItem) {
	editingItem.value = item
	isEditModalOpen.value = true
}

function removeFixedItems(ids: number[]) {
	if (!ids || ids.length === 0) return
	const idSet = new Set(ids)
	for (const id of ids) {
		fixedIds.value.add(id)
	}

	let removedStuck = 0
	let removedCustom = 0
	let removedNormal = 0

	auditResult.value.items = auditResult.value.items.filter((item) => {
		if (idSet.has(item.id)) {
			if (item.isStuckOneToOne) {
				removedStuck++
			} else if (item.isCustom) {
				removedCustom++
			} else {
				removedNormal++
			}
			return false
		}
		return true
	})

	auditResult.value.stuckCount = Math.max(0, auditResult.value.stuckCount - removedStuck)
	auditResult.value.customCount = Math.max(0, auditResult.value.customCount - removedCustom)
	auditResult.value.normalCount = Math.max(0, auditResult.value.normalCount - removedNormal)
	auditResult.value.totalFiles = Math.max(0, auditResult.value.totalFiles - (removedStuck + removedCustom + removedNormal))

	const nextSelected = new Set(selectedIds.value)
	for (const id of ids) {
		nextSelected.delete(id)
	}
	selectedIds.value = nextSelected
}

function onEditSaved(_libraryData?: unknown, updatedFile?: SongFile) {
	const item = editingItem.value
	isEditModalOpen.value = false
	editingItem.value = null

	if (!item) return

	const newOrder = updatedFile?.pageOrder ?? item.pageOrder
	const isNowStuck = (newOrder === '1-1' || newOrder === '1') && item.actualPageCount > 1
	const isNowCustom = newOrder !== item.suggestedPageOrder

	// If it was reset to suggested page order, remove it as fixed
	if (newOrder === item.suggestedPageOrder) {
		removeFixedItems([item.id])
		return
	}

	// If it was stuck and is no longer stuck:
	if (item.isStuckOneToOne && !isNowStuck && filterMode.value === 'stuck') {
		removeFixedItems([item.id])
		return
	}

	// Update item in place
	item.pageOrder = newOrder
	if (item.isStuckOneToOne !== isNowStuck) {
		if (item.isStuckOneToOne && !isNowStuck) {
			auditResult.value.stuckCount = Math.max(0, auditResult.value.stuckCount - 1)
		} else if (!item.isStuckOneToOne && isNowStuck) {
			auditResult.value.stuckCount++
		}
		item.isStuckOneToOne = isNowStuck
	}
	if (item.isCustom !== isNowCustom) {
		if (item.isCustom && !isNowCustom) {
			auditResult.value.customCount = Math.max(0, auditResult.value.customCount - 1)
		} else if (!item.isCustom && isNowCustom) {
			auditResult.value.customCount++
		}
		item.isCustom = isNowCustom
	}
}

const {
	progress: batchProgress,
	start: startBatchPolling,
	stop: stopProgressPolling,
} = useProgressPolling()

function startProgressPolling(progressId: string) {
	startBatchPolling(progressId, t('sheetmusic', 'Starting page order reset…'))
}

async function loadAudit() {
	if (props.selectedLibrary < 0) return

	isLoading.value = true
	try {
		const res = await apiGet(`/api/libraries/${props.selectedLibrary}/tools/page-order-audit` as any)
		const data = res.data?.ocs?.data as PageOrderAuditResult
		if (data) {
			if (fixedIds.value.size > 0) {
				data.items = data.items.filter(item => !fixedIds.value.has(item.id))
			}
			auditResult.value = data
			// Only on the initial scan of the library, if there are no stuck files, default to 'all'
			if (!hasInitialLoaded) {
				hasInitialLoaded = true
				if (data.stuckCount === 0 && filterMode.value === 'stuck') {
					filterMode.value = 'all'
				}
			}
		}
	} catch (error) {
		console.error('Failed to audit page orders:', error)
		showError(describeRequestFailure(error, t('sheetmusic', 'Failed to audit page orders.')))
	} finally {
		isLoading.value = false
	}
}

const filteredItems = computed(() => {
	const query = searchQuery.value.trim().toLowerCase()
	return auditResult.value.items.filter((item) => {
		if (fixedIds.value.has(item.id)) {
			return false
		}

		const pages = Math.max(item.actualPageCount || 0, item.pageCount || 0)
		const isOneToOne = item.pageOrder === '1-1' || item.pageOrder === '1'
		const isMultiPage = pages > 1

		// If multi-page filter is enabled, require >1 pages
		if (onlyMultiPage.value && !isMultiPage) {
			return false
		}

		// Filter by mode
		if (filterMode.value === 'stuck') {
			if (!isOneToOne || !isMultiPage) {
				return false
			}
		} else if (filterMode.value === 'all-1-1') {
			if (!isOneToOne) {
				return false
			}
		} else if (filterMode.value === 'custom') {
			if (!item.isCustom) {
				return false
			}
		}

		// Filter by search query
		if (query !== '') {
			const titleMatches = item.songTitle.toLowerCase().includes(query)
			const fileMatches = item.fileName.toLowerCase().includes(query)
			if (!titleMatches && !fileMatches) {
				return false
			}
		}

		return true
	})
})

function toggleSelect(id: number) {
	const next = new Set(selectedIds.value)
	if (next.has(id)) {
		next.delete(id)
	} else {
		next.add(id)
	}
	selectedIds.value = next
}

function selectAllFiltered(selectAll: boolean) {
	if (!selectAll) {
		selectedIds.value = new Set()
		return
	}
	const next = new Set(selectedIds.value)
	for (const item of filteredItems.value) {
		next.add(item.id)
	}
	selectedIds.value = next
}

async function resetSingleItem(item: PageOrderAuditItem) {
	resettingId.value = item.id
	try {
		await apiPost(`/api/libraries/${props.selectedLibrary}/tools/reset-page-orders` as any, {
			fileIds: [item.id],
			mode: 'stuck',
		} as any)
		showSuccess(t('sheetmusic', 'Page ordering for "{title}" reset to {order}.', {
			title: item.songTitle,
			order: item.suggestedPageOrder,
		}, { escape: false }))
		removeFixedItems([item.id])
	} catch (error) {
		console.error('Failed to reset item page order:', error)
		showError(describeRequestFailure(error, t('sheetmusic', 'Failed to reset page ordering.')))
	} finally {
		resettingId.value = null
	}
}

async function resetBatch(fileIds: number[] | null, mode: PageOrderFilterMode = 'stuck') {
	if (props.selectedLibrary < 0) return

	const targetIds = fileIds ?? filteredItems.value.map(i => i.id)
	if (targetIds.length === 0) return

	isBatchResetting.value = true
	const progressId = 'tool-page-order-' + Date.now() + '-' + Math.random().toString(36).substring(2, 9)
	startProgressPolling(progressId)

	try {
		const payload: Record<string, unknown> = {
			progressId,
			mode,
			fileIds: targetIds,
		}

		const res = await apiPost(`/api/libraries/${props.selectedLibrary}/tools/reset-page-orders` as any, payload as any)
		stopProgressPolling()

		const result = res.data?.ocs?.data
		const count = result?.resetCount ?? targetIds.length
		showSuccess(t('sheetmusic', 'Successfully reset page ordering for {count} file(s).', { count }, { escape: false }))
		removeFixedItems(targetIds)
	} catch (error) {
		stopProgressPolling()
		console.error('Failed to batch reset page ordering:', error)
		showError(describeRequestFailure(error, t('sheetmusic', 'Failed to batch reset page ordering.')))
	} finally {
		isBatchResetting.value = false
		stopProgressPolling()
		batchProgress.value = null
	}
}

function resetSelected() {
	const ids = Array.from(selectedIds.value)
	if (ids.length === 0) return
	resetBatch(ids)
}

function resetAllFiltered() {
	const ids = filteredItems.value.map(i => i.id)
	if (ids.length === 0) return
	resetBatch(ids)
}

function openSong(songId: number) {
	if (songId <= 0) return
	router.push({
		name: 'LibraryView',
		params: { library: String(props.selectedLibrary), view: 'songs' },
		query: { song: String(songId), edit: '1' },
	})
}

watch(() => props.selectedLibrary, (newLib) => {
	if (newLib >= 0) {
		selectedIds.value = new Set()
		fixedIds.value = new Set()
		hasInitialLoaded = false
		loadAudit()
	}
}, { immediate: true })
</script>

<template>
	<div :class="$style.toolRoot" data-test="page-order-reset-tool">
		<div :class="$style.toolHeader">
			<div :class="$style.toolTitleArea">
				<h2 :class="$style.toolTitle">{{ t('sheetmusic', 'Reset Page Ordering') }}</h2>
				<p :class="$style.toolDescription">
					{{ t('sheetmusic', 'Multi-page files that were previously split or imported may be restricted to "1-1", causing only the first page to be displayed. This tool restores the complete page range (1-N) semi-automatically or in batch.') }}
				</p>
			</div>

			<div :class="$style.batchActions">
				<NcButton
					variant="secondary"
					:disabled="isBatchResetting || selectedIds.size === 0"
					data-test="reset-selected-btn"
					@click="resetSelected">
					<template #icon>
						<CheckAll :size="18" />
					</template>
					{{ t('sheetmusic', 'Reset Selected ({count})', { count: selectedIds.size }, { escape: false }) }}
				</NcButton>

				<NcButton
					variant="primary"
					:disabled="isBatchResetting || filteredItems.length === 0"
					data-test="reset-all-filtered-btn"
					@click="resetAllFiltered">
					<template #icon>
						<RotateLeft :size="18" />
					</template>
					{{ t('sheetmusic', 'Reset All Filtered ({count})', { count: filteredItems.length }, { escape: false }) }}
				</NcButton>
			</div>
		</div>

		<PageOrderAuditSummary
			:total-files="auditResult.totalFiles"
			:stuck-count="auditResult.stuckCount"
			:custom-count="auditResult.customCount"
			:normal-count="auditResult.normalCount" />

		<PageOrderFilterBar
			v-model:filter-mode="filterMode"
			v-model:search-query="searchQuery"
			v-model:only-multi-page="onlyMultiPage"
			:stuck-count="auditResult.stuckCount"
			:total-files="auditResult.totalFiles"
			:disabled="isLoading || isBatchResetting"
			@refresh="loadAudit" />

		<div v-if="isLoading && auditResult.items.length === 0" :class="$style.loadingState">
			<NcLoadingIcon :size="36" />
			<span>{{ t('sheetmusic', 'Scanning library song files and page orders…') }}</span>
		</div>

		<PageOrderTable
			v-else
			:items="filteredItems"
			:selected-ids="selectedIds"
			:resetting-id="resettingId"
			:disabled="isBatchResetting"
			@toggle-select="toggleSelect"
			@select-all="selectAllFiltered"
			@reset-item="resetSingleItem"
			@edit-item="openEditItem"
			@open-song="openSong" />

		<ProgressDialog
			:open="isBatchResetting"
			:title="t('sheetmusic', 'Resetting Page Ordering…')"
			:percent="batchProgress ? batchProgress.percent : 0"
			:message="batchProgress ? batchProgress.message : ''"
			:fallback-message="t('sheetmusic', 'Processing files…')"
			test-id="page-order-progress" />

		<SongPageOrderModal
			v-if="isEditModalOpen"
			v-model:open="isEditModalOpen"
			:file="editingFile"
			:song-id="editingItem?.songId ?? 0"
			:library-id="selectedLibrary"
			@saved="onEditSaved" />
	</div>
</template>

<style module>
.toolRoot {
	display: flex;
	flex-direction: column;
	width: 100%;
}

.toolHeader {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 20px;
}

.toolTitleArea {
	max-width: 680px;
}

.toolTitle {
	margin: 0 0 6px 0;
	font-size: 1.4rem;
	font-weight: 700;
	color: var(--color-main-text, #222);
}

.toolDescription {
	margin: 0;
	font-size: 0.92rem;
	line-height: 1.45;
	color: var(--color-text-maxcontrast, #666);
}

.batchActions {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
}

.loadingState {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 16px;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast, #666);
}
</style>
