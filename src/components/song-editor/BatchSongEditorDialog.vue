<script setup lang="ts">
import type { Artist, Book, Collection, Composer, Genre } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { computed, ref, watch } from 'vue'

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
}

const props = defineProps<{
	open: boolean
	selectedCount: number
	composers: Composer[]
	books: Book[]
	collections: Collection[]
	artists: Artist[]
	genres?: Genre[]
}>()

const emit = defineEmits<{
	'update:open': [open: boolean]
	'apply': [payload: BatchUpdatePayload]
}>()

// Category enable flags
const enableComposers = ref(false)
const enableBooks = ref(false)
const enableCollections = ref(false)
const enableArtists = ref(false)
const enableGenres = ref(false)

// Actions
const composerAction = ref<BatchAction>('add')
const bookAction = ref<BatchAction>('add')
const collectionAction = ref<BatchAction>('add')
const artistAction = ref<BatchAction>('add')
const genreAction = ref<BatchAction>('add')

// Selected entity arrays
const selectedComposers = ref<Composer[]>([])
const selectedBooks = ref<Book[]>([])
const selectedCollections = ref<Collection[]>([])
const selectedArtists = ref<Artist[]>([])
const selectedGenres = ref<Genre[]>([])

function resetForm() {
	enableComposers.value = false
	enableBooks.value = false
	enableCollections.value = false
	enableArtists.value = false
	enableGenres.value = false

	composerAction.value = 'add'
	bookAction.value = 'add'
	collectionAction.value = 'add'
	artistAction.value = 'add'
	genreAction.value = 'add'

	selectedComposers.value = []
	selectedBooks.value = []
	selectedCollections.value = []
	selectedArtists.value = []
	selectedGenres.value = []
}

watch(() => props.open, (isOpen) => {
	if (isOpen) resetForm()
})

const isAnythingEnabled = computed(() => {
	return enableComposers.value || enableBooks.value || enableCollections.value || enableArtists.value || enableGenres.value
})

function handleApply() {
	if (!isAnythingEnabled.value) return

	const payload: BatchUpdatePayload = {}

	if (enableComposers.value) {
		payload.composers = {
			action: composerAction.value,
			ids: selectedComposers.value.map((c) => c.id),
		}
	}
	if (enableBooks.value) {
		payload.books = {
			action: bookAction.value,
			ids: selectedBooks.value.map((b) => b.id),
		}
	}
	if (enableCollections.value) {
		payload.collections = {
			action: collectionAction.value,
			ids: selectedCollections.value.map((c) => c.id),
		}
	}
	if (enableArtists.value) {
		payload.artists = {
			action: artistAction.value,
			ids: selectedArtists.value.map((a) => a.id),
		}
	}
	if (enableGenres.value) {
		payload.genres = {
			action: genreAction.value,
			ids: selectedGenres.value.map((g) => g.id),
		}
	}

	emit('apply', payload)
}

function handleClose() {
	emit('update:open', false)
}

const actionOptions: { value: BatchAction; label: string }[] = [
	{ value: 'add', label: t('mobilesheetsmanager', 'Add to existing') },
	{ value: 'replace', label: t('mobilesheetsmanager', 'Replace all existing') },
	{ value: 'remove', label: t('mobilesheetsmanager', 'Remove from selected songs') },
]
</script>

