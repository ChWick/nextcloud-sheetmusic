import type { ImageBuffer } from './scan-types'

const crcTable = new Uint32Array(256)
for (let i = 0; i < 256; i++) {
	let c = i
	for (let k = 0; k < 8; k++) {
		c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1)
	}
	crcTable[i] = c >>> 0
}

function crc32(buf: Uint8Array): number {
	let crc = -1
	for (let i = 0; i < buf.length; i++) {
		crc = (crc >>> 8) ^ crcTable[(crc ^ buf[i]) & 0xff]
	}
	return (crc ^ -1) >>> 0
}

/**
 * Encodes an ImageBuffer (Grayscale, RGB, or RGBA) into PNG binary bytes.
 * Uses the Web Standard CompressionStream API for zero-dependency Deflate compression.
 */
export async function encodeImageBufferToPng(img: ImageBuffer): Promise<Uint8Array> {
	const { width: w, height: h, channels: ch } = img
	const bytesPerPixel = ch === 1 ? 1 : ch === 3 ? 3 : 4
	const rowSize = 1 + w * bytesPerPixel
	const raw = new Uint8Array(h * rowSize)

	// Format scanlines: each line starts with filter type 0 (None)
	for (let y = 0; y < h; y++) {
		const outOffset = y * rowSize
		raw[outOffset] = 0 // Filter type 0
		raw.set(
			img.data.subarray(y * w * bytesPerPixel, (y + 1) * w * bytesPerPixel),
			outOffset + 1,
		)
	}

	// Deflate scanlines
	const cs = new CompressionStream('deflate')
	const writer = cs.writable.getWriter()
	writer.write(raw)
	writer.close()

	const reader = cs.readable.getReader()
	const chunks: Uint8Array[] = []
	let deflatedLen = 0

	while (true) {
		const { done, value } = await reader.read()
		if (done) break
		if (value) {
			chunks.push(value)
			deflatedLen += value.length
		}
	}

	const deflated = new Uint8Array(deflatedLen)
	let deflOff = 0
	for (const chunk of chunks) {
		deflated.set(chunk, deflOff)
		deflOff += chunk.length
	}

	// Construct PNG chunks: Signature + IHDR + IDAT + IEND
	// Signature (8) + IHDR (25) + IDAT (12 + deflatedLen) + IEND (12)
	const totalSize = 8 + 25 + (12 + deflatedLen) + 12
	const out = new Uint8Array(totalSize)

	// 1. Signature
	out.set([137, 80, 78, 71, 13, 10, 26, 10], 0)

	// 2. IHDR Chunk (Length 13)
	const ihdr = new Uint8Array(4 + 4 + 13)
	const ihdrView = new DataView(ihdr.buffer)
	ihdrView.setUint32(0, 13)
	ihdr.set([73, 72, 68, 82], 4) // 'IHDR'
	ihdrView.setUint32(8, w)
	ihdrView.setUint32(12, h)
	ihdr[16] = 8 // bit depth 8
	ihdr[17] = ch === 1 ? 0 : ch === 3 ? 2 : 6 // 0=Grayscale, 2=RGB, 6=RGBA
	ihdr[18] = 0 // compression Deflate
	ihdr[19] = 0 // filter
	ihdr[20] = 0 // interlace

	const ihdrFull = new Uint8Array(25)
	ihdrFull.set(ihdr, 0)
	const ihdrCrc = crc32(ihdr.subarray(4, 21))
	new DataView(ihdrFull.buffer).setUint32(21, ihdrCrc)
	out.set(ihdrFull, 8)

	// 3. IDAT Chunk
	let writePos = 8 + 25
	const idatHeader = new Uint8Array(8)
	new DataView(idatHeader.buffer).setUint32(0, deflatedLen)
	idatHeader.set([73, 68, 65, 84], 4) // 'IDAT'
	out.set(idatHeader, writePos)
	writePos += 8

	out.set(deflated, writePos)
	writePos += deflatedLen

	// IDAT CRC over 'IDAT' + deflated
	const idatCrcBuf = new Uint8Array(4 + deflatedLen)
	idatCrcBuf.set([73, 68, 65, 84], 0)
	idatCrcBuf.set(deflated, 4)
	const idatCrc = crc32(idatCrcBuf)
	const idatCrcBytes = new Uint8Array(4)
	new DataView(idatCrcBytes.buffer).setUint32(0, idatCrc)
	out.set(idatCrcBytes, writePos)
	writePos += 4

	// 4. IEND Chunk
	const iend = new Uint8Array(12)
	new DataView(iend.buffer).setUint32(0, 0)
	iend.set([73, 69, 78, 68], 4) // 'IEND'
	const iendCrc = crc32(iend.subarray(4, 8))
	new DataView(iend.buffer).setUint32(8, iendCrc)
	out.set(iend, writePos)

	return out
}

