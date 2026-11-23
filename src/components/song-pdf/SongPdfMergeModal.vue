<script setup lang="ts">
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LibraryData, Song, SongFile } from '../../types/song'
import { ref, toRefs } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Close from 'vue-material-design-icons/Close.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import MagnifyPlusOutline from 'vue-material-design-icons/MagnifyPlusOutline.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import Restore from 'vue-material-design-icons/Restore.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import { usePageOrderDragDrop } from './usePageOrderDragDrop'
import SongPageOrderZoomModal from './SongPageOrderZoomModal.vue'
import SongPdfSaveProgress from './SongPdfSaveProgress.vue'
import { getSourceFileColor } from './pdf-merge-engine'
import { thumbKey, usePdfMerge } from './usePdfMerge'

const props = defineProps<{
	open: boolean
	song: Song
	libraryId: number
	pdfFiles: SongFile[]
}>()

const emit = defineEmits<{
	'update:open': [value: boolean]
	'merged': [data: LibraryData]
}>()

const { open, song, libraryId, pdfFiles } = toRefs(props)

const {
	isLoading,
	isSaving,
	saveProgressPercent,
	saveProgressText,
	errorMessage,
	loadedSources,
	includedSources,
	mergePages,
	thumbnails,
	originalsAction,
	canSubmit,
	isZoomModalOpen,
	zoomSequenceIndex,
	highResCache,
	isLoadingHighRes,
	activeZoomEntry,
	activeZoomKey,
	getPageSourceLabel,
	getPageSourceTitle,
	getPageSourceColor,
	toggleSourceIncluded,
	moveSource,
	resetPagesOrder,
	reorderPages,
	movePage,
	rotatePage,
	duplicatePage,
	removePage,
	openPageZoom,
	goToZoomIndex,
	handleMerge,
} = usePdfMerge({
	open,
	song,
	libraryId,
	pdfFiles,
	onMerged: (data) => emit('merged', data),
	onClose: () => emit('update:open', false),
})

// Card width zoom controls
const DEFAULT_CARD_WIDTH = 156
const MIN_CARD_WIDTH = 110
const MAX_CARD_WIDTH = 280
const cardWidth = ref(DEFAULT_CARD_WIDTH)

function zoomIn() {
	cardWidth.value = Math.min(MAX_CARD_WIDTH, cardWidth.value + 28)
}

function zoomOut() {
	cardWidth.value = Math.max(MIN_CARD_WIDTH, cardWidth.value - 28)
}

function resetZoom() {
	cardWidth.value = DEFAULT_CARD_WIDTH
}

// Drag & drop state (reused from page-order editor)
const {
	draggedItem,
	dropTargetIndex,
	dropIndicatorSide,
	handleSequenceDragStart,
	handleCardDragOver,
	handleCardDragLeave,
	handleCardDrop,
	handleDragEnd,
} = usePageOrderDragDrop()

function handlePageCardDrop(event: DragEvent, targetIndex: number) {
	handleCardDrop(event, targetIndex, {
		onInsert: () => {},
		onReorder: (from, to) => reorderPages(from, to),
	})
}
</script>

