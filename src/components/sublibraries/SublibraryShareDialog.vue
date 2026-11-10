<script setup lang="ts">
import ShareDialog from '../ShareDialog.vue'
import type { Sublibrary } from '../../types/library'

defineProps<{
	libraryId: number
	sublibrary: Sublibrary
	sublibraryPath?: string
	ownerId?: string | null
}>()

const emit = defineEmits<{
	close: []
}>()
</script>

<template>
	<ShareDialog
		:library-id="libraryId"
		:owner-id="ownerId"
		:sublibrary="sublibrary"
		:sublibrary-path="sublibraryPath"
		@close="emit('close')" />
</template>
