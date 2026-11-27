import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import SongAnnotationOverlay from '../../../src/components/song-viewer/SongAnnotationOverlay.vue'
import { resetStylusSettingsStateForTests } from '../../../src/composables/useStylusSettings'
import type { SheetAnnotation } from '../../../src/types/annotation'

function makePointerEvent(
	type: string,
	init: Partial<PointerEventInit> & { clientX?: number; clientY?: number },
): PointerEvent {
	const ev = new MouseEvent(type, {
		bubbles: true,
		cancelable: true,
		button: init.button ?? 0,
		buttons: init.buttons ?? (type === 'pointerup' ? 0 : 1),
		clientX: init.clientX ?? 0,
		clientY: init.clientY ?? 0,
	}) as PointerEvent
	Object.defineProperty(ev, 'pointerId', { value: init.pointerId ?? 1 })
	Object.defineProperty(ev, 'pointerType', { value: init.pointerType ?? 'pen' })
	Object.defineProperty(ev, 'pressure', { value: init.pressure ?? 0.5 })
	return ev
}

function attachRect(el: Element) {
	Object.defineProperty(el, 'getBoundingClientRect', {
		configurable: true,
		value: () => ({
			left: 0,
			top: 0,
			width: 500,
			height: 700,
			right: 500,
			bottom: 700,
			x: 0,
			y: 0,
			toJSON: () => {},
		}),
	})
}

