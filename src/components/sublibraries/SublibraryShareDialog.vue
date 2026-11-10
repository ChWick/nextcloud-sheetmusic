<script setup lang="ts">
import ShareDialog from '../ShareDialog.vue'
import type { Sublibrary } from '../../types/library'

defineProps<{
	libraryId: number
	sublibrary: Sublibrary
	sublibraryPath?: string
}>()

const emit = defineEmits<{
	close: []
}>()
</script>

<template>
	<ShareDialog
		:library-id="libraryId"
		:sublibrary="sublibrary"
		:sublibrary-path="sublibraryPath"
		@close="emit('close')" />
</template>
