export const resolveTaskIdParam = (rawId) => {
  const normalized = Number(rawId)
  if (!Number.isInteger(normalized) || normalized <= 0) {
    return null
  }

  return normalized
}

export const hasPendingTaskException = (detail) =>
  detail?.route_meta?.exception?.status === 'pending'

export const canOperateTaskDetail = (detail) =>
  ['accepted', 'in_progress'].includes(detail?.status) && !hasPendingTaskException(detail)

export const shouldShowAcceptTaskTip = (detail) => detail?.status === 'assigned'

export const buildUploadWarningMessage = (detail) =>
  hasPendingTaskException(detail) ? '异常处理中，暂不可上传单据' : '请先接单后再上传单据'

