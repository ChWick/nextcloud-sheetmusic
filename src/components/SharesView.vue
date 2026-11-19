<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import FolderMusicOutline from 'vue-material-design-icons/FolderMusicOutline.vue'
import FolderSyncOutline from 'vue-material-design-icons/FolderSyncOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ViewHeader from './ViewHeader.vue'
import ShareDialog from './ShareDialog.vue'
import { describeRequestFailure } from '../utils/api'
import { useCopyToClipboard } from '../composables/useCopyToClipboard'
import type { Library, LibraryShare, SharesOverview, Sublibrary } from '../types/library'

const props = defineProps<{
	selectedLibrary: number
	libraries?: Library[]
}>()

const emit = defineEmits<{
	'open-sublibraries': []
}>()

const isLoading = ref(false)
const error = ref('')
const overview = ref<SharesOverview | null>(null)
const { copiedKey: copiedToken, copy: copyText } = useCopyToClipboard(2500)
const deletingShareId = ref<string | number | null>(null)

// Dialog state
const isShareDialogOpen = ref(false)
const activeSublibrary = ref<Sublibrary | null>(null)

const currentLibrary = computed(() => {
	return props.libraries?.find(l => Number(l.id) === props.selectedLibrary)
})

const libraryDisplayName = computed(() => {
	if (overview.value?.library.name) {
		return overview.value.library.name
	}
	const p = currentLibrary.value?.path ?? ''
	const clean = p.replace(/\/+$/, '')
	const base = clean.substring(clean.lastIndexOf('/') + 1)
	return base || t('sheetmusic', 'Library')
})

const totalSharesCount = computed(() => {
	if (!overview.value) return 0
	let count = overview.value.library.shares.length
	for (const sub of overview.value.sublibraries) {
		count += sub.shares.length
	}
	return count
})

async function fetchSharesOverview() {
	if (props.selectedLibrary < 0) return
	isLoading.value = true
	error.value = ''
	try {
		const url = generateOcsUrl(`/apps/sheetmusic/api/libraries/${props.selectedLibrary}/shares/overview`)
		const response = await axios.get(url, {
			headers: {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
			},
		})
		overview.value = response.data?.ocs?.data ?? null
	} catch (err) {
		console.error('Failed to load shares overview:', err)
		error.value = describeRequestFailure(err, t('sheetmusic', 'Could not load shares overview.'))
	} finally {
		isLoading.value = false
	}
}

function openMainLibraryShare() {
	activeSublibrary.value = null
	isShareDialogOpen.value = true
}

function openSublibraryShare(sub: Sublibrary) {
	activeSublibrary.value = sub
	isShareDialogOpen.value = true
}

function onShareDialogClose() {
	isShareDialogOpen.value = false
	activeSublibrary.value = null
	fetchSharesOverview()
}

async function copyToClipboard(text: string, token: string) {
	await copyText(text, token, {
		successMessage: t('sheetmusic', 'Link copied to clipboard.'),
		errorMessage: t('sheetmusic', 'Failed to copy to clipboard.'),
	})
}

async function deleteShare(share: LibraryShare, sublibraryId?: number) {
	deletingShareId.value = share.id
	try {
		const endpoint = sublibraryId !== undefined
			? `/ocs/v2.php/apps/sheetmusic/api/libraries/${props.selectedLibrary}/sublibraries/${sublibraryId}/shares/${share.id}`
			: `/ocs/v2.php/apps/sheetmusic/api/libraries/${props.selectedLibrary}/shares/${share.id}`

		await axios.delete(endpoint, {
			headers: {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
			},
		})
		showSuccess(t('sheetmusic', 'Share removed.'))
		await fetchSharesOverview()
	} catch (err) {
		console.error('Failed to delete share:', err)
		showError(describeRequestFailure(err, t('sheetmusic', 'Could not remove share.')))
	} finally {
		deletingShareId.value = null
	}
}

