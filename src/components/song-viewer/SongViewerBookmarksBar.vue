<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import BookmarkMusicOutline from 'vue-material-design-icons/BookmarkMusicOutline.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import type { Bookmark } from '../../types/song'
import { formatBookmarkLabel } from '../../utils/song-formatter'
import ResponsiveNcButton from '../ResponsiveNcButton.vue'
import ViewportPopover from '../ViewportPopover.vue'

const props = withDefaults(
	defineProps<{
		bookmarks: Bookmark[]
		activeBookmarkPage?: number
		canWrite?: boolean
	}>(),
	{
		activeBookmarkPage: undefined,
		canWrite: true,
	},
)

const emit = defineEmits<{
	jump: [pageNum: number]
	edit: []
}>()

const isOpen = ref(false)

const buttonLabel = computed(() =>
	props.bookmarks.length > 0
		? t('sheetmusic', 'Bookmarks ({count})', { count: props.bookmarks.length }, { escape: false })
		: t('sheetmusic', 'Bookmarks'),
)

function handleJump(pageNum: number) {
	emit('jump', pageNum)
	isOpen.value = false
}

function handleEdit() {
	isOpen.value = false
	emit('edit')
}
</script>

<template>
	<ViewportPopover
		v-if="bookmarks.length > 0 || canWrite !== false"
		v-model:open="isOpen"
		pane-test-id="bookmarks-dropdown"
		:pane-class="$style.bookmarksMenu">
		<template #trigger="{ toggle }">
			<ResponsiveNcButton
				:variant="bookmarks.length > 0 ? 'secondary' : 'tertiary'"
				collapse-at="768"
				:label="buttonLabel"
				:aria-expanded="isOpen"
				data-test="bookmarks-menu-trigger"
				@click="toggle">
				<template #icon>
					<BookmarkMusicOutline :size="20" />
				</template>
			</ResponsiveNcButton>
		</template>

		<div v-if="bookmarks.length > 0" :class="$style.bookmarksList" role="menu">
			<button
				v-for="(bm, idx) in bookmarks"
				:key="bm.id ?? `bm-${idx}`"
				type="button"
				role="menuitem"
				:class="[$style.menuItem, activeBookmarkPage === bm.pageNum && $style.menuItemActive]"
				:title="t('sheetmusic', 'Jump to {name}', { name: bm.name }, { escape: false })"
				@click="handleJump(bm.pageNum)">
				{{ formatBookmarkLabel(bm) }}
			</button>
		</div>
		<div v-else :class="$style.emptyState">
			{{ t('sheetmusic', 'No bookmarks yet') }}
		</div>

		<template v-if="canWrite !== false">
			<div :class="$style.divider" />
			<button
				type="button"
				:class="$style.editItem"
				data-test="edit-bookmarks-button"
				@click="handleEdit">
				<PencilOutline :size="16" />
				<span>{{ t('sheetmusic', 'Edit bookmarks…') }}</span>
			</button>
		</template>
	</ViewportPopover>
</template>

<style module>
.bookmarksMenu {
	min-width: 220px;
	width: max-content;
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.bookmarksList {
	display: flex;
	flex-direction: column;
	gap: 2px;
	max-height: 280px;
	overflow-y: auto;
}

.menuItem,
.editItem {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 6px 10px;
	border: none;
	border-radius: var(--border-radius, 4px);
	background: transparent;
	color: var(--color-main-text);
	font-size: 0.85rem;
	text-align: left;
	cursor: pointer;
}

.menuItem:hover,
.editItem:hover {
	background: var(--color-background-hover);
}

.menuItemActive {
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.14));
	color: var(--color-primary-element);
	font-weight: 600;
}

.emptyState {
	padding: 6px 10px;
	font-size: 0.82rem;
	color: var(--color-text-maxcontrast);
}

.divider {
	height: 1px;
	background: var(--color-border);
	margin: 4px 0;
}

.editItem {
	font-weight: 500;
}
</style>