<template>
	<NcDialog
		v-if="open"
		:open="open"
		:name="t('sheetmusic', 'Merge PDFs')"
		size="large"
		:class="$style.largeDialog"
		@update:open="emit('update:open', $event)">
		<template #actions>
			<NcButton
				type="button"
				variant="secondary"
				:disabled="isSaving"
				data-test="merge-pdfs-cancel"
				@click="emit('update:open', false)">
				<template #icon>
					<Close :size="20" />
				</template>
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<NcButton
				type="button"
				variant="primary"
				:disabled="!canSubmit"
				data-test="merge-pdfs-submit"
				@click="handleMerge">
				<template #icon>
					<NcLoadingIcon v-if="isSaving" :size="20" />
					<MergeIcon v-else :size="20" />
				</template>
				{{ isSaving ? t('sheetmusic', 'Merging…') : t('sheetmusic', 'Merge PDFs') }}
			</NcButton>
		</template>

		<div :class="$style.dialogContent">
			<SongPdfSaveProgress
				v-if="isSaving"
				:progress-text="saveProgressText"
				:progress-percent="saveProgressPercent" />

			<p v-if="errorMessage" :class="$style.errorBanner" role="alert">
				{{ errorMessage }}
			</p>

			<div v-if="isLoading" :class="$style.loadingState" data-test="merge-pdfs-loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('sheetmusic', 'Loading PDF documents…') }}</span>
			</div>

			<template v-else>
				<!-- Source PDFs selection & file-level order -->
				<section :class="$style.sourcesSection">
					<div :class="$style.sectionHeader">
						<span :class="$style.sectionTitle">
							{{ t('sheetmusic', 'Source PDFs ({selected} of {total} selected)', { selected: includedSources.length, total: loadedSources.length }, { escape: false }) }}
						</span>
						<span :class="$style.sectionHint">
							{{ t('sheetmusic', 'Reorder files or drag individual pages below.') }}
						</span>
					</div>
					<ul :class="$style.sourceList" data-test="merge-source-list">
						<li
							v-for="(source, idx) in loadedSources"
							:key="source.fileId"
							:class="[$style.sourceItem, !source.included && $style.sourceItemExcluded]"
							:data-test="`merge-source-${source.fileId}`">
							<label :class="$style.sourceCheckboxLabel">
								<input
									type="checkbox"
									:checked="source.included"
									:disabled="isSaving"
									:data-test="`merge-source-checkbox-${source.fileId}`"
									@change="toggleSourceIncluded(idx)">
								<span
									:class="$style.sourceColorDot"
									:style="{ backgroundColor: getSourceFileColor(idx) }">
									{{ idx + 1 }}
								</span>
								<span :class="$style.sourceFileName" :title="source.fileName">
									{{ source.fileName }}
								</span>
								<span :class="$style.sourcePageCount">
									({{ source.pageCount === 1 ? t('sheetmusic', '1 page') : t('sheetmusic', '{count} pages', { count: source.pageCount }, { escape: false }) }})
								</span>
							</label>
							<div :class="$style.sourceMoveButtons">
								<NcButton
									type="button"
									variant="tertiary"
									:disabled="isSaving || idx === 0"
									:title="t('sheetmusic', 'Move file up')"
									:aria-label="t('sheetmusic', 'Move {file} up', { file: source.fileName }, { escape: false })"
									:data-test="`merge-source-up-${source.fileId}`"
									@click="moveSource(idx, -1)">
									<template #icon>
										<ArrowUp :size="18" />
									</template>
								</NcButton>
								<NcButton
									type="button"
									variant="tertiary"
									:disabled="isSaving || idx === loadedSources.length - 1"
									:title="t('sheetmusic', 'Move file down')"
									:aria-label="t('sheetmusic', 'Move {file} down', { file: source.fileName }, { escape: false })"
									:data-test="`merge-source-down-${source.fileId}`"
									@click="moveSource(idx, 1)">
									<template #icon>
										<ArrowDown :size="18" />
									</template>
								</NcButton>
							</div>
						</li>
					</ul>
				</section>

				<!-- Page grid toolbar -->
				<div :class="$style.gridToolbar">
					<div :class="$style.summaryText" data-test="merge-page-summary">
						{{ t('sheetmusic', 'Merged Result: {count} page | Merged Result: {count} pages', { count: mergePages.length }, mergePages.length, { escape: false }) }}
					</div>
					<div :class="$style.toolbarControls">
						<NcButton
							type="button"
							variant="secondary"
							:disabled="isSaving || includedSources.length === 0"
							:title="t('sheetmusic', 'Reset page order and rotations')"
							data-test="merge-reset-pages"
							@click="resetPagesOrder">
							<template #icon>
								<Restore :size="18" />
							</template>
							{{ t('sheetmusic', 'Reset') }}
						</NcButton>
						<div :class="$style.zoomControls">
							<NcButton
								type="button"
								variant="secondary"
								:disabled="cardWidth <= MIN_CARD_WIDTH"
								:title="t('sheetmusic', 'Smaller preview')"
								:aria-label="t('sheetmusic', 'Smaller preview')"
								@click="zoomOut">
								<template #icon>
									<MagnifyMinus :size="18" />
								</template>
							</NcButton>
							<NcButton
								type="button"
								variant="secondary"
								:title="t('sheetmusic', 'Reset preview size')"
								:aria-label="t('sheetmusic', 'Reset preview size')"
								@click="resetZoom">
								{{ cardWidth }}px
							</NcButton>
							<NcButton
								type="button"
								variant="secondary"
								:disabled="cardWidth >= MAX_CARD_WIDTH"
								:title="t('sheetmusic', 'Larger preview')"
								:aria-label="t('sheetmusic', 'Larger preview')"
								@click="zoomIn">
								<template #icon>
									<MagnifyPlus :size="18" />
								</template>
							</NcButton>
						</div>
					</div>
				</div>

				<!-- Rearrangeable Page Grid -->
				<div
					v-if="mergePages.length > 0"
					:class="$style.pagesGrid"
					:style="{ '--merge-card-width': `${cardWidth}px` }"
					data-test="merge-pages-grid">
					<div
						v-for="(entry, index) in mergePages"
						:key="entry.id"
						:draggable="!isSaving"
						:class="[
							$style.pageCard,
							draggedItem?.type === 'sequence-card' && draggedItem.fromIndex === index && $style.pageCardDragging,
							dropTargetIndex === index && dropIndicatorSide === 'left' && $style.dropIndicatorLeft,
							dropTargetIndex === index && dropIndicatorSide === 'right' && $style.dropIndicatorRight,
						]"
						:data-test="`merge-page-card-${index}`"
						@dragstart="handleSequenceDragStart($event, index, entry.sourcePageNum)"
						@dragover="handleCardDragOver($event, index)"
						@dragleave="handleCardDragLeave($event, index)"
						@drop="handlePageCardDrop($event, index)"
						@dragend="handleDragEnd">
						<div :class="$style.cardHeader">
							<span :class="$style.outputPageBadge">#{{ index + 1 }}</span>
							<span
								:class="$style.sourceBadge"
								:style="{ backgroundColor: getPageSourceColor(entry) }"
								:title="getPageSourceTitle(entry)">
								{{ getPageSourceLabel(entry) }}
							</span>
						</div>

						<div :class="$style.thumbWrap" @click="openPageZoom(index)">
							<img
								v-if="thumbnails[thumbKey(entry.fileId, entry.sourcePageNum)]"
								:src="thumbnails[thumbKey(entry.fileId, entry.sourcePageNum)]"
								:alt="getPageSourceTitle(entry)"
								:class="$style.thumbImg"
								:style="entry.rotation ? { transform: `rotate(${entry.rotation}deg)` } : undefined">
							<div v-else :class="$style.thumbPlaceholder">
								<NcLoadingIcon :size="20" />
							</div>
							<span v-if="entry.rotation" :class="$style.rotationBadge">
								{{ entry.rotation }}°
							</span>
						</div>

						<div :class="$style.cardActions">
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving || index === 0"
								:title="t('sheetmusic', 'Move page left')"
								:aria-label="t('sheetmusic', 'Move page left')"
								:data-test="`merge-page-left-${index}`"
								@click="movePage(index, -1)">
								<ArrowLeft :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving || index === mergePages.length - 1"
								:title="t('sheetmusic', 'Move page right')"
								:aria-label="t('sheetmusic', 'Move page right')"
								:data-test="`merge-page-right-${index}`"
								@click="movePage(index, 1)">
								<ArrowRight :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Rotate 90° counter-clockwise')"
								:aria-label="t('sheetmusic', 'Rotate 90° counter-clockwise')"
								:data-test="`merge-page-rot-ccw-${index}`"
								@click="rotatePage(index, -90)">
								<RotateLeft :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Rotate 90° clockwise')"
								:aria-label="t('sheetmusic', 'Rotate 90° clockwise')"
								:data-test="`merge-page-rot-cw-${index}`"
								@click="rotatePage(index, 90)">
								<RotateRight :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Duplicate page')"
								:aria-label="t('sheetmusic', 'Duplicate page')"
								:data-test="`merge-page-duplicate-${index}`"
								@click="duplicatePage(index)">
								<ContentCopy :size="16" />
							</button>
							<button
								type="button"
								:class="$style.cardActionBtn"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Zoom page')"
								:aria-label="t('sheetmusic', 'Zoom page')"
								:data-test="`merge-page-zoom-${index}`"
								@click="openPageZoom(index)">
								<MagnifyPlusOutline :size="16" />
							</button>
							<button
								type="button"
								:class="[$style.cardActionBtn, $style.cardActionDanger]"
								:disabled="isSaving"
								:title="t('sheetmusic', 'Remove page')"
								:aria-label="t('sheetmusic', 'Remove page')"
								:data-test="`merge-page-remove-${index}`"
								@click="removePage(index)">
								<Delete :size="16" />
							</button>
						</div>
					</div>
				</div>
				<p v-else :class="$style.emptyNotice">
					{{ t('sheetmusic', 'Select at least 2 PDFs and keep at least 1 page to merge.') }}
				</p>

				<!-- Original files handling -->
				<section :class="$style.optionsSection">
					<div :class="$style.sectionTitle">
						{{ t('sheetmusic', 'Original PDF files') }}
					</div>
					<div :class="$style.radioGroup" role="radiogroup" :aria-label="t('sheetmusic', 'Original PDF files handling')">
						<label :class="[$style.radioOption, originalsAction === 'delete-originals' && $style.radioOptionActive]">
							<input
								v-model="originalsAction"
								type="radio"
								name="mergeOriginalsAction"
								value="delete-originals"
								:disabled="isSaving"
								data-test="merge-action-delete-originals">
							<div>
								<div :class="$style.radioLabel">
									{{ t('sheetmusic', 'Delete original merged PDFs') }}
								</div>
								<div :class="$style.radioHint">
									{{ t('sheetmusic', 'Saves the merged PDF, deletes the selected original PDFs, and updates file paths according to library naming rules.') }}
								</div>
							</div>
						</label>

						<label :class="[$style.radioOption, originalsAction === 'keep-originals' && $style.radioOptionActive]">
							<input
								v-model="originalsAction"
								type="radio"
								name="mergeOriginalsAction"
								value="keep-originals"
								:disabled="isSaving"
								data-test="merge-action-keep-originals">
							<div>
								<div :class="$style.radioLabel">
									{{ t('sheetmusic', 'Keep original PDFs') }}
								</div>
								<div :class="$style.radioHint">
									{{ t('sheetmusic', 'Adds the merged PDF alongside the original PDFs and updates file paths according to library naming rules.') }}
								</div>
							</div>
						</label>
					</div>
				</section>
			</template>
		</div>

		<!-- High-Res Zoom Lightbox -->
		<SongPageOrderZoomModal
			v-model:open="isZoomModalOpen"
			:page-num="zoomSequenceIndex + 1"
			:total-pages="mergePages.length"
			:high-res-url="highResCache[activeZoomKey]"
			:thumbnail-url="thumbnails[activeZoomKey]"
			:is-loading-high-res="isLoadingHighRes"
			:show-append-action="false"
			:rotation-deg="activeZoomEntry?.rotation ?? 0"
			@prev="goToZoomIndex(zoomSequenceIndex - 1)"
			@next="goToZoomIndex(zoomSequenceIndex + 1)" />
	</NcDialog>
