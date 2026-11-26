/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { releaseCanvas, renderPdfPageToCanvas } from './pdfjs'

export interface NormalizedCropBox {
	left: number
	top: number
	right: number
	bottom: number
}

export const FULL_PAGE_CROP: NormalizedCropBox = {
	left: 0,
	top: 0,
	right: 1,
	bottom: 1,
}

const MAX_CROP_CACHE_ENTRIES = 500
const CROP_STORAGE_KEY = 'sheetmusic:pdf-crop-cache:v1'
const cropBoxCache = new Map<string, NormalizedCropBox>()
const inFlightProbes = new Map<string, Promise<NormalizedCropBox>>()
let storageLoaded = false
let persistTimer: ReturnType<typeof setTimeout> | null = null
let pageHideListenerAttached = false

function flushPersistStorage(): void {
	if (persistTimer !== null) {
		clearTimeout(persistTimer)
		persistTimer = null
	}
	if (typeof localStorage === 'undefined' || cropBoxCache.size === 0) return
	try {
		const obj: Record<string, NormalizedCropBox> = {}
		for (const [k, v] of cropBoxCache.entries()) {
			obj[k] = v
		}
		localStorage.setItem(CROP_STORAGE_KEY, JSON.stringify(obj))
	} catch {
		// Ignore quota errors
	}
}

function schedulePersistStorage(): void {
	if (typeof localStorage === 'undefined') return
	if (!pageHideListenerAttached && typeof window !== 'undefined') {
		pageHideListenerAttached = true
		window.addEventListener('pagehide', flushPersistStorage)
	}
	if (persistTimer !== null) return
	persistTimer = setTimeout(flushPersistStorage, 250)
}

function isValidCropBox(val: unknown): val is NormalizedCropBox {
	if (!val || typeof val !== 'object') return false
	const b = val as Record<string, unknown>
	return typeof b.left === 'number'
		&& typeof b.top === 'number'
		&& typeof b.right === 'number'
		&& typeof b.bottom === 'number'
		&& b.left >= 0 && b.top >= 0 && b.right <= 1 && b.bottom <= 1
		&& b.right > b.left && b.bottom > b.top
}

function ensureStorageLoaded(): void {
	if (storageLoaded) return
	storageLoaded = true
	if (typeof localStorage === 'undefined') return
	try {
		const raw = localStorage.getItem(CROP_STORAGE_KEY)
		if (!raw) return
		const parsed = JSON.parse(raw) as Record<string, unknown>
		if (!parsed || typeof parsed !== 'object') return
		for (const [key, val] of Object.entries(parsed)) {
			if (isValidCropBox(val)) {
				cropBoxCache.set(key, val)
			}
		}
	} catch {
		// Ignore corrupt or inaccessible localStorage
	}
}

export function buildCropCacheKey(fileKey: string, pageNum: number, rotation = 0): string {
	const normRot = ((rotation % 360) + 360) % 360
	return `${fileKey}:${pageNum}:${normRot}`
}

/**
 * Rotate a normalized [0..1] crop box clockwise by 0, 90, 180, or 270 degrees.
 */
export function rotateCropBox(box: NormalizedCropBox, rotationDeg: number): NormalizedCropBox {
	const norm = ((rotationDeg % 360) + 360) % 360
	const r = (n: number) => Math.round(n * 10000) / 10000
	switch (norm) {
		case 90:
			return { left: r(1 - box.bottom), top: r(box.left), right: r(1 - box.top), bottom: r(box.right) }
		case 180:
			return { left: r(1 - box.right), top: r(1 - box.bottom), right: r(1 - box.left), bottom: r(1 - box.top) }
		case 270:
			return { left: r(box.top), top: r(1 - box.right), right: r(box.bottom), bottom: r(1 - box.left) }
		default:
			return { ...box }
	}
}

export function getCachedPdfPageCropBox(cacheKey: string): NormalizedCropBox | null {
	ensureStorageLoaded()
	const cached = cropBoxCache.get(cacheKey)
	if (!cached) return null
	// Refresh LRU order
	cropBoxCache.delete(cacheKey)
	cropBoxCache.set(cacheKey, cached)
	return cached
}

export function setCachedPdfPageCropBox(cacheKey: string, box: NormalizedCropBox): void {
	ensureStorageLoaded()
	if (cropBoxCache.has(cacheKey)) {
		cropBoxCache.delete(cacheKey)
	} else if (cropBoxCache.size >= MAX_CROP_CACHE_ENTRIES) {
		const oldestKey = cropBoxCache.keys().next().value
		if (oldestKey !== undefined) cropBoxCache.delete(oldestKey)
	}
	cropBoxCache.set(cacheKey, box)
	schedulePersistStorage()
}

