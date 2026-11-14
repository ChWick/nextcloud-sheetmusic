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
import { formatScalarInputValue, parseScalarInputValue } from '../songs/song-view-columns'
import {
	ENTITY_COLUMNS,
	SONG_SCALAR_FIELDS,
	type FieldName,
	type SelectOption,
	type SongEditableDraft,
	type SongEntityProp,
	type SongScalarFieldDef,
	type SongScalarProp,
} from './song-merge-utils'

const props = defineProps<{
	songA?: Song
	songB?: Song
	draftA: SongEditableDraft
	draftB: SongEditableDraft
	detachedFilesA: number[]
	detachedFilesB: number[]
	optionsMap: Record<SongEntityProp, SelectOption[]>
}>()

const emit = defineEmits<{
	'update:title': [target: 'A' | 'B', val: string]
	'update:entity': [target: 'A' | 'B', prop: SongEntityProp, val: SelectOption[]]
	'update:scalar': [target: 'A' | 'B', key: SongScalarProp, val: string | number | null]
	copyFieldsBetweenSongs: [from: 'A' | 'B']
	copySingleFieldBetweenSongs: [field: FieldName, from: 'A' | 'B']
	toggleDetachFile: [target: 'A' | 'B', fileId: number]
}>()

function getScalarModelValue(draft: SongEditableDraft, field: SongScalarFieldDef): string {
	return formatScalarInputValue(field, draft.scalars[field.key])
}

function onScalarInput(target: 'A' | 'B', field: SongScalarFieldDef, raw: string | number) {
	emit('update:scalar', target, field.key, parseScalarInputValue(field, raw))
}
</script>

