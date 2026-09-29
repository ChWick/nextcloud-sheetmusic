<script setup lang="ts">
import type { FileLogEntry } from '../../types/song'
import { translate as t } from '@nextcloud/l10n'
import { badgeFor, displayPath, fileLink, messageFor } from '../../utils/file-sync'

defineProps<{
	entry: FileLogEntry
}>()

const emit = defineEmits<{
	openSong: [entry: FileLogEntry]
}>()
</script>

<template>
	<li :class="[$style.entry, $style[entry.level]]">
		<span :class="$style.badge" aria-hidden="true">{{ badgeFor(entry.level) }}</span>
		<div :class="$style.entryBody">
			<div :class="$style.entryHead">
				<button
					v-if="entry.songId > 0"
					type="button"
					:class="$style.songLink"
					:title="t('mobilesheetsmanager', 'Open this song in the editor')"
					@click="emit('openSong', entry)">
					{{ entry.songTitle || '#' + entry.songId }}
				</button>
				<strong v-else>{{ t('mobilesheetsmanager', 'Library') }}</strong>
				<code v-if="displayPath(entry)" :class="$style.path">{{ displayPath(entry) }}</code>
			</div>
			<p :class="$style.message">{{ messageFor(entry) }}</p>
		</div>
		<a
			v-if="fileLink(entry)"
			:href="fileLink(entry)"
			:class="$style.openLink"
			target="_blank"
			rel="noopener">
			{{ t('mobilesheetsmanager', 'Open in Files') }}
		</a>
	</li>
</template>

<style module>
.entry {
	display: flex;
	gap: 10px;
	align-items: flex-start;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-left-width: 3px;
	border-radius: var(--border-radius, 8px);
}

.entry.info {
	border-left-color: var(--color-border-maxcontrast);
}

.entry.warning {
	border-left-color: var(--color-warning);
}

.entry.error {
	border-left-color: var(--color-error);
}

.badge {
	width: 1.4em;
	flex-shrink: 0;
	text-align: center;
}

.entry.warning .badge {
	color: var(--color-warning);
}

.entry.error .badge {
	color: var(--color-error);
}

.entry.info .badge {
	color: var(--color-text-maxcontrast);
}

.entryBody {
	min-width: 0;
	flex: 1;
}

.entryHead {
	display: flex;
	gap: 8px;
	align-items: baseline;
	flex-wrap: wrap;
}

.songLink {
	padding: 0;
	border: 0;
	background: none;
	color: inherit;
	font: inherit;
	font-weight: 600;
	text-align: start;
	text-decoration: underline;
	text-underline-offset: 2px;
	cursor: pointer;
}

.path {
	font-family: monospace;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

.message {
	margin: 2px 0 0;
}

.entry.info .message {
	color: var(--color-text-maxcontrast);
}

.entry.warning .message {
	color: var(--color-warning);
}

.entry.error .message {
	color: var(--color-error);
}

.openLink {
	align-self: center;
	margin-inline-start: auto;
	white-space: nowrap;
	font-size: 0.9em;
}
</style>
