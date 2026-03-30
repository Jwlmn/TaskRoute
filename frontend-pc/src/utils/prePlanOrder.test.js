import { describe, expect, it } from 'vitest'
import {
  formatFreightTemplateLabel,
  getFreightTemplateMeta,
  sortNotificationMessages,
} from './prePlanOrder'

describe('prePlanOrder utils', () => {
  it('getFreightTemplateMeta returns normalized template info', () => {
    expect(getFreightTemplateMeta({
      meta: {
        freight_template_id: 18,
        freight_template_name: '华东模板',
      },
    })).toEqual({
      id: 18,
      name: '华东模板',
    })

    expect(getFreightTemplateMeta({
      meta: {
        freight_template_id: null,
        freight_template_name: '仅名称模板',
      },
    })).toEqual({
      id: null,
      name: '仅名称模板',
    })

    expect(getFreightTemplateMeta({ meta: {} })).toBeNull()
  })

  it('formatFreightTemplateLabel falls back when no template is matched', () => {
    expect(formatFreightTemplateLabel({
      meta: {
        freight_template_name: '客户模板A',
      },
    })).toBe('客户模板A')
    expect(formatFreightTemplateLabel({ meta: {} })).toBe('未命中模板')
  })

  it('sortNotificationMessages keeps notification center rule consistent', () => {
    const sorted = sortNotificationMessages([
      { id: 1, is_pinned: false, read_at: null, created_at: '2026-03-29 10:00:00' },
      { id: 2, is_pinned: true, read_at: '2026-03-29 11:00:00', created_at: '2026-03-29 11:00:00' },
      { id: 3, is_pinned: true, read_at: null, created_at: '2026-03-29 09:00:00' },
      { id: 4, is_pinned: false, read_at: '2026-03-29 12:00:00', created_at: '2026-03-29 12:00:00' },
    ])

    expect(sorted.map((item) => item.id)).toEqual([3, 2, 1, 4])
  })

  it('sortNotificationMessages supports customer card rule without pinned priority', () => {
    const sorted = sortNotificationMessages([
      { id: 1, is_pinned: false, read_at: '2026-03-29 10:00:00', created_at: '2026-03-29 10:00:00' },
      { id: 2, is_pinned: true, read_at: null, created_at: '2026-03-29 09:00:00' },
      { id: 3, is_pinned: false, read_at: null, created_at: '2026-03-29 11:00:00' },
    ], {
      pinnedFirst: false,
    })

    expect(sorted.map((item) => item.id)).toEqual([3, 2, 1])
  })
})
