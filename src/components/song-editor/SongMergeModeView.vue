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
import {
	ENTITY_COLUMNS,
	SONG_SCALAR_FIELDS,
	formatEntityList,
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
	keepId: number
	draft: SongEditableDraft
	optionsMap: Record<SongEntityProp, SelectOption[]>
}>()

const emit = defineEmits<{
	'update:keepId': [id: number]
	'update:title': [val: string]
	'update:entity': [prop: SongEntityProp, val: SelectOption[]]
	'update:scalar': [key: SongScalarProp, val: string | number | null]
	copyField: [field: FieldName, from: 'A' | 'B']
	copyAll: [from: 'A' | 'B']
}>()

function getSongEntities(song: Song | undefined, prop: SongEntityProp): { id?: number; name?: string; title?: string; year?: number }[] | undefined {
	return (song as Record<string, unknown> | undefined)?.[prop] as { id?: number; name?: string; title?: string; year?: number }[] | undefined
}

function getScalarModelValue(field: SongScalarFieldDef): string {
	const val = props.draft.scalars[field.key]
	if (field.type === 'number') {
		return val !== null && val !== undefined && Number(val) > 0 ? String(val) : ''
	}
	return String(val ?? '')
}

function onScalarInput(field: SongScalarFieldDef, raw: string | number) {
	if (field.type === 'number') {
		const num = Number(raw)
		emit('update:scalar', field.key, Number.isFinite(num) && num > 0 ? num : null)
	} else {
		emit('update:scalar', field.key, String(raw ?? ''))
	}
}
</script>

