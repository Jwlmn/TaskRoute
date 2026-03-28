export const readCurrentUser = () => {
  const raw = localStorage.getItem('taskroute_user')
  if (!raw) {
    return null
  }
  try {
    return JSON.parse(raw)
  } catch {
    return null
  }
}

export const hasPermission = (user, permission) => {
  if (!user) {
    return false
  }
  const permissions = Array.isArray(user.permissions) ? user.permissions : []
  return permissions.includes(permission)
}
