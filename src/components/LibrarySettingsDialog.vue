<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelectUsers, { type NcSelectUsersModel } from '@nextcloud/vue/components/NcSelectUsers'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FolderSyncOutline from 'vue-material-design-icons/FolderSyncOutline.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import { describeRequestFailure } from '../utils/api'
import type { Library, LibraryShare } from '../types/library'

const props = defineProps<{
	selectedLibrary: number
	libraries: Library[]
}>()

const emit = defineEmits<{
	close: []
	delete: []
	'update:libraries': [libraries: Library[]]
	'open-sublibraries': []
}>()

const isSaving = ref(false)
const saveError = ref('')
const currentLibrary = computed(() => props.libraries.find(lib => lib.id === props.selectedLibrary))
const isSharedLibrary = computed(() => Boolean(currentLibrary.value?.isShared))

// Sharing state
interface ShareeOption extends NcSelectUsersModel {
	shareType: number
	shareWith: string
}

const shares = ref<LibraryShare[]>([])
const isLoadingShares = ref(false)
const shareError = ref('')
const newSharePermission = ref<'read' | 'write'>('read')
const isCreatingShare = ref(false)
const copiedToken = ref<string | null>(null)

const shareeOptions = ref<ShareeOption[]>([])
const selectedSharee = ref<ShareeOption | null>(null)
const isSearchingSharees = ref(false)
const currentShareSearch = ref('')
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null

const autoPathsModel = computed({
	get: () => currentLibrary.value?.autoPaths ?? true,
	set: (val: boolean) => {
		updateSetting({ autoPaths: val })
	},
})

const autoPdfMetadataModel = computed({
	get: () => currentLibrary.value?.autoPdfMetadata ?? true,
	set: (val: boolean) => {
		updateSetting({ autoPdfMetadata: val })
	},
})

async function updateSetting(settings: { autoPaths?: boolean; autoPdfMetadata?: boolean }) {
	isSaving.value = true
	saveError.value = ''
	try {
		const response = await axios.put(
			`/ocs/v2.php/apps/sheetmusic/api/libraries/${props.selectedLibrary}/settings`,
			settings,
			{
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
				},
			},
		)
		const updatedLibrary = response.data?.ocs?.data?.library as Library | undefined
		if (updatedLibrary) {
			const updatedList = props.libraries.map(lib =>
				lib.id === updatedLibrary.id ? { ...lib, ...updatedLibrary } : lib,
			)
			emit('update:libraries', updatedList)
		}
	} catch (error) {
		console.error('Failed to update library settings:', error)
		saveError.value = describeRequestFailure(error, t('sheetmusic', 'Could not save library settings.'))
	} finally {
		isSaving.value = false
	}
}

async function loadShares() {
	if (isSharedLibrary.value) {
		return
	}
	isLoadingShares.value = true
	shareError.value = ''
	try {
		const response = await axios.get(
			`/ocs/v2.php/apps/sheetmusic/api/libraries/${props.selectedLibrary}/shares`,
			{
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
				},
			},
		)
		shares.value = response.data?.ocs?.data?.shares ?? []
		await searchSharees(currentShareSearch.value)
	} catch (error) {
		console.error('Failed to load shares:', error)
		shareError.value = describeRequestFailure(error, t('sheetmusic', 'Could not load shares.'))
	} finally {
		isLoadingShares.value = false
	}
}

