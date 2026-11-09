<script setup lang="ts">
import { computed, ref, unref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

import FolderSyncOutline from 'vue-material-design-icons/FolderSyncOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

import ViewHeader from './ViewHeader.vue'
import SublibraryCard from './sublibraries/SublibraryCard.vue'
import SublibraryModal from './sublibraries/SublibraryModal.vue'
import DeleteSublibraryDialog from './sublibraries/DeleteSublibraryDialog.vue'

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

const songList = computed(() => {
	const raw = unref(songs)
	return Array.isArray(raw) ? raw : (raw && Array.isArray((raw as any).value) ? (raw as any).value : [])
})
const instrumentList = computed(() => {
	const raw = unref(instruments)
	return Array.isArray(raw) ? raw : (raw && Array.isArray((raw as any).value) ? (raw as any).value : [])
})
const composerList = computed(() => {
	const raw = unref(composers)
	return Array.isArray(raw) ? raw : (raw && Array.isArray((raw as any).value) ? (raw as any).value : [])
})
const genreList = computed(() => {
	const raw = unref(genres)
	return Array.isArray(raw) ? raw : (raw && Array.isArray((raw as any).value) ? (raw as any).value : [])
})
const collectionList = computed(() => {
	const raw = unref(collections)
	return Array.isArray(raw) ? raw : (raw && Array.isArray((raw as any).value) ? (raw as any).value : [])
})
const artistList = computed(() => {
	const raw = unref(artists)
	return Array.isArray(raw) ? raw : (raw && Array.isArray((raw as any).value) ? (raw as any).value : [])
})
const bookList = computed(() => {
	const raw = unref(books)
	return Array.isArray(raw) ? raw : (raw && Array.isArray((raw as any).value) ? (raw as any).value : [])
})

const sublibraries = ref<Sublibrary[]>([])
const isLoading = ref(false)
const error = ref('')
const copiedSlug = ref<string | null>(null)

// Modal state
const isModalOpen = ref(false)
const editingSub = ref<Sublibrary | null>(null)
const isSaving = ref(false)
const formError = ref('')

// Delete state
const isDeleteConfirmOpen = ref(false)
const deletingSub = ref<Sublibrary | null>(null)
const isDeleting = ref(false)

const currentLibrary = computed(() => {
	return props.libraries.find(l => l.id === props.selectedLibrary)
})

const libraryFolderPath = computed(() => {
	const libPath = currentLibrary.value?.path ?? ''
	return libPath.endsWith('.db') ? libPath.substring(0, libPath.lastIndexOf('/')) : libPath
})

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

async function copySublibraryPath(slug: string) {
	const path = `${libraryFolderPath.value}/_SubLibraries_/${slug}`
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
	editingSub.value = null
	formError.value = ''
	isModalOpen.value = true
}

function openEditModal(sub: Sublibrary) {
	editingSub.value = sub
	formError.value = ''
	isModalOpen.value = true
}

async function handleSaveSublibrary(payload: { name: string; slug: string; filters: Record<string, string[]> }) {
	isSaving.value = true
	formError.value = ''
	try {
		if (editingSub.value?.id) {
			const url = generateOcsUrl(`/apps/sheetmusic/api/libraries/${props.selectedLibrary}/sublibraries/${editingSub.value.id}`)
			await axios.put(url, payload, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			showSuccess(t('sheetmusic', 'Sub-library "{name}" updated.', { name: payload.name }))
		} else {
			const url = generateOcsUrl(`/apps/sheetmusic/api/libraries/${props.selectedLibrary}/sublibraries`)
			await axios.post(url, payload, {
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			})
			showSuccess(t('sheetmusic', 'Sub-library "{name}" created.', { name: payload.name }))
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
	if (!deletingSub.value?.id) return
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
				<SublibraryCard
					v-for="sub in sublibraries"
					:key="sub.id"
					:sub="sub"
					:library-folder-path="libraryFolderPath"
					:songs="songList"
					:is-copied="copiedSlug === sub.slug"
					@edit="openEditModal"
					@delete="promptDelete"
					@copy-path="copySublibraryPath" />
			</div>
		</div>

		<!-- Create / Edit Modal -->
		<SublibraryModal
			:is-open="isModalOpen"
			:sublibrary="editingSub"
			:library-folder-path="libraryFolderPath"
			:is-saving="isSaving"
			:form-error="formError"
			:instruments="instrumentList"
			:composers="composerList"
			:genres="genreList"
			:collections="collectionList"
			:artists="artistList"
			:books="bookList"
			@save="handleSaveSublibrary"
			@close="isModalOpen = false" />

		<!-- Delete Confirmation Modal -->
		<DeleteSublibraryDialog
			:is-open="isDeleteConfirmOpen"
			:sub="deletingSub"
			:is-deleting="isDeleting"
			@confirm="confirmDelete"
			@close="isDeleteConfirmOpen = false" />
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
	padding: 20px 30px;
	max-width: 1200px;
	width: 100%;
	box-sizing: border-box;
	margin: 0 auto;
}

.infoBanner {
	display: flex;
	align-items: flex-start;
	gap: 16px;
	padding: 16px 20px;
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-left: 4px solid var(--color-primary-element, #0082c9);
	border-radius: var(--border-radius-large, 8px);
	margin-bottom: 24px;
}

.bannerIcon {
	color: var(--color-primary-element, #0082c9);
	flex-shrink: 0;
	margin-top: 2px;
}

.bannerText {
	font-size: 0.95rem;
	line-height: 1.5;
}

.bannerText strong {
	display: block;
	margin-bottom: 6px;
	color: var(--color-main-text);
}

.bannerText p {
	margin: 0 0 6px 0;
	color: var(--color-text-maxcontrast);
}

.bannerSubtext {
	font-size: 0.85rem;
	opacity: 0.85;
}

.loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 0;
	color: var(--color-text-maxcontrast);
}

.loading p {
	margin-top: 12px;
}

.emptyState {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 60px 20px;
	text-align: center;
}

.emptyIcon {
	color: var(--color-text-maxcontrast);
	opacity: 0.5;
	margin-bottom: 16px;
}

.emptyTitle {
	margin: 0 0 8px 0;
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.emptyDescription {
	margin: 0 0 20px 0;
	color: var(--color-text-maxcontrast);
	max-width: 480px;
}

.emptyActionBtn {
	margin-top: 8px;
}

.grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
	gap: 20px;
}

.error {
	color: var(--color-error);
	padding: 16px;
	background-color: var(--color-error-background, rgba(233, 50, 45, 0.1));
	border-radius: var(--border-radius, 6px);
}
</style>
