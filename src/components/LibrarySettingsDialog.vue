<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import FolderSyncOutline from 'vue-material-design-icons/FolderSyncOutline.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import ShareDialog from './ShareDialog.vue'
import { describeRequestFailure } from '../utils/api'
import type { Library } from '../types/library'

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
const isShareDialogOpen = ref(false)
const currentLibrary = computed(() => props.libraries.find(lib => lib.id === props.selectedLibrary))
const isSharedLibrary = computed(() => Boolean(currentLibrary.value?.isShared))

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

function handleDelete() {
	emit('delete')
}
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
					{{ t('sheetmusic', 'Library Settings') }}
				</h4>

				<div :class="$style.settingRow">
					<NcCheckboxRadioSwitch
						v-model="autoPathsModel"
						type="checkbox"
						:disabled="isSaving">
						{{ t('sheetmusic', 'Auto-sync relative file paths') }}
					</NcCheckboxRadioSwitch>
					<p :class="$style.settingDescription">
						{{ t('sheetmusic', 'Automatically update song file paths in the MobileSheets database when files are moved within your Nextcloud storage.') }}
					</p>
				</div>

				<div :class="$style.settingRow">
					<NcCheckboxRadioSwitch
						v-model="autoPdfMetadataModel"
						type="checkbox"
						:disabled="isSaving">
						{{ t('sheetmusic', 'Extract PDF metadata automatically') }}
					</NcCheckboxRadioSwitch>
					<p :class="$style.settingDescription">
						{{ t('sheetmusic', 'When importing new songs, attempt to extract titles, composers, and page counts from PDF metadata.') }}
					</p>
				</div>

				<!-- Sub-libraries section -->
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

				<NcButton
					type="button"
					variant="secondary"
					@click="isShareDialogOpen = true">
					<template #icon>
						<ShareVariantOutline :size="18" />
					</template>
					{{ t('sheetmusic', 'Manage Library Shares') }}
				</NcButton>

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

		<!-- Reusable Share Dialog for full library -->
		<ShareDialog
			v-if="isShareDialogOpen"
			:library-id="selectedLibrary"
			:library-name="currentLibrary?.path"
			@close="isShareDialogOpen = false" />

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
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.sectionTitle {
	margin: 0;
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

.settingRow {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 0;
}

.settingDescription,
.description {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	padding-left: 28px;
}

.description {
	padding-left: 0;
	margin-top: 2px;
}

.openSublibBtn {
	align-self: flex-start;
	margin-top: 4px;
}

.dangerSectionMargin {
	margin-top: 28px;
	color: var(--color-error);
}

.dangerBox {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px;
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-error-background, rgba(233, 50, 45, 0.05));
	gap: 16px;
}

.dangerInfo {
	flex: 1;
}

.dangerDescription {
	margin: 4px 0 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error);
	margin-top: 8px;
}
</style>