export function hydratePdfCropCache(
	fileKey: string,
	boxesByPage: Record<string | number, NormalizedCropBox>,
): void {
	if (!fileKey || !boxesByPage) return
	ensureStorageLoaded()
	for (const [pageKey, box] of Object.entries(boxesByPage)) {
		const pageNum = Number(pageKey)
		if (!Number.isInteger(pageNum) || pageNum < 1 || !isValidCropBox(box)) continue
		setCachedPdfPageCropBox(buildCropCacheKey(fileKey, pageNum, 0), box)
	}
}

export function isCropped(box?: NormalizedCropBox | null): boolean {
	if (!box) return false
	return box.left > 0.001 || box.top > 0.001 || box.right < 0.999 || box.bottom < 0.999
}

/**
 * Detect the tight normalized [0..1] ink bounding box from an RGBA pixel buffer,
 * adding a small breathing margin around the detected music notation.
 */
export function detectInkBoundsFromRgba(
	data: Uint8ClampedArray,
	width: number,
	height: number,
	options: {
		whiteThreshold?: number
		marginRatio?: number
		edgeIgnorePx?: number
	} = {},
): NormalizedCropBox {
	if (width <= 4 || height <= 4 || data.length < width * height * 4) {
		return { ...FULL_PAGE_CROP }
	}

	const whiteThreshold = options.whiteThreshold ?? 242
	const marginRatio = options.marginRatio ?? 0.015
	const edgeIgnore = Math.min(options.edgeIgnorePx ?? 1, Math.floor(Math.min(width, height) / 10))

	let minX = width
	let minY = height
	let maxX = -1
	let maxY = -1

	for (let y = edgeIgnore; y < height - edgeIgnore; y++) {
		const rowOffset = y * width * 4
		for (let x = edgeIgnore; x < width - edgeIgnore; x++) {
			const idx = rowOffset + x * 4
			const a = data[idx + 3]
			if (a < 16) continue
			const r = data[idx]
			const g = data[idx + 1]
			const b = data[idx + 2]
			if (r < whiteThreshold || g < whiteThreshold || b < whiteThreshold) {
				if (x < minX) minX = x
				if (x > maxX) maxX = x
				if (y < minY) minY = y
				if (y > maxY) maxY = y
			}
		}
	}

	if (maxX < minX || maxY < minY) {
		return { ...FULL_PAGE_CROP }
	}

	const rawLeft = minX / width
	const rawTop = minY / height
	const rawRight = (maxX + 1) / width
	const rawBottom = (maxY + 1) / height

	const left = Math.max(0, Math.round((rawLeft - marginRatio) * 10000) / 10000)
	const top = Math.max(0, Math.round((rawTop - marginRatio) * 10000) / 10000)
	const right = Math.min(1, Math.round((rawRight + marginRatio) * 10000) / 10000)
	const bottom = Math.min(1, Math.round((rawBottom + marginRatio) * 10000) / 10000)

	const cropW = right - left
	const cropH = bottom - top

	// Guard against tiny specks or negligible savings (< 4% in both dimensions)
	if (cropW < 0.1 || cropH < 0.1 || (cropW > 0.96 && cropH > 0.96)) {
		return { ...FULL_PAGE_CROP }
	}

	return { left, top, right, bottom }
}

/**
 * Probe a PDF.js page at low resolution (~160px wide) to detect its tight ink crop box.
 * Results are cached in memory and persisted to localStorage by `cacheKey`.
 * If an unrotated (rotation=0) crop box is already cached for the same file+page,
 * rotated boxes are derived mathematically without re-rendering.
 */
