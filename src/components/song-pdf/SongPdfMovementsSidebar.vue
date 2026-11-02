<script setup lang="ts">
import SongPdfBookmarksSidebar from './SongPdfBookmarksSidebar.vue'
import type { BookmarkSegment } from './usePdfBookmarks'

const props = defineProps<{
	movements?: BookmarkSegment[]
	bookmarks?: BookmarkSegment[]
	totalPages: number
	selectedPages?: number[]
	isExtractingOutline?: boolean
	autoPdfMetadata?: boolean
}>()

const emit = defineEmits<{
	'add-movement': []
	'add-movement-from-selection': []
	'remove-movement': [id: string]
	'update-movement': [id: string, updates: Partial<BookmarkSegment>]
	'assign-selected': [id: string]
	'extract-outline': []
}>()
</script>

<template>
	<SongPdfBookmarksSidebar
		v-bind="props"
		@add-bookmark="emit('add-movement')"
		@add-bookmark-from-selection="emit('add-movement-from-selection')"
		@remove-bookmark="emit('remove-movement', $event)"
		@update-bookmark="(id, updates) => emit('update-movement', id, updates)"
		@assign-selected="emit('assign-selected', $event)"
		@extract-outline="emit('extract-outline')" />
</template>