async function searchSharees(search: string = '') {
	isSearchingSharees.value = true
	try {
		const response = await axios.get('/ocs/v2.php/apps/files_sharing/api/v1/sharees', {
			params: {
				format: 'json',
				itemType: 'folder',
				search,
				perPage: 25,
			},
			headers: {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
			},
		})
		const data = response.data?.ocs?.data ?? {}
		const rawUsers: any[] = [
			...(data.exact?.users ?? []),
			...(data.users ?? []),
		]
		const rawGroups: any[] = [
			...(data.exact?.groups ?? []),
			...(data.groups ?? []),
		]

		const existingUserShares = new Set(
			shares.value
				.filter(s => s.shareType === 0)
				.map(s => s.shareWith.toLowerCase()),
		)
		const existingGroupShares = new Set(
			shares.value
				.filter(s => s.shareType === 1)
				.map(s => s.shareWith.toLowerCase()),
		)

		const results: ShareeOption[] = []
		const seenIds = new Set<string>()

		for (const u of rawUsers) {
			const shareWith = u.value?.shareWith || u.label
			const id = `user:${shareWith}`
			if (!shareWith || seenIds.has(id)) continue
			seenIds.add(id)

			if (existingUserShares.has(shareWith.toLowerCase())) continue
			if (currentLibrary.value?.ownerId && currentLibrary.value.ownerId.toLowerCase() === shareWith.toLowerCase()) continue

			results.push({
				id,
				displayName: u.label || shareWith,
				user: shareWith,
				subname: u.subline || (u.shareWithDisplayNameUnique && u.shareWithDisplayNameUnique !== u.label ? `@${shareWith}` : ''),
				isNoUser: false,
				shareType: 0,
				shareWith,
			})
		}

		for (const g of rawGroups) {
			const shareWith = g.value?.shareWith || g.label
			const id = `group:${shareWith}`
			if (!shareWith || seenIds.has(id)) continue
			seenIds.add(id)

			if (existingGroupShares.has(shareWith.toLowerCase())) continue

			results.push({
				id,
				displayName: g.label || shareWith,
				subname: t('sheetmusic', 'Group'),
				isNoUser: true,
				shareType: 1,
				shareWith,
			})
		}

		shareeOptions.value = results
	} catch (error) {
		console.error('Failed to search sharees:', error)
	} finally {
		isSearchingSharees.value = false
	}
}

function onSearchSharees(query: string) {
	currentShareSearch.value = query
	if (searchDebounceTimer) {
		clearTimeout(searchDebounceTimer)
	}
	searchDebounceTimer = setTimeout(() => {
		searchSharees(query)
	}, 200)
}

function createShareeOption(val: string): ShareeOption {
	const trimmed = val.trim()
	return {
		id: `user:${trimmed}`,
		displayName: trimmed,
		user: trimmed,
		subname: '',
		isNoUser: false,
		shareType: 0,
		shareWith: trimmed,
	}
}

async function addShare(shareType: 'user' | 'link' = 'user') {
	let targetShareWith = ''
	let actualShareType: number = 0

	if (shareType === 'link') {
		actualShareType = 3
	} else if (selectedSharee.value) {
		targetShareWith = selectedSharee.value.shareWith
		actualShareType = selectedSharee.value.shareType
	} else if (currentShareSearch.value.trim()) {
		targetShareWith = currentShareSearch.value.trim()
		actualShareType = 0
	} else {
		return
	}

	isCreatingShare.value = true
	shareError.value = ''
	try {
		const payload = {
			shareWith: targetShareWith,
			shareType: actualShareType,
			permission: newSharePermission.value,
		}
		const response = await axios.post(
			`/ocs/v2.php/apps/sheetmusic/api/libraries/${props.selectedLibrary}/shares`,
			payload,
			{
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
				},
			},
		)
		const created = response.data?.ocs?.data as LibraryShare | undefined
		if (created) {
			shares.value = [...shares.value, created]
			selectedSharee.value = null
			currentShareSearch.value = ''
			await searchSharees('')
		} else {
			await loadShares()
			selectedSharee.value = null
			currentShareSearch.value = ''
			await searchSharees('')
		}
	} catch (error) {
		console.error('Failed to create share:', error)
		shareError.value = describeRequestFailure(error, t('sheetmusic', 'Could not share library.'))
	} finally {
		isCreatingShare.value = false
	}
}

async function updateSharePermission(share: LibraryShare, newPerm: 'read' | 'write') {
	try {
		const response = await axios.put(
			`/ocs/v2.php/apps/sheetmusic/api/libraries/${props.selectedLibrary}/shares/${encodeURIComponent(share.id)}`,
			{ permission: newPerm },
			{
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
				},
			},
		)
		const updated = response.data?.ocs?.data as LibraryShare | undefined
		if (updated) {
			shares.value = shares.value.map(s => s.id === share.id ? { ...s, ...updated } : s)
		} else {
			share.permission = newPerm
			share.canWrite = newPerm === 'write'
		}
	} catch (error) {
		console.error('Failed to update share permission:', error)
		shareError.value = describeRequestFailure(error, t('sheetmusic', 'Could not update share permission.'))
	}
}