function formatFilterTags(filters: Record<string, string[] | string>): string[] {
	const tags: string[] = []
	for (const [key, val] of Object.entries(filters)) {
		if (Array.isArray(val) && val.length > 0) {
			tags.push(`${key}: ${val.join(', ')}`)
		} else if (typeof val === 'string' && val.trim() !== '') {
			tags.push(`${key}: ${val}`)
		}
	}
	return tags
}

watch(
	() => props.selectedLibrary,
	(newId) => {
		if (newId >= 0) {
			fetchSharesOverview()
		}
	},
	{ immediate: true },
)
</script>

<template>
	<div :class="$style.wrapper" data-test="shares-view">
		<ViewHeader :title="t('sheetmusic', 'Shares')">
			<!-- Button to share main library -->
			<NcButton
				type="button"
				variant="primary"
				@click="openMainLibraryShare">
				<template #icon>
					<ShareVariantOutline :size="20" />
				</template>
				{{ t('sheetmusic', 'Share main library') }}
			</NcButton>

			<!-- Action or menu to share a sub-library if any exist -->
			<NcActions
				v-if="overview && overview.sublibraries.length > 1"
				:name="t('sheetmusic', 'Share sub-library')"
				:aria-label="t('sheetmusic', 'Share sub-library')">
				<template #icon>
					<FolderSyncOutline :size="20" />
				</template>
				<NcActionButton
					v-for="sub in overview.sublibraries"
					:key="sub.id"
					:close-after-click="true"
					@click="openSublibraryShare(sub)">
					<template #icon>
						<ShareVariantOutline :size="20" />
					</template>
					{{ sub.name }}
				</NcActionButton>
			</NcActions>
			<NcButton
				v-else-if="overview && overview.sublibraries.length === 1"
				type="button"
				variant="secondary"
				@click="openSublibraryShare(overview.sublibraries[0])">
				<template #icon>
					<FolderSyncOutline :size="20" />
				</template>
				{{ t('sheetmusic', 'Share {name}', { name: overview.sublibraries[0].name }, { escape: false }) }}
			</NcButton>
		</ViewHeader>

		<div :class="$style.content">
			<!-- Guide Banner -->
			<div :class="$style.infoBanner">
				<InformationOutline :size="24" :class="$style.bannerIcon" />
				<div :class="$style.bannerText">
					<strong>{{ t('sheetmusic', 'About Library & Sub-Library Sharing') }}</strong>
					<p>
						{{ t('sheetmusic', 'You can share your full library or isolated sub-libraries with other Nextcloud users, groups, or generate public links for MobileSheets tablet sync. Shared sub-libraries only expose matching songs without duplicating physical PDF files.') }}
					</p>
				</div>
			</div>

			<!-- Loading State -->
			<div v-if="isLoading && !overview" :class="$style.loading">
				<NcLoadingIcon :size="36" />
				<p>{{ t('sheetmusic', 'Loading shares overview…') }}</p>
			</div>

			<!-- Error State -->
			<p v-else-if="error" :class="$style.error" role="alert">
				{{ error }}
			</p>

			<!-- Overview Sections -->
			<div v-else-if="overview" :class="$style.sections">
				<!-- Empty state if zero total shares -->
				<div v-if="totalSharesCount === 0" :class="$style.emptyTotalBanner">
					<ShareVariantOutline :size="48" :class="$style.emptyIcon" />
					<div :class="$style.emptyBannerContent">
						<h3>{{ t('sheetmusic', 'No active shares') }}</h3>
						<p>{{ t('sheetmusic', 'No shares have been created for this library yet. Share the full library or any sub-library with users or via public sync link.') }}</p>
					</div>
					<div :class="$style.emptyBannerActions">
						<NcButton variant="primary" @click="openMainLibraryShare">
							<template #icon>
								<ShareVariantOutline :size="18" />
							</template>
							{{ t('sheetmusic', 'Share main library') }}
						</NcButton>
					</div>
				</div>

				<!-- Section 1: Main Library Shares -->
				<div :class="$style.card" data-test="main-library-shares">
					<div :class="$style.cardHeader">
						<div :class="$style.cardTitleGroup">
							<FolderMusicOutline :size="24" :class="$style.cardIcon" />
							<div>
								<h3 :class="$style.cardTitle">
									{{ t('sheetmusic', 'Main library: {name}', { name: libraryDisplayName }, { escape: false }) }}
								</h3>
								<span :class="$style.cardSubtitle">
									{{ overview.library.path }}
								</span>
							</div>
						</div>
						<NcButton
							variant="secondary"
							size="small"
							@click="openMainLibraryShare">
							<template #icon>
								<ShareVariantOutline :size="18" />
							</template>
							{{ t('sheetmusic', 'Share') }}
						</NcButton>
					</div>

					<!-- Main Library Shares List -->
					<div v-if="overview.library.shares.length === 0" :class="$style.emptyCardState">
						<p>{{ t('sheetmusic', 'No shares for the main library yet.') }}</p>
					</div>
					<ul v-else :class="$style.sharesList">
						<li
							v-for="share in overview.library.shares"
							:key="share.id"
							:class="$style.shareRow">
							<div :class="$style.shareInfo">
								<component
									:is="share.shareType === 3 ? LinkVariant : (share.shareType === 1 ? AccountGroupOutline : AccountOutline)"
									:size="20"
									:class="$style.shareTypeIcon" />
								<div :class="$style.shareDetails">
									<span :class="$style.shareTarget">
										{{ share.shareType === 3 ? t('sheetmusic', 'Public Link (MobileSheets sync)') : (share.shareWithDisplayName || share.shareWith) }}
									</span>
									<div :class="$style.shareBadges">
										<span :class="[$style.badge, share.canWrite ? $style.badgeWrite : $style.badgeRead]">
											{{ share.canWrite ? t('sheetmusic', 'Read & Write') : t('sheetmusic', 'Read-only') }}
										</span>
										<span v-if="share.shareType === 1" :class="$style.badgeGroup">
											{{ t('sheetmusic', 'Group') }}
										</span>
									</div>
								</div>
							</div>

							<div :class="$style.shareActions">
								<NcButton
									v-if="share.publicUrl || share.webdavUrl"
									variant="secondary"
									size="small"
									:title="t('sheetmusic', 'Copy sync URL')"
									:aria-label="t('sheetmusic', 'Copy sync URL')"
									@click="copyToClipboard(share.webdavUrl || share.publicUrl || '', String(share.id))">
									<template #icon>
										<Check v-if="copiedToken === String(share.id)" :size="16" />
										<ContentCopy v-else :size="16" />
									</template>
									{{ copiedToken === String(share.id) ? t('sheetmusic', 'Copied') : t('sheetmusic', 'Copy link') }}
								</NcButton>

								<NcButton
									variant="error"
									size="small"
									:disabled="deletingShareId === share.id"
									:title="t('sheetmusic', 'Delete share')"
									:aria-label="t('sheetmusic', 'Delete share')"
									@click="deleteShare(share)">
									<template #icon>
										<DeleteOutline :size="18" />
									</template>
								</NcButton>
							</div>
						</li>
					</ul>
				</div>

				<!-- Section 2: Sub-Libraries Shares -->
				<div :class="$style.sublibrariesSection">
					<div :class="$style.subSectionHeader">
						<div>
							<h3 :class="$style.subSectionTitle">{{ t('sheetmusic', 'Sub-library shares') }}</h3>
							<p :class="$style.subSectionDesc">
								{{ t('sheetmusic', 'Each sub-library can be independently shared with users, groups, or as a filtered tablet sync folder.') }}
							</p>
						</div>
					</div>

					<!-- No sub-libraries configured yet -->
					<div v-if="overview.sublibraries.length === 0" :class="$style.noSublibrariesCard">
						<FolderSyncOutline :size="32" :class="$style.emptyIcon" />
						<p>{{ t('sheetmusic', 'No sub-libraries configured yet. Create a sub-library to share filtered score subsets.') }}</p>
						<NcButton
							variant="secondary"
							size="small"
							@click="emit('open-sublibraries')">
							<template #icon>
								<Plus :size="16" />
							</template>
							{{ t('sheetmusic', 'Go to Sub-Libraries') }}
						</NcButton>
					</div>

					<!-- Sub-libraries List -->
					<div v-else :class="$style.sublibrariesGrid">
						<div
							v-for="sub in overview.sublibraries"
							:key="sub.id"
							:class="$style.card"
							:data-test="`sublibrary-card-${sub.slug}`">
							<div :class="$style.cardHeader">
								<div :class="$style.cardTitleGroup">
									<FolderSyncOutline :size="24" :class="$style.subCardIcon" />
									<div>
										<h4 :class="$style.cardTitle">{{ sub.name }}</h4>
										<span :class="$style.cardSubtitle">
											/SubLibraries/{{ sub.slug }}
										</span>
									</div>
								</div>
								<NcButton
									variant="secondary"
									size="small"
									@click="openSublibraryShare(sub)">
									<template #icon>
										<ShareVariantOutline :size="18" />
									</template>
									{{ t('sheetmusic', 'Share') }}
								</NcButton>
							</div>

							<!-- Filter criteria tags -->
							<div v-if="formatFilterTags(sub.filters).length > 0" :class="$style.filterTags">
								<span
									v-for="tag in formatFilterTags(sub.filters)"
									:key="tag"
									:class="$style.filterTag">
									{{ tag }}
								</span>
							</div>

							<!-- Shares list for this sub-library -->
							<div v-if="sub.shares.length === 0" :class="$style.emptyCardState">
								<p>{{ t('sheetmusic', 'No shares for this sub-library yet.') }}</p>
							</div>
							<ul v-else :class="$style.sharesList">
								<li
									v-for="share in sub.shares"
									:key="share.id"
									:class="$style.shareRow">
									<div :class="$style.shareInfo">
										<component
											:is="share.shareType === 3 ? LinkVariant : (share.shareType === 1 ? AccountGroupOutline : AccountOutline)"
											:size="20"
											:class="$style.shareTypeIcon" />
										<div :class="$style.shareDetails">
											<span :class="$style.shareTarget">
												{{ share.shareType === 3 ? t('sheetmusic', 'Public Link (MobileSheets sync)') : (share.shareWithDisplayName || share.shareWith) }}
											</span>
											<div :class="$style.shareBadges">
												<span :class="[$style.badge, share.canWrite ? $style.badgeWrite : $style.badgeRead]">
													{{ share.canWrite ? t('sheetmusic', 'Read & Write') : t('sheetmusic', 'Read-only') }}
												</span>
												<span v-if="share.shareType === 1" :class="$style.badgeGroup">
													{{ t('sheetmusic', 'Group') }}
												</span>
											</div>
										</div>
									</div>

									<div :class="$style.shareActions">
										<NcButton
											v-if="share.publicUrl || share.webdavUrl"
											variant="secondary"
											size="small"
											:title="t('sheetmusic', 'Copy sync URL')"
											:aria-label="t('sheetmusic', 'Copy sync URL')"
											@click="copyToClipboard(share.webdavUrl || share.publicUrl || '', String(share.id))">
											<template #icon>
												<Check v-if="copiedToken === String(share.id)" :size="16" />
												<ContentCopy v-else :size="16" />
											</template>
											{{ copiedToken === String(share.id) ? t('sheetmusic', 'Copied') : t('sheetmusic', 'Copy link') }}
										</NcButton>

										<NcButton
											variant="error"
											size="small"
											:disabled="deletingShareId === share.id"
											:title="t('sheetmusic', 'Delete share')"
											:aria-label="t('sheetmusic', 'Delete share')"
											@click="deleteShare(share, sub.id)">
											<template #icon>
												<DeleteOutline :size="18" />
											</template>
										</NcButton>
									</div>
								</li>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Unified Share Dialog -->
		<ShareDialog
			v-if="isShareDialogOpen"
			:library-id="selectedLibrary"
			:library-name="libraryDisplayName"
			:sublibrary="activeSublibrary"
			:sublibrary-path="activeSublibrary ? `${overview?.library.path}/SubLibraries/${activeSublibrary.slug}` : ''"
			@close="onShareDialogClose" />
	</div>
