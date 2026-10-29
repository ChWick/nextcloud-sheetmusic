<script setup lang="ts">
import type { Song } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { getFileName, openFileExternally } from '../../utils/file'
import { formatEntityList, type FieldName } from './song-merge-utils'

defineProps<{
	songA?: Song
	songB?: Song
	keepId: number
	mergedTitle: string
	mergedComposers: { id: number; label: string }[]
	mergedBooks: { id: number; label: string }[]
	mergedCollections: { id: number; label: string }[]
	mergedArtists: { id: number; label: string }[]
	mergedGenres: { id: number; label: string }[]
	mergedKeys: { id: number; label: string }[]
	mergedKeywords: string
	composerOptions: { id: number; label: string }[]
	bookOptions: { id: number; label: string }[]
	collectionOptions: { id: number; label: string }[]
	artistOptions: { id: number; label: string }[]
	genreOptions: { id: number; label: string }[]
	keyOptions: { id: number; label: string }[]
}>()

const emit = defineEmits<{
	'update:keepId': [id: number]
	'update:mergedTitle': [val: string]
	'update:mergedComposers': [val: { id: number; label: string }[]]
	'update:mergedBooks': [val: { id: number; label: string }[]]
	'update:mergedCollections': [val: { id: number; label: string }[]]
	'update:mergedArtists': [val: { id: number; label: string }[]]
	'update:mergedGenres': [val: { id: number; label: string }[]]
	'update:mergedKeys': [val: { id: number; label: string }[]]
	'update:mergedKeywords': [val: string]
	copyField: [field: FieldName, from: 'A' | 'B']
}>()
</script>

<template>
	<div :class="$style.comparisonGrid">
		<!-- Column Headers -->
		<div :class="[$style.headerCol, $style.songLeft]">
			<strong>{{ t('sheetmusic', 'Song A') }}</strong>
			<span :class="$style.idBadge">ID {{ songA?.id }}</span>
			<NcCheckboxRadioSwitch
				:checked="keepId === (songA?.id ?? 0)"
				type="radio"
				name="keepId"
				:value="String(songA?.id)"
				@update:checked="emit('update:keepId', songA?.id ?? 0)">
				{{ t('sheetmusic', 'Keep ID') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div :class="[$style.headerCol, $style.songMerged]">
			<MergeIcon :size="18" />
			<strong>{{ t('sheetmusic', 'Merged Result') }}</strong>
			<span :class="$style.idBadge">Keeping ID {{ keepId }}</span>
		</div>

		<div :class="[$style.headerCol, $style.songRight]">
			<strong>{{ t('sheetmusic', 'Song B') }}</strong>
			<span :class="$style.idBadge">ID {{ songB?.id }}</span>
			<NcCheckboxRadioSwitch
				:checked="keepId === (songB?.id ?? 0)"
				type="radio"
				name="keepId"
				:value="String(songB?.id)"
				@update:checked="emit('update:keepId', songB?.id ?? 0)">
				{{ t('sheetmusic', 'Keep ID') }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Row: Title -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Title') }}</span>
				<span :class="$style.val">{{ songA?.title || '-' }}</span>
			</div>
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Title')" @click="emit('copyField', 'title', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellMerged">
			<NcTextField
				:label="t('sheetmusic', 'Title')"
				:value="mergedTitle"
				@update:value="emit('update:mergedTitle', $event)" />
		</div>
		<div :class="$style.cellB">
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Title')" @click="emit('copyField', 'title', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Title') }}</span>
				<span :class="$style.val">{{ songB?.title || '-' }}</span>
			</div>
		</div>

		<!-- Row: Composers -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Composers') }}</span>
				<span :class="$style.val">{{ formatEntityList(songA?.composers) }}</span>
			</div>
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Composers')" @click="emit('copyField', 'composers', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellMerged">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Composers') }}</label>
			<NcSelect
				:value="mergedComposers"
				:options="composerOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:mergedComposers', $event)" />
		</div>
		<div :class="$style.cellB">
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Composers')" @click="emit('copyField', 'composers', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Composers') }}</span>
				<span :class="$style.val">{{ formatEntityList(songB?.composers) }}</span>
			</div>
		</div>

		<!-- Row: Books -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Books') }}</span>
				<span :class="$style.val">{{ formatEntityList(songA?.books) }}</span>
			</div>
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Books')" @click="emit('copyField', 'books', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellMerged">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Books') }}</label>
			<NcSelect
				:value="mergedBooks"
				:options="bookOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:mergedBooks', $event)" />
		</div>
		<div :class="$style.cellB">
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Books')" @click="emit('copyField', 'books', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Books') }}</span>
				<span :class="$style.val">{{ formatEntityList(songB?.books) }}</span>
			</div>
		</div>

		<!-- Row: Collections -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Collections') }}</span>
				<span :class="$style.val">{{ formatEntityList(songA?.collections) }}</span>
			</div>
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Collections')" @click="emit('copyField', 'collections', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellMerged">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Collections') }}</label>
			<NcSelect
				:value="mergedCollections"
				:options="collectionOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:mergedCollections', $event)" />
		</div>
		<div :class="$style.cellB">
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Collections')" @click="emit('copyField', 'collections', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Collections') }}</span>
				<span :class="$style.val">{{ formatEntityList(songB?.collections) }}</span>
			</div>
		</div>

		<!-- Row: Artists -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Artists') }}</span>
				<span :class="$style.val">{{ formatEntityList(songA?.artists) }}</span>
			</div>
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Artists')" @click="emit('copyField', 'artists', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellMerged">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Artists') }}</label>
			<NcSelect
				:value="mergedArtists"
				:options="artistOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:mergedArtists', $event)" />
		</div>
		<div :class="$style.cellB">
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Artists')" @click="emit('copyField', 'artists', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Artists') }}</span>
				<span :class="$style.val">{{ formatEntityList(songB?.artists) }}</span>
			</div>
		</div>

		<!-- Row: Genres -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Genres') }}</span>
				<span :class="$style.val">{{ formatEntityList(songA?.genres) }}</span>
			</div>
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Genres')" @click="emit('copyField', 'genres', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellMerged">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Genres') }}</label>
			<NcSelect
				:value="mergedGenres"
				:options="genreOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:mergedGenres', $event)" />
		</div>
		<div :class="$style.cellB">
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Genres')" @click="emit('copyField', 'genres', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Genres') }}</span>
				<span :class="$style.val">{{ formatEntityList(songB?.genres) }}</span>
			</div>
		</div>

		<!-- Row: Keys -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Keys') }}</span>
				<span :class="$style.val">{{ formatEntityList(songA?.keys) }}</span>
			</div>
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Keys')" @click="emit('copyField', 'keys', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellMerged">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Keys') }}</label>
			<NcSelect
				:value="mergedKeys"
				:options="keyOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:mergedKeys', $event)" />
		</div>
		<div :class="$style.cellB">
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Keys')" @click="emit('copyField', 'keys', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Keys') }}</span>
				<span :class="$style.val">{{ formatEntityList(songB?.keys) }}</span>
			</div>
		</div>

		<!-- Row: Keywords -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Keywords') }}</span>
				<span :class="$style.val">{{ songA?.keywords || '-' }}</span>
			</div>
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song A Keywords')" @click="emit('copyField', 'keywords', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellMerged">
			<NcTextField
				:label="t('sheetmusic', 'Keywords')"
				:value="mergedKeywords"
				@update:value="emit('update:mergedKeywords', $event)" />
		</div>
		<div :class="$style.cellB">
			<NcButton type="tertiary" :title="t('sheetmusic', 'Use Song B Keywords')" @click="emit('copyField', 'keywords', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Keywords') }}</span>
				<span :class="$style.val">{{ songB?.keywords || '-' }}</span>
			</div>
		</div>

		<!-- Row: Attached Files -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Attached Files') }}</span>
				<ul v-if="songA?.files?.length" :class="$style.fileList">
					<li v-for="f in songA.files" :key="f.id" :class="$style.fileItem">
						<FileDocumentIcon :size="16" />
						<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
						<NcButton type="tertiary" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
							<template #icon><OpenInNewIcon :size="14" /></template>
						</NcButton>
					</li>
				</ul>
				<span v-else :class="$style.val">{{ t('sheetmusic', '(None)') }}</span>
			</div>
		</div>
		<div :class="$style.cellMerged">
			<div :class="$style.filesNotice">
				<strong>{{ t('sheetmusic', 'Attached Files Policy') }}</strong>
				<p>{{ t('sheetmusic', 'All files from both songs will be attached to Song ID {id}. Duplicate files are merged automatically.', { id: String(keepId) }) }}</p>
			</div>
		</div>
		<div :class="$style.cellB">
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Attached Files') }}</span>
				<ul v-if="songB?.files?.length" :class="$style.fileList">
					<li v-for="f in songB.files" :key="f.id" :class="$style.fileItem">
						<FileDocumentIcon :size="16" />
						<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
						<NcButton type="tertiary" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
							<template #icon><OpenInNewIcon :size="14" /></template>
						</NcButton>
					</li>
				</ul>
				<span v-else :class="$style.val">{{ t('sheetmusic', '(None)') }}</span>
			</div>
		</div>
	</div>
