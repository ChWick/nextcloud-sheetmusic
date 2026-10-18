<script setup lang="ts">
import type { Song } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import TrashCanOutlineIcon from 'vue-material-design-icons/TrashCanOutline.vue'
import { getFileName, openFileExternally } from '../../utils/file'

defineProps<{
	songA?: Song
	songB?: Song
	songA_title: string
	songB_title: string
	songA_composers: { id: number; label: string }[]
	songB_composers: { id: number; label: string }[]
	songA_books: { id: number; label: string }[]
	songB_books: { id: number; label: string }[]
	songA_collections: { id: number; label: string }[]
	songB_collections: { id: number; label: string }[]
	songA_artists: { id: number; label: string }[]
	songB_artists: { id: number; label: string }[]
	songA_genres: { id: number; label: string }[]
	songB_genres: { id: number; label: string }[]
	songA_keys: { id: number; label: string }[]
	songB_keys: { id: number; label: string }[]
	songA_keywords: string
	songB_keywords: string
	songA_detachedFiles: number[]
	songB_detachedFiles: number[]
	composerOptions: { id: number; label: string }[]
	bookOptions: { id: number; label: string }[]
	collectionOptions: { id: number; label: string }[]
	artistOptions: { id: number; label: string }[]
	genreOptions: { id: number; label: string }[]
	keyOptions: { id: number; label: string }[]
}>()

const emit = defineEmits<{
	'update:songA_title': [val: string]
	'update:songB_title': [val: string]
	'update:songA_composers': [val: { id: number; label: string }[]]
	'update:songB_composers': [val: { id: number; label: string }[]]
	'update:songA_books': [val: { id: number; label: string }[]]
	'update:songB_books': [val: { id: number; label: string }[]]
	'update:songA_collections': [val: { id: number; label: string }[]]
	'update:songB_collections': [val: { id: number; label: string }[]]
	'update:songA_artists': [val: { id: number; label: string }[]]
	'update:songB_artists': [val: { id: number; label: string }[]]
	'update:songA_genres': [val: { id: number; label: string }[]]
	'update:songB_genres': [val: { id: number; label: string }[]]
	'update:songA_keys': [val: { id: number; label: string }[]]
	'update:songB_keys': [val: { id: number; label: string }[]]
	'update:songA_keywords': [val: string]
	'update:songB_keywords': [val: string]
	copyFieldsBetweenSongs: [from: 'A' | 'B']
	toggleDetachFile: [target: 'A' | 'B', fileId: number]
}>()
</script>

