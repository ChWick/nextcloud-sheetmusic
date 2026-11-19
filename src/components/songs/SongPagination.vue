<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDoubleLeft from 'vue-material-design-icons/ChevronDoubleLeft.vue'
import ChevronDoubleRight from 'vue-material-design-icons/ChevronDoubleRight.vue'

export type PageSize = number | 'all'

const props = withDefaults(
	defineProps<{
		currentPage: number
		totalItems: number
		pageSize?: PageSize
		pageSizeOptions?: PageSize[]
	}>(),
	{
		pageSize: 50,
		pageSizeOptions: () => [25, 50, 100, 250, 'all'],
	},
)

const emit = defineEmits<{
	'update:currentPage': [page: number]
	'update:pageSize': [size: PageSize]
}>()

const totalPages = computed(() => {
	if (props.pageSize === 'all' || typeof props.pageSize !== 'number' || props.pageSize <= 0) {
		return 1
	}
	return Math.max(1, Math.ceil(props.totalItems / props.pageSize))
})

const fromItem = computed(() => {
	if (props.totalItems === 0) return 0
	if (props.pageSize === 'all' || typeof props.pageSize !== 'number') return 1
	return (props.currentPage - 1) * props.pageSize + 1
})

const toItem = computed(() => {
	if (props.totalItems === 0) return 0
	if (props.pageSize === 'all' || typeof props.pageSize !== 'number') return props.totalItems
	return Math.min(props.totalItems, props.currentPage * props.pageSize)
})

type PageItem = number | 'ellipsis-prev' | 'ellipsis-next'

const visiblePages = computed<PageItem[]>(() => {
	const total = totalPages.value
	const current = props.currentPage

	if (total <= 7) {
		return Array.from({ length: total }, (_, i) => i + 1)
	}

	const pages: PageItem[] = [1]

	if (current <= 4) {
		pages.push(2, 3, 4, 5, 'ellipsis-next', total)
	} else if (current >= total - 3) {
		pages.push('ellipsis-prev', total - 4, total - 3, total - 2, total - 1, total)
	} else {
		pages.push('ellipsis-prev', current - 1, current, current + 1, 'ellipsis-next', total)
	}

	return pages
})

function goToPage(page: number) {
	if (page < 1 || page > totalPages.value || page === props.currentPage) return
	emit('update:currentPage', page)
}

function handlePageSizeChange(e: Event) {
	const target = e.target as HTMLSelectElement
	const val = target.value
	const newSize: PageSize = val === 'all' ? 'all' : parseInt(val, 10)
	emit('update:pageSize', newSize)
	emit('update:currentPage', 1)
}
</script>

