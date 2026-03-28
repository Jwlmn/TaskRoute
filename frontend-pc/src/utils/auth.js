export const rolePermissions = {
  admin: ['dashboard', 'dispatch', 'users', 'mobile_tasks', 'resources', 'freight_templates', 'settlement', 'notifications', 'audit_log'],
  dispatcher: ['dashboard', 'dispatch', 'mobile_tasks', 'resources', 'freight_templates', 'settlement', 'notifications', 'audit_log'],
  driver: ['dashboard', 'mobile_tasks', 'notifications'],
  customer: ['dashboard', 'customer_orders', 'notifications'],
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
  const roleBased = rolePermissions[user.role] || []
  const custom = Array.isArray(user.permissions) ? user.permissions : []
  const permissions = [...new Set([...roleBased, ...custom])]
  return permissions.includes(permission)
}
