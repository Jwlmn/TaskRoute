const normalizeSiteIds = (user) => {
  const raw = user?.data_scope?.site_ids
  if (!Array.isArray(raw)) {
    return []
  }
  return [...new Set(raw.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0))]
}

const collectTaskSiteIds = (task) => {
  const siteIds = []
  if (task?.vehicle?.site_id) {
    siteIds.push(Number(task.vehicle.site_id))
  }

  const orders = Array.isArray(task?.orders) ? task.orders : []
  for (const order of orders) {
    if (order?.pickup_site_id) {
      siteIds.push(Number(order.pickup_site_id))
    }
    if (order?.dropoff_site_id) {
      siteIds.push(Number(order.dropoff_site_id))
    }
  }

  return [...new Set(siteIds.filter((id) => Number.isFinite(id) && id > 0))]
}

export const filterTasksByDataScope = (user, tasks) => {
  const list = Array.isArray(tasks) ? tasks : []
  if (!user) {
    return []
  }

  const role = user.role
  if (role === 'driver') {
    return list.filter((task) => Number(task?.driver_id) === Number(user.id))
  }

  if (user?.data_scope_type === 'all' || role === 'admin') {
    return list
  }

  const scopeSiteIds = normalizeSiteIds(user)
  if (scopeSiteIds.length === 0) {
    return []
  }

  const scopeSet = new Set(scopeSiteIds)
  return list.filter((task) => {
    const taskSiteIds = collectTaskSiteIds(task)
    if (taskSiteIds.length === 0) {
      return false
    }
    return taskSiteIds.some((id) => scopeSet.has(id))
  })
}

export const formatDataScopeSummary = (user) => {
  if (!user) {
    return '-'
  }
  const type = user?.data_scope_type || 'all'
  if (type === 'all') {
    return '全部数据'
  }
  if (type === 'site') {
    const siteIds = normalizeSiteIds(user)
    return siteIds.length > 0 ? `站点范围：${siteIds.join(', ')}` : '站点范围：空'
  }
  const regionCodes = Array.isArray(user?.data_scope?.region_codes)
    ? user.data_scope.region_codes.filter((item) => String(item || '').trim() !== '')
    : []
  return regionCodes.length > 0 ? `区域范围：${regionCodes.join(', ')}` : '区域范围：空'
}
