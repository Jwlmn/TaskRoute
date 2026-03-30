export const resolveTaskIdParam = (rawId) => {
  const normalized = Number(rawId)
  if (!Number.isInteger(normalized) || normalized <= 0) {
    return null
  }

  return normalized
}

export const hasPendingTaskException = (detail) =>
  detail?.route_meta?.exception?.status === 'pending'

export const getTaskOperationBlockReason = (detail) => {
  if (!detail) return '任务详情不存在'
  if (hasPendingTaskException(detail)) return '异常处理中，暂不可继续执行任务'

  const handledAction = detail?.route_meta?.exception?.handle_action
  if (handledAction === 'cancel' || detail?.status === 'cancelled') {
    return '任务已取消，当前不可继续执行'
  }
  if (handledAction === 'reassign' && detail?.status === 'assigned') {
    return '任务已改派，请等待新司机接单执行'
  }
  if (detail?.status === 'assigned') {
    return '请先接单后再执行任务'
  }
  return ''
}

export const canOperateTaskDetail = (detail) =>
  ['accepted', 'in_progress'].includes(detail?.status) && !getTaskOperationBlockReason(detail)

export const shouldShowAcceptTaskTip = (detail) => detail?.status === 'assigned'

export const buildUploadWarningMessage = (detail) =>
  getTaskOperationBlockReason(detail) || '请先接单后再上传单据'