async function deleteShare(share: LibraryShare) {
	try {
		await axios.delete(
			`/ocs/v2.php/apps/sheetmusic/api/libraries/${props.selectedLibrary}/shares/${encodeURIComponent(share.id)}`,
			{
				headers: {
					'OCS-APIREQUEST': 'true',
					Accept: 'application/json',
				},
			},
		)
		shares.value = shares.value.filter(s => s.id !== share.id)
		await searchSharees(currentShareSearch.value)
	} catch (error) {
		console.error('Failed to remove share:', error)
		shareError.value = describeRequestFailure(error, t('sheetmusic', 'Could not delete share.'))
	}
}

function copyToClipboard(text: string, key: string) {
	if (navigator.clipboard) {
		navigator.clipboard.writeText(text).then(() => {
			copiedToken.value = key
			setTimeout(() => {
				if (copiedToken.value === key) {
					copiedToken.value = null
				}
			}, 2000)
		})
	}
}

function handleDelete() {
	emit('delete')
}

onMounted(() => {
	loadShares()
})
</script>

<template>
	<NcDialog
		:name="t('sheetmusic', 'Library Settings')"
		size="normal"
		@close="emit('close')">
		<div :class="$style.dialogBody">
			<!-- Shared library banner if current user is not the owner -->
			<div v-if="isSharedLibrary" :class="$style.sharedBanner">
				<div :class="$style.sharedHeader">
					<ShareVariantOutline :size="20" />
					<strong>{{ t('sheetmusic', 'Shared Library') }}</strong>
				</div>
				<p :class="$style.sharedText">
					{{ t('sheetmusic', 'This library is shared with you by {owner}.', { owner: currentLibrary?.ownerDisplayName || currentLibrary?.ownerId || 'Nextcloud' }) }}
				</p>
				<div :class="$style.sharedMeta">
					<span :class="[$style.badge, currentLibrary?.canWrite ? $style.badgeWrite : $style.badgeRead]">
						{{ currentLibrary?.canWrite ? t('sheetmusic', 'Read & Write') : t('sheetmusic', 'Read-only') }}
					</span>
				</div>
			</div>

			<!-- Owner Settings -->
			<template v-if="!isSharedLibrary">
				<h4 :class="$style.sectionTitle">
					{{ t('sheetmusic', 'File Management') }}
				</h4>

				<div :class="$style.settingRow">
					<NcCheckboxRadioSwitch
						v-model="autoPathsModel"
						type="switch"
						:disabled="isSaving">
						{{ t('sheetmusic', 'Automatic file and directory organization') }}
					</NcCheckboxRadioSwitch>
					<NcLoadingIcon v-if="isSaving" :size="20" :class="$style.loading" />
				</div>

				<p :class="$style.description">
					{{ t('sheetmusic', 'When enabled, song files are automatically organized into folders by composer and named according to their title. When disabled, files remain in their original locations.') }}
				</p>

				<h4 :class="[$style.sectionTitle, $style.sectionMargin]">
					{{ t('sheetmusic', 'PDF Metadata') }}
				</h4>

				<div :class="$style.settingRow">
					<NcCheckboxRadioSwitch
						v-model="autoPdfMetadataModel"
						type="switch"
						:disabled="isSaving">
						{{ t('sheetmusic', 'Automatic PDF metadata synchronization') }}
					</NcCheckboxRadioSwitch>
					<NcLoadingIcon v-if="isSaving" :size="20" :class="$style.loading" />
				</div>

				<p :class="$style.description">
					{{ t('sheetmusic', 'When enabled, song metadata is automatically written to attached PDF files when saving songs. When disabled, PDF files are left unmodified.') }}
				</p>

				<h4 :class="[$style.sectionTitle, $style.sectionMargin]">
					{{ t('sheetmusic', 'Sub-Libraries (MobileSheets Sync)') }}
				</h4>
				<p :class="$style.description">
					{{ t('sheetmusic', 'Define filtered sub-libraries (e.g. by instrument or composer) that expose virtual WebDAV folders for MobileSheets sync.') }}
				</p>

				<NcButton
					type="button"
					:class="$style.openSublibBtn"
					@click="emit('open-sublibraries')">
					<template #icon>
						<FolderSyncOutline :size="18" />
					</template>
					{{ t('sheetmusic', 'Manage Sub-Libraries') }}
				</NcButton>

				<!-- Sharing Section -->
				<h4 :class="[$style.sectionTitle, $style.sectionMargin]">
					{{ t('sheetmusic', 'Sharing & Synchronization') }}
				</h4>
				<p :class="$style.description">
					{{ t('sheetmusic', 'Share this library with other Nextcloud users or create an external public link for MobileSheets sync.') }}
				</p>

				<div :class="$style.addShareRow">
					<div :class="$style.shareUserInputWrapper">
						<NcSelectUsers
							v-model="selectedSharee"
							:options="shareeOptions"
							:loading="isSearchingSharees"
							:placeholder="t('sheetmusic', 'Search for user or group…')"
							:input-label="t('sheetmusic', 'User or group')"
							:label-outside="true"
							:disabled="isCreatingShare"
							:taggable="true"
							:create-option="createShareeOption"
							@search="onSearchSharees" />
					</div>
					<select v-model="newSharePermission" :class="$style.permSelect" :disabled="isCreatingShare">
						<option value="read">{{ t('sheetmusic', 'Read-only') }}</option>
						<option value="write">{{ t('sheetmusic', 'Read & Write') }}</option>
					</select>
					<NcButton
						variant="primary"
						:disabled="(!selectedSharee && !currentShareSearch.trim()) || isCreatingShare"
						@click="addShare('user')">
						{{ t('sheetmusic', 'Share') }}
					</NcButton>
				</div>

				<div :class="$style.linkShareRow">
					<NcButton
						variant="secondary"
						:disabled="isCreatingShare"
						@click="addShare('link')">
						<template #icon>
							<LinkVariant :size="18" />
						</template>
						{{ t('sheetmusic', 'Create Public Sync Link') }}
					</NcButton>
				</div>

				<NcLoadingIcon v-if="isLoadingShares" :size="24" :class="$style.shareLoading" />

				<div v-if="shares.length > 0" :class="$style.sharesList">
					<div v-for="share in shares" :key="share.id" :class="$style.shareItem">
						<div :class="$style.shareItemInfo">
							<component :is="share.shareType === 3 ? LinkVariant : (share.shareType === 1 ? AccountGroupOutline : AccountOutline)" :size="18" :class="$style.shareIcon" />
							<div :class="$style.shareItemDetails">
								<span :class="$style.shareName">
									{{ share.shareType === 3 ? t('sheetmusic', 'Public Link Share') : (share.shareWithDisplayName || share.shareWith) }}
								</span>
								<span v-if="share.shareType === 1" :class="$style.shareUserSub">
									{{ t('sheetmusic', 'Group') }}
								</span>
								<span v-else-if="share.shareType === 0 && share.shareWith" :class="$style.shareUserSub">
									@{{ share.shareWith }}
								</span>
								<span v-if="share.publicUrl" :class="$style.webdavUrlText" :title="share.publicUrl">
									{{ t('sheetmusic', 'Browser:') }} {{ share.publicUrl }}
								</span>
								<span v-if="share.webdavUrl" :class="$style.webdavUrlText" :title="share.webdavUrl">
									{{ t('sheetmusic', 'WebDAV:') }} {{ share.webdavUrl }}
								</span>
							</div>
						</div>

						<div :class="$style.shareItemActions">
							<template v-if="share.shareType !== 3">
								<select
									:value="share.permission"
									:class="$style.permSelectSmall"
									@change="updateSharePermission(share, ($event.target as HTMLSelectElement).value as 'read' | 'write')">
									<option value="read">{{ t('sheetmusic', 'Read-only') }}</option>
									<option value="write">{{ t('sheetmusic', 'Read & Write') }}</option>
								</select>
							</template>
							<template v-if="share.shareType === 3 && share.publicUrl">
								<NcButton
									size="small"
									variant="secondary"
									:title="t('sheetmusic', 'Copy Public Browser Link')"
									@click="copyToClipboard(share.publicUrl, 'pub_' + share.id)">
									<template #icon>
										<Check v-if="copiedToken === 'pub_' + share.id" :size="16" />
										<ContentCopy v-else :size="16" />
									</template>
									{{ copiedToken === 'pub_' + share.id ? t('sheetmusic', 'Copied!') : t('sheetmusic', 'Copy Link') }}
								</NcButton>
							</template>
							<template v-if="share.webdavUrl">
								<NcButton
									size="small"
									variant="secondary"
									:title="t('sheetmusic', 'Copy MobileSheets WebDAV URL')"
									@click="copyToClipboard(share.webdavUrl, 'dav_' + share.id)">
									<template #icon>
										<Check v-if="copiedToken === 'dav_' + share.id" :size="16" />
										<ContentCopy v-else :size="16" />
									</template>
									{{ copiedToken === 'dav_' + share.id ? t('sheetmusic', 'Copied!') : t('sheetmusic', 'Copy WebDAV URL') }}
								</NcButton>
							</template>
							<NcButton
								size="small"
								variant="error"
								:title="t('sheetmusic', 'Revoke share')"
								@click="deleteShare(share)">
								<template #icon>
									<DeleteOutline :size="18" />
								</template>
							</NcButton>
						</div>
					</div>
				</div>

				<p v-if="shareError" :class="$style.error" role="alert">
					{{ shareError }}
				</p>

				<h4 :class="[$style.sectionTitle, $style.dangerSectionMargin]">
					{{ t('sheetmusic', 'Danger Zone') }}
				</h4>

				<div :class="$style.dangerBox">
					<div :class="$style.dangerInfo">
						<strong>{{ t('sheetmusic', 'Delete library') }}</strong>
						<p :class="$style.dangerDescription">
							{{ t('sheetmusic', 'Remove this library from Sheet Music. Files and databases in Nextcloud will not be deleted.') }}
						</p>
					</div>
					<NcButton
						type="button"
						variant="error"
						@click="handleDelete">
						<template #icon>
							<TrashCanOutline :size="20" />
						</template>
						{{ t('sheetmusic', 'Delete library') }}
					</NcButton>
				</div>
			</template>

			<p v-if="saveError" :class="$style.error" role="alert">
				{{ saveError }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="emit('close')">
				{{ t('sheetmusic', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.dialogBody {
	padding: 8px 0;
}

.sectionTitle {
	margin: 0 0 12px 0;
	font-size: 1rem;
	font-weight: 600;
}

.sectionMargin {
	margin-top: 20px;
}

.sharedBanner {
	padding: 14px 16px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	margin-bottom: 12px;
}

.sharedHeader {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 1rem;
	margin-bottom: 6px;
}

.sharedText {
	margin: 4px 0 10px 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.sharedMeta {
	display: flex;
	gap: 8px;
}

.badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.8rem;
	font-weight: 600;
}

.badgeWrite {
	background-color: var(--color-success-background, #e6f4ea);
	color: var(--color-success-text, #137333);
}

.badgeRead {
	background-color: var(--color-primary-light, #e8f0fe);
	color: var(--color-primary-element, #1a73e8);
}

.addShareRow {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 10px;
}

.shareUserInputWrapper {
	flex: 1;
	min-width: 0;
}

.linkShareRow {
	margin-top: 10px;
}

.permSelect {
	padding: 6px 10px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.9rem;
	height: 36px;
}

.permSelectSmall {
	padding: 3px 6px;
	border-radius: var(--border-radius-pill, 12px);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.8rem;
}

.shareLoading {
	margin: 12px auto;
	display: block;
}

.sharesList {
	margin-top: 14px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.shareItem {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	border-radius: var(--border-radius, 6px);
	background: var(--color-background-hover);
	gap: 12px;
}

.shareItemInfo {
	display: flex;
	align-items: center;
	gap: 10px;
	flex: 1;
	min-width: 0;
}

.shareIcon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.shareItemDetails {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.shareName {
	font-weight: 500;
	font-size: 0.9rem;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.shareUserSub {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.webdavUrlText {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 320px;
}

.shareItemActions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-shrink: 0;
}

.dangerSectionMargin {
	margin-top: 28px;
	color: var(--color-error);
}

.dangerBox {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	padding: 12px 16px;
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
}

.dangerInfo {
	flex: 1;
}

.dangerDescription {
	margin: 4px 0 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	line-height: 1.3;
}

.settingRow {
	display: flex;
	align-items: center;
	gap: 12px;
}

.loading {
	margin-inline-start: 8px;
}

.description {
	margin-top: 10px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	line-height: 1.4;
}

.openSublibBtn {
	margin-top: 8px;
}

.error {
	color: var(--color-error);
	margin-top: 10px;
}
</style>
