import type { ImageBuffer } from './scan-types'

// Standard ITU-T T.81 / JPEG Annex K luminance quantization table (zigzag order is applied separately)
const STD_LUMINANCE_QUANT = new Uint8Array([
	16, 11, 10, 16, 24, 40, 51, 61,
	12, 12, 14, 19, 26, 58, 60, 55,
	14, 13, 16, 24, 40, 57, 69, 56,
	14, 17, 22, 29, 51, 87, 80, 62,
	18, 22, 37, 56, 68, 109, 103, 77,
	24, 35, 55, 64, 81, 104, 113, 92,
	49, 64, 78, 87, 103, 121, 120, 101,
	72, 92, 95, 98, 112, 100, 103, 99,
])

const STD_CHROMINANCE_QUANT = new Uint8Array([
	17, 18, 24, 47, 99, 99, 99, 99,
	18, 21, 26, 66, 99, 99, 99, 99,
	24, 26, 56, 99, 99, 99, 99, 99,
	47, 66, 99, 99, 99, 99, 99, 99,
	99, 99, 99, 99, 99, 99, 99, 99,
	99, 99, 99, 99, 99, 99, 99, 99,
	99, 99, 99, 99, 99, 99, 99, 99,
	99, 99, 99, 99, 99, 99, 99, 99,
])

// Maps natural 8x8 raster index (row*8 + col) -> zigzag index (0..63)
const ZIGZAG_ORDER = new Uint8Array([
	0,  1,  5,  6,  14, 15, 27, 28,
	2,  4,  7,  13, 16, 26, 29, 42,
	3,  8,  12, 17, 25, 30, 41, 43,
	9,  11, 18, 24, 31, 40, 44, 53,
	10, 19, 23, 32, 39, 45, 52, 54,
	20, 22, 33, 38, 46, 51, 55, 60,
	21, 34, 37, 47, 50, 56, 59, 61,
	35, 36, 48, 49, 57, 58, 62, 63,
])

// Standard Huffman tables (ITU-T T.81 Annex K.3)
const BITS_DC_LUMINANCE = new Uint8Array([0, 1, 5, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0])
const VAL_DC_LUMINANCE = new Uint8Array([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11])

const BITS_DC_CHROMINANCE = new Uint8Array([0, 3, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 0, 0, 0])
const VAL_DC_CHROMINANCE = new Uint8Array([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11])

const BITS_AC_LUMINANCE = new Uint8Array([0, 2, 1, 3, 3, 2, 4, 3, 5, 5, 4, 4, 0, 0, 1, 0x7d])
const VAL_AC_LUMINANCE = new Uint8Array([
	0x01, 0x02, 0x03, 0x00, 0x04, 0x11, 0x05, 0x12,
	0x21, 0x31, 0x41, 0x06, 0x13, 0x51, 0x61, 0x07,
	0x22, 0x71, 0x14, 0x32, 0x81, 0x91, 0xa1, 0x08,
	0x23, 0x42, 0xb1, 0xc1, 0x15, 0x52, 0xd1, 0xf0,
	0x24, 0x33, 0x62, 0x72, 0x82, 0x09, 0x0a, 0x16,
	0x17, 0x18, 0x19, 0x1a, 0x25, 0x26, 0x27, 0x28,
	0x29, 0x2a, 0x34, 0x35, 0x36, 0x37, 0x38, 0x39,
	0x3a, 0x43, 0x44, 0x45, 0x46, 0x47, 0x48, 0x49,
	0x4a, 0x53, 0x54, 0x55, 0x56, 0x57, 0x58, 0x59,
	0x5a, 0x63, 0x64, 0x65, 0x66, 0x67, 0x68, 0x69,
	0x6a, 0x73, 0x74, 0x75, 0x76, 0x77, 0x78, 0x79,
	0x7a, 0x83, 0x84, 0x85, 0x86, 0x87, 0x88, 0x89,
	0x8a, 0x92, 0x93, 0x94, 0x95, 0x96, 0x97, 0x98,
	0x99, 0x9a, 0xa2, 0xa3, 0xa4, 0xa5, 0xa6, 0xa7,
	0xa8, 0xa9, 0xaa, 0xb2, 0xb3, 0xb4, 0xb5, 0xb6,
	0xb7, 0xb8, 0xb9, 0xba, 0xc2, 0xc3, 0xc4, 0xc5,
	0xc6, 0xc7, 0xc8, 0xc9, 0xca, 0xd2, 0xd3, 0xd4,
	0xd5, 0xd6, 0xd7, 0xd8, 0xd9, 0xda, 0xe1, 0xe2,
	0xe3, 0xe4, 0xe5, 0xe6, 0xe7, 0xe8, 0xe9, 0xea,
	0xf1, 0xf2, 0xf3, 0xf4, 0xf5, 0xf6, 0xf7, 0xf8,
	0xf9, 0xfa,
])

