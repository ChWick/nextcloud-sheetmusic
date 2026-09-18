<script setup lang="ts">
import type { INode } from '@nextcloud/files'

import axios from '@nextcloud/axios'
import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { onMounted, ref } from 'vue'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'

const isDialogOpen = ref(false)
const selectedFile = ref<INode>()
const libraries = ref<string[]>([])
const fileError = ref('')

onMounted(async () => {
	try {
		const response = await axios.get(generateOcsUrl('/apps/mobilesheetsmanager/api/libraries'))
		libraries.value = response.data.ocs.data.libraries
	} catch {
		fileError.value = t('mobilesheetsmanager', 'Could not load your MobileSheets libraries.')
	}
})

function openDialog() {
	selectedFile.value = undefined
	fileError.value = ''
	isDialogOpen.value = true
}

async function openFilePicker() {
	fileError.value = ''

	const picker = getFilePickerBuilder(t('mobilesheetsmanager', 'Select a MobileSheets file'))
		.setMultiSelect(false)
		.setFilter((node) => node.type !== 'file' || node.basename.toLowerCase().endsWith('.db'))
		.setCanPick((node) => node.type === 'file' && node.basename.toLowerCase().endsWith('.db'))
		.setButtonFactory(([node]) => [{
			label: node
				? t('mobilesheetsmanager', 'Select {file}', { file: node.basename })
				: t('mobilesheetsmanager', 'Select a .db file'),
			callback: () => {},
			disabled: !node || node.type !== 'file' || !node.basename.toLowerCase().endsWith('.db'),
			variant: 'primary',
		}])
		.build()

	try {
		[selectedFile.value] = await picker.pickNodes()
	} catch (error) {
		if (!(error instanceof FilePickerClosed)) {
			fileError.value = t('mobilesheetsmanager', 'Could not select a MobileSheets file.')
		}
	}
}

async function addLibrary() {
	if (!selectedFile.value) {
		fileError.value = t('mobilesheetsmanager', 'Please select a .db file first.')
		return
	}

	try {
		const response = await axios.post(generateOcsUrl('/apps/mobilesheetsmanager/api/libraries'), {
			path: selectedFile.value.path,
		})
		if (!libraries.value.includes(response.data.ocs.data.path)) {
			libraries.value.push(response.data.ocs.data.path)
		}
		selectedFile.value = undefined
		isDialogOpen.value = false
	} catch {
		fileError.value = t('mobilesheetsmanager', 'Could not save the MobileSheets library.')
	}
}
</script>

<template>
	<NcContent app-name="mobilesheetsmanager">
		<NcAppNavigation :aria-label="t('mobilesheetsmanager', 'MobileSheets libraries')">
			<ul :class="$style.libraryList">
				<li v-for="library in libraries" :key="library" :class="$style.libraryItem">
					{{ library }}
				</li>
			</ul>

			<template #footer>
				<NcAppNavigationItem
					:name="t('mobilesheetsmanager', 'Add MobileSheets Library')"
					@click="openDialog">
					<template #icon>
						<span aria-hidden="true">+</span>
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent :class="$style.content">
			<h2>{{ t('mobilesheetsmanager', 'MobileSheets Manager') }}</h2>
		</NcAppContent>

		<NcDialog
			v-if="isDialogOpen"
			:name="t('mobilesheetsmanager', 'Add MobileSheets Library')"
			@close="isDialogOpen = false">
			<p>{{ t('mobilesheetsmanager', 'Select a MobileSheets .db file from your Nextcloud files.') }}</p>
			<NcButton variant="primary" @click="openFilePicker">
				{{ selectedFile?.path ?? t('mobilesheetsmanager', 'Choose .db file from Nextcloud') }}
			</NcButton>
			<p v-if="fileError" :class="$style.error" role="alert">{{ fileError }}</p>
			<div :class="$style.dialogActions">
				<NcButton @click="isDialogOpen = false">{{ t('mobilesheetsmanager', 'Cancel') }}</NcButton>
				<NcButton variant="primary" :disabled="!selectedFile" @click="addLibrary">
					{{ t('mobilesheetsmanager', 'Add library') }}
				</NcButton>
			</div>
		</NcDialog>

	</NcContent>
</template>

<style module>
.libraryList {
	margin: 0;
	padding: 0;
}

.libraryItem {
	padding: 8px 16px;
	word-break: break-word;
}

.content {
	display: flex;
	justify-content: center;
	margin: 16px;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
}

.error {
	color: var(--color-error);
}
</style>
