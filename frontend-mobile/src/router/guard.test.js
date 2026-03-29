import { describe, expect, it, vi } from 'vitest'
import { createAuthGuard, evaluateRouteAccess } from './guard'

describe('evaluateRouteAccess', () => {
  it('未登录访问受保护页面应跳转登录', () => {
    const result = evaluateRouteAccess({ meta: { requiresAuth: true } }, null, () => false)
    expect(result).toEqual({ name: 'login' })
  })

  it('已登录访问 guestOnly 页面应跳转首页', () => {
    const result = evaluateRouteAccess({ meta: { guestOnly: true } }, { id: 1 }, () => true)
    expect(result).toEqual({ name: 'mobile-home' })
  })

  it('权限不足访问受限页面应跳转首页', () => {
    const result = evaluateRouteAccess(
      { meta: { permission: 'notifications' } },
      { id: 1, permissions: ['dashboard'] },
      (user, permission) => Array.isArray(user?.permissions) && user.permissions.includes(permission),
    )
    expect(result).toEqual({ name: 'mobile-home' })
  })

  it('权限满足时允许访问', () => {
    const result = evaluateRouteAccess(
      { meta: { requiresAuth: true, permission: 'mobile_tasks' } },
      { id: 1, permissions: ['mobile_tasks'] },
      (user, permission) => Array.isArray(user?.permissions) && user.permissions.includes(permission),
    )
    expect(result).toBe(true)
  })
})

describe('createAuthGuard', () => {
  it('有 token 时会调用 ensureAuthSession', async () => {
    const ensureAuthSessionFn = vi.fn().mockResolvedValue({ id: 1, permissions: ['dashboard'] })
    const guard = createAuthGuard({
      apiClient: {},
      ensureAuthSessionFn,
      hasPermissionFn: (user, permission) => Array.isArray(user?.permissions) && user.permissions.includes(permission),
      readAuthTokenFn: () => 'token',
      readCurrentUserFn: () => ({ id: 1, permissions: ['dashboard'] }),
    })

    const result = await guard({ meta: { requiresAuth: true, permission: 'dashboard' } })
    expect(result).toBe(true)
    expect(ensureAuthSessionFn).toHaveBeenCalledTimes(1)
  })

  it('无 token 时不调用 ensureAuthSession 且按未登录处理', async () => {
    const ensureAuthSessionFn = vi.fn()
    const guard = createAuthGuard({
      apiClient: {},
      ensureAuthSessionFn,
      hasPermissionFn: () => false,
      readAuthTokenFn: () => '',
      readCurrentUserFn: () => null,
    })

    const result = await guard({ meta: { requiresAuth: true } })
    expect(result).toEqual({ name: 'login' })
    expect(ensureAuthSessionFn).not.toHaveBeenCalled()
  })
})
