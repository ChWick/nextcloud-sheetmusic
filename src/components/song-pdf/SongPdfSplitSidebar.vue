<script lang="ts">
export interface SplitSegment {
	id: string
	title: string
	startPage: number
	endPage: number
}

export const segmentColors = [
	'#3b82f6', // blue
	'#10b981', // emerald
	'#f59e0b', // amber
	'#8b5cf6', // purple
	'#ec4899', // pink
	'#06b6d4', // cyan
	'#84cc16', // lime
	'#f97316', // orange
]

export function getSegmentColor(segIndex: number): string {
	return segmentColors[segIndex % segmentColors.length]
}
</script>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import OriginalSongActionSelector, { type OriginalSongAction } from './OriginalSongActionSelector.vue'


const props = withDefaults(
	defineProps<{
		segments: SplitSegment[]
		originalSongAction: OriginalSongAction
		attachedFilesCount: number
		totalPages: number
		selectedPages?: number[]
		canRemovePages?: boolean
	}>(),
	{
		selectedPages: () => [],
		canRemovePages: true,
	},
)

const emit = defineEmits<{
	'update:originalSongAction': [action: OriginalSongAction]
	'add-segment': []
	'remove-segment': [index: number]
	'assign-selected': [segment: SplitSegment]
}>()

const selectedRangeText = computed(() => {
	if (!props.selectedPages || props.selectedPages.length === 0) return ''
	const min = Math.min(...props.selectedPages)
	const max = Math.max(...props.selectedPages)
	return `${min}–${max}`
})

const segmentListRef = ref<HTMLElement | null>(null)
const isMobileCollapsed = ref(false)

watch(
	() => props.segments.length,
	async (newLen, oldLen) => {
		if (newLen > oldLen) {
			await nextTick()
			if (segmentListRef.value) {
				segmentListRef.value.scrollTop = segmentListRef.value.scrollHeight
			}
		}
	},
)
</script>

<template>
	<aside :class="[$style.splitSidebar, isMobileCollapsed && $style.splitSidebarCollapsed]" data-test="split-sidebar">
		<div :class="$style.sidebarHeader">
			<button
				type="button"
				:class="$style.mobileCollapseBtn"
				:aria-expanded="!isMobileCollapsed"
				@click="isMobileCollapsed = !isMobileCollapsed">
				<h4>{{ t('sheetmusic', 'Split Segments') }} ({{ segments.length }})</h4>
				<ChevronUp v-if="isMobileCollapsed" :size="18" />
				<ChevronDown v-else :size="18" />
			</button>
			<h4 :class="$style.desktopTitle">{{ t('sheetmusic', 'Split Segments') }}</h4>
			<NcButton size="small" variant="secondary" @click="emit('add-segment')">
				<template #icon>
					<PlusIcon :size="16" />
				</template>
				{{ t('sheetmusic', 'Add Part') }}
			</NcButton>
		</div>

		<p :class="$style.sidebarHelp">
			{{ t('sheetmusic', 'Each segment creates a new song in the same book, copying metadata from the original song.') }}
		</p>

		<!-- Segments list -->
		<div
			ref="segmentListRef"
			:class="$style.segmentList"
			data-test="split-segment-list">
			<div
				v-for="(seg, idx) in segments"
				:key="seg.id"
				:class="$style.segmentCard"
				:style="{ borderLeftColor: getSegmentColor(idx) }">
				<div :class="$style.segmentCardHeader">
					<span
						:class="$style.segmentNumberBadge"
						:style="{ backgroundColor: getSegmentColor(idx) }">
						{{ idx + 1 }}
					</span>
					<NcTextField
						v-model="seg.title"
						:label="t('sheetmusic', 'Song Title')"
						hide-label
						:class="$style.segmentTitleInput"
						:placeholder="t('sheetmusic', 'Song title…')" />
					<NcButton
						v-if="segments.length > 1"
						size="small"
						variant="tertiary"
						:title="t('sheetmusic', 'Remove part')"
						@click="emit('remove-segment', idx)">
						<template #icon>
							<DeleteIcon :size="16" />
						</template>
					</NcButton>
				</div>

				<div :class="$style.segmentCardBody">
					<div :class="$style.rangeGroup">
						<label :class="$style.rangeLabel">{{ t('sheetmusic', 'Pages:') }}</label>
						<input
							v-model.number="seg.startPage"
							type="number"
							min="1"
							:max="totalPages"
							:class="$style.pageInput" />
						<span>{{ t('sheetmusic', 'to') }}</span>
						<input
							v-model.number="seg.endPage"
							type="number"
							:min="seg.startPage"
							:max="totalPages"
							:class="$style.pageInput" />
					</div>

					<NcButton
						v-if="selectedPages.length > 0"
						size="small"
						variant="secondary"
						:class="$style.assignBtn"
						@click="emit('assign-selected', seg)">
						<template #icon>
							<ArrowRight :size="14" />
						</template>
						{{ t('sheetmusic', 'Assign selected ({range})', {
							range: selectedRangeText
						}, { escape: false }) }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Options for Original Song -->
		<OriginalSongActionSelector
			:model-value="originalSongAction"
			:attached-files-count="attachedFilesCount"
			:can-remove-pages="canRemovePages"
			@update:model-value="emit('update:originalSongAction', $event)" />
	</aside>
</template>

<style module>
.splitSidebar {
	width: 320px;
	height: 100%;
	max-height: 100%;
	min-height: 0;
	box-sizing: border-box;
	border-left: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	display: flex;
	flex-direction: column;
	padding: 16px;
	overflow: hidden;
	flex-shrink: 0;
	gap: 12px;
}

.sidebarHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-shrink: 0;
}

.sidebarHeader h4 {
	margin: 0;
	font-size: 16px;
}

.mobileCollapseBtn {
	display: none;
	align-items: center;
	gap: 6px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	padding: 2px 0;
	cursor: pointer;
}

.desktopTitle {
	display: block;
}

.sidebarHelp {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 0;
	flex-shrink: 0;
}

.segmentList {
	display: flex;
	flex-direction: column;
	gap: 12px;
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	scrollbar-gutter: stable;
	padding-right: 4px;
}

.segmentCard {
	border: 1px solid var(--color-border);
	border-left-width: 4px;
	border-radius: 6px;
	padding: 10px;
	background-color: var(--color-background-hover);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.segmentCardHeader {
	display: flex;
	align-items: center;
	gap: 6px;
}

.segmentNumberBadge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	color: #fff;
	font-size: 11px;
	font-weight: bold;
	flex-shrink: 0;
}

.segmentTitleInput {
	flex: 1;
	min-width: 0;
}

.segmentCardBody {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.rangeGroup {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
}

.rangeLabel {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.rangeGroup .pageInput {
	width: 72px;
	min-width: 72px;
	box-sizing: border-box;
	margin: 0;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 13px;
	font-variant-numeric: tabular-nums;
	text-align: center;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.assignBtn {
	align-self: flex-start;
}

@media (max-width: 840px) {
	.splitSidebar {
		width: 100%;
		border-left: none;
		border-top: 1px solid var(--color-border);
		max-height: 280px;
		min-height: 180px;
		padding: 10px 12px;
	}

	.splitSidebarCollapsed {
		min-height: 0;
		max-height: 52px;
	}

	.splitSidebarCollapsed .sidebarHelp,
	.splitSidebarCollapsed .segmentList,
	.splitSidebarCollapsed > :last-child:not(.sidebarHeader) {
		display: none;
	}

	.mobileCollapseBtn {
		display: inline-flex;
	}

	.desktopTitle {
		display: none;
	}
}
</style>