const BITS_AC_CHROMINANCE = new Uint8Array([0, 2, 1, 2, 4, 4, 3, 4, 7, 5, 4, 4, 0, 1, 2, 0x77])
const VAL_AC_CHROMINANCE = new Uint8Array([
	0x00, 0x01, 0x02, 0x03, 0x11, 0x04, 0x05, 0x21,
	0x31, 0x06, 0x12, 0x41, 0x51, 0x07, 0x61, 0x71,
	0x13, 0x22, 0x32, 0x81, 0x08, 0x14, 0x42, 0x91,
	0xa1, 0xb1, 0xc1, 0x09, 0x23, 0x33, 0x52, 0xf0,
	0x15, 0x62, 0x72, 0xd1, 0x0a, 0x16, 0x24, 0x34,
	0xe1, 0x25, 0xf1, 0x17, 0x18, 0x19, 0x1a, 0x26,
	0x27, 0x28, 0x29, 0x2a, 0x35, 0x36, 0x37, 0x38,
	0x39, 0x3a, 0x43, 0x44, 0x45, 0x46, 0x47, 0x48,
	0x49, 0x4a, 0x53, 0x54, 0x55, 0x56, 0x57, 0x58,
	0x59, 0x5a, 0x63, 0x64, 0x65, 0x66, 0x67, 0x68,
	0x69, 0x6a, 0x73, 0x74, 0x75, 0x76, 0x77, 0x78,
	0x79, 0x7a, 0x82, 0x83, 0x84, 0x85, 0x86, 0x87,
	0x88, 0x89, 0x8a, 0x92, 0x93, 0x94, 0x95, 0x96,
	0x97, 0x98, 0x99, 0x9a, 0xa2, 0xa3, 0xa4, 0xa5,
	0xa6, 0xa7, 0xa8, 0xa9, 0xaa, 0xb2, 0xb3, 0xb4,
	0xb5, 0xb6, 0xb7, 0xb8, 0xb9, 0xba, 0xc2, 0xc3,
	0xc4, 0xc5, 0xc6, 0xc7, 0xc8, 0xc9, 0xca, 0xd2,
	0xd3, 0xd4, 0xd5, 0xd6, 0xd7, 0xd8, 0xd9, 0xda,
	0xe2, 0xe3, 0xe4, 0xe5, 0xe6, 0xe7, 0xe8, 0xe9,
	0xea, 0xf2, 0xf3, 0xf4, 0xf5, 0xf6, 0xf7, 0xf8,
	0xf9, 0xfa,
])

interface HuffmanTable {
	codes: Uint16Array
	lengths: Uint8Array
}

function buildHuffmanTable(bits: Uint8Array, vals: Uint8Array): HuffmanTable {
	const codes = new Uint16Array(256)
	const lengths = new Uint8Array(256)
	let code = 0
	let valIdx = 0
	for (let length = 1; length <= 16; length++) {
		const count = bits[length - 1]
		for (let i = 0; i < count; i++) {
			const symbol = vals[valIdx++]
			codes[symbol] = code
			lengths[symbol] = length
			code++
		}
		code <<= 1
	}
	return { codes, lengths }
}

