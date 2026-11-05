<script setup lang="ts">
import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, Genre, Instrument, Key, Signature, SourceType, Year } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { computed, reactive, watch } from 'vue'

export type BatchAction = 'add' | 'replace' | 'remove'

export interface CategoryBatchUpdate {
	action: BatchAction
	ids: number[]
}

export interface BatchUpdatePayload {
	composers?: CategoryBatchUpdate
	books?: CategoryBatchUpdate
	collections?: CategoryBatchUpdate
	artists?: CategoryBatchUpdate
	genres?: CategoryBatchUpdate
	keys?: CategoryBatchUpdate
	customGroups?: CategoryBatchUpdate
	years?: CategoryBatchUpdate
	signatures?: CategoryBatchUpdate
	sourceTypes?: CategoryBatchUpdate
	instruments?: CategoryBatchUpdate
	arrangers?: CategoryBatchUpdate
}

type CategoryKey = keyof BatchUpdatePayload

interface CategoryState {
	enabled: boolean
	action: BatchAction
	selected: Array<{ id: number }>
}

const CATEGORY_KEYS: CategoryKey[] = [
	'composers',
	'books',
	'collections',
	'artists',
	'genres',
	'keys',
	'customGroups',
	'years',
	'signatures',
	'sourceTypes',
	'instruments',
	'arrangers',
]

const props = defineProps<{
	open: boolean
	selectedCount: number
	composers: Composer[]
	books: Book[]
	collections: Collection[]
	artists: Artist[]
	genres?: Genre[]
	keys?: Key[]
	customGroups?: CustomGroup[]
	years?: Year[]
	signatures?: Signature[]
	sourceTypes?: SourceType[]
	instruments?: Instrument[]
	arrangers?: Arranger[]
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

function resetForm() {
	for (const key of CATEGORY_KEYS) {
		state[key].enabled = false
		state[key].action = 'add'
		state[key].selected = []
	}
}

watch(() => props.open, (isOpen) => {
	if (isOpen) resetForm()
})

const categories = computed(() => [
	{ key: 'composers' as const, label: t('sheetmusic', 'Composers'), placeholder: t('sheetmusic', 'Select composers…'), optionLabel: 'name', radioName: 'composer-action', options: props.composers },
	{ key: 'books' as const, label: t('sheetmusic', 'Books'), placeholder: t('sheetmusic', 'Select books…'), optionLabel: 'title', radioName: 'book-action', options: props.books },
	{ key: 'collections' as const, label: t('sheetmusic', 'Collections'), placeholder: t('sheetmusic', 'Select collections…'), optionLabel: 'name', radioName: 'collection-action', options: props.collections },
	{ key: 'artists' as const, label: t('sheetmusic', 'Artists'), placeholder: t('sheetmusic', 'Select artists…'), optionLabel: 'name', radioName: 'artist-action', options: props.artists },
	{ key: 'genres' as const, label: t('sheetmusic', 'Genres'), placeholder: t('sheetmusic', 'Select genres…'), optionLabel: 'name', radioName: 'genre-action', options: props.genres || [] },
	{ key: 'keys' as const, label: t('sheetmusic', 'Keys'), placeholder: t('sheetmusic', 'Select keys…'), optionLabel: 'name', radioName: 'key-action', options: props.keys || [] },
	{ key: 'customGroups' as const, label: t('sheetmusic', 'Custom Groups'), placeholder: t('sheetmusic', 'Select custom groups…'), optionLabel: 'name', radioName: 'custom-group-action', options: props.customGroups || [] },
	{ key: 'years' as const, label: t('sheetmusic', 'Years'), placeholder: t('sheetmusic', 'Select years…'), optionLabel: 'name', radioName: 'year-action', options: props.years || [] },
	{ key: 'signatures' as const, label: t('sheetmusic', 'Time Signatures'), placeholder: t('sheetmusic', 'Select time signatures…'), optionLabel: 'name', radioName: 'signature-action', options: props.signatures || [] },
	{ key: 'sourceTypes' as const, label: t('sheetmusic', 'Source Types'), placeholder: t('sheetmusic', 'Select source types…'), optionLabel: 'name', radioName: 'source-type-action', options: props.sourceTypes || [] },
	{ key: 'instruments' as const, label: t('sheetmusic', 'Instruments'), placeholder: t('sheetmusic', 'Select instruments…'), optionLabel: 'name', radioName: 'instrument-action', options: props.instruments || [] },
	{ key: 'arrangers' as const, label: t('sheetmusic', 'Arrangers'), placeholder: t('sheetmusic', 'Select arrangers…'), optionLabel: 'name', radioName: 'arranger-action', options: props.arrangers || [] },
])

const isAnythingEnabled = computed(() => CATEGORY_KEYS.some((key) => state[key].enabled))

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
