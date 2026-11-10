<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelectUsers, { type NcSelectUsersModel } from '@nextcloud/vue/components/NcSelectUsers'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import { describeRequestFailure } from '../utils/api'
import type { Sublibrary, LibraryShare } from '../types/library'

const props = withDefaults(
	defineProps<{
		libraryId: number
		libraryName?: string
		ownerId?: string | null
		sublibrary?: Sublibrary | null
		sublibraryPath?: string
	}>(),
	{
		libraryName: '',
		ownerId: '',
		sublibrary: null,
		sublibraryPath: '',
	},
)

const emit = defineEmits<{
	close: []
}>()

interface ShareeOption extends NcSelectUsersModel {
	shareType: number
	shareWith: string
}

const isSublibrary = computed(() => Boolean(props.sublibrary && props.sublibrary.id))

const dialogTitle = computed(() => {
	if (isSublibrary.value && props.sublibrary) {
		return t('sheetmusic', 'Share sub-library: {name}', { name: props.sublibrary.name })
	}
	const displayName = props.libraryName ? (props.libraryName.endsWith('.db') ? props.libraryName.substring(0, props.libraryName.lastIndexOf('/')) : props.libraryName) : ''
	return displayName
		? t('sheetmusic', 'Share library: {name}', { name: displayName })
		: t('sheetmusic', 'Share library')
})

const infoText = computed(() => {
	if (isSublibrary.value && props.sublibrary) {
		return t('sheetmusic', 'Shares created here grant access only to songs matching the criteria of "{name}".', { name: props.sublibrary.name })
	}
	return t('sheetmusic', 'Share this library with other Nextcloud users or create an external public link for MobileSheets sync.')
})

const sharesEndpoint = computed(() => {
	if (isSublibrary.value && props.sublibrary?.id) {
		return `/ocs/v2.php/apps/sheetmusic/api/libraries/${props.libraryId}/sublibraries/${props.sublibrary.id}/shares`
	}
	return `/ocs/v2.php/apps/sheetmusic/api/libraries/${props.libraryId}/shares`
})

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

const hasLinkShare = computed(() => shares.value.some(s => s.shareType === 3))

async function loadShares() {
	if (props.libraryId < 0) return
	isLoadingShares.value = true
	shareError.value = ''
	try {
		const response = await axios.get(sharesEndpoint.value, {
			headers: {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
			},
		})
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
			if (!shareWith) continue
			const id = `user:${shareWith}`
			if (seenIds.has(id)) continue
			seenIds.add(id)

			if (existingUserShares.has(shareWith.toLowerCase())) continue
			if (props.ownerId && props.ownerId.toLowerCase() === shareWith.toLowerCase()) continue

			results.push({
				id,
				displayName: u.label || shareWith,
				user: shareWith,
				subname: u.subline || (u.shareWithDisplayNameUnique && u.shareWithDisplayNameUnique !== u.label ? `@${shareWith}` : (u.label !== shareWith ? `@${shareWith}` : '')),
				icon: 'icon-user',
				isNoUser: false,
				shareType: 0,
				shareWith,
			})
		}

		for (const g of rawGroups) {
			const shareWith = g.value?.shareWith || g.label
			if (!shareWith) continue
			const id = `group:${shareWith}`
			if (seenIds.has(id)) continue
			seenIds.add(id)

			if (existingGroupShares.has(shareWith.toLowerCase())) continue

			results.push({
				id,
				displayName: g.label || shareWith,
				subname: t('sheetmusic', 'Group'),
				icon: 'icon-group',
				isNoUser: true,
				shareType: 1,
				shareWith,
			})
		}

		shareeOptions.value = results
	} catch (err) {
		console.error('Failed to search sharees:', err)
	} finally {
		isSearchingSharees.value = false
	}
}

function handleShareeSearch(search: string) {
	currentShareSearch.value = search
	if (searchDebounceTimer) {
		clearTimeout(searchDebounceTimer)
	}
	searchDebounceTimer = setTimeout(() => {
		searchSharees(search)
	}, 200)
}

async function addShare(shareType: 'user_or_group' | 'link' = 'user_or_group') {
	let targetShareWith = ''
	let actualShareType = 0

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
		const response = await axios.post(sharesEndpoint.value, payload, {
			headers: {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
			},
		})
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
		shareError.value = describeRequestFailure(error, t('sheetmusic', 'Could not share.'))
	} finally {
		isCreatingShare.value = false
	}
}