const HUFF_DC_Y = buildHuffmanTable(BITS_DC_LUMINANCE, VAL_DC_LUMINANCE)
const HUFF_AC_Y = buildHuffmanTable(BITS_AC_LUMINANCE, VAL_AC_LUMINANCE)
const HUFF_DC_C = buildHuffmanTable(BITS_DC_CHROMINANCE, VAL_DC_CHROMINANCE)
const HUFF_AC_C = buildHuffmanTable(BITS_AC_CHROMINANCE, VAL_AC_CHROMINANCE)

// Precompute 1D DCT cosine basis matrix: C[u * 8 + x] = alpha(u) * cos((2x + 1) * u * pi / 16)
const DCT_COS = new Float32Array(64)
for (let u = 0; u < 8; u++) {
	const alpha = u === 0 ? Math.sqrt(1 / 8) : Math.sqrt(2 / 8)
	for (let x = 0; x < 8; x++) {
		DCT_COS[u * 8 + x] = alpha * Math.cos(((2 * x + 1) * u * Math.PI) / 16)
	}
}

export function buildQuantTables(quality: number): { yQuant: Uint8Array, cQuant: Uint8Array } {
	const q = Math.max(1, Math.min(100, Math.round(quality)))
	const scale = q < 50 ? Math.floor(5000 / q) : 200 - 2 * q
	const yQuant = new Uint8Array(64)
	const cQuant = new Uint8Array(64)

	for (let i = 0; i < 64; i++) {
		const yVal = Math.floor((STD_LUMINANCE_QUANT[i] * scale + 50) / 100)
		const cVal = Math.floor((STD_CHROMINANCE_QUANT[i] * scale + 50) / 100)
		yQuant[i] = Math.max(1, Math.min(255, yVal))
		cQuant[i] = Math.max(1, Math.min(255, cVal))
	}
	return { yQuant, cQuant }
}

function forwardDCTAndQuantize(
	block: Float32Array,
	quantTable: Uint8Array,
	temp: Float32Array,
	outZigZag: Int16Array,
): void {
	// 1D DCT along rows: temp[y * 8 + u] = sum_x block[y * 8 + x] * DCT_COS[u * 8 + x]
	for (let y = 0; y < 8; y++) {
		const rowOff = y * 8
		for (let u = 0; u < 8; u++) {
			const cosOff = u * 8
			let sum = 0
			for (let x = 0; x < 8; x++) {
				sum += block[rowOff + x] * DCT_COS[cosOff + x]
			}
			temp[rowOff + u] = sum
		}
	}

	// 1D DCT along cols and quantize into zigzag order
	for (let v = 0; v < 8; v++) {
		const cosOff = v * 8
		for (let u = 0; u < 8; u++) {
			let sum = 0
			for (let y = 0; y < 8; y++) {
				sum += temp[y * 8 + u] * DCT_COS[cosOff + y]
			}
			const naturalIdx = v * 8 + u
			const zzIdx = ZIGZAG_ORDER[naturalIdx]
			outZigZag[zzIdx] = Math.round(sum / quantTable[naturalIdx])
		}
	}
}

