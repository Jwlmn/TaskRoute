export const evaluateRouteAccess = (to, user, hasPermissionFn) => {
  if (to?.meta?.requiresAuth && !user) {
    return { name: 'login' }
  }

  if (to?.meta?.guestOnly && user) {
    return { name: 'mobile-home' }
  }

  if (to?.meta?.permission && !hasPermissionFn(user, to.meta.permission)) {
    return { name: 'mobile-home' }
  }

  return true
}

export const createAuthGuard = ({
  apiClient,
  ensureAuthSessionFn,
  hasPermissionFn,
  readAuthTokenFn,
  readCurrentUserFn,
}) => {
  return async (to) => {
    const token = readAuthTokenFn()
    let user = readCurrentUserFn()

    if (token) {
      user = (await ensureAuthSessionFn(apiClient)) || readCurrentUserFn()
    }

    return evaluateRouteAccess(to, user, hasPermissionFn)
  }
}