describe('SongAnnotationOverlay Always-On Stylus Quick-Draw (no mocks)', () => {
	beforeEach(() => {
		resetStylusSettingsStateForTests()
	})

	it('draws immediately with pen while letting touch and mouse pass through, and suppresses trailing pen click', async () => {
		let parentClickCount = 0
		const host = document.createElement('div')
		host.addEventListener('click', () => {
			parentClickCount += 1
		})
		document.body.appendChild(host)

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				pageNumber: 1,
				visible: true,
				editable: false,
				stylusQuickDraw: true,
				annotations: [],
			},
			attachTo: host,
		})

		const svg = wrapper.find('[data-test="annotation-overlay"]').element
		attachRect(svg)

		// Touch and mouse do not start drawing and their clicks bubble to parent stage
		svg.dispatchEvent(makePointerEvent('pointerdown', { pointerType: 'touch', clientX: 50, clientY: 50 }))
		svg.dispatchEvent(makePointerEvent('pointermove', { pointerType: 'touch', clientX: 120, clientY: 120 }))
		svg.dispatchEvent(makePointerEvent('pointerup', { pointerType: 'touch', clientX: 120, clientY: 120 }))
		svg.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
		expect(wrapper.emitted('create-annotation')).toBeUndefined()
		expect(parentClickCount).toBe(1)

		svg.dispatchEvent(makePointerEvent('pointerdown', { pointerType: 'mouse', clientX: 60, clientY: 60 }))
		svg.dispatchEvent(makePointerEvent('pointermove', { pointerType: 'mouse', clientX: 130, clientY: 130 }))
		svg.dispatchEvent(makePointerEvent('pointerup', { pointerType: 'mouse', clientX: 130, clientY: 130 }))
		svg.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
		expect(wrapper.emitted('create-annotation')).toBeUndefined()
		expect(parentClickCount).toBe(2)

		// Active pen immediately draws a freehand stroke and suppresses the trailing click
		svg.dispatchEvent(makePointerEvent('pointerdown', { pointerType: 'pen', pointerId: 7, clientX: 100, clientY: 100 }))
		svg.dispatchEvent(makePointerEvent('pointermove', { pointerType: 'pen', pointerId: 7, clientX: 180, clientY: 160 }))
		svg.dispatchEvent(makePointerEvent('pointerup', { pointerType: 'pen', pointerId: 7, clientX: 180, clientY: 160 }))
		svg.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))

		const created = wrapper.emitted('create-annotation')
		expect(created).toHaveLength(1)
		const draft = created![0][0] as Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'>
		expect(draft.type).toBe('drawing')
		expect(draft.pageNumber).toBe(0)
		expect(draft.data?.points?.length).toBeGreaterThanOrEqual(2)
		expect(parentClickCount).toBe(2)

		wrapper.unmount()
		host.remove()
	})

	it('supports hardware stylus eraser in quick-draw mode and disables drawing when stylusQuickDraw is false', async () => {
		const existing: SheetAnnotation = {
			id: 99,
			uuid: 'ann-pen-1',
			songId: 5,
			fileId: 10,
			pageNumber: 0,
			type: 'drawing',
			x: 0.2,
			y: 0.2,
			width: 0.2,
			height: 0.1,
			color: '#e53935',
			strokeWidth: 2.5,
			opacity: 1,
			data: {
				points: [
					{ x: 0.2, y: 0.2 },
					{ x: 0.4, y: 0.3 },
				],
			},
		}

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				pageNumber: 1,
				visible: true,
				editable: false,
				stylusQuickDraw: true,
				annotations: [existing],
			},
			attachTo: document.body,
		})

		const svg = wrapper.find('[data-test="annotation-overlay"]').element
		attachRect(svg)

		// Hardware eraser (button 5 / buttons 32) hitting the existing annotation deletes it
		const annEl = wrapper.find('[data-ann-uuid="ann-pen-1"]').element
		annEl.dispatchEvent(makePointerEvent('pointerdown', {
			pointerType: 'pen',
			pointerId: 8,
			button: 5,
			buttons: 32,
			clientX: 150,
			clientY: 175,
		}))
		annEl.dispatchEvent(makePointerEvent('pointerup', {
			pointerType: 'pen',
			pointerId: 8,
			button: 5,
			buttons: 0,
			clientX: 150,
			clientY: 175,
		}))

		expect(wrapper.emitted('delete-annotation')).toHaveLength(1)
		expect((wrapper.emitted('delete-annotation')![0][0] as SheetAnnotation).uuid).toBe('ann-pen-1')

		// Disabling stylusQuickDraw (e.g. entering Concert Mode) stops pen drawing
		await wrapper.setProps({ stylusQuickDraw: false })
		svg.dispatchEvent(makePointerEvent('pointerdown', { pointerType: 'pen', pointerId: 9, clientX: 100, clientY: 100 }))
		svg.dispatchEvent(makePointerEvent('pointermove', { pointerType: 'pen', pointerId: 9, clientX: 200, clientY: 200 }))
		svg.dispatchEvent(makePointerEvent('pointerup', { pointerType: 'pen', pointerId: 9, clientX: 200, clientY: 200 }))

		expect(wrapper.emitted('create-annotation')).toBeUndefined()
		wrapper.unmount()
	})

	it('keeps hidden annotations hidden until created and cancels an active stroke when stylusQuickDraw turns off', async () => {
		const hiddenAnn: SheetAnnotation = {
			id: 101,
			uuid: 'ann-hidden-1',
			songId: 5,
			fileId: 10,
			pageNumber: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.1,
			width: 0.1,
			height: 0.1,
			color: '#1e88e5',
			strokeWidth: 2,
			opacity: 1,
			data: {
				points: [
					{ x: 0.1, y: 0.1 },
					{ x: 0.2, y: 0.2 },
				],
			},
		}

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				pageNumber: 1,
				visible: false,
				editable: false,
				stylusQuickDraw: true,
				annotations: [hiddenAnn],
			},
			attachTo: document.body,
		})

		const svg = wrapper.find('[data-test="annotation-overlay"]').element
		attachRect(svg)

		// Existing annotation stays hidden while visible=false
		expect(wrapper.find('[data-ann-uuid="ann-hidden-1"]').exists()).toBe(false)

		// Starting a pen stroke renders the live draft preview
		svg.dispatchEvent(makePointerEvent('pointerdown', { pointerType: 'pen', pointerId: 15, clientX: 80, clientY: 80 }))
		svg.dispatchEvent(makePointerEvent('pointermove', { pointerType: 'pen', pointerId: 15, clientX: 160, clientY: 160 }))
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-ann-uuid="__draft__"]').exists()).toBe(true)

		// Disabling stylusQuickDraw mid-stroke (entering Concert Mode) cancels the stroke cleanly
		await wrapper.setProps({ stylusQuickDraw: false })
		svg.dispatchEvent(makePointerEvent('pointerup', { pointerType: 'pen', pointerId: 15, clientX: 160, clientY: 160 }))
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-ann-uuid="__draft__"]').exists()).toBe(false)
		expect(wrapper.emitted('create-annotation')).toBeUndefined()
		wrapper.unmount()
	})

	it('does not trigger link jump when drawing with pen over a link, while normal clicks still jump', async () => {
		const linkAnn: SheetAnnotation = {
			id: 201,
			uuid: 'ann-link-1',
			songId: 5,
			fileId: 10,
			pageNumber: 0,
			type: 'link',
			x: 0.2,
			y: 0.2,
			width: 0.2,
			height: 0.1,
			data: {
				targetPage: 2,
				label: 'Coda',
			},
		}

		const wrapper = mount(SongAnnotationOverlay, {
			props: {
				pageNumber: 1,
				visible: true,
				editable: false,
				stylusQuickDraw: true,
				annotations: [linkAnn],
			},
			attachTo: document.body,
		})

		const svg = wrapper.find('[data-test="annotation-overlay"]').element
		attachRect(svg)
		const linkEl = wrapper.find('[data-test="annotation-link"]').element

		// Normal click on the link triggers jump-to-page
		linkEl.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
		expect(wrapper.emitted('jump-to-page')).toHaveLength(1)

		// Pen stroke over the link creates a drawing and suppresses the trailing link click
		linkEl.dispatchEvent(makePointerEvent('pointerdown', { pointerType: 'pen', pointerId: 21, clientX: 120, clientY: 160 }))
		linkEl.dispatchEvent(makePointerEvent('pointermove', { pointerType: 'pen', pointerId: 21, clientX: 180, clientY: 200 }))
		linkEl.dispatchEvent(makePointerEvent('pointerup', { pointerType: 'pen', pointerId: 21, clientX: 180, clientY: 200 }))
		linkEl.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))

		expect(wrapper.emitted('create-annotation')).toHaveLength(1)
		expect(wrapper.emitted('jump-to-page')).toHaveLength(1)
		wrapper.unmount()
	})
})