function dequantizeAndInverseDCT(
	inZigZag: Int16Array,
	quantTable: Uint8Array,
	temp: Float32Array,
	coeff: Float32Array,
	outBlock: Float32Array,
): void {
	for (let naturalIdx = 0; naturalIdx < 64; naturalIdx++) {
		const zzIdx = ZIGZAG_ORDER[naturalIdx]
		coeff[naturalIdx] = inZigZag[zzIdx] * quantTable[naturalIdx]
	}

	// 1D IDCT along columns: temp[y * 8 + u] = sum_v coeff[v * 8 + u] * DCT_COS[v * 8 + y]
	for (let y = 0; y < 8; y++) {
		for (let u = 0; u < 8; u++) {
			let sum = 0
			for (let v = 0; v < 8; v++) {
				sum += coeff[v * 8 + u] * DCT_COS[v * 8 + y]
			}
			temp[y * 8 + u] = sum
		}
	}

	// 1D IDCT along rows: outBlock[y * 8 + x] = sum_u temp[y * 8 + u] * DCT_COS[u * 8 + x]
	for (let y = 0; y < 8; y++) {
		const rowOff = y * 8
		for (let x = 0; x < 8; x++) {
			let sum = 0
			for (let u = 0; u < 8; u++) {
				sum += temp[rowOff + u] * DCT_COS[u * 8 + x]
			}
			outBlock[rowOff + x] = sum
		}
	}
}

function bitLength(absVal: number): number {
	let len = 0
	let v = absVal
	while (v > 0) {
		len++
		v >>= 1
	}
	return len
}

/**
 * Encodes an ImageBuffer into a standard baseline JFIF JPEG stream.
 * Supports both 1-channel grayscale (`/DeviceGray`) and 3-channel color (`/DeviceRGB`).
 */
