<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import FolderSyncOutline from 'vue-material-design-icons/FolderSyncOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import MusicNote from 'vue-material-design-icons/MusicNote.vue'
import Close from 'vue-material-design-icons/Close.vue'

import ViewHeader from './ViewHeader.vue'
import { useLibraryData } from '../composables/useLibraryData'
import type { Library, Sublibrary } from '../types/library'

const props = defineProps<{
	selectedLibrary: number
	libraries: Library[]
}>()

const {
	songs,
	instruments,
	composers,
	genres,
	collections,
	artists,
	books,
	loadLibraryData,
} = useLibraryData()

const sublibraries = ref<Sublibrary[]>([])
const isLoading = ref(false)
const error = ref('')
const copiedSlug = ref<string | null>(null)

// Create / Edit modal state
interface FormFilterRule {
	id: number
	type: string
	values: string[]
}

const isModalOpen = ref(false)
const isEditing = ref(false)
const editingId = ref<number | null>(null)
const formName = ref('')
const formSlug = ref('')
const isSlugCustomized = ref(false)
const formFilterRules = ref<FormFilterRule[]>([])
const isSaving = ref(false)
const formError = ref('')

// Delete confirm modal state
const isDeleteConfirmOpen = ref(false)
const deletingSub = ref<Sublibrary | null>(null)
const isDeleting = ref(false)

const ENTITY_TYPE_OPTIONS = [
	{ id: 'instrument', label: t('sheetmusic', 'Instrument') },
	{ id: 'composer', label: t('sheetmusic', 'Composer') },
	{ id: 'genre', label: t('sheetmusic', 'Genre') },
	{ id: 'collection', label: t('sheetmusic', 'Collection') },
	{ id: 'artist', label: t('sheetmusic', 'Artist') },
	{ id: 'book', label: t('sheetmusic', 'Book') },
]

const currentLibrary = computed(() => {
	return props.libraries.find(l => l.id === props.selectedLibrary)
})

const libraryFolderPath = computed(() => {
	const libPath = currentLibrary.value?.path ?? ''
	return libPath.endsWith('.db') ? libPath.substring(0, libPath.lastIndexOf('/')) : libPath
})

function getSublibraryRelativePath(slug: string): string {
	return `${libraryFolderPath.value}/_SubLibraries_/${slug}`
}

function slugify(text: string): string {
	return text
		.toLowerCase()
		.trim()
		.replace(/[^\p{L}\p{N}\s-]/gu, '')
		.replace(/[\s_-]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

function handleNameInput(val: string | null | undefined) {
	const str = String(val ?? '')
	formName.value = str
	if (!isEditing.value && !isSlugCustomized.value) {
		formSlug.value = slugify(str)
	}
}

function handleSlugInput(val: string | null | undefined) {
	const str = String(val ?? '')
	formSlug.value = str
	isSlugCustomized.value = true
}

const previewSlug = computed(() => {
	const custom = formSlug.value.trim()
	if (custom) {
		return slugify(custom)
	}
	const fromName = slugify(formName.value.trim())
	return fromName || 'slug'
})

const resultingSublibraryPath = computed(() => {
	return `${libraryFolderPath.value}/_SubLibraries_/${previewSlug.value}`
})

let nextRuleId = 1
function addFilterRule(type = 'instrument', values: string[] = []) {
	formFilterRules.value.push({
		id: nextRuleId++,
		type,
		values: [...values],
	})
}

function removeFilterRule(id: number) {
	formFilterRules.value = formFilterRules.value.filter(r => r.id !== id)
	if (formFilterRules.value.length === 0) {
		addFilterRule()
	}
}

function handleRuleTypeChange(rule: FormFilterRule, newType: string) {
	rule.type = newType
	rule.values = []
}

function getEntityList(type: string): { id?: number; name?: string; title?: string }[] {
	switch (type) {
		case 'instrument':
			return instruments.value ?? []
		case 'composer':
			return composers.value ?? []
		case 'genre':
			return genres.value ?? []
		case 'collection':
			return collections.value ?? []
		case 'artist':
			return artists.value ?? []
		case 'book':
			return books.value ?? []
		default:
			return []
	}
}

function getEntityOptions(type: string, currentValues: string[] = []): { id: string; label: string }[] {
	const list = getEntityList(type)
	const seen = new Set<string>()
	const options: { id: string; label: string }[] = []

	for (const item of list) {
		const rawName = ('title' in item && item.title) ? item.title : item.name
		const name = (rawName ?? '').trim()
		if (name && !seen.has(name.toLowerCase())) {
			seen.add(name.toLowerCase())
			options.push({ id: name, label: name })
		}
	}

	for (const val of currentValues) {
		const name = (val ?? '').trim()
		if (name && !seen.has(name.toLowerCase())) {
			seen.add(name.toLowerCase())
			options.push({ id: name, label: name })
		}
	}

	return options.sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: 'base' }))
}

