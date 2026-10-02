<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

defineProps<{
	isRunning: boolean
	disabled: boolean
}>()

const emit = defineEmits<{
	sync: []
	'audit-integrity': []
}>()
</script>

<template>
	<div :class="$style.cardsContainer">
		<!-- Sync Tool Card -->
		<section :class="$style.toolCard">
			<h3 :class="$style.toolTitle">{{ t('mobilesheetsmanager', 'Sync file paths and metadata') }}</h3>
			<p :class="$style.toolIntro">
				{{ t('mobilesheetsmanager', 'Checks the whole library: every file is moved to the path its song’s naming rules produce, and every attached PDF gets its song’s document properties - title, composers, artists, books and collections - so it can be imported back into MobileSheets. The report lists everything that was checked, with links to the files that need attention.') }}
			</p>
			<ul :class="$style.toolPoints">
				<li>{{ t('mobilesheetsmanager', 'File paths: moved to their naming-rule location; missing files are reported.') }}</li>
				<li>{{ t('mobilesheetsmanager', 'Document properties: refreshed from the song data; files that already match are left untouched.') }}</li>
				<li>{{ t('mobilesheetsmanager', 'Hash codes and file facts: kept in step with both, so MobileSheets sees the changes.') }}</li>
			</ul>
			<div :class="$style.toolActions">
				<NcButton
					variant="primary"
					:disabled="isRunning || disabled"
					@click="emit('sync')">
					{{ isRunning ? t('mobilesheetsmanager', 'Syncing…') : t('mobilesheetsmanager', 'Check and sync now') }}
				</NcButton>
				<span v-if="disabled" :class="$style.hint">
					{{ t('mobilesheetsmanager', 'Select a library first.') }}
				</span>
			</div>
		</section>

		<!-- Integrity Audit Card -->
		<section :class="$style.toolCard">
			<h3 :class="$style.toolTitle">{{ t('mobilesheetsmanager', 'Library Integrity & Health Audit') }}</h3>
			<p :class="$style.toolIntro">
				{{ t('mobilesheetsmanager', 'Inspects your MobileSheets database and Nextcloud storage for file conflicts and database integrity issues.') }}
			</p>
			<ul :class="$style.toolPoints">
				<li>{{ t('mobilesheetsmanager', 'Duplicate File Paths: detects if multiple database songs/files point to the exact same physical file.') }}</li>
				<li>{{ t('mobilesheetsmanager', 'Missing Files: flags database entries referencing files that no longer exist on storage.') }}</li>
				<li>{{ t('mobilesheetsmanager', 'Naming Rule Mismatches: identifies file paths that do not adhere to standard naming rules.') }}</li>
				<li>{{ t('mobilesheetsmanager', 'Orphan Files: scans Nextcloud storage for files not linked to any song.') }}</li>
			</ul>
			<div :class="$style.toolActions">
				<NcButton
					variant="secondary"
					:disabled="isRunning || disabled"
					@click="emit('audit-integrity')">
					{{ t('mobilesheetsmanager', 'Audit Library Integrity') }}
				</NcButton>
				<span v-if="disabled" :class="$style.hint">
					{{ t('mobilesheetsmanager', 'Select a library first.') }}
				</span>
			</div>
		</section>
	</div>
</template>

<style module>
.cardsContainer {
	display: flex;
	flex-direction: column;
	gap: 24px;
	width: min(720px, 100%);
}

.toolCard {
	width: 100%;
	padding: 24px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
}

.toolTitle {
	margin: 0 0 8px;
	font-size: 1.1rem;
	font-weight: 600;
}

.toolIntro {
	margin: 0;
}

.toolPoints {
	display: grid;
	gap: 4px;
	margin: 12px 0 0;
	padding-left: 20px;
	color: var(--color-text-maxcontrast);
}

.toolActions {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 20px;
}

.hint {
	color: var(--color-text-maxcontrast);
}
</style>