async function updateSharePermission(share: LibraryShare, newPerm: 'read' | 'write') {
	try {
		const response = await axios.put(
			`${sharesEndpoint.value}/${encodeURIComponent(share.id)}`,
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
			shares.value = shares.value.map(s => (s.id === share.id ? { ...s, ...updated } : s))
		} else {
			share.permission = newPerm
			share.canWrite = (newPerm === 'write')
		}
	} catch (error) {
		console.error('Failed to update share permission:', error)
		shareError.value = describeRequestFailure(error, t('sheetmusic', 'Could not update share permission.'))
		await loadShares()
	}
}

async function deleteShare(share: LibraryShare) {
	try {
		await axios.delete(`${sharesEndpoint.value}/${encodeURIComponent(share.id)}`, {
			headers: {
				'OCS-APIREQUEST': 'true',
				Accept: 'application/json',
			},
		})
		shares.value = shares.value.filter(s => s.id !== share.id)
		await searchSharees(currentShareSearch.value)
	} catch (error) {
		console.error('Failed to delete share:', error)
		shareError.value = describeRequestFailure(error, t('sheetmusic', 'Could not revoke share.'))
	}
}

async function copyToClipboard(text: string, token: string) {
	try {
		await navigator.clipboard.writeText(text)
		copiedToken.value = token
		setTimeout(() => {
			if (copiedToken.value === token) {
				copiedToken.value = null
			}
		}, 2000)
	} catch {
		// Fallback
	}
}

onMounted(() => {
	loadShares()
})
</script>

<template>
	<NcDialog
		:name="dialogTitle"
		size="normal"
		@update:open="emit('close')">
		<div :class="$style.content">
			<div :class="$style.infoBox">
				<p :class="$style.infoText">
					{{ infoText }}
				</p>
			</div>

			<!-- Add user/group share -->
			<div :class="$style.addShareRow">
				<div :class="$style.shareUserInputWrapper">
					<NcSelectUsers
						v-model="selectedSharee"
						:options="shareeOptions"
						:loading="isSearchingSharees"
						:placeholder="t('sheetmusic', 'Search Nextcloud users or groups…')"
						:input-label="t('sheetmusic', 'User or group')"
						:label-outside="true"
						:disabled="isCreatingShare"
						@search="handleShareeSearch" />
				</div>
				<select
					v-model="newSharePermission"
					:class="$style.permSelect"
					:disabled="isCreatingShare"
					:aria-label="t('sheetmusic', 'Permission')">
					<option value="read">{{ t('sheetmusic', 'Read-only') }}</option>
					<option value="write">{{ t('sheetmusic', 'Read & Write') }}</option>
				</select>
				<NcButton
					type="button"
					variant="primary"
					:disabled="(!selectedSharee && !currentShareSearch.trim()) || isCreatingShare"
					@click="addShare('user_or_group')">
					<template #icon>
						<ShareVariantOutline :size="18" />
					</template>
					{{ t('sheetmusic', 'Share') }}
				</NcButton>
			</div>

			<!-- Link share button -->
			<div v-if="!hasLinkShare" :class="$style.linkShareRow">
				<NcButton
					type="button"
					variant="secondary"
					:disabled="isCreatingShare"
					@click="addShare('link')">
					<template #icon>
						<LinkVariant :size="18" />
					</template>
					{{ t('sheetmusic', 'Create Public Link Share') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="isLoadingShares" :size="24" :class="$style.shareLoading" />

			<!-- Existing shares list -->
			<div v-if="shares.length > 0" :class="$style.sharesList">
				<div v-for="share in shares" :key="share.id" :class="$style.shareItem">
					<div :class="$style.shareItemInfo">
						<component
							:is="share.shareType === 3 ? LinkVariant : (share.shareType === 1 ? AccountGroupOutline : AccountOutline)"
							:size="18"
							:class="$style.shareIcon" />
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
							<span v-if="share.publicUrl" :class="$style.urlText" :title="share.publicUrl">
								{{ t('sheetmusic', 'Browser:') }} {{ share.publicUrl }}
							</span>
							<span v-if="share.webdavUrl" :class="$style.urlText" :title="share.webdavUrl">
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
		</div>

		<template #actions>
			<NcButton @click="emit('close')">
				{{ t('sheetmusic', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style module>
.content {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.infoBox {
	padding: 10px 14px;
	border-radius: var(--border-radius, 6px);
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
}

.infoText {
	margin: 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.addShareRow {
	display: flex;
	align-items: center;
	gap: 8px;
}

.shareUserInputWrapper {
	flex: 1;
	min-width: 0;
}

.linkShareRow {
	display: flex;
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

.urlText {
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
	gap: 6px;
	flex-shrink: 0;
}

.error {
	color: var(--color-error);
	margin: 0;
	font-size: 0.85rem;
}
</style>