<template>
	<div :class="$style.keepBothGrid" data-test="resolve-both-grid">
		<!-- Column Headers -->
		<div :class="[$style.headerCol, $style.songLeft]">
			<strong>{{ t('sheetmusic', 'Song A') }}</strong>
			<span :class="$style.idBadge">ID {{ songA?.id }}</span>
			<NcButton
				variant="tertiary"
				type="button"
				:title="t('sheetmusic', 'Copy Song A values to Song B')"
				data-test="copy-all-to-b"
				@click="emit('copyFieldsBetweenSongs', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
				{{ t('sheetmusic', 'Copy all to B') }}
			</NcButton>
		</div>

		<div :class="$style.headerCenter" />

		<div :class="[$style.headerCol, $style.songRight]">
			<NcButton
				variant="tertiary"
				type="button"
				:title="t('sheetmusic', 'Copy Song B values to Song A')"
				data-test="copy-all-to-a"
				@click="emit('copyFieldsBetweenSongs', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
				{{ t('sheetmusic', 'Copy all to A') }}
			</NcButton>
			<strong>{{ t('sheetmusic', 'Song B') }}</strong>
			<span :class="$style.idBadge">ID {{ songB?.id }}</span>
		</div>

		<!-- Row: Title -->
		<div :class="$style.cellEditable" data-test="field-a-title">
			<NcTextField
				id="resolve-a-title-input"
				:label="t('sheetmusic', 'Song A Title')"
				:model-value="draftA.title"
				@update:model-value="emit('update:title', 'A', String($event ?? ''))" />
		</div>
		<div :class="$style.transferCell">
			<NcButton
				variant="tertiary"
				type="button"
				:title="t('sheetmusic', 'Copy Song A Title to Song B')"
				:aria-label="t('sheetmusic', 'Copy Song A Title to Song B')"
				data-test="copy-a-to-b-title"
				@click="emit('copySingleFieldBetweenSongs', 'title', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
			<NcButton
				variant="tertiary"
				type="button"
				:title="t('sheetmusic', 'Copy Song B Title to Song A')"
				:aria-label="t('sheetmusic', 'Copy Song B Title to Song A')"
				data-test="copy-b-to-a-title"
				@click="emit('copySingleFieldBetweenSongs', 'title', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellEditable" data-test="field-b-title">
			<NcTextField
				id="resolve-b-title-input"
				:label="t('sheetmusic', 'Song B Title')"
				:model-value="draftB.title"
				@update:model-value="emit('update:title', 'B', String($event ?? ''))" />
		</div>

		<!-- Rows: All Entity Types (from ENTITY_COLUMNS single source of truth) -->
		<template v-for="col in ENTITY_COLUMNS" :key="col.songProp">
			<div :class="$style.cellEditable" :data-test="`field-a-${col.songProp}`">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A {field}', { field: col.pluralLabel() }, { escape: false }) }}</label>
				<NcSelect
					:model-value="draftA.entities[col.songProp]"
					:options="optionsMap[col.songProp] ?? []"
					:multiple="true"
					:input-label="t('sheetmusic', 'Song A {field}', { field: col.pluralLabel() }, { escape: false })"
					label="label"
					track-by="id"
					@update:model-value="emit('update:entity', 'A', col.songProp, ($event ?? []) as SelectOption[])" />
			</div>
			<div :class="$style.transferCell">
				<NcButton
					variant="tertiary"
					type="button"
					:title="t('sheetmusic', 'Copy Song A {field} to Song B', { field: col.pluralLabel() }, { escape: false })"
					:aria-label="t('sheetmusic', 'Copy Song A {field} to Song B', { field: col.pluralLabel() }, { escape: false })"
					:data-test="`copy-a-to-b-${col.songProp}`"
					@click="emit('copySingleFieldBetweenSongs', col.songProp, 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
				<NcButton
					variant="tertiary"
					type="button"
					:title="t('sheetmusic', 'Copy Song B {field} to Song A', { field: col.pluralLabel() }, { escape: false })"
					:aria-label="t('sheetmusic', 'Copy Song B {field} to Song A', { field: col.pluralLabel() }, { escape: false })"
					:data-test="`copy-b-to-a-${col.songProp}`"
					@click="emit('copySingleFieldBetweenSongs', col.songProp, 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellEditable" :data-test="`field-b-${col.songProp}`">
				<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B {field}', { field: col.pluralLabel() }, { escape: false }) }}</label>
				<NcSelect
					:model-value="draftB.entities[col.songProp]"
					:options="optionsMap[col.songProp] ?? []"
					:multiple="true"
					:input-label="t('sheetmusic', 'Song B {field}', { field: col.pluralLabel() }, { escape: false })"
					label="label"
					track-by="id"
					@update:model-value="emit('update:entity', 'B', col.songProp, ($event ?? []) as SelectOption[])" />
			</div>
		</template>

		<!-- Rows: All Scalar Fields (from SONG_SCALAR_FIELDS single source of truth) -->
		<template v-for="field in SONG_SCALAR_FIELDS" :key="field.key">
			<div :class="$style.cellEditable" :data-test="`field-a-${field.key}`">
				<NcTextField
					:id="`resolve-a-${field.key}-input`"
					:type="field.type"
					:min="field.min"
					:max="field.max"
					:label="t('sheetmusic', 'Song A {field}', { field: field.inputLabel() }, { escape: false })"
					:placeholder="field.placeholder()"
					:model-value="getScalarModelValue(draftA, field)"
					@update:model-value="onScalarInput('A', field, $event)" />
			</div>
			<div :class="$style.transferCell">
				<NcButton
					variant="tertiary"
					type="button"
					:title="t('sheetmusic', 'Copy Song A {field} to Song B', { field: field.label() }, { escape: false })"
					:aria-label="t('sheetmusic', 'Copy Song A {field} to Song B', { field: field.label() }, { escape: false })"
					:data-test="`copy-a-to-b-${field.key}`"
					@click="emit('copySingleFieldBetweenSongs', field.key, 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
				<NcButton
					variant="tertiary"
					type="button"
					:title="t('sheetmusic', 'Copy Song B {field} to Song A', { field: field.label() }, { escape: false })"
					:aria-label="t('sheetmusic', 'Copy Song B {field} to Song A', { field: field.label() }, { escape: false })"
					:data-test="`copy-b-to-a-${field.key}`"
					@click="emit('copySingleFieldBetweenSongs', field.key, 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellEditable" :data-test="`field-b-${field.key}`">
				<NcTextField
					:id="`resolve-b-${field.key}-input`"
					:type="field.type"
					:min="field.min"
					:max="field.max"
					:label="t('sheetmusic', 'Song B {field}', { field: field.inputLabel() }, { escape: false })"
					:placeholder="field.placeholder()"
					:model-value="getScalarModelValue(draftB, field)"
					@update:model-value="onScalarInput('B', field, $event)" />
			</div>
		</template>

		<!-- Row: Attached Files -->
		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song A Files') }}</label>
			<ul v-if="songA?.files?.length" :class="$style.fileList">
				<li v-for="f in songA.files" :key="f.id" :class="[$style.fileItem, detachedFilesA.includes(f.id) && $style.detachedFile]">
					<FileDocumentIcon :size="16" />
					<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
					<NcButton variant="tertiary" type="button" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
						<template #icon><OpenInNewIcon :size="14" /></template>
					</NcButton>
					<NcButton
						type="button"
						:variant="detachedFilesA.includes(f.id) ? 'secondary' : 'tertiary'"
						:title="detachedFilesA.includes(f.id) ? t('sheetmusic', 'Keep File') : t('sheetmusic', 'Detach File from Song A')"
						:data-test="`detach-file-a-${f.id}`"
						@click="emit('toggleDetachFile', 'A', f.id)">
						<template #icon><TrashCanOutlineIcon :size="14" /></template>
					</NcButton>
				</li>
			</ul>
			<span v-else :class="$style.val">{{ t('sheetmusic', '(None)') }}</span>
		</div>

		<div :class="$style.transferCell" />

		<div :class="$style.cellEditable">
			<label :class="$style.fieldLabel">{{ t('sheetmusic', 'Song B Files') }}</label>
			<ul v-if="songB?.files?.length" :class="$style.fileList">
				<li v-for="f in songB.files" :key="f.id" :class="[$style.fileItem, detachedFilesB.includes(f.id) && $style.detachedFile]">
					<FileDocumentIcon :size="16" />
					<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
					<NcButton variant="tertiary" type="button" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
						<template #icon><OpenInNewIcon :size="14" /></template>
					</NcButton>
					<NcButton
						type="button"
						:variant="detachedFilesB.includes(f.id) ? 'secondary' : 'tertiary'"
						:title="detachedFilesB.includes(f.id) ? t('sheetmusic', 'Keep File') : t('sheetmusic', 'Detach File from Song B')"
						:data-test="`detach-file-b-${f.id}`"
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
	grid-template-columns: 1fr auto 1fr;
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

.headerCenter {
	min-width: 64px;
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

.transferCell {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 4px;
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