</template>

<style module>
.largeDialog :global(.modal-container) {
	width: 94vw !important;
	max-width: 1440px !important;
	height: 90vh !important;
	max-height: 940px !important;
	display: flex !important;
	flex-direction: column !important;
	overflow: hidden !important;
}

.largeDialog :global(.modal-container__content),
.largeDialog :global(.dialog),
.largeDialog :global(.dialog__wrapper) {
	display: flex !important;
	flex-direction: column !important;
	flex: 1 1 auto !important;
	min-height: 0 !important;
	height: 100% !important;
	overflow: hidden !important;
}

.largeDialog :global(.dialog__content) {
	display: flex !important;
	flex-direction: column !important;
	flex: 1 1 auto !important;
	min-height: 0 !important;
	overflow-y: auto !important;
	overflow-x: hidden !important;
	padding: 8px 16px 14px !important;
}

.largeDialog :global(.dialog__actions) {
	flex-shrink: 0 !important;
	background-color: var(--color-main-background, #fff) !important;
	border-top: 1px solid var(--color-border) !important;
	padding: 10px 18px !important;
	margin: 0 !important;
	z-index: 50 !important;
}

.dialogContent {
	display: flex;
	flex-direction: column;
	gap: 14px;
	min-width: 0;
	width: 100%;
	box-sizing: border-box;
}

.errorBanner {
	margin: 0;
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-error-light, rgba(233, 50, 45, 0.12));
	border: 1px solid var(--color-error);
	color: var(--color-error);
	font-size: 0.875rem;
}

