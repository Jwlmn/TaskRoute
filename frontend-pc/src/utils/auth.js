export const rolePermissions = {
  admin: ['dashboard', 'dispatch', 'users', 'mobile_tasks', 'resources'],
  dispatcher: ['dashboard', 'dispatch', 'mobile_tasks', 'resources'],
  driver: ['dashboard', 'mobile_tasks'],
  customer: ['dashboard', 'customer_orders'],
}

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
  const permissions = rolePermissions[user.role] || []
  return permissions.includes(permission)
}
