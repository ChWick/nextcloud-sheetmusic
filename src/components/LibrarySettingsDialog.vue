<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import type { Library } from '../types/library'

const props = defineProps<{
	selectedLibrary: number
	libraries: Library[]
}>()

const emit = defineEmits<{
	close: []
	'update:libraries': [libraries: Library[]]
}>()

const isSaving = ref(false)
const saveError = ref('')
const currentLibrary = computed(() => props.libraries.find(lib => lib.id === props.selectedLibrary))

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

async function updateSetting(settings: { autoPaths?: boolean, autoPdfMetadata?: boolean }) {
	if (props.selectedLibrary < 0) return
	isSaving.value = true
	saveError.value = ''
	try {
		const response = await axios.put(
			`/ocs/v2.php/apps/mobilesheetsmanager/api/libraries/${props.selectedLibrary}/settings`,
			settings,
			{ headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } },
		)
		const updatedLib = response.data?.ocs?.data?.library as Library | undefined
		if (updatedLib) {
			const updatedList = props.libraries.map(lib => lib.id === updatedLib.id ? { ...lib, ...updatedLib } : lib)
			emit('update:libraries', updatedList)
		}
	} catch (error) {
		console.error('Failed to update library settings:', error)
		saveError.value = t('mobilesheetsmanager', 'Could not save library settings.')
	} finally {
		isSaving.value = false
	}
}
</script>

<template>
	<NcDialog
		:name="t('mobilesheetsmanager', 'Library Settings')"
		size="normal"
		@close="emit('close')">
		<div :class="$style.dialogBody">
			<h4 :class="$style.sectionTitle">
				{{ t('mobilesheetsmanager', 'File Management') }}
			</h4>

			<div :class="$style.settingRow">
				<NcCheckboxRadioSwitch
					v-model="autoPathsModel"
					type="switch"
					:disabled="isSaving">
					{{ t('mobilesheetsmanager', 'Automatic file path handling') }}
				</NcCheckboxRadioSwitch>
				<NcLoadingIcon v-if="isSaving" :size="20" :class="$style.loading" />
			</div>

			<p :class="$style.description">
				{{ t('mobilesheetsmanager', 'When enabled, files are automatically organized and moved to subfolders based on song metadata (composer, artist, collection). When disabled, files remain in the library root folder and paths are never automatically changed.') }}
			</p>

			<h4 :class="[$style.sectionTitle, $style.sectionMargin]">
				{{ t('mobilesheetsmanager', 'PDF Metadata') }}
			</h4>

			<div :class="$style.settingRow">
				<NcCheckboxRadioSwitch
					v-model="autoPdfMetadataModel"
					type="switch"
					:disabled="isSaving">
					{{ t('mobilesheetsmanager', 'Automatic PDF metadata synchronization') }}
				</NcCheckboxRadioSwitch>
				<NcLoadingIcon v-if="isSaving" :size="20" :class="$style.loading" />
			</div>

			<p :class="$style.description">
				{{ t('mobilesheetsmanager', 'When enabled, song metadata is automatically written to attached PDF files when saving songs. When disabled, PDF files are left unmodified.') }}
			</p>

			<p v-if="saveError" :class="$style.error" role="alert">
				{{ saveError }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="emit('close')">
				{{ t('mobilesheetsmanager', 'Close') }}
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

.error {
	color: var(--color-error);
	margin-top: 10px;
}
</style>