</template>

<style module>
.wrapper {
	display: flex;
	flex-direction: column;
	height: 100%;
	overflow: hidden;
}

.content {
	flex: 1;
	overflow-y: auto;
	padding: 16px 24px;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.infoBanner {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px 16px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	border: 1px solid var(--color-border);
}

.bannerIcon {
	color: var(--color-primary-element);
	flex-shrink: 0;
	margin-top: 2px;
}

.bannerText {
	font-size: 13px;
	line-height: 1.45;
	color: var(--color-main-text);
}

.bannerText strong {
	display: block;
	margin-bottom: 4px;
	font-size: 14px;
}

.bannerText p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 48px 16px;
	color: var(--color-text-maxcontrast);
}

.error {
	padding: 12px 16px;
	border-radius: var(--border-radius-large);
	background-color: var(--color-error-background, rgba(233, 50, 45, 0.1));
	color: var(--color-error);
	font-size: 14px;
}

.sections {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.emptyTotalBanner {
	display: flex;
	align-items: center;
	gap: 16px;
	padding: 20px 24px;
	background-color: var(--color-main-background);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius-large);
}

.emptyIcon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.emptyBannerContent {
	flex: 1;
}

.emptyBannerContent h3 {
	margin: 0 0 4px 0;
	font-size: 16px;
	font-weight: 600;
}