</template>

<style module>
.comparisonGrid {
	display: grid;
	grid-template-columns: 1fr 1.3fr 1fr;
	gap: 12px 16px;
	align-items: center;
}

.headerCol {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	font-weight: 600;
	padding: 12px;
	border-radius: var(--border-radius-large, 8px);
	border: 1px solid var(--color-border);
}

.songLeft {
	background: var(--color-background-hover);
	border-left: 4px solid var(--color-primary-element);
}

.songRight {
	background: var(--color-background-hover);
	border-left: 4px solid var(--color-warning);
}

.songMerged {
	background: var(--color-main-background);
	border-left: 4px solid var(--color-success);
}

.idBadge {
	background: var(--color-background-dark);
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.8rem;
	font-weight: normal;
}

.cellA,
.cellB {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 10px 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius, 4px);
	border: 1px solid var(--color-border);
	min-height: 48px;
}

.cellMerged {
	display: flex;
	flex-direction: column;
	justify-content: center;
	min-height: 48px;
}

.fieldInfo {
	display: flex;
	flex-direction: column;
	gap: 2px;
	overflow: hidden;
}

.alignRight {
	align-items: flex-end;
	text-align: right;
}

.label {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.val {
	font-size: 0.9rem;
	color: var(--color-main-text);
	word-break: break-word;
}

.fieldLabel {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: -6px;
}

.fileList {
	margin: 4px 0 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.fileItem {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.8rem;
	padding: 2px 6px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.fileName {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.filesNotice {
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	font-size: 0.85rem;
}

.filesNotice p {
	margin: 2px 0 0;
	color: var(--color-text-maxcontrast);
}
</style>

