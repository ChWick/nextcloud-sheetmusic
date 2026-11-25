<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import CogOutlineIcon from 'vue-material-design-icons/CogOutline.vue'
import EyeOffOutlineIcon from 'vue-material-design-icons/EyeOffOutline.vue'
import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import FullscreenIcon from 'vue-material-design-icons/Fullscreen.vue'
import FullscreenExitIcon from 'vue-material-design-icons/FullscreenExit.vue'
import StopIcon from 'vue-material-design-icons/Stop.vue'

withDefaults(defineProps<{
	visible: boolean
	setlistName?: string
	songTitle: string
	bookmarkName?: string | null
	itemIndex: number
	itemCount: number
	showItemCounter?: boolean
	showAnnotations: boolean
	isFullscreen: boolean
	showSettingsButton: boolean
	isSettingsOpen: boolean
	isSetlistOverride: boolean
}>(), {
	setlistName: '',
	showItemCounter: true,
})

const emit = defineEmits<{
	exit: []
	stop: []
	toggleAnnotations: []
	toggleSettings: []
	toggleFullscreen: []
	pauseAutoHide: []
	resumeAutoHide: []
}>()
</script>

<template>
	<header
		v-show="visible"
		:class="$style.topBar"
		@mouseenter="emit('pauseAutoHide')"
		@mouseleave="emit('resumeAutoHide')"
		@focusin="emit('pauseAutoHide')"
		@focusout="emit('resumeAutoHide')">
		<div :class="$style.topLeft">
			<NcButton
				variant="tertiary"
				:title="t('sheetmusic', 'Exit performance mode')"
				:aria-label="t('sheetmusic', 'Exit performance mode')"
				@click="emit('exit')">
				<template #icon><ArrowLeftIcon :size="20" /></template>
				{{ t('sheetmusic', 'Exit') }}
			</NcButton>

			<div :class="$style.titleBlock">
				<div :class="$style.songTitleRow">
					<span v-if="showItemCounter" :class="$style.itemCounter">{{ itemIndex + 1 }} / {{ itemCount }}</span>
					<strong :class="$style.songTitle">{{ songTitle }}</strong>
					<span v-if="bookmarkName" :class="$style.bookmarkBadge">
						{{ bookmarkName }}
					</span>
				</div>
				<span v-if="setlistName" :class="$style.setlistSubtitle">{{ setlistName }}</span>
			</div>
		</div>

		<div :class="$style.topRight">
			<NcButton
				variant="primary"
				:title="t('sheetmusic', 'Stop playing and return to editor')"
				:aria-label="t('sheetmusic', 'Stop playing')"
				data-test="stop-performance-button"
				@click="emit('stop')">
				<template #icon><StopIcon :size="20" /></template>
			</NcButton>
			<NcButton
				:variant="showAnnotations ? 'primary' : 'tertiary'"
				:title="t('sheetmusic', 'Toggle annotations')"
				:aria-label="t('sheetmusic', 'Toggle annotations')"
				@click="emit('toggleAnnotations')">
				<template #icon>
					<EyeOutlineIcon v-if="showAnnotations" :size="20" />
					<EyeOffOutlineIcon v-else :size="20" />
				</template>
			</NcButton>
			<NcButton
				v-if="showSettingsButton"
				:variant="isSettingsOpen || isSetlistOverride ? 'primary' : 'tertiary'"
				:title="t('sheetmusic', 'Viewer settings & defaults')"
				:aria-label="t('sheetmusic', 'Viewer settings & defaults')"
				@click="emit('toggleSettings')">
				<template #icon><CogOutlineIcon :size="20" /></template>
				<template v-if="isSetlistOverride">
					{{ t('sheetmusic', 'Custom') }}
				</template>
			</NcButton>
			<NcButton
				variant="tertiary"
				:title="isFullscreen ? t('sheetmusic', 'Exit fullscreen') : t('sheetmusic', 'Fullscreen')"
				:aria-label="isFullscreen ? t('sheetmusic', 'Exit fullscreen') : t('sheetmusic', 'Fullscreen')"
				@click="emit('toggleFullscreen')">
				<template #icon>
					<FullscreenExitIcon v-if="isFullscreen" :size="20" />
					<FullscreenIcon v-else :size="20" />
				</template>
			</NcButton>
		</div>
	</header>
</template>

<style module>
.topBar {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	z-index: 2;
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	box-shadow: 0 2px 12px rgba(0, 0, 0, 0.12);
}

.topLeft {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

.titleBlock {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.songTitleRow {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.itemCounter {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.songTitle {
	font-size: 1rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.bookmarkBadge {
	font-size: 0.78rem;
	padding: 2px 8px;
	border-radius: 999px;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
	white-space: nowrap;
}

.setlistSubtitle {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.topRight {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}
</style>
