<script setup lang="ts">
import type { SongEntityLists } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, reactive, watch } from 'vue'
import {
	ENTITY_COLUMNS,
	SONG_SCALAR_FIELDS,
	type SongEntityProp,
	type SongScalarFieldDef,
	type SongScalarProp,
} from '../songs/song-view-columns'

export type BatchAction = 'add' | 'replace' | 'remove'

export interface CategoryBatchUpdate {
	action: BatchAction
	ids: number[]
}

export type BatchScalarUpdates = Partial<Record<SongScalarProp, string | number | null>>

export type BatchUpdatePayload = Partial<Record<SongEntityProp, CategoryBatchUpdate>> & {
	scalars?: BatchScalarUpdates
}

type CategoryKey = SongEntityProp

interface CategoryState {
	enabled: boolean
	action: BatchAction
	selected: Array<{ id: number }>
}

interface ScalarFieldState {
	enabled: boolean
	value: string | number | null
}

const CATEGORY_KEYS: CategoryKey[] = ENTITY_COLUMNS.map((col) => col.songProp)
const SCALAR_KEYS: SongScalarProp[] = SONG_SCALAR_FIELDS.map((field) => field.key)

const props = defineProps<SongEntityLists & {
	open: boolean
	selectedCount: number
}>()

const emit = defineEmits<{
	'update:open': [open: boolean]
	'apply': [payload: BatchUpdatePayload]
}>()

const state = reactive<Record<CategoryKey, CategoryState>>(
	Object.fromEntries(
		CATEGORY_KEYS.map((key) => [key, { enabled: false, action: 'add' as BatchAction, selected: [] }]),
	) as Record<CategoryKey, CategoryState>,
)

const scalarState = reactive<Record<SongScalarProp, ScalarFieldState>>(
	Object.fromEntries(
		SONG_SCALAR_FIELDS.map((field) => [
			field.key,
			{ enabled: false, value: field.type === 'number' ? null : '' },
		]),
	) as Record<SongScalarProp, ScalarFieldState>,
)

function resetForm() {
	for (const key of CATEGORY_KEYS) {
		state[key].enabled = false
		state[key].action = 'add'
		state[key].selected = []
	}
	for (const field of SONG_SCALAR_FIELDS) {
		scalarState[field.key].enabled = false
		scalarState[field.key].value = field.type === 'number' ? null : ''
	}
}

watch(() => props.open, (isOpen) => {
	if (isOpen) resetForm()
})

const categories = computed(() =>
	ENTITY_COLUMNS.map((col) => ({
		key: col.songProp,
		label: col.pluralLabel(),
		placeholder: col.selectPlaceholder(),
		optionLabel: col.nameKey,
		radioName: `${col.col.replace(/[A-Z]/g, (m) => `-${m.toLowerCase()}`)}-action`,
		options: (props[col.songProp] ?? []) as Array<{ id: number }>,
	})),
)

const isAnythingEnabled = computed(() =>
	CATEGORY_KEYS.some((key) => state[key].enabled)
	|| SCALAR_KEYS.some((key) => scalarState[key].enabled),
)

function getScalarModelValue(field: SongScalarFieldDef): string {
	const val = scalarState[field.key].value
	if (field.type === 'number') {
		return val !== null && val !== undefined && Number(val) > 0 ? String(val) : ''
	}
	return String(val ?? '')
}

function onScalarInput(field: SongScalarFieldDef, raw: string | number) {
	if (field.type === 'number') {
		const num = Number(raw)
		scalarState[field.key].value = Number.isFinite(num) && num > 0 ? num : null
	} else {
		scalarState[field.key].value = String(raw ?? '')
	}
}

function handleApply() {
	if (!isAnythingEnabled.value) return

	const payload: BatchUpdatePayload = {}
	for (const key of CATEGORY_KEYS) {
		if (state[key].enabled) {
			payload[key] = {
				action: state[key].action,
				ids: state[key].selected.map((item) => item.id),
			}
		}
	}

	const scalars: BatchScalarUpdates = {}
	let hasScalars = false
	for (const field of SONG_SCALAR_FIELDS) {
		if (scalarState[field.key].enabled) {
			scalars[field.key] = scalarState[field.key].value
			hasScalars = true
		}
	}
	if (hasScalars) {
		payload.scalars = scalars
	}

	emit('apply', payload)
}

function handleClose() {
	emit('update:open', false)
}

const actionOptions: { value: BatchAction; label: string }[] = [
	{ value: 'add', label: t('sheetmusic', 'Add to existing') },
	{ value: 'replace', label: t('sheetmusic', 'Replace all existing') },
	{ value: 'remove', label: t('sheetmusic', 'Remove from selected songs') },
]
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('sheetmusic', 'Batch edit {count} songs', { count: selectedCount })"
		size="normal"
		@close="handleClose">
		<div :class="$style.dialogContent">
			<p :class="$style.intro">
				{{ t('sheetmusic', 'Select which metadata fields to update across all {count} selected songs.', { count: selectedCount }) }}
			</p>

			<div v-for="cat in categories" :key="cat.key" :class="$style.fieldGroup">
				<div :class="$style.fieldHeader">
					<NcCheckboxRadioSwitch
						v-model="state[cat.key].enabled"
						type="checkbox">
						{{ cat.label }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="state[cat.key].enabled" :class="$style.fieldBody">
					<div :class="$style.actionSelector">
						<label v-for="opt in actionOptions" :key="opt.value" :class="$style.radioLabel">
							<input
								v-model="state[cat.key].action"
								type="radio"
								:name="cat.radioName"
								:value="opt.value">
							{{ opt.label }}
						</label>
					</div>
					<NcSelect
						v-model="state[cat.key].selected"
						:options="cat.options"
						:multiple="true"
						:close-on-select="false"
						:input-label="cat.label"
						:placeholder="cat.placeholder"
						:label="cat.optionLabel"
						track-by="id" />
				</div>
			</div>

			<div v-for="field in SONG_SCALAR_FIELDS" :key="field.key" :class="$style.fieldGroup">
				<div :class="$style.fieldHeader">
					<NcCheckboxRadioSwitch
						v-model="scalarState[field.key].enabled"
						type="checkbox"
						:data-test="`batch-scalar-checkbox-${field.key}`">
						{{ field.label() }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="scalarState[field.key].enabled" :class="$style.fieldBody">
					<NcTextField
						:id="`batch-${field.key}-input`"
						:type="field.type"
						:min="field.min"
						:max="field.max"
						:label="field.inputLabel()"
						:placeholder="field.placeholder()"
						:value="getScalarModelValue(field)"
						:model-value="getScalarModelValue(field)"
						@update:value="onScalarInput(field, $event)"
						@update:model-value="onScalarInput(field, $event)" />
				</div>
			</div>

			<div :class="$style.dialogActions">
				<NcButton @click="handleClose">
					{{ t('sheetmusic', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!isAnythingEnabled"
					@click="handleApply">
					{{ t('sheetmusic', 'Apply to {count} songs', { count: selectedCount }) }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<style module>
.dialogContent {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 0 8px;
}

.intro {
	margin: 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.fieldGroup {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 12px;
	background-color: var(--color-main-background);
	position: relative;
}

.fieldGroup:focus-within {
	z-index: 10;
}

.fieldHeader {
	font-weight: 600;
}

.fieldBody {
	margin-top: 10px;
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding-left: 24px;
}

.actionSelector {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	font-size: 0.85rem;
}

.radioLabel {
	display: flex;
	align-items: center;
	gap: 4px;
	cursor: pointer;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 12px;
}
</style>