<template>
	<div :class="$style.comparisonGrid" data-test="merge-mode-grid">
		<!-- Column Headers -->
		<div :class="[$style.headerCol, $style.songLeft]">
			<strong>{{ t('sheetmusic', 'Song A') }}</strong>
			<span :class="$style.idBadge">ID {{ songA?.id }}</span>
			<NcCheckboxRadioSwitch
				:model-value="String(keepId)"
				type="radio"
				name="keepId"
				:value="String(songA?.id ?? 0)"
				data-test="keep-id-a"
				@update:model-value="emit('update:keepId', Number($event))">
				{{ t('sheetmusic', 'Keep ID') }}
			</NcCheckboxRadioSwitch>
			<NcButton
				variant="tertiary"
				type="button"
				:title="t('sheetmusic', 'Use all values from Song A')"
				data-test="copy-all-from-a"
				@click="emit('copyAll', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
				{{ t('sheetmusic', 'Use all A') }}
			</NcButton>
		</div>

		<div :class="[$style.headerCol, $style.songMerged]">
			<MergeIcon :size="18" />
			<strong>{{ t('sheetmusic', 'Merged Result') }}</strong>
			<span :class="$style.idBadge">Keeping ID {{ keepId }}</span>
		</div>

		<div :class="[$style.headerCol, $style.songRight]">
			<NcButton
				variant="tertiary"
				type="button"
				:title="t('sheetmusic', 'Use all values from Song B')"
				data-test="copy-all-from-b"
				@click="emit('copyAll', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
				{{ t('sheetmusic', 'Use all B') }}
			</NcButton>
			<strong>{{ t('sheetmusic', 'Song B') }}</strong>
			<span :class="$style.idBadge">ID {{ songB?.id }}</span>
			<NcCheckboxRadioSwitch
				:model-value="String(keepId)"
				type="radio"
				name="keepId"
				:value="String(songB?.id ?? 0)"
				data-test="keep-id-b"
				@update:model-value="emit('update:keepId', Number($event))">
				{{ t('sheetmusic', 'Keep ID') }}
			</NcCheckboxRadioSwitch>
		</div>

		<!-- Row: Title -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Title') }}</span>
				<span :class="$style.val" data-test="val-a-title">{{ songA?.title || '-' }}</span>
			</div>
			<NcButton
				variant="tertiary"
				type="button"
				:title="t('sheetmusic', 'Use Song A Title')"
				:aria-label="t('sheetmusic', 'Use Song A Title')"
				data-test="copy-from-a-title"
				@click="emit('copyField', 'title', 'A')">
				<template #icon><ArrowRightIcon :size="16" /></template>
			</NcButton>
		</div>
		<div :class="$style.cellMerged" data-test="merged-field-title">
			<NcTextField
				id="merge-title-input"
				:label="t('sheetmusic', 'Title')"
				:model-value="draft.title"
				@update:model-value="emit('update:title', String($event ?? ''))" />
		</div>
		<div :class="$style.cellB">
			<NcButton
				variant="tertiary"
				type="button"
				:title="t('sheetmusic', 'Use Song B Title')"
				:aria-label="t('sheetmusic', 'Use Song B Title')"
				data-test="copy-from-b-title"
				@click="emit('copyField', 'title', 'B')">
				<template #icon><ArrowLeftIcon :size="16" /></template>
			</NcButton>
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Title') }}</span>
				<span :class="$style.val" data-test="val-b-title">{{ songB?.title || '-' }}</span>
			</div>
		</div>

		<!-- Rows: All Entity Types (from ENTITY_COLUMNS single source of truth) -->
		<template v-for="col in ENTITY_COLUMNS" :key="col.songProp">
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ col.pluralLabel() }}</span>
					<span :class="$style.val" :data-test="`val-a-${col.songProp}`">{{ formatEntityList(getSongEntities(songA, col.songProp)) }}</span>
				</div>
				<NcButton
					variant="tertiary"
					type="button"
					:title="t('sheetmusic', 'Use Song A {field}', { field: col.pluralLabel() }, { escape: false })"
					:aria-label="t('sheetmusic', 'Use Song A {field}', { field: col.pluralLabel() }, { escape: false })"
					:data-test="`copy-from-a-${col.songProp}`"
					@click="emit('copyField', col.songProp, 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged" :data-test="`merged-field-${col.songProp}`">
				<label :class="$style.fieldLabel">{{ col.pluralLabel() }}</label>
				<NcSelect
					:model-value="draft.entities[col.songProp]"
					:options="optionsMap[col.songProp] ?? []"
					:multiple="true"
					:input-label="col.pluralLabel()"
					label="label"
					track-by="id"
					@update:model-value="emit('update:entity', col.songProp, ($event ?? []) as SelectOption[])" />
			</div>
			<div :class="$style.cellB">
				<NcButton
					variant="tertiary"
					type="button"
					:title="t('sheetmusic', 'Use Song B {field}', { field: col.pluralLabel() }, { escape: false })"
					:aria-label="t('sheetmusic', 'Use Song B {field}', { field: col.pluralLabel() }, { escape: false })"
					:data-test="`copy-from-b-${col.songProp}`"
					@click="emit('copyField', col.songProp, 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ col.pluralLabel() }}</span>
					<span :class="$style.val" :data-test="`val-b-${col.songProp}`">{{ formatEntityList(getSongEntities(songB, col.songProp)) }}</span>
				</div>
			</div>
		</template>

		<!-- Rows: All Scalar Fields (from SONG_SCALAR_FIELDS single source of truth) -->
		<template v-for="field in SONG_SCALAR_FIELDS" :key="field.key">
			<div :class="$style.cellA">
				<div :class="$style.fieldInfo">
					<span :class="$style.label">{{ field.label() }}</span>
					<span :class="$style.val" :data-test="`val-a-${field.key}`">{{ field.formatDisplay(songA) }}</span>
				</div>
				<NcButton
					variant="tertiary"
					type="button"
					:title="t('sheetmusic', 'Use Song A {field}', { field: field.label() }, { escape: false })"
					:aria-label="t('sheetmusic', 'Use Song A {field}', { field: field.label() }, { escape: false })"
					:data-test="`copy-from-a-${field.key}`"
					@click="emit('copyField', field.key, 'A')">
					<template #icon><ArrowRightIcon :size="16" /></template>
				</NcButton>
			</div>
			<div :class="$style.cellMerged" :data-test="`merged-field-${field.key}`">
				<NcTextField
					:id="`merge-${field.key}-input`"
					:type="field.type"
					:min="field.min"
					:max="field.max"
					:label="field.inputLabel()"
					:placeholder="field.placeholder()"
					:model-value="getScalarModelValue(field)"
					@update:model-value="onScalarInput(field, $event)" />
			</div>
			<div :class="$style.cellB">
				<NcButton
					variant="tertiary"
					type="button"
					:title="t('sheetmusic', 'Use Song B {field}', { field: field.label() }, { escape: false })"
					:aria-label="t('sheetmusic', 'Use Song B {field}', { field: field.label() }, { escape: false })"
					:data-test="`copy-from-b-${field.key}`"
					@click="emit('copyField', field.key, 'B')">
					<template #icon><ArrowLeftIcon :size="16" /></template>
				</NcButton>
				<div :class="[$style.fieldInfo, $style.alignRight]">
					<span :class="$style.label">{{ field.label() }}</span>
					<span :class="$style.val" :data-test="`val-b-${field.key}`">{{ field.formatDisplay(songB) }}</span>
				</div>
			</div>
		</template>

		<!-- Row: Attached Files -->
		<div :class="$style.cellA">
			<div :class="$style.fieldInfo">
				<span :class="$style.label">{{ t('sheetmusic', 'Attached Files') }}</span>
				<ul v-if="songA?.files?.length" :class="$style.fileList">
					<li v-for="f in songA.files" :key="f.id" :class="$style.fileItem">
						<FileDocumentIcon :size="16" />
						<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
						<NcButton variant="tertiary" type="button" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
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
				<p>{{ t('sheetmusic', 'All files from both songs will be attached to Song ID {id}. Duplicate files are merged automatically.', { id: String(keepId) }, { escape: false }) }}</p>
			</div>
		</div>
		<div :class="$style.cellB">
			<div :class="[$style.fieldInfo, $style.alignRight]">
				<span :class="$style.label">{{ t('sheetmusic', 'Attached Files') }}</span>
				<ul v-if="songB?.files?.length" :class="$style.fileList">
					<li v-for="f in songB.files" :key="f.id" :class="$style.fileItem">
						<FileDocumentIcon :size="16" />
						<span :class="$style.fileName">{{ getFileName(f.path) }}</span>
						<NcButton variant="tertiary" type="button" :title="t('sheetmusic', 'Open File')" @click="openFileExternally(f.path)">
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