async function fetchSublibraries() {
	if (props.selectedLibrary < 0) return
	isLoading.value = true
	error.value = ''
	try {
		const url = generateOcsUrl(`/apps/sheetmusic/api/libraries/${props.selectedLibrary}/sublibraries`)
		const response = await axios.get(url, {
			headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
		})
		sublibraries.value = response.data?.ocs?.data?.sublibraries ?? []
	} catch (e: any) {
		console.error('Failed to load sub-libraries:', e)
		error.value = e?.message || t('sheetmusic', 'Failed to load sub-libraries.')
	} finally {
		isLoading.value = false
	}
}

function getMatchingSongCount(sub: Sublibrary): number | null {
	if (!songs.value || songs.value.length === 0) {
		return null
	}
	const filters = sub.filters
	if (!filters || Object.keys(filters).length === 0) {
		return songs.value.length
	}

	return songs.value.filter(song => {
		for (const [entityType, vals] of Object.entries(filters)) {
			const expectedValues = (Array.isArray(vals) ? vals : [vals])
				.map(v => String(v).toLowerCase().trim())
				.filter(v => v !== '')

			if (expectedValues.length === 0) {
				continue
			}

			const propName = entityType.endsWith('s') ? entityType : `${entityType}s`
			const songEntities = (song as any)[propName] as { name?: string; title?: string }[] | undefined
			if (!songEntities || songEntities.length === 0) {
				return false
			}

			const hasMatch = songEntities.some(ent => {
				const entName = (ent.title || ent.name || '').toLowerCase().trim()
				return expectedValues.includes(entName)
			})

			if (!hasMatch) {
				return false
			}
		}
		return true
	}).length
}

async function copySublibraryPath(slug: string) {
	const path = getSublibraryRelativePath(slug)
	try {
		await navigator.clipboard.writeText(path)
		copiedSlug.value = slug
		showSuccess(t('sheetmusic', 'Copied WebDAV path to clipboard: {path}', { path }))
		setTimeout(() => {
			if (copiedSlug.value === slug) {
				copiedSlug.value = null
			}
		}, 2500)
	} catch (e) {
		console.error('Failed to copy WebDAV path:', e)
		showError(t('sheetmusic', 'Could not copy path to clipboard.'))
	}
}

function openCreateModal() {
	isEditing.value = false
	editingId.value = null
	formName.value = ''
	formSlug.value = ''
	isSlugCustomized.value = false
	formError.value = ''
	formFilterRules.value = []
	addFilterRule('instrument', [])
	isModalOpen.value = true
}

function openEditModal(sub: Sublibrary) {
	isEditing.value = true
	editingId.value = sub.id ?? null
	formName.value = sub.name
	formSlug.value = sub.slug
	isSlugCustomized.value = true
	formError.value = ''
	formFilterRules.value = []

	const filters = sub.filters ?? {}
	for (const [type, values] of Object.entries(filters)) {
		const vals = Array.isArray(values) ? values : [values]
		addFilterRule(type, vals.map(v => String(v).trim()).filter(Boolean))
	}

	if (formFilterRules.value.length === 0) {
		addFilterRule('instrument', [])
	}

	isModalOpen.value = true
}

