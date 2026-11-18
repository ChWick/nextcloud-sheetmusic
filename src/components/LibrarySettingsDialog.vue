<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
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
const isNamingHelpOpen = ref(false)
const currentLibrary = computed(() => props.libraries.find(lib => lib.id === props.selectedLibrary))
const isSharedLibrary = computed(() => Boolean(currentLibrary.value?.isShared))

type BooleanSettingKey = 'autoPaths' | 'autoPdfMetadata' | 'mobilesheetsSyncEnabled'

const booleanSettings = computed<Array<{
	key: BooleanSettingKey
	label: string
	description: string
	value: boolean
}>>(() => [
	{
		key: 'autoPaths',
		label: t('sheetmusic', 'Auto-sync relative file paths'),
		description: t('sheetmusic', 'Automatically update song file paths in the MobileSheets database when files are moved within your Nextcloud storage.'),
		value: currentLibrary.value?.autoPaths ?? true,
	},
	{
		key: 'autoPdfMetadata',
		label: t('sheetmusic', 'Extract PDF metadata automatically'),
		description: t('sheetmusic', 'When importing new songs, attempt to extract titles, composers, and page counts from PDF metadata.'),
		value: currentLibrary.value?.autoPdfMetadata ?? true,
	},
	{
		key: 'mobilesheetsSyncEnabled',
		label: t('sheetmusic', 'Enable MobileSheets WebDAV sync'),
		description: t('sheetmusic', 'Expose virtual mobilesheets.db and mobilesheets_hashcodes.txt files over WebDAV for MobileSheets synchronization.'),
		value: currentLibrary.value?.mobilesheetsSyncEnabled ?? false,
	},
])

const namingRulePresetModel = computed({
	get: () => currentLibrary.value?.namingRulePreset ?? 'auto',
	set: (val: string) => {
		updateSetting({ namingRulePreset: val })
	},
})

const namingPresetOptions = computed(() => [
	{
		id: 'auto',
		label: t('sheetmusic', 'Automatic (smart guess)'),
		description: t('sheetmusic', 'Adapts per song: uses Artist for modern bands, Composer for classical, Collection for songbooks.'),
	},
	{
		id: 'composer',
		label: t('sheetmusic', 'Composer-based'),
		description: t('sheetmusic', 'Always prioritize composer (e.g. Composer / Composer - Book / Title).'),
	},
	{
		id: 'artist',
		label: t('sheetmusic', 'Artist-based'),
		description: t('sheetmusic', 'Always prioritize artist/band (e.g. Artist / Artist - Book / Title).'),
	},
	{
		id: 'collection',
		label: t('sheetmusic', 'Collection / Book-based'),
		description: t('sheetmusic', 'Prioritize book or collection folders.'),
	},
	{
		id: 'disabled',
		label: t('sheetmusic', 'Disabled (flat / no subfolders)'),
		description: t('sheetmusic', 'Uploads stay directly in the library root folder without automatic subfolders or renaming.'),
	},
])

const selectedNamingOption = computed({
	get: () => namingPresetOptions.value.find(opt => opt.id === namingRulePresetModel.value) ?? namingPresetOptions.value[0],
	set: (opt: { id: string } | null) => {
		if (opt?.id) {
			namingRulePresetModel.value = opt.id
		}
	},
})

