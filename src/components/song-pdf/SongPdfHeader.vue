<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import type { Song, SongFile } from '../../types/song'
import { getFileName } from '../../utils/file'

// Icons
import CloseIcon from 'vue-material-design-icons/Close.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import CropIcon from 'vue-material-design-icons/Crop.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import CallSplit from 'vue-material-design-icons/CallSplit.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'

export type Mode = 'rotate' | 'crop' | 'enhance' | 'split'

const props = defineProps<{
	song: Song
	targetFile?: SongFile
	totalPages: number
	remainingPagesCount: number
	hasAnyDeletion: boolean
	activeMode: Mode
	isSaving: boolean
	isLoading: boolean
	hasAnyChange: boolean
	hasAnyRotation?: boolean
	hasAnyCrop?: boolean
	hasAnyEnhance?: boolean
	hasAnyJoin?: boolean
	splitSegmentsCount: number
}>()

const emit = defineEmits<{
	'cancel': []
	'update:activeMode': [mode: Mode]
	'save': []
}>()

const saveButtonDisabled = computed(() => {
	if (props.isSaving || props.isLoading) return true
	if (props.activeMode === 'split') {
		return props.splitSegmentsCount === 0
	}
	return !props.hasAnyChange || props.remainingPagesCount === 0
})

const saveButtonTitle = computed(() => {
	if (props.activeMode === 'rotate') {
		return (props.hasAnyDeletion || props.hasAnyCrop || props.hasAnyEnhance || props.hasAnyJoin)
			? t('sheetmusic', 'Save changes')
			: t('sheetmusic', 'Save rotated PDF')
	}
	if (props.activeMode === 'crop') {
		return (props.hasAnyDeletion || props.hasAnyRotation || props.hasAnyEnhance || props.hasAnyJoin)
			? t('sheetmusic', 'Save changes')
			: t('sheetmusic', 'Save cropped PDF')
	}
	if (props.activeMode === 'enhance') {
		return t('sheetmusic', 'Save enhanced PDF')
	}
	return t('sheetmusic', 'Split and create songs')
})

const saveButtonText = computed(() => {
	if (props.isSaving) {
		return props.activeMode === 'split' ? t('sheetmusic', 'Processing…') : t('sheetmusic', 'Saving…')
	}
	if (props.activeMode === 'rotate') {
		return (props.hasAnyDeletion || props.hasAnyCrop || props.hasAnyEnhance || props.hasAnyJoin)
			? t('sheetmusic', 'Save Changes')
			: t('sheetmusic', 'Save Rotations')
	}
	if (props.activeMode === 'crop') {
		return (props.hasAnyDeletion || props.hasAnyRotation || props.hasAnyEnhance || props.hasAnyJoin)
			? t('sheetmusic', 'Save Changes')
			: t('sheetmusic', 'Save Crops')
	}
	if (props.activeMode === 'enhance') {
		return t('sheetmusic', 'Save Enhanced PDF')
	}
	return t('sheetmusic', 'Split & Create Songs')
})
</script>

<template>
	<header :class="$style.header">
		<div :class="$style.headerLeft">
			<NcButton
				variant="secondary"
				:title="t('sheetmusic', 'Back to song')"
				:aria-label="t('sheetmusic', 'Back to song')"
				@click="emit('cancel')">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<div :class="$style.fileMeta">
				<span :class="$style.songTitle">{{ song.title }}</span>
				<span v-if="targetFile" :class="$style.fileName">
					({{ getFileName(targetFile.path) }} — {{ hasAnyDeletion ? `${remainingPagesCount} / ${totalPages}` : totalPages }} {{ totalPages === 1 ? t('sheetmusic', 'page') : t('sheetmusic', 'pages') }})
				</span>
			</div>
		</div>

		<!-- Mode Switcher Tabs -->
		<div :class="$style.modeTabs">
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'rotate' && $style.tabButtonActive]"
				@click="emit('update:activeMode', 'rotate')">
				<RotateRight :size="18" />
				<span>{{ t('sheetmusic', 'Rotate Pages') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'crop' && $style.tabButtonActive]"
				@click="emit('update:activeMode', 'crop')">
				<CropIcon :size="18" />
				<span>{{ t('sheetmusic', 'Crop Pages') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'enhance' && $style.tabButtonActive]"
				@click="emit('update:activeMode', 'enhance')">
				<Tune :size="18" />
				<span>{{ t('sheetmusic', 'Enhance Scans') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'split' && $style.tabButtonActive]"
				@click="emit('update:activeMode', 'split')">
				<CallSplit :size="18" />
				<span>{{ t('sheetmusic', 'Split PDF') }}</span>
			</button>
		</div>

		<div :class="$style.headerRight">
			<NcButton
				variant="primary"
				:disabled="saveButtonDisabled"
				:title="saveButtonTitle"
				@click="emit('save')">
				<template #icon>
					<CallSplit v-if="activeMode === 'split'" :size="20" />
					<ContentSaveOutlineIcon v-else :size="20" />
				</template>
				{{ saveButtonText }}
			</NcButton>
		</div>
	</header>
</template>

<style module>
.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	gap: 16px;
	flex-shrink: 0;
}

.headerLeft {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

.fileMeta {
	display: flex;
	align-items: baseline;
	gap: 8px;
	overflow: hidden;
	white-space: nowrap;
}

.songTitle {
	font-weight: bold;
	font-size: 16px;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
}

.fileName {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.modeTabs {
	display: flex;
	align-items: center;
	background-color: var(--color-background-hover);
	padding: 3px;
	border-radius: 8px;
	gap: 2px;
}

.tabButton {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: none;
	background: transparent;
	border-radius: 6px;
	cursor: pointer;
	font-size: 13px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	transition: all 0.15s ease-in-out;
}

.tabButton:hover {
	color: var(--color-main-text);
}

.tabButtonActive {
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 600;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.headerRight {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>