export function encodeImageBufferToJpeg(
	img: ImageBuffer,
	quality = 75,
	grayscale = false,
): Uint8Array {
	const { width, height, data } = img
	const { yQuant, cQuant } = buildQuantTables(quality)

	const yQuantZigZag = new Uint8Array(64)
	const cQuantZigZag = new Uint8Array(64)
	for (let i = 0; i < 64; i++) {
		yQuantZigZag[ZIGZAG_ORDER[i]] = yQuant[i]
		cQuantZigZag[ZIGZAG_ORDER[i]] = cQuant[i]
	}

	const bytes: number[] = []
	const writeByte = (b: number) => bytes.push(b & 0xff)
	const writeWord = (w: number) => {
		bytes.push((w >> 8) & 0xff, w & 0xff)
	}

	// SOI
	writeWord(0xffd8)

	// APP0 (JFIF header)
	writeWord(0xffe0)
	writeWord(16)
	bytes.push(0x4a, 0x46, 0x49, 0x46, 0x00) // "JFIF\0"
	writeWord(0x0101) // version 1.1
	writeByte(0) // units
	writeWord(1) // Xdensity
	writeWord(1) // Ydensity
	writeByte(0) // Xthumbnail
	writeByte(0) // Ythumbnail

	// DQT (Quantization tables)
	if (grayscale) {
		writeWord(0xffdb)
		writeWord(2 + 65)
		writeByte(0x00) // table 0, 8-bit precision
		for (let i = 0; i < 64; i++) writeByte(yQuantZigZag[i])
	} else {
		writeWord(0xffdb)
		writeWord(2 + 65 * 2)
		writeByte(0x00) // table 0 (Y)
		for (let i = 0; i < 64; i++) writeByte(yQuantZigZag[i])
		writeByte(0x01) // table 1 (Cb/Cr)
		for (let i = 0; i < 64; i++) writeByte(cQuantZigZag[i])
	}

	// SOF0 (Baseline DCT)
	writeWord(0xffc0)
	const numComponents = grayscale ? 1 : 3
	writeWord(8 + 3 * numComponents)
	writeByte(8) // 8 bits per sample
	writeWord(height)
	writeWord(width)
	writeByte(numComponents)
	if (grayscale) {
		writeByte(1) // Component 1 (Y)
		writeByte(0x11) // 1x1 sampling
		writeByte(0) // Quant table 0
	} else {
		writeByte(1) // Y
		writeByte(0x11)
		writeByte(0)
		writeByte(2) // Cb
		writeByte(0x11)
		writeByte(1)
		writeByte(3) // Cr
		writeByte(0x11)
		writeByte(1)
	}

	// DHT (Huffman tables)
	const writeDHTTable = (tableClassAndId: number, bits: Uint8Array, vals: Uint8Array) => {
		writeByte(tableClassAndId)
		for (let i = 0; i < 16; i++) writeByte(bits[i])
		for (let i = 0; i < vals.length; i++) writeByte(vals[i])
	}

	writeWord(0xffc4)
	if (grayscale) {
		const dhtLen = 2 + (1 + 16 + VAL_DC_LUMINANCE.length) + (1 + 16 + VAL_AC_LUMINANCE.length)
		writeWord(dhtLen)
		writeDHTTable(0x00, BITS_DC_LUMINANCE, VAL_DC_LUMINANCE)
		writeDHTTable(0x10, BITS_AC_LUMINANCE, VAL_AC_LUMINANCE)
	} else {
		const dhtLen = 2
			+ (1 + 16 + VAL_DC_LUMINANCE.length)
			+ (1 + 16 + VAL_AC_LUMINANCE.length)
			+ (1 + 16 + VAL_DC_CHROMINANCE.length)
			+ (1 + 16 + VAL_AC_CHROMINANCE.length)
		writeWord(dhtLen)
		writeDHTTable(0x00, BITS_DC_LUMINANCE, VAL_DC_LUMINANCE)
		writeDHTTable(0x10, BITS_AC_LUMINANCE, VAL_AC_LUMINANCE)
		writeDHTTable(0x01, BITS_DC_CHROMINANCE, VAL_DC_CHROMINANCE)
		writeDHTTable(0x11, BITS_AC_CHROMINANCE, VAL_AC_CHROMINANCE)
	}

	// SOS (Start of Scan)
	writeWord(0xffda)
	writeWord(6 + 2 * numComponents)
	writeByte(numComponents)
	if (grayscale) {
		writeByte(1)
		writeByte(0x00) // DC table 0, AC table 0
	} else {
		writeByte(1)
		writeByte(0x00)
		writeByte(2)
		writeByte(0x11) // DC table 1, AC table 1
		writeByte(3)
		writeByte(0x11)
	}
	writeByte(0x00) // Ss
	writeByte(0x3f) // Se
	writeByte(0x00) // Ah/Al

	// Entropy-coded bitstream state
	let bitBuf = 0
	let bitCount = 0

	const putBits = (code: number, length: number) => {
		bitBuf = (bitBuf << length) | (code & ((1 << length) - 1))
		bitCount += length
		while (bitCount >= 8) {
			bitCount -= 8
			const outByte = (bitBuf >> bitCount) & 0xff
			bytes.push(outByte)
			if (outByte === 0xff) {
				bytes.push(0x00) // byte stuffing
			}
		}
	}

	const encodeBlock = (
		zz: Int16Array,
		prevDC: number,
		dcHuff: HuffmanTable,
		acHuff: HuffmanTable,
	): number => {
		const dc = zz[0]
		const diff = dc - prevDC
		const absDiff = Math.abs(diff)
		const dcCat = bitLength(absDiff)
		putBits(dcHuff.codes[dcCat], dcHuff.lengths[dcCat])
		if (dcCat > 0) {
			const dcBits = diff < 0 ? diff - 1 : diff
			putBits(dcBits, dcCat)
		}

		let zeroRun = 0
		for (let k = 1; k < 64; k++) {
			const ac = zz[k]
			if (ac === 0) {
				zeroRun++
			} else {
				while (zeroRun >= 16) {
					putBits(acHuff.codes[0xf0], acHuff.lengths[0xf0]) // ZRL
					zeroRun -= 16
				}
				const absAC = Math.abs(ac)
				const acCat = bitLength(absAC)
				const rs = (zeroRun << 4) | acCat
				putBits(acHuff.codes[rs], acHuff.lengths[rs])
				const acBits = ac < 0 ? ac - 1 : ac
				putBits(acBits, acCat)
				zeroRun = 0
			}
		}
		if (zeroRun > 0) {
			putBits(acHuff.codes[0x00], acHuff.lengths[0x00]) // EOB
		}
		return dc
	}

	const yBlock = new Float32Array(64)
	const cbBlock = new Float32Array(64)
	const crBlock = new Float32Array(64)
	const dctTemp = new Float32Array(64)
	const zzCoeffs = new Int16Array(64)

	let prevDCY = 0
	let prevDCCb = 0
	let prevDCCr = 0

	for (let by = 0; by < height; by += 8) {
		for (let bx = 0; bx < width; bx += 8) {
			for (let y = 0; y < 8; y++) {
				const py = Math.min(height - 1, by + y)
				const rowOff = y * 8
				for (let x = 0; x < 8; x++) {
					const px = Math.min(width - 1, bx + x)
					const idx = (py * width + px) * img.channels
					const r = data[idx]
					const g = img.channels >= 3 ? data[idx + 1] : r
					const b = img.channels >= 3 ? data[idx + 2] : r
					const lum = 0.299 * r + 0.587 * g + 0.114 * b
					yBlock[rowOff + x] = lum - 128
					if (!grayscale) {
						cbBlock[rowOff + x] = -0.168736 * r - 0.331264 * g + 0.5 * b
						crBlock[rowOff + x] = 0.5 * r - 0.418688 * g - 0.081312 * b
					}
				}
			}

			forwardDCTAndQuantize(yBlock, yQuant, dctTemp, zzCoeffs)
			prevDCY = encodeBlock(zzCoeffs, prevDCY, HUFF_DC_Y, HUFF_AC_Y)

			if (!grayscale) {
				forwardDCTAndQuantize(cbBlock, cQuant, dctTemp, zzCoeffs)
				prevDCCb = encodeBlock(zzCoeffs, prevDCCb, HUFF_DC_C, HUFF_AC_C)

				forwardDCTAndQuantize(crBlock, cQuant, dctTemp, zzCoeffs)
				prevDCCr = encodeBlock(zzCoeffs, prevDCCr, HUFF_DC_C, HUFF_AC_C)
			}
		}
	}

	// Pad remaining bits with 1s
	if (bitCount > 0) {
		const padLen = 8 - bitCount
		putBits((1 << padLen) - 1, padLen)
	}

	// EOI
	writeWord(0xffd9)

	return new Uint8Array(bytes)
}

