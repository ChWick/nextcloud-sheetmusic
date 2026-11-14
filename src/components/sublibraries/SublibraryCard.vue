<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import FolderSyncOutline from 'vue-material-design-icons/FolderSyncOutline.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import MusicNote from 'vue-material-design-icons/MusicNote.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
import { countMatchingSongs } from '../../utils/sublibrary'
import type { Sublibrary } from '../../types/library'
import type { Song } from '../../types/song'

const props = withDefaults(
	defineProps<{
		sub: Sublibrary
		libraryFolderPath: string
		songs: Song[]
		isCopied: boolean
		canWrite?: boolean
	}>(),
	{
		canWrite: true,
	},
)

const emit = defineEmits<{
	edit: [sub: Sublibrary]
	delete: [sub: Sublibrary]
	share: [sub: Sublibrary]
	copyPath: [slug: string]
	viewSongs: [sub: Sublibrary]
}>()

const relativePath = computed(() => {
	return `${props.libraryFolderPath}/_SubLibraries_/${props.sub.slug}`
})

const matchingCount = computed(() => {
	return countMatchingSongs(props.sub.filters, props.songs)
})
</script>

<template>
	<div :class="$style.card" data-test="sublibrary-card">
		<div :class="$style.cardHeader">
			<div :class="$style.cardTitleArea">
				<FolderSyncOutline :size="22" :class="$style.cardIcon" />
				<div>
					<h3 :class="$style.cardTitle">{{ sub.name }}</h3>
					<span :class="$style.slugBadge">{{ sub.slug }}</span>
				</div>
			</div>
			<div v-if="canWrite !== false" :class="$style.cardActions">
				<NcButton
					type="button"
					variant="tertiary"
					:title="t('sheetmusic', 'Share sub-library')"
					:aria-label="t('sheetmusic', 'Share sub-library')"
					@click="emit('share', sub)">
					<template #icon>
						<ShareVariantOutline :size="18" />
					</template>
				</NcButton>
				<NcButton
					type="button"
					variant="tertiary"
					:title="t('sheetmusic', 'Edit sub-library')"
					:aria-label="t('sheetmusic', 'Edit sub-library')"
					@click="emit('edit', sub)">
					<template #icon>
						<PencilOutline :size="18" />
					</template>
				</NcButton>
				<NcButton
					type="button"
					variant="error"
					:title="t('sheetmusic', 'Delete sub-library')"
					:aria-label="t('sheetmusic', 'Delete sub-library')"
					@click="emit('delete', sub)">
					<template #icon>
						<TrashCanOutline :size="18" />
					</template>
				</NcButton>
			</div>
		</div>

		<!-- WebDAV Path Box -->
		<div :class="$style.pathBox">
			<div :class="$style.pathLabelRow">
				<span :class="$style.pathLabel">{{ t('sheetmusic', 'WebDAV Folder Path for MobileSheets:') }}</span>
				<button
					type="button"
					:class="$style.copyLink"
					@click="emit('copyPath', sub.slug)">
					<component :is="isCopied ? Check : ContentCopy" :size="14" />
					<span>{{ isCopied ? t('sheetmusic', 'Copied!') : t('sheetmusic', 'Copy Path') }}</span>
				</button>
			</div>
			<code :class="$style.pathCode">{{ relativePath }}</code>
		</div>

		<!-- Filter Badges -->
		<div :class="$style.filtersSection">
			<span :class="$style.filtersLabel">{{ t('sheetmusic', 'Filter criteria:') }}</span>
			<div :class="$style.badgeGroup">
				<template v-for="(vals, type) in sub.filters" :key="type">
					<span :class="$style.badge">
						<strong>{{ type }}:</strong> {{ Array.isArray(vals) ? vals.join(', ') : vals }}
					</span>
				</template>
			</div>
		</div>

		<!-- Footer info: Matching song count and direct view button -->
		<div :class="$style.cardFooter">
			<div :class="$style.matchCount">
				<MusicNote :size="16" />
				<span v-if="matchingCount !== null">
					{{ t('sheetmusic', '{count} matching score(s)', { count: matchingCount }, { escape: false }) }}
				</span>
				<span v-else>
					{{ t('sheetmusic', 'Synchronized sub-library') }}
				</span>
			</div>
			<NcButton
				type="button"
				variant="secondary"
				size="small"
				:title="t('sheetmusic', 'View songs in this sub-library')"
				:aria-label="t('sheetmusic', 'View songs in this sub-library')"
				@click="emit('viewSongs', sub)">
				<template #icon>
					<ArrowRight :size="16" />
				</template>
				{{ t('sheetmusic', 'View songs') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.card {
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 10px);
	padding: 18px 20px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
	transition: box-shadow 0.2s ease, border-color 0.2s ease;
}

.card:hover {
	border-color: var(--color-primary-element-light, #0082c9);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.cardHeader {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 14px;
}

.cardTitleArea {
	display: flex;
	align-items: flex-start;
	gap: 12px;
}

.cardIcon {
	color: var(--color-primary-element, #0082c9);
	margin-top: 2px;
}

.cardTitle {
	margin: 0 0 4px 0;
	font-size: 1.15rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.slugBadge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill, 10px);
	background-color: var(--color-background-darker, rgba(0, 0, 0, 0.06));
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: 0.75rem;
}

.cardActions {
	display: flex;
	gap: 4px;
}

.pathBox {
	margin-bottom: 14px;
	padding: 10px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius, 6px);
	border: 1px solid var(--color-border);
}

.pathLabelRow {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 6px;
}

.pathLabel {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.copyLink {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 6px;
	background: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	transition: background-color 0.15s ease, color 0.15s ease;
}

.copyLink:hover {
	background-color: var(--color-background-darker, rgba(0, 0, 0, 0.06));
	color: var(--color-main-text);
}

.pathCode {
	display: block;
	font-family: monospace;
	font-size: 0.85rem;
	padding: 4px 8px;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	color: var(--color-main-text);
	word-break: break-all;
}

.filtersSection {
	margin-bottom: 14px;
	flex: 1;
}

.filtersLabel {
	display: block;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	margin-bottom: 6px;
	font-weight: 500;
}

.badgeGroup {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.badge {
	display: inline-block;
	padding: 3px 10px;
	border-radius: var(--border-radius-pill, 12px);
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.12));
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	font-size: 0.8rem;
}

.cardFooter {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.matchCount {
	display: flex;
	align-items: center;
	gap: 6px;
}
</style>