async function saveSublibrary() {
	formError.value = ''
	const name = formName.value.trim()
	if (!name) {
		formError.value = t('sheetmusic', 'Sub-library name is required.')
		return
	}

	const slug = formSlug.value.trim() !== '' ? slugify(formSlug.value.trim()) : slugify(name)
	if (!slug) {
		formError.value = t('sheetmusic', 'A valid folder slug is required.')
		return
	}

	const filters: Record<string, string[]> = {}
	let hasFilterValues = false

	for (const rule of formFilterRules.value) {
		const vals = (rule.values || [])
			.map(v => v.trim())
			.filter(Boolean)
		if (vals.length > 0) {
			filters[rule.type] = Array.from(new Set([...(filters[rule.type] || []), ...vals]))
			hasFilterValues = true
		}
	}

	if (!hasFilterValues) {
		formError.value = t('sheetmusic', 'Please select at least one filter value.')
		return
	}

	isSaving.value = true
	try {
		const payload = {
			name,
			slug,
			filters,
		}

		if (isEditing.value && editingId.value) {
			const url = generateOcsUrl(`/apps/sheetmusic/api/libraries/${props.selectedLibrary}/sublibraries/${editingId.value}`)
			await axios.put(url, payload, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			showSuccess(t('sheetmusic', 'Sub-library "{name}" updated.', { name }))
		} else {
			const url = generateOcsUrl(`/apps/sheetmusic/api/libraries/${props.selectedLibrary}/sublibraries`)
			await axios.post(url, payload, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			showSuccess(t('sheetmusic', 'Sub-library "{name}" created.', { name }))
		}

		isModalOpen.value = false
		await fetchSublibraries()
	} catch (e: any) {
		console.error('Failed to save sub-library:', e)
		formError.value = e?.response?.data?.ocs?.data?.error || e?.message || t('sheetmusic', 'Failed to save sub-library.')
	} finally {
		isSaving.value = false
	}
}

function promptDelete(sub: Sublibrary) {
	deletingSub.value = sub
	isDeleteConfirmOpen.value = true
}

async function confirmDelete() {
	if (!deletingSub.value || !deletingSub.value.id) return
	isDeleting.value = true
	try {
		const url = generateOcsUrl(`/apps/sheetmusic/api/libraries/${props.selectedLibrary}/sublibraries/${deletingSub.value.id}`)
		await axios.delete(url, {
			headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
		})
		showSuccess(t('sheetmusic', 'Sub-library "{name}" deleted.', { name: deletingSub.value.name }))
		isDeleteConfirmOpen.value = false
		deletingSub.value = null
		await fetchSublibraries()
	} catch (e: any) {
		console.error('Failed to delete sub-library:', e)
		showError(e?.message || t('sheetmusic', 'Failed to delete sub-library.'))
	} finally {
		isDeleting.value = false
	}
}

watch(
	() => props.selectedLibrary,
	() => {
		if (props.selectedLibrary >= 0) {
			fetchSublibraries()
			loadLibraryData(props.selectedLibrary)
		}
	},
	{ immediate: true },
)
</script>

<template>
	<div :class="$style.wrapper" data-test="sublibraries-view">
		<ViewHeader :title="t('sheetmusic', 'Sub-Libraries (MobileSheets Sync)')">
			<NcButton
				type="button"
				variant="primary"
				@click="openCreateModal">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('sheetmusic', 'Create sub-library') }}
			</NcButton>
		</ViewHeader>

		<div :class="$style.content">
			<!-- Guide Banner -->
			<div :class="$style.infoBanner">
				<InformationOutline :size="24" :class="$style.bannerIcon" />
				<div :class="$style.bannerText">
					<strong>{{ t('sheetmusic', 'About Sub-Libraries & Tablet Sync') }}</strong>
					<p>
						{{ t('sheetmusic', 'Sub-libraries define filtered subsets of your library (e.g. by instrument or composer) and expose them as isolated virtual WebDAV folders for MobileSheets. Physical score files are shared from your library without taking extra storage space.') }}
					</p>
					<p :class="$style.bannerSubtext">
						{{ t('sheetmusic', 'Two-way sync is fully supported: tablet edits and annotations sync back to Nextcloud. When you delete a song on your tablet within a sub-library, it is softly removed from the sub-library without deleting your master score.') }}
					</p>
				</div>
			</div>

			<!-- Loading State -->
			<div v-if="isLoading" :class="$style.loading">
				<NcLoadingIcon :size="36" />
				<p>{{ t('sheetmusic', 'Loading sub-libraries…') }}</p>
			</div>

			<!-- Error State -->
			<p v-else-if="error" :class="$style.error" role="alert">
				{{ error }}
			</p>

			<!-- Empty State -->
			<div v-else-if="sublibraries.length === 0" :class="$style.emptyState">
				<FolderSyncOutline :size="64" :class="$style.emptyIcon" />
				<h3 :class="$style.emptyTitle">{{ t('sheetmusic', 'No sub-libraries configured') }}</h3>
				<p :class="$style.emptyDescription">
					{{ t('sheetmusic', 'Create your first sub-library to filter songs for MobileSheets tablet sync.') }}
				</p>
				<NcButton
					type="button"
					variant="primary"
					:class="$style.emptyActionBtn"
					@click="openCreateModal">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('sheetmusic', 'Create sub-library') }}
				</NcButton>
			</div>

			<!-- Sublibraries Grid -->
			<div v-else :class="$style.grid">
				<div
					v-for="sub in sublibraries"
					:key="sub.id"
					:class="$style.card">
					<div :class="$style.cardHeader">
						<div :class="$style.cardTitleArea">
							<FolderSyncOutline :size="22" :class="$style.cardIcon" />
							<div>
								<h3 :class="$style.cardTitle">{{ sub.name }}</h3>
								<span :class="$style.slugBadge">{{ sub.slug }}</span>
							</div>
						</div>
						<div :class="$style.cardActions">
							<NcButton
								type="button"
								variant="tertiary"
								:title="t('sheetmusic', 'Edit sub-library')"
								:aria-label="t('sheetmusic', 'Edit sub-library')"
								@click="openEditModal(sub)">
								<template #icon>
									<PencilOutline :size="18" />
								</template>
							</NcButton>
							<NcButton
								type="button"
								variant="error"
								:title="t('sheetmusic', 'Delete sub-library')"
								:aria-label="t('sheetmusic', 'Delete sub-library')"
								@click="promptDelete(sub)">
								<template #icon>
									<TrashCanOutline :size="18" />
								</template>
							</NcButton>
						</div>
					</div>

					<!-- WebDAV Path Box -->
					<div :class="$style.pathBox">
						<div :class="$style.pathLabelRow">
							<span :class="$style.pathLabel">{{ t('sheetmusic', 'WebDAV Folder Path for MobileSheets:') }}</span>
							<button
								type="button"
								:class="$style.copyLink"
								@click="copySublibraryPath(sub.slug)">
								<component :is="copiedSlug === sub.slug ? Check : ContentCopy" :size="14" />
								<span>{{ copiedSlug === sub.slug ? t('sheetmusic', 'Copied!') : t('sheetmusic', 'Copy Path') }}</span>
							</button>
						</div>
						<code :class="$style.pathCode">{{ getSublibraryRelativePath(sub.slug) }}</code>
					</div>

					<!-- Filter Badges -->
					<div :class="$style.filtersSection">
						<span :class="$style.filtersLabel">{{ t('sheetmusic', 'Filter criteria:') }}</span>
						<div :class="$style.badgeGroup">
							<template v-for="(vals, type) in sub.filters" :key="type">
								<span :class="$style.badge">
									<strong>{{ type }}:</strong> {{ Array.isArray(vals) ? vals.join(', ') : vals }}
								</span>
							</template>
						</div>
					</div>

					<!-- Footer info: Matching song count -->
					<div :class="$style.cardFooter">
						<div :class="$style.matchCount">
							<MusicNote :size="16" />
							<span v-if="getMatchingSongCount(sub) !== null">
								{{ t('sheetmusic', '{count} matching score(s)', { count: getMatchingSongCount(sub) }) }}
							</span>
							<span v-else>
								{{ t('sheetmusic', 'Synchronized sub-library') }}
							</span>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Create / Edit Modal -->
		<NcDialog
			v-if="isModalOpen"
			:name="isEditing ? t('sheetmusic', 'Edit Sub-Library') : t('sheetmusic', 'Create Sub-Library')"
			size="normal"
			@close="!isSaving && (isModalOpen = false)">
			<div :class="$style.dialogBody">
				<NcTextField
					:model-value="formName"
					:label="t('sheetmusic', 'Sub-library Name')"
					placeholder="e.g. Organ Repertoire"
					@update:model-value="handleNameInput" />

				<NcTextField
					:model-value="formSlug"
					:label="t('sheetmusic', 'Folder Slug (used in WebDAV path)')"
					placeholder="e.g. organ-repertoire"
					@update:model-value="handleSlugInput" />

				<div :class="$style.resultingPathBox">
					<span :class="$style.resultingPathLabel">{{ t('sheetmusic', 'Resulting WebDAV folder path for MobileSheets:') }}</span>
					<code :class="$style.resultingPathCode">{{ resultingSublibraryPath }}</code>
				</div>

				<div :class="$style.formSectionHeader">
					<h4>{{ t('sheetmusic', 'Filter Criteria') }}</h4>
					<p :class="$style.formSectionSub">
						{{ t('sheetmusic', 'Only songs matching these criteria will be included in this sub-library.') }}
					</p>
				</div>

				<div :class="$style.ruleList">
					<div
						v-for="rule in formFilterRules"
						:key="rule.id"
						:class="$style.ruleRow">
						<NcSelect
							:model-value="rule.type"
							:options="ENTITY_TYPE_OPTIONS"
							:reduce="(opt: any) => opt.id"
							label="label"
							:input-label="t('sheetmusic', 'Filter by')"
							:clearable="false"
							:class="$style.ruleSelect"
							@update:model-value="handleRuleTypeChange(rule, $event)" />

						<NcSelect
							v-model="rule.values"
							:options="getEntityOptions(rule.type, rule.values)"
							:reduce="(opt: any) => opt.id"
							label="label"
							track-by="id"
							:multiple="true"
							:close-on-select="false"
							:taggable="false"
							:input-label="t('sheetmusic', 'Select values')"
							:placeholder="t('sheetmusic', 'Select values…')"
							:class="$style.ruleValuesSelect" />

						<NcButton
							v-if="formFilterRules.length > 1"
							type="button"
							variant="tertiary"
							:title="t('sheetmusic', 'Remove filter rule')"
							:class="$style.removeRuleBtn"
							@click="removeFilterRule(rule.id)">
							<template #icon>
								<Close :size="18" />
							</template>
						</NcButton>
					</div>
				</div>

				<NcButton
					type="button"
					:class="$style.addRuleBtn"
					@click="addFilterRule()">
					<template #icon>
						<Plus :size="16" />
					</template>
					{{ t('sheetmusic', 'Add another filter criterion') }}
				</NcButton>

				<p v-if="formError" :class="$style.error" role="alert">
					{{ formError }}
				</p>
			</div>

			<template #actions>
				<NcButton
					type="button"
					variant="primary"
					:disabled="isSaving"
					@click="saveSublibrary">
					<template v-if="isSaving" #icon>
						<NcLoadingIcon :size="18" />
					</template>
					{{ isEditing ? t('sheetmusic', 'Save Changes') : t('sheetmusic', 'Create Sub-Library') }}
				</NcButton>
				<NcButton
					type="button"
					:disabled="isSaving"
					@click="isModalOpen = false">
					{{ t('sheetmusic', 'Cancel') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Delete Confirmation Modal -->
		<NcDialog
			v-if="isDeleteConfirmOpen"
			:name="t('sheetmusic', 'Delete Sub-Library?')"
			size="normal"
			@close="!isDeleting && (isDeleteConfirmOpen = false)">
			<div :class="$style.dialogBody">
				<p>
					{{ t('sheetmusic', 'Are you sure you want to delete the sub-library "{name}"?', { name: deletingSub?.name }) }}
				</p>
				<p :class="$style.deleteNote">
					{{ t('sheetmusic', 'This will remove the virtual WebDAV folder. All your physical sheet music files and library entries will remain safe and untouched.') }}
				</p>
			</div>
			<template #actions>
				<NcButton
					type="button"
					variant="error"
					:disabled="isDeleting"
					@click="confirmDelete">
					<template v-if="isDeleting" #icon>
						<NcLoadingIcon :size="18" />
					</template>
					{{ t('sheetmusic', 'Delete Sub-Library') }}
				</NcButton>
				<NcButton
					type="button"
					:disabled="isDeleting"
					@click="isDeleteConfirmOpen = false">
					{{ t('sheetmusic', 'Cancel') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<style module>
.wrapper {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow-y: auto;
}

.content {
	padding: 24px;
	max-width: 1200px;
	margin: 0 auto;
	width: 100%;
	box-sizing: border-box;
}

.infoBanner {
	display: flex;
	align-items: flex-start;
	gap: 16px;
	padding: 16px 20px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	margin-bottom: 24px;
}

.bannerIcon {
	color: var(--color-primary-element);
	flex-shrink: 0;
	margin-top: 2px;
}

.bannerText {
	font-size: 0.9rem;
	line-height: 1.45;
}

.bannerText strong {
	display: block;
	margin-bottom: 4px;
	font-size: 0.95rem;
}

.bannerText p {
	margin: 0 0 6px 0;
	color: var(--color-main-text);
}

.bannerSubtext {
	color: var(--color-text-maxcontrast) !important;
	font-size: 0.85rem;
}

.loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px;
	gap: 16px;
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error);
	padding: 12px 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large, 8px);
	margin-bottom: 16px;
}

.emptyState {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 64px 24px;
	text-align: center;
	background-color: var(--color-main-background);
	border: 2px dashed var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.emptyIcon {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
	opacity: 0.6;
}

.emptyTitle {
	margin: 0 0 8px 0;
	font-size: 1.25rem;
	font-weight: 600;
}

.emptyDescription {
	margin: 0 0 20px 0;
	color: var(--color-text-maxcontrast);
	max-width: 480px;
	line-height: 1.4;
}

.emptyActionBtn {
	margin-top: 4px;
}

.grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
	gap: 20px;
}

.card {
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	padding: 18px 20px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
	transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.card:hover {
	border-color: var(--color-primary-element-light, #0082c9);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.cardHeader {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 14px;
}

.cardTitleArea {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
	flex: 1;
}

.cardIcon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.cardTitle {
	margin: 0 0 4px 0;
	font-size: 1.1rem;
	font-weight: 600;
	word-break: break-word;
}

.slugBadge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill, 10px);
	background-color: var(--color-background-darker, rgba(0, 0, 0, 0.06));
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: 0.75rem;
}

.cardActions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.pathBox {
	margin-bottom: 14px;
	padding: 10px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 6px);
	border: 1px solid var(--color-border);
}

.pathLabelRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 4px;
}

.pathLabel {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.copyLink {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	background: none;
	border: none;
	padding: 2px 6px;
	font-size: 0.75rem;
	color: var(--color-primary-element);
	cursor: pointer;
	border-radius: var(--border-radius, 4px);
}

.copyLink:hover {
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
}

.pathCode {
	display: block;
	font-family: monospace;
	font-size: 0.8rem;
	word-break: break-all;
	color: var(--color-main-text);
}

.filtersSection {
	margin-bottom: 14px;
	flex: 1;
}

.filtersLabel {
	display: block;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 6px;
	font-weight: 500;
}

.badgeGroup {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.badge {
	display: inline-block;
	padding: 3px 10px;
	border-radius: var(--border-radius-pill, 12px);
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.12));
	color: var(--color-primary-element-text, var(--color-main-text));
	font-size: 0.8rem;
}

.cardFooter {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.matchCount {
	display: flex;
	align-items: center;
	gap: 6px;
	font-weight: 500;
}

.dialogBody {
	padding: 12px 0;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.resultingPathBox {
	padding: 10px 14px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
}

.resultingPathLabel {
	display: block;
	font-size: 0.75rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.resultingPathCode {
	font-family: monospace;
	font-size: 0.85rem;
	color: var(--color-main-text);
	word-break: break-all;
}

.formSectionHeader {
	margin-top: 8px;
}

.formSectionHeader h4 {
	margin: 0 0 4px 0;
	font-size: 0.95rem;
	font-weight: 600;
}

.formSectionSub {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.ruleList {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.ruleRow {
	display: flex;
	align-items: center;
	gap: 10px;
}

.ruleSelect {
	flex: 1;
	min-width: 140px;
}

.ruleValuesSelect {
	flex: 2;
	min-width: 220px;
}

.removeRuleBtn {
	flex-shrink: 0;
	margin-top: 4px;
}

.addRuleBtn {
	align-self: flex-start;
	margin-top: 4px;
}

.deleteNote {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	line-height: 1.4;
	margin-top: 8px;
}
</style>
