<template>
	<NcDialog
		:open="true"
		:name="file.filename"
		size="large"
		@closing="handleClose">
		<div :class="$style.dialogBody">
			<AnnotatableFilePreview
				ref="previewRef"
				:file-path="fileUrl"
				:file-name="file.filename"
				:annotations="annotations"
				:annotation-context="annotationContext"
				:can-annotate="isPdf && canWrite !== false"
				:show-annotate-button="true"
				@update:annotations="annotations = $event" />
		</div>
	</NcDialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import AnnotatableFilePreview from '../file-preview/AnnotatableFilePreview.vue'
import { useSetlistFileAnnotations } from '../../composables/useSetlistFileAnnotations'
import type { SetlistFile } from '../../types/song'

const props = withDefaults(defineProps<{
	libraryId: number
	setlistId: number
	file: SetlistFile
	canWrite?: boolean
}>(), {
	canWrite: true,
})

const emit = defineEmits<{
	close: []
}>()

const previewRef = ref<InstanceType<typeof AnnotatableFilePreview> | null>(null)

const { annotations, isPdf, fileUrl } = useSetlistFileAnnotations(() => ({
	libraryId: props.libraryId,
	setlistId: props.setlistId,
	file: props.file,
}))

const annotationContext = computed(() => ({
	libraryId: props.libraryId,
	setlistId: props.setlistId,
	setlistFileId: props.file.id,
	fileId: props.file.ocFileId,
}))

watch(() => props.file.id, () => {
	previewRef.value?.exitEditMode()
})

function handleClose() {
	previewRef.value?.exitEditMode()
	emit('close')
}
</script>

<style module>
.dialogBody {
	height: 75vh;
	display: flex;
	flex-direction: column;
	overflow: hidden;
}
</style>