<template>
	<div :class="$style.keepBothGrid">
		<!-- Column Headers -->
		<div :class="[$style.headerCol, $style.songLeft]">
			<strong>{{ t('sheetmusic', 'Song A') }}</strong>
			<span :class="$style.idBadge">ID {{ songA?.id }}</span>
			<NcButton type="tertiary" :title="t('sheetmusic', 'Copy Song A values to Song B')" @click="emit('copyFieldsBetweenSongs', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
				{{ t('sheetmusic', 'Copy all to B') }}
			</NcButton>
		</div>

		<div :class="[$style.headerCol, $style.songRight]">
			<NcButton type="tertiary" :title="t('sheetmusic', 'Copy Song B values to Song A')" @click="emit('copyFieldsBetweenSongs', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
				{{ t('sheetmusic', 'Copy all to A') }}
			</NcButton>
			<strong>{{ t('sheetmusic', 'Song B') }}</strong>
			<span :class="$style.idBadge">ID {{ songB?.id }}</span>
		</div>

		<!-- Row: Title -->
		<div :class="$style.cellEditable">
			<NcTextField
				:label="t('sheetmusic', 'Song A Title')"
				:value="songA_title"
				@update:value="emit('update:songA_title', $event)" />
		</div>
		<div :class="$style.cellEditable">
			<NcTextField
				:label="t('sheetmusic', 'Song B Title')"
				:value="songB_title"
				@update:value="emit('update:songB_title', $event)" />
		</div>

		<!-- Row: Composers -->
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Composers') }}</label>
			<NcSelect
				:value="songA_composers"
				:options="composerOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songA_composers', $event)" />
		</div>
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Composers') }}</label>
			<NcSelect
				:value="songB_composers"
				:options="composerOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songB_composers', $event)" />
		</div>

		<!-- Row: Books -->
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Books') }}</label>
			<NcSelect
				:value="songA_books"
				:options="bookOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songA_books', $event)" />
		</div>
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Books') }}</label>
			<NcSelect
				:value="songB_books"
				:options="bookOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songB_books', $event)" />
		</div>

		<!-- Row: Collections -->
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Collections') }}</label>
			<NcSelect
				:value="songA_collections"
				:options="collectionOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songA_collections', $event)" />
		</div>
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Collections') }}</label>
			<NcSelect
				:value="songB_collections"
				:options="collectionOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songB_collections', $event)" />
		</div>

		<!-- Row: Artists -->
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Artists') }}</label>
			<NcSelect
				:value="songA_artists"
				:options="artistOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songA_artists', $event)" />
		</div>
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Artists') }}</label>
			<NcSelect
				:value="songB_artists"
				:options="artistOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songB_artists', $event)" />
		</div>

		<!-- Row: Genres -->
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Genres') }}</label>
			<NcSelect
				:value="songA_genres"
				:options="genreOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songA_genres', $event)" />
		</div>
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Genres') }}</label>
			<NcSelect
				:value="songB_genres"
				:options="genreOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songB_genres', $event)" />
		</div>

		<!-- Row: Keys -->
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Keys') }}</label>
			<NcSelect
				:value="songA_keys"
				:options="keyOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songA_keys', $event)" />
		</div>
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Keys') }}</label>
			<NcSelect
				:value="songB_keys"
				:options="keyOptions"
				:multiple="true"
				label="label"
				track-by="id"
				@update:value="emit('update:songB_keys', $event)" />
		</div>

		<!-- Row: Keywords -->
		<div :class="$style.cellEditable">
			<NcTextField
				:label="t('sheetmusic', 'Song A Keywords')"
				:value="songA_keywords"
				@update:value="emit('update:songA_keywords', $event)" />
		</div>
		<div :class="$style.cellEditable">
			<NcTextField
				:label="t('sheetmusic', 'Song B Keywords')"
				:value="songB_keywords"
				@update:value="emit('update:songB_keywords', $event)" />
		</div>

		<!-- Row: Attached Files -->
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Files') }}</label>
			<ul v-if="songA?.files?.length" :class="$style.fileList">
				<li v-for="f in songA.files" :key="f.id" :class="[$style.fileItem, songA_detachedFiles.includes(f.id) && $style.detachedFile]">
					<FileDocumentIcon :size="16" />
					<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
					<NcButton type="tertiary" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
						<template #icon><OpenInNewIcon :size="14" /></template>
					</NcButton>
					<NcButton
						:variant="songA_detachedFiles.includes(f.id) ? 'secondary' : 'tertiary'"
						:title="songA_detachedFiles.includes(f.id) ? t('sheetmusic', 'Keep File') : t('sheetmusic', 'Detach File from Song A')"
						@click="emit('toggleDetachFile', 'A', f.id)">
						<template #icon><TrashCanOutlineIcon :size="14" /></template>
					</NcButton>
				</li>
			</ul>
			<span v-else :class="$style.val">{{ t('sheetmusic', '(None)') }}</span>
		</div>

		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Files') }}</label>
			<ul v-if="songB?.files?.length" :class="$style.fileList">
				<li v-for="f in songB.files" :key="f.id" :class="[$style.fileItem, songB_detachedFiles.includes(f.id) && $style.detachedFile]">
					<FileDocumentIcon :size="16" />
					<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
					<NcButton type="tertiary" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
						<template #icon><OpenInNewIcon :size="14" /></template>
					</NcButton>
					<NcButton
						:variant="songB_detachedFiles.includes(f.id) ? 'secondary' : 'tertiary'"
						:title="songB_detachedFiles.includes(f.id) ? t('sheetmusic', 'Keep File') : t('sheetmusic', 'Detach File from Song B')"
						@click="emit('toggleDetachFile', 'B', f.id)">
						<template #icon><TrashCanOutlineIcon :size="14" /></template>
					</NcButton>
				</li>
			</ul>
			<span v-else :class="$style.val">{{ t('sheetmusic', '(None)') }}</span>
		</div>
	</div>
</template>

<style module>
.keepBothGrid {
	display: grid;
	grid-template-columns: 1fr 1fr;
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

.idBadge {
	background: var(--color-background-dark);
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 0.8rem;
	font-weight: normal;
}

.cellEditable {
	display: flex;
	flex-direction: column;
	justify-content: center;
	min-height: 48px;
}

.fieldLabel {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: -6px;
}

.val {
	font-size: 0.9rem;
	color: var(--color-main-text);
	word-break: break-word;
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

.detachedFile {
	opacity: 0.5;
	text-decoration: line-through;
}

.fileName {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>