/**
 * Simulates baseline JPEG 8x8 DCT quantization on an ImageBuffer so the live preview
 * reflects the exact quality and color mode chosen by the user.
 */
export function simulateJpegCompression(
	img: ImageBuffer,
	quality = 75,
	grayscale = false,
): ImageBuffer {
	const { width, height, channels: ch, data } = img
	const dst = new Uint8ClampedArray(data.length)
	const { yQuant, cQuant } = buildQuantTables(quality)

	const yBlock = new Float32Array(64)
	const cbBlock = new Float32Array(64)
	const crBlock = new Float32Array(64)
	const dctTemp = new Float32Array(64)
	const idctCoeff = new Float32Array(64)
	const zzY = new Int16Array(64)
	const zzCb = new Int16Array(64)
	const zzCr = new Int16Array(64)
	const recY = new Float32Array(64)
	const recCb = new Float32Array(64)
	const recCr = new Float32Array(64)

	for (let by = 0; by < height; by += 8) {
		for (let bx = 0; bx < width; bx += 8) {
			for (let y = 0; y < 8; y++) {
				const py = Math.min(height - 1, by + y)
				const rowOff = y * 8
				for (let x = 0; x < 8; x++) {
					const px = Math.min(width - 1, bx + x)
					const idx = (py * width + px) * ch
					const r = data[idx]
					const g = ch >= 3 ? data[idx + 1] : r
					const b = ch >= 3 ? data[idx + 2] : r
					yBlock[rowOff + x] = (0.299 * r + 0.587 * g + 0.114 * b) - 128
					if (!grayscale) {
						cbBlock[rowOff + x] = -0.168736 * r - 0.331264 * g + 0.5 * b
						crBlock[rowOff + x] = 0.5 * r - 0.418688 * g - 0.081312 * b
					}
				}
			}

			forwardDCTAndQuantize(yBlock, yQuant, dctTemp, zzY)
			dequantizeAndInverseDCT(zzY, yQuant, dctTemp, idctCoeff, recY)

			if (!grayscale) {
				forwardDCTAndQuantize(cbBlock, cQuant, dctTemp, zzCb)
				dequantizeAndInverseDCT(zzCb, cQuant, dctTemp, idctCoeff, recCb)

				forwardDCTAndQuantize(crBlock, cQuant, dctTemp, zzCr)
				dequantizeAndInverseDCT(zzCr, cQuant, dctTemp, idctCoeff, recCr)
			}

			const maxY = Math.min(8, height - by)
			const maxX = Math.min(8, width - bx)
			for (let y = 0; y < maxY; y++) {
				const py = by + y
				const rowOff = y * 8
				for (let x = 0; x < maxX; x++) {
					const px = bx + x
					const idx = (py * width + px) * ch
					const lum = recY[rowOff + x] + 128
					if (grayscale || ch === 1) {
						const v = Math.max(0, Math.min(255, Math.round(lum)))
						dst[idx] = v
						if (ch >= 3) {
							dst[idx + 1] = v
							dst[idx + 2] = v
						}
						if (ch === 4) {
							dst[idx + 3] = data[idx + 3]
						}
					} else {
						const cb = recCb[rowOff + x]
						const cr = recCr[rowOff + x]
						dst[idx] = Math.max(0, Math.min(255, Math.round(lum + 1.402 * cr)))
						dst[idx + 1] = Math.max(0, Math.min(255, Math.round(lum - 0.344136 * cb - 0.714136 * cr)))
						dst[idx + 2] = Math.max(0, Math.min(255, Math.round(lum + 1.772 * cb)))
						if (ch === 4) {
							dst[idx + 3] = data[idx + 3]
						}
					}
				}
			}
		}
	}

	return { width, height, channels: ch, data: dst }
}