<template>
	<NcDialog
		v-if="open"
		:name="t('mobilesheetsmanager', 'Batch edit {count} songs', { count: selectedCount })"
		size="normal"
		@close="handleClose">
		<div :class="$style.dialogContent">
			<p :class="$style.intro">
				{{ t('mobilesheetsmanager', 'Select which metadata fields to update across all {count} selected songs.', { count: selectedCount }) }}
			</p>

			<!-- Composers Section -->
			<div :class="$style.fieldGroup">
				<div :class="$style.fieldHeader">
					<NcCheckboxRadioSwitch
						v-model="enableComposers"
						type="checkbox">
						{{ t('mobilesheetsmanager', 'Composers') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="enableComposers" :class="$style.fieldBody">
					<div :class="$style.actionSelector">
						<label v-for="opt in actionOptions" :key="opt.value" :class="$style.radioLabel">
							<input
								v-model="composerAction"
								type="radio"
								:name="'composer-action'"
								:value="opt.value">
							{{ opt.label }}
						</label>
					</div>
					<NcSelect
						v-model="selectedComposers"
						:options="composers"
						:multiple="true"
						:close-on-select="false"
						:input-label="t('mobilesheetsmanager', 'Composers')"
						:placeholder="t('mobilesheetsmanager', 'Select composers…')"
						label="name"
						track-by="id" />
				</div>
			</div>

			<!-- Books Section -->
			<div :class="$style.fieldGroup">
				<div :class="$style.fieldHeader">
					<NcCheckboxRadioSwitch
						v-model="enableBooks"
						type="checkbox">
						{{ t('mobilesheetsmanager', 'Books') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="enableBooks" :class="$style.fieldBody">
					<div :class="$style.actionSelector">
						<label v-for="opt in actionOptions" :key="opt.value" :class="$style.radioLabel">
							<input
								v-model="bookAction"
								type="radio"
								:name="'book-action'"
								:value="opt.value">
							{{ opt.label }}
						</label>
					</div>
					<NcSelect
						v-model="selectedBooks"
						:options="books"
						:multiple="true"
						:close-on-select="false"
						:input-label="t('mobilesheetsmanager', 'Books')"
						:placeholder="t('mobilesheetsmanager', 'Select books…')"
						label="title"
						track-by="id" />
				</div>
			</div>

			<!-- Collections Section -->
			<div :class="$style.fieldGroup">
				<div :class="$style.fieldHeader">
					<NcCheckboxRadioSwitch
						v-model="enableCollections"
						type="checkbox">
						{{ t('mobilesheetsmanager', 'Collections') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="enableCollections" :class="$style.fieldBody">
					<div :class="$style.actionSelector">
						<label v-for="opt in actionOptions" :key="opt.value" :class="$style.radioLabel">
							<input
								v-model="collectionAction"
								type="radio"
								:name="'collection-action'"
								:value="opt.value">
							{{ opt.label }}
						</label>
					</div>
					<NcSelect
						v-model="selectedCollections"
						:options="collections"
						:multiple="true"
						:close-on-select="false"
						:input-label="t('mobilesheetsmanager', 'Collections')"
						:placeholder="t('mobilesheetsmanager', 'Select collections…')"
						label="name"
						track-by="id" />
				</div>
			</div>

			<!-- Artists Section -->
			<div :class="$style.fieldGroup">
				<div :class="$style.fieldHeader">
					<NcCheckboxRadioSwitch
						v-model="enableArtists"
						type="checkbox">
						{{ t('mobilesheetsmanager', 'Artists') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="enableArtists" :class="$style.fieldBody">
					<div :class="$style.actionSelector">
						<label v-for="opt in actionOptions" :key="opt.value" :class="$style.radioLabel">
							<input
								v-model="artistAction"
								type="radio"
								:name="'artist-action'"
								:value="opt.value">
							{{ opt.label }}
						</label>
					</div>
					<NcSelect
						v-model="selectedArtists"
						:options="artists"
						:multiple="true"
						:close-on-select="false"
						:input-label="t('mobilesheetsmanager', 'Artists')"
						:placeholder="t('mobilesheetsmanager', 'Select artists…')"
						label="name"
						track-by="id" />
				</div>
			</div>

			<!-- Genres Section -->
			<div :class="$style.fieldGroup">
				<div :class="$style.fieldHeader">
					<NcCheckboxRadioSwitch
						v-model="enableGenres"
						type="checkbox">
						{{ t('mobilesheetsmanager', 'Genres') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div v-if="enableGenres" :class="$style.fieldBody">
					<div :class="$style.actionSelector">
						<label v-for="opt in actionOptions" :key="opt.value" :class="$style.radioLabel">
							<input
								v-model="genreAction"
								type="radio"
								:name="'genre-action'"
								:value="opt.value">
							{{ opt.label }}
						</label>
					</div>
					<NcSelect
						v-model="selectedGenres"
						:options="genres || []"
						:multiple="true"
						:close-on-select="false"
						:input-label="t('mobilesheetsmanager', 'Genres')"
						:placeholder="t('mobilesheetsmanager', 'Select genres…')"
						label="name"
						track-by="id" />
				</div>
			</div>

			<div :class="$style.dialogActions">
				<NcButton @click="handleClose">
					{{ t('mobilesheetsmanager', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!isAnythingEnabled"
					@click="handleApply">
					{{ t('mobilesheetsmanager', 'Apply to {count} songs', { count: selectedCount }) }}
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