export async function detectPdfPageCropBox(
	page: any,
	rotation: number,
	cacheKey?: string,
	options?: {
		fileKey?: string
		pageNum?: number
		onComputed?: (unrotatedBox: NormalizedCropBox) => void
	},
): Promise<NormalizedCropBox> {
	const normRot = ((rotation % 360) + 360) % 360
	const effectiveKey = cacheKey ?? (options?.fileKey && options?.pageNum ? buildCropCacheKey(options.fileKey, options.pageNum, normRot) : undefined)

	if (effectiveKey) {
		const cached = getCachedPdfPageCropBox(effectiveKey)
		if (cached) return cached
	}

	// Derive rotated crop box directly from cached rotation=0 box when available
	if (normRot !== 0 && options?.fileKey && options?.pageNum) {
		const baseCached = getCachedPdfPageCropBox(buildCropCacheKey(options.fileKey, options.pageNum, 0))
		if (baseCached) {
			const rotated = rotateCropBox(baseCached, normRot)
			if (effectiveKey) setCachedPdfPageCropBox(effectiveKey, rotated)
			return rotated
		}
	}

	if (effectiveKey) {
		const inflight = inFlightProbes.get(effectiveKey)
		if (inflight) return inflight
	}

	if (typeof document === 'undefined') {
		return { ...FULL_PAGE_CROP }
	}

	const promise = (async (): Promise<NormalizedCropBox> => {
		let probeCanvas: HTMLCanvasElement | null = null
		try {
			const baseViewport = page.getViewport({ scale: 1.0, rotation: normRot })
			if (!baseViewport?.width || !baseViewport?.height) {
				return { ...FULL_PAGE_CROP }
			}
			const probeScale = Math.min(0.4, 160 / Math.max(baseViewport.width, baseViewport.height))
			const probeViewport = page.getViewport({ scale: Math.max(0.12, probeScale), rotation: normRot })

			probeCanvas = document.createElement('canvas')
			await renderPdfPageToCanvas(page, probeCanvas, probeViewport, {
				outputScale: 1,
				applyCssSize: false,
			})

			const ctx = probeCanvas.getContext('2d')
			if (!ctx || probeCanvas.width <= 0 || probeCanvas.height <= 0) {
				return { ...FULL_PAGE_CROP }
			}
			const imgData = ctx.getImageData(0, 0, probeCanvas.width, probeCanvas.height)
			const box = detectInkBoundsFromRgba(imgData.data, probeCanvas.width, probeCanvas.height)

			if (effectiveKey) {
				setCachedPdfPageCropBox(effectiveKey, box)
			}
			// Also store the unrotated equivalent so future rotations are free and server write-back is normalized
			const unrotatedBox = normRot === 0 ? box : rotateCropBox(box, 360 - normRot)
			if (options?.fileKey && options?.pageNum && normRot !== 0) {
				setCachedPdfPageCropBox(buildCropCacheKey(options.fileKey, options.pageNum, 0), unrotatedBox)
			}
			options?.onComputed?.(unrotatedBox)
			return box
		} catch {
			return { ...FULL_PAGE_CROP }
		} finally {
			releaseCanvas(probeCanvas)
			if (effectiveKey) {
				inFlightProbes.delete(effectiveKey)
			}
		}
	})()

	if (effectiveKey) {
		inFlightProbes.set(effectiveKey, promise)
	}
	return promise
}

export function clearPdfCropCache(): void {
	cropBoxCache.clear()
	inFlightProbes.clear()
	storageLoaded = false
	if (persistTimer !== null) {
		clearTimeout(persistTimer)
		persistTimer = null
	}
	for (const pending of pendingServerSaves.values()) {
		if (pending.timer !== null) clearTimeout(pending.timer)
	}
	pendingServerSaves.clear()
	if (typeof localStorage !== 'undefined') {
		try {
			localStorage.removeItem(CROP_STORAGE_KEY)
		} catch {
			// ignore
		}
	}
}

const SERVER_SAVE_DEBOUNCE_MS = 600
const pendingServerSaves = new Map<number, {
	boxes: Record<string, NormalizedCropBox>
	timer: ReturnType<typeof setTimeout> | null
}>()

/**
 * Queue a newly computed unrotated crop box for debounced write-back to the Nextcloud server.
 * Multiple pages computed for the same file within the debounce window are merged into one POST.
 */
export function scheduleSaveFileCropBox(
	fileId: number | null | undefined,
	pageNum: number,
	box: NormalizedCropBox,
): void {
	if (!fileId || fileId <= 0 || !Number.isInteger(pageNum) || pageNum < 1) return

	let entry = pendingServerSaves.get(fileId)
	if (!entry) {
		entry = { boxes: {}, timer: null }
		pendingServerSaves.set(fileId, entry)
	}
	entry.boxes[String(pageNum)] = box

	if (entry.timer !== null) {
		clearTimeout(entry.timer)
	}
	entry.timer = setTimeout(() => {
		const current = pendingServerSaves.get(fileId)
		pendingServerSaves.delete(fileId)
		if (!current || Object.keys(current.boxes).length === 0) return
		const url = generateOcsUrl('/apps/sheetmusic/api/files/{fileId}/crop-boxes', { fileId })
		void axios.post(url, { cropBoxes: current.boxes }).catch(() => {
			// Best-effort write-back; ignore offline or read-only errors
		})
	}, SERVER_SAVE_DEBOUNCE_MS)
}