.emptyBannerContent p {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.emptyBannerActions {
	flex-shrink: 0;
}

.card {
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.cardHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.cardTitleGroup {
	display: flex;
	align-items: center;
	gap: 12px;
}

.cardIcon {
	color: var(--color-primary-element);
}

.subCardIcon {
	color: var(--color-text-maxcontrast);
}

.cardTitle {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
	color: var(--color-main-text);
}

.cardSubtitle {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
}

.emptyCardState {
	padding: 12px 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-style: italic;
}

.emptyCardState p {
	margin: 0;
}

.sharesList {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.shareRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	gap: 12px;
}

.shareInfo {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

.shareTypeIcon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.shareDetails {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.shareTarget {
	font-weight: 500;
	font-size: 14px;
	color: var(--color-main-text);
}

.shareBadges {
	display: flex;
	align-items: center;
	gap: 6px;
}

.badge {
	font-size: 11px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 10px;
	text-transform: uppercase;
}

.badgeRead {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.badgeWrite {
	background-color: rgba(0, 130, 200, 0.15);
	color: var(--color-primary-element);
}

.badgeGroup {
	font-size: 11px;
	padding: 2px 6px;
	border-radius: 10px;
	background-color: var(--color-background-darker, rgba(0, 0, 0, 0.08));
	color: var(--color-text-maxcontrast);
}

.shareActions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}

.sublibrariesSection {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.subSectionHeader {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
}

.subSectionTitle {
	margin: 0 0 2px 0;
	font-size: 16px;
	font-weight: 600;
}

.subSectionDesc {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.noSublibrariesCard {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 32px 16px;
	background-color: var(--color-main-background);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-align: center;
}

.noSublibrariesCard p {
	margin: 0;
	max-width: 420px;
}

.sublibrariesGrid {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.filterTags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.filterTag {
	font-size: 11px;
	background-color: var(--color-background-dark);
	padding: 2px 8px;
	border-radius: var(--border-radius);
	color: var(--color-text-maxcontrast);
}
</style>
