import axios, { type AxiosRequestConfig } from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export function ocsUrl(path: string): string {
	return generateOcsUrl(`/apps/sheetmusic${path}`)
}

export async function ocsGet<T>(path: string, config?: AxiosRequestConfig): Promise<T | undefined> {
	const response = config !== undefined
		? await axios.get(ocsUrl(path), config)
		: await axios.get(ocsUrl(path))
	return response.data?.ocs?.data as T | undefined
}

export async function ocsPost<T>(path: string, body?: unknown, config?: AxiosRequestConfig): Promise<T | undefined> {
	const response = config !== undefined
		? await axios.post(ocsUrl(path), body, config)
		: await axios.post(ocsUrl(path), body)
	return response.data?.ocs?.data as T | undefined
}

export async function ocsDelete(path: string, config?: AxiosRequestConfig): Promise<void> {
	if (config !== undefined) {
		await axios.delete(ocsUrl(path), config)
	} else {
		await axios.delete(ocsUrl(path))
	}
}