.loadingState {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	min-height: 240px;
	color: var(--color-text-maxcontrast);
}

.sourcesSection {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 10px 12px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.sectionHeader {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.sectionTitle {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.sectionHint {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.sourceList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.sourceItem {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	padding: 6px 10px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
}

.sourceItemExcluded {
	opacity: 0.55;
}

.sourceCheckboxLabel {
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1 1 0;
	min-width: 0;
	cursor: pointer;
}

.sourceColorDot {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	color: #fff;
	font-size: 0.75rem;
	font-weight: 700;
	flex-shrink: 0;
}

.sourceFileName {
	font-weight: 600;
	font-size: 0.875rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.sourcePageCount {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.sourceMoveButtons {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.gridToolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.summaryText {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.toolbarControls {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.zoomControls {
	display: flex;
	align-items: center;
	gap: 4px;
}

.pagesGrid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(var(--merge-card-width, 156px), 1fr));
	gap: 12px;
	padding: 10px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	min-height: 220px;
}

.pageCard {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	cursor: grab;
	user-select: none;
	transition: box-shadow 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
}

.pageCard:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.pageCardDragging {
	opacity: 0.45;
	cursor: grabbing;
}

.dropIndicatorLeft {
	box-shadow: -4px 0 0 0 var(--color-primary-element);
}

.dropIndicatorRight {
	box-shadow: 4px 0 0 0 var(--color-primary-element);
}

.cardHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
}

.outputPageBadge {
	font-size: 0.78rem;
	font-weight: 700;
	color: var(--color-main-text);
}

.sourceBadge {
	display: inline-flex;
	align-items: center;
	padding: 1px 6px;
	border-radius: 999px;
	color: #fff;
	font-size: 0.7rem;
	font-weight: 600;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 100%;
}

.thumbWrap {
	position: relative;
	aspect-ratio: 3 / 4;
	width: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	background: #fff;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	overflow: hidden;
	cursor: zoom-in;
}

.thumbImg {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
	display: block;
	transition: transform 0.15s ease;
}

.thumbPlaceholder {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	background: var(--color-background-hover);
}

.rotationBadge {
	position: absolute;
	bottom: 4px;
	right: 4px;
	padding: 1px 5px;
	border-radius: 4px;
	background: rgba(0, 0, 0, 0.72);
	color: #fff;
	font-size: 0.7rem;
	font-weight: 600;
}

.cardActions {
	display: flex;
	align-items: center;
	justify-content: center;
	flex-wrap: wrap;
	gap: 2px;
}

.cardActionBtn {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	padding: 0;
	border: 1px solid transparent;
	border-radius: 4px;
	background: transparent;
	color: var(--color-main-text);
	cursor: pointer;
}

.cardActionBtn:hover:not(:disabled) {
	background: var(--color-background-dark, var(--color-background-hover));
	border-color: var(--color-border);
}

.cardActionBtn:disabled {
	opacity: 0.35;
	cursor: not-allowed;
}

.cardActionDanger:hover:not(:disabled) {
	color: var(--color-error);
}

.emptyNotice {
	margin: 0;
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 6px);
}

.optionsSection {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.radioGroup {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.radioOption {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	background: var(--color-main-background);
	cursor: pointer;
}

.radioOptionActive {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.radioLabel {
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.radioHint {
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}
</style>
