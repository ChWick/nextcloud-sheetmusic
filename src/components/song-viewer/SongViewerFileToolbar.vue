<script setup lang="ts">
import type { SongFile } from '../../types/song'
import type { PdfPageResolutionSummary } from '../song-pdf/pdf-enhance-engine'

import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import DrawIcon from 'vue-material-design-icons/Draw.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FullscreenIcon from 'vue-material-design-icons/Fullscreen.vue'
import FullscreenExitIcon from 'vue-material-design-icons/FullscreenExit.vue'
import History from 'vue-material-design-icons/History.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import PdfInfoPopover from '../song-pdf/PdfInfoPopover.vue'
import { getFileName } from '../../utils/file'
import { formatByteSize } from '../song-pdf/pdf-enhance-engine'

const props = withDefaults(
	defineProps<{
		file: SongFile
		files?: SongFile[]
		isPdf: boolean
		currentPage?: number
		totalPages?: number
		fileSizeBytes?: number | null
		pageResolutions?: PdfPageResolutionSummary[]
		showAnnotations?: boolean
		isEditingAnnotations?: boolean
		isFullscreen?: boolean
		canWrite?: boolean
	}>(),
	{
		files: () => [],
		currentPage: 1,
		totalPages: 1,
		fileSizeBytes: null,
		pageResolutions: () => [],
		showAnnotations: true,
		isEditingAnnotations: false,
		isFullscreen: false,
		canWrite: true,
	},
)

const emit = defineEmits<{
	'select-file': [fileId: string]
	'open-external': []
	'modify-pdf': []
	'open-versions': []
	'toggle-annotations': []
	'toggle-edit-annotations': []
	'toggle-fullscreen': []
}>()

const effectiveSizeBytes = computed(() => props.fileSizeBytes ?? props.file?.fileSize ?? null)
const formattedFileSize = computed(() => formatByteSize(effectiveSizeBytes.value))
const fileName = computed(() => getFileName(props.file.path))
const editAnnotationsLabel = computed(() =>
	props.isEditingAnnotations ? t('sheetmusic', 'Exit annotation editor') : t('sheetmusic', 'Edit annotations'),
)
const fullscreenLabel = computed(() =>
	props.isFullscreen ? t('sheetmusic', 'Exit fullscreen') : t('sheetmusic', 'Fullscreen'),
)
const showAnnotationsLabel = computed(() =>
	props.showAnnotations ? t('sheetmusic', 'Hide annotations') : t('sheetmusic', 'Show annotations'),
)

const activePageResolution = computed<PdfPageResolutionSummary | null>(() => {
	if (!props.pageResolutions || props.pageResolutions.length === 0) return null
	return (
		props.pageResolutions.find((p) => p.pageNum === props.currentPage) ??
		props.pageResolutions[0] ??
		null
	)
})
</script>

<template>
	<div :class="$style.fileToolbar" data-test="song-file-toolbar">
		<div :class="$style.fileInfoSection">
			<div v-if="files.length > 1" :class="$style.fileSelectWrap">
				<FileDocumentOutline :size="18" :class="$style.fileIcon" />
				<select
					:value="String(file.id)"
					:class="$style.fileSelect"
					:aria-label="t('sheetmusic', 'Files of this song')"
					data-test="song-file-selector"
					@change="emit('select-file', ($event.target as HTMLSelectElement).value)">
					<option
						v-for="(item, index) in files"
						:key="item.id"
						:value="String(item.id)">
						{{ index + 1 }}. {{ getFileName(item.path) }}
					</option>
				</select>
			</div>
			<span v-else :class="$style.fileName" :title="file.path">
				<FileDocumentOutline :size="18" :class="$style.fileIcon" />
				<span :class="$style.fileNameText">{{ fileName }}</span>
			</span>

			<PdfInfoPopover
				v-if="isPdf && (formattedFileSize !== '—' || activePageResolution)"
				:formatted-file-size="formattedFileSize"
				:file-size-bytes="effectiveSizeBytes"
				:total-pages="totalPages"
				:primary-resolution="activePageResolution"
				:page-resolutions="pageResolutions"
				trigger-test-id="pdf-file-info-trigger"
				dropdown-test-id="pdf-file-info-dropdown"
				file-size-test-id="pdf-file-info-size"
				resolution-test-id="pdf-file-info-resolution" />
		</div>

		<div :class="$style.fileActions">
			<NcButton
				v-if="isPdf && canWrite !== false"
				:variant="isEditingAnnotations ? 'primary' : 'secondary'"
				:title="editAnnotationsLabel"
				:aria-label="editAnnotationsLabel"
				data-test="edit-annotations-button"
				@click="emit('toggle-edit-annotations')">
				<template #icon>
					<DrawIcon :size="20" />
				</template>
			</NcButton>
			<NcButton
				:variant="isFullscreen ? 'primary' : 'secondary'"
				:title="fullscreenLabel"
				:aria-label="fullscreenLabel"
				data-test="viewer-fullscreen-button"
				@click="emit('toggle-fullscreen')">
				<template #icon>
					<FullscreenExitIcon v-if="isFullscreen" :size="20" />
					<FullscreenIcon v-else :size="20" />
				</template>
			</NcButton>
			<NcActions
				:aria-label="t('sheetmusic', 'More file actions')"
				data-test="pdf-actions-menu">
				<NcActionButton
					v-if="isPdf"
					:title="showAnnotationsLabel"
					data-test="toggle-annotations-button"
					@click="emit('toggle-annotations')">
					<template #icon>
						<EyeOutline v-if="showAnnotations" :size="18" />
						<EyeOffOutline v-else :size="18" />
					</template>
					{{ showAnnotationsLabel }}
				</NcActionButton>
				<NcActionButton
					v-if="isPdf && canWrite !== false"
					:title="t('sheetmusic', 'Modify PDF')"
					@click="emit('modify-pdf')">
					<template #icon>
						<FileDocumentEditOutline :size="18" />
					</template>
					{{ t('sheetmusic', 'Modify PDF') }}
				</NcActionButton>
				<NcActionButton
					v-if="isPdf && canWrite !== false"
					:title="t('sheetmusic', 'PDF version history')"
					@click="emit('open-versions')">
					<template #icon>
						<History :size="18" />
					</template>
					{{ t('sheetmusic', 'PDF version history') }}
				</NcActionButton>
				<NcActionButton
					:title="t('sheetmusic', 'Open in external viewer')"
					@click="emit('open-external')">
					<template #icon>
						<OpenInNewIcon :size="18" />
					</template>
					{{ t('sheetmusic', 'Open in external viewer') }}
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<style module>
.fileToolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 6px 12px;
	margin-bottom: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	flex-wrap: wrap;
	box-sizing: border-box;
}

.fileInfoSection {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	flex-wrap: wrap;
}

.fileName {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-main-text);
	min-width: 0;
}

.fileIcon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.fileNameText {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.fileSelectWrap {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
}

.fileSelect {
	height: 32px;
	max-width: 260px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	font-weight: 500;
	cursor: pointer;
}

.fileActions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}
</style>
