export const auditStatusLabelMap = {
  pending_approval: '待审核',
  approved: '已审核',
  rejected: '已驳回',
}

export const auditStatusTypeMap = {
  pending_approval: 'warning',
  approved: 'success',
  rejected: 'danger',
}

export const freightSchemeLabelMap = {
  by_weight: '按重量',
  by_volume: '按体积',
  by_trip: '按趟',
}

export const historyActionLabelMap = {
  dispatcher_create: '调度创建',
  dispatcher_batch_create: '批量创建',
  dispatcher_update: '调度编辑',
  dispatcher_lock: '锁单',
  dispatcher_unlock: '解锁',
  dispatcher_void: '作废',
  dispatcher_split_create: '拆单生成子单',
  dispatcher_split_source_voided: '拆单作废原单',
  dispatcher_merge_create: '并单生成新单',
  dispatcher_merge_source_voided: '并单作废来源单',
  dispatcher_audit_approve: '审核通过',
  dispatcher_audit_reject: '审核驳回',
  customer_submit: '客户提报',
  customer_update: '客户修改',
  customer_resubmit: '客户重提',
}

export const formatDateTime = (value) => {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  const yyyy = date.getFullYear()
  const mm = String(date.getMonth() + 1).padStart(2, '0')
  const dd = String(date.getDate()).padStart(2, '0')
  const hh = String(date.getHours()).padStart(2, '0')
  const mi = String(date.getMinutes()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd} ${hh}:${mi}`
}

export const getFreightTemplateMeta = (row) => {
  const meta = row?.meta
  if (!meta || typeof meta !== 'object') return null
  const templateId = meta.freight_template_id
  const templateName = meta.freight_template_name
  if (!templateId && !templateName) return null
  return {
    id: templateId || null,
    name: templateName || '未命名模板',
  }
}

export const formatFreightTemplateLabel = (row) => {
  const template = getFreightTemplateMeta(row)
  return template?.name || '未命中模板'
}

export const sortNotificationMessages = (rawMessages, options = {}) => {
  const { pinnedFirst = true } = options

  return [...rawMessages].sort((a, b) => {
    if (pinnedFirst) {
      const aPinned = a?.is_pinned ? 1 : 0
      const bPinned = b?.is_pinned ? 1 : 0
      if (aPinned !== bPinned) return bPinned - aPinned
    }

    const aUnread = a?.read_at ? 1 : 0
    const bUnread = b?.read_at ? 1 : 0
    if (aUnread !== bUnread) return aUnread - bUnread

    return String(b?.created_at || '').localeCompare(String(a?.created_at || ''))
  })
}

export const loadRevisionCompareDiffs = async (httpClient, orderId) => {
  if (!httpClient || !orderId) return []

  const response = await httpClient.post('/pre-plan-order/revision-compare', { id: orderId })
  return Array.isArray(response?.data?.diffs) ? response.data.diffs : []
}
