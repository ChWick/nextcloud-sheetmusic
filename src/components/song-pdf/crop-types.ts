export interface PageCrop {
	/** Normalized left fraction 0..1 from left of page */
	left: number
	/** Normalized top fraction 0..1 from top of page */
	top: number
	/** Normalized width fraction 0..1 */
	width: number
	/** Normalized height fraction 0..1 */
	height: number
}

export interface CropBox {
	x: number
	y: number
	width: number
	height: number
}

/**
 * Computes the PDF user space CropBox for a given screen-space PageCrop
 * based on the net rotation angle of the page (0, 90, 180, 270 degrees).
 *
 * In PDF user space, y=0 is at the bottom of the page.
 * When a page has a clockwise rotation angle R in degrees:
 * - 0°: standard unrotated orientation
 * - 90°: rotated 90° clockwise (displayed width = origH, displayed height = origW)
 * - 180°: upside down
 * - 270°: rotated 270° clockwise (displayed width = origH, displayed height = origW)
 */
export function computeCropBoxForRotation(
	origX: number,
	origY: number,
	origW: number,
	origH: number,
	crop: PageCrop,
	rotationDegrees = 0,
): CropBox {
	const rot = ((rotationDegrees % 360) + 360) % 360
	const r = (val: number) => Math.round(val * 10000) / 10000

	switch (rot) {
		case 90:
			return {
				x: r(origX + origW * crop.top),
				y: r(origY + origH * crop.left),
				width: r(origW * crop.height),
				height: r(origH * crop.width),
			}
		case 180:
			return {
				x: r(origX + origW * (1 - crop.left - crop.width)),
				y: r(origY + origH * crop.top),
				width: r(origW * crop.width),
				height: r(origH * crop.height),
			}
		case 270:
			return {
				x: r(origX + origW * (1 - crop.top - crop.height)),
				y: r(origY + origH * (1 - crop.left - crop.width)),
				width: r(origW * crop.height),
				height: r(origH * crop.width),
			}
		case 0:
		default:
			return {
				x: r(origX + origW * crop.left),
				y: r(origY + origH * (1 - crop.top - crop.height)),
				width: r(origW * crop.width),
				height: r(origH * crop.height),
			}
	}
}
