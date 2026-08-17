import { api } from '@/services/api'
import type { ApiEnvelope } from '@/types/api'
import type { BrandAssetKey, BrandSettings, ClubLoaderKey, LoaderPreset, LoginHeroCopy, LoginHeroFit } from '@/modules/settings/types'

export type LoaderPresetPayload = Omit<LoaderPreset, 'key' | 'logo_url'>

export const brandSettingsService = {
  async get(): Promise<BrandSettings> {
    const { data } = await api.get<ApiEnvelope<BrandSettings>>('/api/v1/settings/brand')
    return data.data
  },

  async upload(asset: BrandAssetKey, file: File): Promise<BrandSettings> {
    const body = new FormData()
    body.append('image', file)
    const { data } = await api.post<ApiEnvelope<BrandSettings>>(
      `/api/v1/settings/brand/${asset}`,
      body,
    )
    return data.data
  },

  async reset(asset: BrandAssetKey): Promise<BrandSettings> {
    const { data } = await api.delete<ApiEnvelope<BrandSettings>>(`/api/v1/settings/brand/${asset}`)
    return data.data
  },

  async updateHeroFit(fit: LoginHeroFit): Promise<BrandSettings> {
    const { data } = await api.put<ApiEnvelope<BrandSettings>>('/api/v1/settings/brand/hero-fit', fit)
    return data.data
  },

  async updateHeroCopy(copy: LoginHeroCopy): Promise<BrandSettings> {
    const { data } = await api.put<ApiEnvelope<BrandSettings>>('/api/v1/settings/brand/hero-copy', copy)
    return data.data
  },

  async updateLoader(key: ClubLoaderKey, payload: LoaderPresetPayload): Promise<BrandSettings> {
    const { data } = await api.put<ApiEnvelope<BrandSettings>>(
      `/api/v1/settings/brand/loaders/${key}`,
      payload,
    )
    return data.data
  },

  async uploadLoaderLogo(key: ClubLoaderKey, file: File): Promise<BrandSettings> {
    const body = new FormData()
    body.append('image', file)
    const { data } = await api.post<ApiEnvelope<BrandSettings>>(
      `/api/v1/settings/brand/loaders/${key}/logo`,
      body,
    )
    return data.data
  },

  async resetLoaderLogo(key: ClubLoaderKey): Promise<BrandSettings> {
    const { data } = await api.delete<ApiEnvelope<BrandSettings>>(
      `/api/v1/settings/brand/loaders/${key}/logo`,
    )
    return data.data
  },

  async resetLoader(key: ClubLoaderKey): Promise<BrandSettings> {
    const { data } = await api.delete<ApiEnvelope<BrandSettings>>(
      `/api/v1/settings/brand/loaders/${key}`,
    )
    return data.data
  },
}
