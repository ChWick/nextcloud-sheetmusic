import { computed, defineComponent, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { useLinkTargetPicking } from '../../../src/composables/useLinkTargetPicking'
import type { SheetAnnotation } from '../../../src/types/annotation'
import { createDefaultToolStyle } from '../../../src/utils/annotationToolStyle'

function mountPicking(selected: SheetAnnotation | null = null) {
	const isEditing = ref(true)
	const currentPage = computed(() => 1)
	const selectedAnnotation = computed(() => selected)
	const toolStyle = ref(createDefaultToolStyle())
	const onCreate = vi.fn()
	const onUpdate = vi.fn()

	let api!: ReturnType<typeof useLinkTargetPicking>
	const Comp = defineComponent({
		setup() {
			api = useLinkTargetPicking({
				isEditing,
				currentPage,
				selectedAnnotation,
				toolStyle,
				onCreate,
				onUpdate,
			})
			return () => null
		},
	})
	const wrapper = mount(Comp)
	return { wrapper, api, isEditing, onCreate, onUpdate }
}

describe('useLinkTargetPicking', () => {
	it('creates forward and reverse links on 2-step target pick', () => {
		const { wrapper, api, onCreate } = mountPicking()

		api.startPickTarget({
			mode: 'create',
			sourcePageIndex: 0,
			x: 0.2,
			y: 0.3,
			width: 0.1,
			height: 0.06,
			label: 'To Coda',
			linkStyle: 'box',
			createReverse: true,
		})
		expect(api.linkTargetPick.value).not.toBeNull()

		api.completePickTarget({ pageIndex: 2, x: 0.6, y: 0.4 })
		expect(api.linkTargetPick.value).toBeNull()
		expect(onCreate).toHaveBeenCalledTimes(2)
		expect(onCreate).toHaveBeenNthCalledWith(1, expect.objectContaining({
			pageNumber: 0,
			data: expect.objectContaining({ targetPage: 2, targetX: 0.6, targetY: 0.4, label: 'To Coda' }),
		}))
		expect(onCreate).toHaveBeenNthCalledWith(2, expect.objectContaining({
			pageNumber: 2,
			data: expect.objectContaining({ targetPage: 0, label: '← p. 1' }),
		}))
		wrapper.unmount()
	})

	it('updates an existing link via side-by-side picker and clears state when exiting edit mode', async () => {
		const existing: SheetAnnotation = {
			id: 1,
			uuid: 'link-1',
			songId: 5,
			fileId: 50,
			pageNumber: 0,
			type: 'link',
			x: 0.2,
			y: 0.2,
			width: 0.1,
			height: 0.06,
			style: { color: '#0052cc', opacity: 1, fontSize: 16 },
			data: { targetPage: 1, label: 'D.S.' },
		}
		const { wrapper, api, isEditing, onUpdate } = mountPicking(existing)

		api.openPickerForSelected()
		expect(api.linkPickerRequest.value?.mode).toBe('edit')

		api.saveLinkPicker({
			sourcePageIndex: 0,
			sourceX: 0.3,
			sourceY: 0.4,
			targetPageIndex: 2,
			targetX: 0.7,
			targetY: 0.5,
			label: '',
			linkStyle: 'circle',
			createReverse: false,
		})

		expect(onUpdate).toHaveBeenCalledWith(existing, expect.objectContaining({
			data: expect.objectContaining({
				targetPage: 2,
				targetX: 0.7,
				targetY: 0.5,
				label: '',
				linkStyle: 'circle',
			}),
		}))

		api.openPickerForSelected()
		expect(api.linkPickerRequest.value).not.toBeNull()
		isEditing.value = false
		await wrapper.vm.$nextTick()
		expect(api.linkPickerRequest.value).toBeNull()
		wrapper.unmount()
	})
})