async function updateSetting(settings: { autoPaths?: boolean; autoPdfMetadata?: boolean; namingRulePreset?: string; mobilesheetsSyncEnabled?: boolean }) {
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
					{{ t('sheetmusic', 'This library is shared with you by {owner}.', { owner: currentLibrary?.ownerDisplayName || currentLibrary?.ownerId || 'Nextcloud' }, { escape: false }) }}
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

				<div
					v-for="setting in booleanSettings"
					:key="setting.key"
					:class="$style.settingRow">
					<NcCheckboxRadioSwitch
						:checked="setting.value"
						type="checkbox"
						:disabled="isSaving"
						@update:checked="updateSetting({ [setting.key]: $event })">
						{{ setting.label }}
					</NcCheckboxRadioSwitch>
					<p :class="$style.settingDescription">
						{{ setting.description }}
					</p>
				</div>

				<div :class="$style.settingRow">
					<div :class="$style.settingHeaderWithHelp">
						<label :class="$style.settingLabel">
							{{ t('sheetmusic', 'Song file organization & naming rule') }}
						</label>
						<NcButton
							type="button"
							variant="tertiary"
							size="small"
							:class="$style.helpToggleBtn"
							@click="isNamingHelpOpen = !isNamingHelpOpen">
							<template #icon>
								<HelpCircleOutline :size="16" />
							</template>
							{{ isNamingHelpOpen ? t('sheetmusic', 'Hide help') : t('sheetmusic', 'Help & examples') }}
						</NcButton>
					</div>
					<NcSelect
						v-model="selectedNamingOption"
						:options="namingPresetOptions"
						:clearable="false"
						:disabled="isSaving" />
					<p :class="$style.settingSelectDescription">
						{{ selectedNamingOption?.description }}
					</p>

					<!-- Collapsible Help Guide -->
					<div v-if="isNamingHelpOpen" :class="$style.namingHelpBox">
						<h5 :class="$style.namingHelpTitle">
							{{ t('sheetmusic', 'Understanding File Organization & Naming Rules') }}
						</h5>
						<p :class="$style.namingHelpIntro">
							{{ t('sheetmusic', 'Sheet Music can keep song files organized in consistent folders based on your song metadata, keeping physical files and MobileSheets sync in harmony.') }}
						</p>

						<div :class="$style.presetHelpItem">
							<div :class="$style.presetHelpHeader">
								<strong>{{ t('sheetmusic', 'Automatic (smart guess) [Recommended]') }}</strong>
							</div>
							<p :class="$style.presetHelpDesc">
								{{ t('sheetmusic', 'Intelligently determines the best folder structure for each individual song:') }}
							</p>
							<ul :class="$style.presetHelpList">
								<li>{{ t('sheetmusic', 'Modern band / pop songs: grouped by Artist (e.g. The Beatles/The Beatles - Abbey Road/The Beatles - Abbey Road - Come Together.pdf)') }}</li>
								<li>{{ t('sheetmusic', 'Classical music (detected by Opus, catalog numbers, or orchestra performers): grouped by Composer (e.g. Beethoven/Beethoven - Sonatas/Beethoven - Sonatas - Moonlight Sonata.pdf)') }}</li>
								<li>{{ t('sheetmusic', 'Songbooks without artist/composer: grouped by Book/Collection (e.g. Real Book/Real Book - Autumn Leaves.pdf)') }}</li>
							</ul>
						</div>

						<div :class="$style.presetHelpItem">
							<div :class="$style.presetHelpHeader">
								<strong>{{ t('sheetmusic', 'Composer-based') }}</strong>
							</div>
							<p :class="$style.presetHelpDesc">
								{{ t('sheetmusic', 'Always groups files under the Composer (Composer/Composer - Book/Song.pdf). Falls back to artist only if composer is blank. Ideal for classical, choral, and traditional sheet music.') }}
							</p>
						</div>

						<div :class="$style.presetHelpItem">
							<div :class="$style.presetHelpHeader">
								<strong>{{ t('sheetmusic', 'Artist-based') }}</strong>
							</div>
							<p :class="$style.presetHelpDesc">
								{{ t('sheetmusic', 'Always groups files under the Artist or Band name (Artist/Artist - Book/Song.pdf). Falls back to composer only if artist is blank. Ideal for pop, rock, and modern bands.') }}
							</p>
						</div>

						<div :class="$style.presetHelpItem">
							<div :class="$style.presetHelpHeader">
								<strong>{{ t('sheetmusic', 'Collection / Book-based') }}</strong>
							</div>
							<p :class="$style.presetHelpDesc">
								{{ t('sheetmusic', 'Groups files primarily by Book, Collection, or Anthology name (Book/Book - Title.pdf). Ideal for collections of standards, hymnals, and fake books.') }}
							</p>
						</div>

						<div :class="$style.presetHelpItem">
							<div :class="$style.presetHelpHeader">
								<strong>{{ t('sheetmusic', 'Disabled (flat / no subfolders)') }}</strong>
							</div>
							<p :class="$style.presetHelpDesc">
								{{ t('sheetmusic', 'No automatic folder organization or renaming occurs. Newly uploaded files stay directly in the library root folder (e.g. MyLibrary/Song.pdf), and existing files are left in their current locations. Library integrity checks will not flag naming rules.') }}
							</p>
						</div>
					</div>
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
			:owner-id="currentLibrary?.ownerId"
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

.settingHeaderWithHelp {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.helpToggleBtn {
	margin: 0;
	padding: 0 6px;
	font-size: 0.8rem;
}

.namingHelpBox {
	margin-top: 8px;
	padding: 12px 14px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
	border: 1px solid var(--color-border);
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.namingHelpTitle {
	margin: 0;
	font-size: 0.92rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.namingHelpIntro {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.presetHelpItem {
	padding: 6px 0 2px 0;
	border-top: 1px solid var(--color-border);
}

.presetHelpHeader {
	font-size: 0.88rem;
	color: var(--color-main-text);
	margin-bottom: 2px;
}

.presetHelpDesc {
	margin: 0;
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast);
}

.presetHelpList {
	margin: 4px 0 0 0;
	padding-left: 18px;
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast);
}

.presetHelpList li {
	margin-bottom: 2px;
}

.settingLabel {
	font-weight: 600;
	font-size: 0.9rem;
	margin-bottom: 2px;
	color: var(--color-main-text);
}

.settingSelectDescription {
	margin: 2px 0 0 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
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