<template>
	<nav
		v-if="totalItems > 0"
		:class="$style.pagination"
		role="navigation"
		:aria-label="t('sheetmusic', 'Song list pagination')">
		<!-- Summary Info -->
		<div :class="$style.summary" data-test="pagination-summary">
			<template v-if="pageSize === 'all' || totalItems <= (typeof pageSize === 'number' ? pageSize : totalItems)">
				{{ t('sheetmusic', 'Showing all {count} songs', { count: totalItems }, { escape: false }) }}
			</template>
			<template v-else>
				{{ t('sheetmusic', 'Showing {from}–{to} of {total} songs', { from: fromItem, to: toItem, total: totalItems }, { escape: false }) }}
			</template>
		</div>

		<!-- Navigation Controls -->
		<div v-if="totalPages > 1" :class="$style.controls">
			<!-- First Page -->
			<NcButton
				variant="tertiary"
				:disabled="currentPage <= 1"
				:title="t('sheetmusic', 'First page')"
				:aria-label="t('sheetmusic', 'First page')"
				data-test="pagination-first"
				@click="goToPage(1)">
				<template #icon>
					<ChevronDoubleLeft :size="20" />
				</template>
			</NcButton>

			<!-- Previous Page -->
			<NcButton
				variant="tertiary"
				:disabled="currentPage <= 1"
				:title="t('sheetmusic', 'Previous page')"
				:aria-label="t('sheetmusic', 'Previous page')"
				data-test="pagination-prev"
				@click="goToPage(currentPage - 1)">
				<template #icon>
					<ChevronLeft :size="20" />
				</template>
			</NcButton>

			<!-- Desktop Page Buttons -->
			<div :class="$style.pageButtons">
				<template v-for="(p, idx) in visiblePages" :key="idx">
					<span
						v-if="typeof p === 'string'"
						:class="$style.ellipsis"
						aria-hidden="true">
						…
					</span>
					<button
						v-else
						type="button"
						:class="[$style.pageBtn, p === currentPage && $style.pageBtnActive]"
						:aria-current="p === currentPage ? 'page' : undefined"
						:title="t('sheetmusic', 'Page {page}', { page: p }, { escape: false })"
						@click="goToPage(p)">
						{{ p }}
					</button>
				</template>
			</div>

			<!-- Mobile Page Indicator -->
			<span :class="$style.mobileIndicator">
				{{ t('sheetmusic', '{current} / {total}', { current: currentPage, total: totalPages }, { escape: false }) }}
			</span>

			<!-- Next Page -->
			<NcButton
				variant="tertiary"
				:disabled="currentPage >= totalPages"
				:title="t('sheetmusic', 'Next page')"
				:aria-label="t('sheetmusic', 'Next page')"
				data-test="pagination-next"
				@click="goToPage(currentPage + 1)">
				<template #icon>
					<ChevronRight :size="20" />
				</template>
			</NcButton>

			<!-- Last Page -->
			<NcButton
				variant="tertiary"
				:disabled="currentPage >= totalPages"
				:title="t('sheetmusic', 'Last page')"
				:aria-label="t('sheetmusic', 'Last page')"
				data-test="pagination-last"
				@click="goToPage(totalPages)">
				<template #icon>
					<ChevronDoubleRight :size="20" />
				</template>
			</NcButton>
		</div>

		<!-- Page Size Selector -->
		<div :class="$style.pageSizeSelectWrap">
			<label for="sheetmusic-page-size" :class="$style.pageSizeLabel">
				{{ t('sheetmusic', 'Per page:') }}
			</label>
			<select
				id="sheetmusic-page-size"
				:class="$style.pageSizeSelect"
				:value="pageSize"
				data-test="pagination-page-size"
				@change="handlePageSizeChange">
				<option
					v-for="opt in pageSizeOptions"
					:key="opt"
					:value="opt">
					{{ opt === 'all' ? t('sheetmusic', 'All') : opt }}
				</option>
			</select>
		</div>
	</nav>
</template>

<style module>
.pagination {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 16px;
	background-color: var(--color-background-hover);
	border-top: 1px solid var(--color-border);
	gap: 12px;
	flex-wrap: wrap;
	box-sizing: border-box;
}

.summary {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.controls {
	display: flex;
	align-items: center;
	gap: 4px;
}

.pageButtons {
	display: flex;
	align-items: center;
	gap: 2px;
}

.pageBtn {
	min-width: 32px;
	height: 32px;
	padding: 0 6px;
	border: 1px solid transparent;
	background: transparent;
	border-radius: var(--border-radius, 6px);
	font-size: 13px;
	font-weight: 500;
	color: var(--color-main-text);
	cursor: pointer;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	transition: background-color 0.15s ease, color 0.15s ease;
}

.pageBtn:hover {
	background-color: var(--color-background-dark);
}

.pageBtnActive {
	background-color: var(--color-primary-element) !important;
	color: var(--color-primary-element-text) !important;
	font-weight: 600;
}

.ellipsis {
	padding: 0 4px;
	color: var(--color-text-maxcontrast);
	user-select: none;
}

.mobileIndicator {
	display: none;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-main-text);
	padding: 0 6px;
	white-space: nowrap;
}

.pageSizeSelectWrap {
	display: flex;
	align-items: center;
	gap: 6px;
	white-space: nowrap;
}

.pageSizeLabel {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.pageSizeSelect {
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	padding: 4px 8px;
	font-size: 13px;
	cursor: pointer;
}

.pageSizeSelect:focus {
	outline: 2px solid var(--color-primary-element);
}

@media (max-width: 768px) {
	.pagination {
		padding: 8px 12px;
		gap: 8px;
	}

	.pageButtons {
		display: none;
	}

	.mobileIndicator {
		display: inline-block;
	}
}

@media (max-width: 480px) {
	.pagination {
		flex-direction: column;
		align-items: center;
		gap: 6px;
	}

	.summary {
		font-size: 12px;
	}

	.pageSizeSelectWrap {
		font-size: 12px;
	}
}
</style>