/**
 * Packs an ImageBuffer into a single-channel `/DeviceGray` byte stream with 1, 2, 4, or 8 bits per pixel.
 * Follows ISO 32000-1 Section 8.9.5 row byte-alignment rules.
 */
export function packGrayscaleBits(img: ImageBuffer, bitDepth: 1 | 2 | 4 | 8): Uint8Array {
	const { width, height, channels: ch, data } = img
	if (bitDepth === 8) {
		const out = new Uint8Array(width * height)
		for (let p = 0; p < out.length; p++) {
			const idx = p * ch
			out[p] = ch >= 3
				? Math.round(0.299 * data[idx] + 0.587 * data[idx + 1] + 0.114 * data[idx + 2])
				: data[idx]
		}
		return out
	}

	const rowBytes = Math.ceil((width * bitDepth) / 8)
	const out = new Uint8Array(rowBytes * height)
	const maxSample = (1 << bitDepth) - 1

	for (let y = 0; y < height; y++) {
		const rowOffset = y * rowBytes
		let currentByte = 0
		let bitsFilled = 0
		let byteCol = 0

		for (let x = 0; x < width; x++) {
			const idx = (y * width + x) * ch
			const lum = ch >= 3
				? 0.299 * data[idx] + 0.587 * data[idx + 1] + 0.114 * data[idx + 2]
				: data[idx]
			const sample = Math.max(0, Math.min(maxSample, Math.round((lum / 255) * maxSample)))

			currentByte = (currentByte << bitDepth) | sample
			bitsFilled += bitDepth

			if (bitsFilled === 8) {
				out[rowOffset + byteCol++] = currentByte
				currentByte = 0
				bitsFilled = 0
			}
		}

		if (bitsFilled > 0) {
			out[rowOffset + byteCol] = currentByte << (8 - bitsFilled)
		}
	}

	return out
}
