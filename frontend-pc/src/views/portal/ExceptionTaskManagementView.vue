<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { readCurrentUser } from '../../utils/auth'
import { getLabel, taskStatusLabelMap } from '../../utils/labels'

const router = useRouter()
const route = useRoute()
const props = defineProps({
  pageMode: {
    type: String,
    default: 'operations',
  },
})
const loadingExceptions = ref(false)
const handlingException = ref(false)
const exceptionTasks = ref([])
const vehicles = ref([])
const exceptionSummary = ref(null)
const assigneeStats = ref([])
const currentPage = ref(1)
const pageSize = ref(10)
const selectedTaskIds = ref([])
const detailOrderCurrentPage = ref(1)
const detailOrderPageSize = ref(10)
const exceptionHandleDialogVisible = ref(false)
const exceptionAssignDialogVisible = ref(false)
const exceptionFeedbackDialogVisible = ref(false)
const exceptionDetailDialogVisible = ref(false)
const handlingTask = ref(null)
const assigningTask = ref(null)
const feedbackTask = ref(null)
const selectedExceptionTask = ref(null)
const assigneeTimelyWindow = ref('7d')
const assigningExceptionHandler = ref(false)
const remindingException = ref(false)
const submittingFeedback = ref(false)
const assignCandidates = ref([])
const exceptionHandleForm = ref({
  action: 'continue',
  handle_note: '',
  reassign_vehicle_id: null,
})
const exceptionAssignForm = ref({
  assigned_handler_id: null,
  assign_note: '',
})
const exceptionFeedbackForm = ref({
  feedback_content: '',
})
const filterForm = ref({
  status: 'pending',
  task_no: '',
  exception_type: '',
  handle_action: '',
  recommendation_action: '',
  handled_by_keyword: '',
  handled_by_me: false,
  overtime_only: false,
  overtime_level: '',
  driver_focus: '',
  site_focus: '',
  assigned_to_me: false,
  assigned_handler_id: null,
  feedback_filter: '',
  feedback_timeout_minutes: '30',
  sort_by_feedback: '',
  timely_rate_threshold: '70',
  timely_low_only: false,
})
const currentUser = readCurrentUser()
const overtimeThresholdMinutes = 30
const overtimeLevelOptions = [
  { label: '超时 30 分钟', value: '30', min: 30 },
  { label: '超时 60 分钟', value: '60', min: 60 },
  { label: '超时 120 分钟', value: '120', min: 120 },
]
const feedbackTimeoutOptions = [
  { label: '30 分钟未反馈', value: '30', min: 30 },
  { label: '60 分钟未反馈', value: '60', min: 60 },
  { label: '120 分钟未反馈', value: '120', min: 120 },
]

const exceptionTypeLabelMap = {
  vehicle_breakdown: '车辆故障',
  traffic_jam: '交通拥堵',
  customer_reject: '客户拒收',
  address_change: '地址变更',
  goods_damage: '货损异常',
  other: '其他异常',
}

const exceptionStatusLabelMap = {
  pending: '待处理',
  handled: '已处理',
}

const exceptionStatusTagTypeMap = {
  pending: 'danger',
  handled: 'success',
}
const isAnalyticsPage = computed(() => props.pageMode === 'analytics')
const isOperationsPage = computed(() => props.pageMode === 'operations')
const pageTitle = computed(() => (isAnalyticsPage.value ? '异常分析看板' : '异常处置工作台'))

const exceptionActionLabelMap = {
  continue: '继续执行',
  cancel: '取消任务',
  reassign: '改派车辆',
}

const exceptionActionTagTypeMap = {
  continue: 'success',
  cancel: 'danger',
  reassign: 'warning',
}

const auditStatusLabelMap = {
  pending_approval: '待审核',
  approved: '已审核',
  rejected: '已驳回',
}

const formatDateTime = (value) => {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString('zh-CN', { hour12: false })
}
const parseDateTimeValue = (value) => {
  if (!value) return null
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return null
  return date
}

const formatEntityChange = (label, beforeValue, afterValue) => `${label}：${beforeValue || '-'} -> ${afterValue || '-'}`
const formatOperator = (name, account, id) => name || account || (id ? `#${id}` : '-')
const formatVehicleDisplay = (plateNumber, name, id) => {
  if (plateNumber && name) return `${plateNumber}｜${name}`
  if (plateNumber) return plateNumber
  if (name) return name
  return id ? `#${id}` : '-'
}
const formatDriverDisplay = (name, account, id) => name || account || (id ? `#${id}` : '-')
const getExceptionSla = (task) => {
  const sla = task?.route_meta?.exception?.sla
  return sla && typeof sla === 'object' ? sla : null
}
const getPendingDurationMinutes = (task) => {
  const sla = getExceptionSla(task)
  if (sla && Number.isFinite(Number(sla.pending_minutes))) {
    return Number(sla.pending_minutes)
  }
  const reportedAt = task?.route_meta?.exception?.reported_at
  if (!reportedAt) return 0
  const date = new Date(reportedAt)
  if (Number.isNaN(date.getTime())) return 0
  return Math.max(0, Math.floor((Date.now() - date.getTime()) / 60000))
}
const formatPendingDurationMinutes = (minutes) => {
  if (minutes <= 0) return '刚刚'
  const hours = Math.floor(minutes / 60)
  const remainMinutes = minutes % 60
  if (hours <= 0) return `${remainMinutes} 分钟`
  if (remainMinutes === 0) return `${hours} 小时`
  return `${hours} 小时 ${remainMinutes} 分钟`
}
const formatPendingDuration = (task) => formatPendingDurationMinutes(getPendingDurationMinutes(task))
const formatSlaRemaining = (task) => {
  const sla = getExceptionSla(task)
  if (!sla || !Number.isFinite(Number(sla.remaining_minutes))) return '-'
  const remaining = Math.max(0, Number(sla.remaining_minutes))
  if (remaining === 0) return '已超时'
  return `${remaining} 分钟`
}
const formatNextReminderMinutes = (minutes) => {
  if (!Number.isFinite(Number(minutes))) return '-'
  const value = Math.max(0, Number(minutes))
  if (value === 0) return '立即催办'
  return `${value} 分钟后`
}
const formatNextReminder = (task) => {
  const sla = getExceptionSla(task)
  if (!sla) return '-'
  return formatNextReminderMinutes(sla.next_reminder_minutes)
}
const formatLastReminder = (task) => {
  const exception = task?.route_meta?.exception
  if (!exception) return '-'
  const remindedAt = exception.last_reminded_at || exception.sla?.last_notice_at
  return remindedAt ? formatDateTime(remindedAt) : '-'
}
const getLastFeedbackAt = (task) => {
  const exception = task?.route_meta?.exception || {}
  const value = exception.last_feedback_at || ''
  if (!value) return null
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return null
  return date
}
const getLastFeedbackGapMinutes = (task) => {
  const sla = getExceptionSla(task)
  if (sla && Number.isFinite(Number(sla.feedback_pending_minutes))) {
    return Math.max(0, Number(sla.feedback_pending_minutes))
  }
  const feedbackAt = getLastFeedbackAt(task)
  if (!feedbackAt) return null
  return Math.max(0, Math.floor((Date.now() - feedbackAt.getTime()) / 60000))
}
const getFeedbackPolicyMinutes = (task) => {
  const sla = getExceptionSla(task)
  if (sla && Number.isFinite(Number(sla.feedback_policy_minutes))) {
    return Math.max(1, Number(sla.feedback_policy_minutes))
  }
  return Number(exceptionSummary.value?.feedback_timeout_threshold_minutes || 30)
}
const isFeedbackTimeoutTask = (task, thresholdMinutes = null) => {
  const exception = task?.route_meta?.exception
  if (!exception || exception.status !== 'pending') return false
  if (!exception.last_feedback_at) return false
  const gap = getLastFeedbackGapMinutes(task)
  if (gap === null) return false
  const threshold = Number(thresholdMinutes)
  if (Number.isFinite(threshold) && threshold > 0) {
    return gap >= threshold
  }
  const sla = getExceptionSla(task)
  if (sla && typeof sla.feedback_is_overtime === 'boolean') {
    return sla.feedback_is_overtime
  }
  return gap >= getFeedbackPolicyMinutes(task)
}
const getFeedbackSlaTag = (task) => {
  const exception = task?.route_meta?.exception
  if (!exception || exception.status !== 'pending') {
    return { label: '-', type: 'info' }
  }
  if (!exception.last_feedback_at) {
    return { label: '暂无反馈', type: 'info' }
  }
  if (isFeedbackTimeoutTask(task)) {
    return { label: '反馈已超时', type: 'danger' }
  }
  return { label: '反馈正常', type: 'success' }
}
const formatFeedbackSlaRemaining = (task) => {
  const exception = task?.route_meta?.exception
  if (!exception || exception.status !== 'pending') return '-'
  if (!exception.last_feedback_at) return '待首次反馈'
  const sla = getExceptionSla(task)
  if (sla && Number.isFinite(Number(sla.feedback_remaining_minutes))) {
    const remaining = Math.max(0, Number(sla.feedback_remaining_minutes))
    return remaining === 0 ? '已超时' : `${remaining} 分钟`
  }
  const gap = getLastFeedbackGapMinutes(task)
  if (gap === null) return '-'
  const remaining = Math.max(0, getFeedbackPolicyMinutes(task) - gap)
  return remaining === 0 ? '已超时' : `${remaining} 分钟`
}
const formatLastReminderOperator = (task) => {
  const exception = task?.route_meta?.exception
  if (!exception) return '-'
  return formatOperator(exception.last_reminded_by_name, exception.last_reminded_by_account, exception.last_reminded_by)
}
const isTaskInReminderCooldown = (task) => {
  const sla = getExceptionSla(task)
  if (!sla) return false
  return Number(sla.next_reminder_minutes || 0) > 0
}
const getHistoryEventLabel = (event) => {
  if (event === 'reported') return '司机上报异常'
  if (event === 'handled') return '调度处理异常'
  if (event === 'sla_alert') return 'SLA 升级预警'
  if (event === 'sla_reminder') return 'SLA 超时催办'
  if (event === 'manual_reminder') return '人工催办'
  if (event === 'manual_feedback') return '人工反馈'
  if (event === 'feedback_sla_reminder') return '反馈超时催办'
  if (event === 'sla_assign') return 'SLA 自动指派'
  if (event === 'manual_assign') return '人工改派责任人'
  return '系统事件'
}
const getPendingDurationTagType = (task) => {
  const sla = getExceptionSla(task)
  if (sla?.level_type) return sla.level_type
  const minutes = getPendingDurationMinutes(task)
  if (minutes >= overtimeThresholdMinutes * 2) return 'danger'
  if (minutes >= overtimeThresholdMinutes) return 'warning'
  return 'info'
}
const getSlaLevel = (task) => {
  const sla = getExceptionSla(task)
  if (sla?.level_label) {
    return {
      label: sla.level_label,
      type: sla.level_type || 'info',
    }
  }
  const minutes = getPendingDurationMinutes(task)
  if (minutes >= 120) return { label: '严重超时', type: 'danger' }
  if (minutes >= 60) return { label: '高优先级', type: 'warning' }
  if (minutes >= 30) return { label: '临近超时', type: 'primary' }
  return { label: '正常', type: 'success' }
}
const getExceptionRecommendation = (task) => {
  const exception = task?.route_meta?.exception || {}
  const taskStatus = task?.status || ''
  const slaLevel = getSlaLevel(task)
  const type = exception.type || 'other'

  if (type === 'vehicle_breakdown') {
    if (['accepted', 'in_progress'].includes(taskStatus)) {
      return {
        action: 'reassign',
        type: 'warning',
        label: '建议改派车辆',
        reason: '车辆故障会直接阻断履约，优先改派可减少订单延误。',
      }
    }
    return {
      action: 'cancel',
      type: 'danger',
      label: '建议取消任务',
      reason: '任务尚未实质执行，车辆故障下取消并重排更稳妥。',
    }
  }

  if (type === 'customer_reject' || type === 'goods_damage') {
    return {
      action: 'cancel',
      type: 'danger',
      label: '建议取消任务',
      reason: '客户拒收或货损通常无法在原任务内继续履约，建议先止损。',
    }
  }

  if (type === 'traffic_jam') {
    if (slaLevel.label === '严重超时') {
      return {
        action: 'reassign',
        type: 'warning',
        label: '建议改派或人工干预',
        reason: '交通拥堵且已严重超时，建议评估改派或调整履约路径。',
      }
    }
    return {
      action: 'continue',
      type: 'success',
      label: '建议继续执行',
      reason: '交通拥堵具备恢复可能，优先观察并保持当前任务推进。',
    }
  }

  if (type === 'address_change') {
    return {
      action: slaLevel.label === '严重超时' ? 'reassign' : 'continue',
      type: slaLevel.label === '严重超时' ? 'warning' : 'primary',
      label: slaLevel.label === '严重超时' ? '建议改派跟进' : '建议继续执行',
      reason: '地址变更需先确认新线路；若已严重超时，建议调度重新分配资源。',
    }
  }

  if (slaLevel.label === '严重超时' || slaLevel.label === '高优先级') {
    return {
      action: 'reassign',
      type: 'warning',
      label: '建议优先改派',
      reason: '异常影响已扩散到时效目标，建议优先调度替代资源。',
    }
  }

  return {
    action: 'continue',
    type: 'info',
    label: '建议继续执行',
    reason: '当前异常影响相对可控，可先保留任务并持续观察。',
  }
}
const buildRecommendationNoteTemplate = (task, recommendation) => {
  const exception = task?.route_meta?.exception || {}
  const lines = []
  const exceptionTypeLabel = getLabel(exceptionTypeLabelMap, exception.type)
  const taskNo = task?.task_no || '-'
  const slaLabel = getSlaLevel(task).label

  lines.push(`任务 ${taskNo} 出现${exceptionTypeLabel}，当前判定为${slaLabel}。`)

  if (recommendation?.action === 'reassign') {
    lines.push('建议尽快协调可用车辆或司机资源改派，避免继续扩大履约延误。')
  } else if (recommendation?.action === 'cancel') {
    lines.push('建议先停止当前任务并通知相关方，待资源或订单信息明确后再重新安排。')
  } else {
    lines.push('建议保留当前任务继续执行，同时持续关注现场反馈与时效变化。')
  }

  if (exception.description) {
    lines.push(`现场说明：${exception.description}`)
  }

  return lines.join(' ')
}
const isTaskMatchedOvertimeLevel = (task) => {
  const level = overtimeLevelOptions.find((item) => item.value === filterForm.value.overtime_level)
  if (!level) return true
  return getPendingDurationMinutes(task) >= level.min
}

const currentException = computed(() => selectedExceptionTask.value?.route_meta?.exception || null)
const currentExceptionHistory = computed(() => {
  const history = currentException.value?.history
  return Array.isArray(history) ? [...history].reverse() : []
})
const feedbackSlaTrendSegments = computed(() => {
  const exception = currentException.value
  if (!exception) return []
  const reportedAtDate = parseDateTimeValue(exception.reported_at)
  if (!reportedAtDate) return []
  const thresholdMinutes = Number.isFinite(Number(exception?.sla?.feedback_policy_minutes))
    ? Math.max(1, Number(exception.sla.feedback_policy_minutes))
    : 30
  const feedbackHistory = (Array.isArray(exception.history) ? exception.history : [])
    .filter((item) => item?.event === 'manual_feedback')
    .map((item, index) => ({
      index: index + 1,
      occurred_at: item?.occurred_at || '',
      feedback_content: item?.feedback_content || '',
      operator: formatOperator(item?.operator_name, item?.operator_account, item?.operator_id),
      date: parseDateTimeValue(item?.occurred_at),
    }))
    .filter((item) => item.date)
    .sort((a, b) => a.date.getTime() - b.date.getTime())

  const points = [
    {
      label: '异常上报',
      type: 'reported',
      date: reportedAtDate,
      detail: exception.description || '',
    },
    ...feedbackHistory.map((item) => ({
      label: `反馈 #${item.index}`,
      type: 'feedback',
      date: item.date,
      detail: item.feedback_content,
      operator: item.operator,
    })),
  ]

  if (points.length <= 0) return []

  const segments = []
  for (let index = 0; index < points.length - 1; index += 1) {
    const start = points[index]
    const end = points[index + 1]
    const gapMinutes = Math.max(0, Math.floor((end.date.getTime() - start.date.getTime()) / 60000))
    segments.push({
      key: `${start.type}-${index}-to-${end.type}-${index + 1}`,
      label: `${start.label} -> ${end.label}`,
      start_at: start.date.toISOString(),
      end_at: end.date.toISOString(),
      gap_minutes: gapMinutes,
      is_overtime: gapMinutes >= thresholdMinutes,
      threshold_minutes: thresholdMinutes,
      start_detail: start.detail || '',
      end_detail: end.detail || '',
      operator: end.operator || '',
      stage: 'history',
    })
  }

  if ((exception.status || '') === 'pending') {
    const lastPoint = points[points.length - 1]
    const nowDate = new Date()
    const gapMinutes = Math.max(0, Math.floor((nowDate.getTime() - lastPoint.date.getTime()) / 60000))
    segments.push({
      key: `pending-${lastPoint.type}`,
      label: feedbackHistory.length > 0 ? '最近反馈 -> 当前' : '异常上报 -> 当前',
      start_at: lastPoint.date.toISOString(),
      end_at: nowDate.toISOString(),
      gap_minutes: gapMinutes,
      is_overtime: gapMinutes >= thresholdMinutes,
      threshold_minutes: thresholdMinutes,
      start_detail: lastPoint.detail || '',
      end_detail: feedbackHistory.length > 0 ? '等待下一次反馈' : '待首次反馈',
      operator: '',
      stage: 'pending',
    })
  }

  return segments
})
const selectedTaskOrders = computed(() => Array.isArray(selectedExceptionTask.value?.orders) ? selectedExceptionTask.value.orders : [])
const pagedSelectedTaskOrders = computed(() => {
  const start = (detailOrderCurrentPage.value - 1) * detailOrderPageSize.value
  return selectedTaskOrders.value.slice(start, start + detailOrderPageSize.value)
})
const selectedTaskOrderTotal = computed(() => selectedTaskOrders.value.length)
const primaryTaskOrder = computed(() => selectedTaskOrders.value[0] || null)
const currentExceptionRecommendation = computed(() => {
  if (!selectedExceptionTask.value) return null
  return getExceptionRecommendation(selectedExceptionTask.value)
})
const currentHandlingRecommendation = computed(() => {
  if (!handlingTask.value) return null
  return getExceptionRecommendation(handlingTask.value)
})
const currentHandlingRecommendationNote = computed(() => {
  if (!handlingTask.value || !currentHandlingRecommendation.value) return ''
  return buildRecommendationNoteTemplate(handlingTask.value, currentHandlingRecommendation.value)
})
const displayedExceptionTasks = computed(() => {
  if (filterForm.value.status !== 'pending') return exceptionTasks.value
  const timelyRateThreshold = Math.max(0, Math.min(100, Number(filterForm.value.timely_rate_threshold || 70))) / 100
  const use30d = assigneeTimelyWindow.value === '30d'
  const timelyRateKey = use30d ? 'recent_feedback_30d_timely_rate' : 'recent_feedback_7d_timely_rate'
  const assigneeTimelyRateMap = new Map(
    (Array.isArray(assigneeStats.value) ? assigneeStats.value : []).map((item) => [
      Number(item?.assigned_handler_id || 0),
      Number(item?.[timelyRateKey] || 0),
    ]),
  )

  return exceptionTasks.value.filter((task) => {
    if (filterForm.value.overtime_only && getPendingDurationMinutes(task) < overtimeThresholdMinutes) {
      return false
    }
    if (filterForm.value.driver_focus && (task.driver?.account || '') !== filterForm.value.driver_focus) {
      return false
    }
    if (filterForm.value.site_focus) {
      const orders = Array.isArray(task.orders) ? task.orders : []
      const matchedSite = orders.some((order) => (order.pickup_address || '') === filterForm.value.site_focus)
      if (!matchedSite) return false
    }
    if (filterForm.value.recommendation_action && getExceptionRecommendation(task).action !== filterForm.value.recommendation_action) {
      return false
    }
    if (filterForm.value.feedback_filter === 'no_feedback' && getLastFeedbackAt(task)) {
      return false
    }
    if (filterForm.value.feedback_filter === 'feedback_timeout') {
      const threshold = Number(filterForm.value.feedback_timeout_minutes || 30)
      if (!isFeedbackTimeoutTask(task, threshold)) return false
    }
    if (filterForm.value.timely_low_only) {
      const assigneeId = Number(task?.route_meta?.exception?.assigned_handler_id || 0)
      if (assigneeId <= 0) return false
      const assigneeRate = Number(assigneeTimelyRateMap.get(assigneeId) || 0)
      if (assigneeRate >= timelyRateThreshold) return false
    }
    return isTaskMatchedOvertimeLevel(task)
  }).sort((a, b) => {
    if (filterForm.value.sort_by_feedback !== 'latest_feedback') return 0
    const aDate = getLastFeedbackAt(a)
    const bDate = getLastFeedbackAt(b)
    if (aDate && bDate) return bDate.getTime() - aDate.getTime()
    if (aDate && !bDate) return -1
    if (!aDate && bDate) return 1
    return 0
  })
})
const pagedExceptionTasks = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return displayedExceptionTasks.value.slice(start, start + pageSize.value)
})
const exceptionTaskTotal = computed(() => displayedExceptionTasks.value.length)
const hasAggregationFilter = computed(() => Boolean(filterForm.value.driver_focus || filterForm.value.site_focus))
const aggregationMatchedCount = computed(() => {
  if (filterForm.value.status !== 'pending' || !hasAggregationFilter.value) return 0
  return displayedExceptionTasks.value.length
})
const pendingExceptionCount = computed(() => exceptionTasks.value.filter((task) => task.route_meta?.exception?.status === 'pending').length)
const pendingTotalCount = computed(() => Number(exceptionSummary.value?.total ?? pendingExceptionCount.value))
const pendingAssignedCount = computed(() => {
  if (Number.isFinite(Number(exceptionSummary.value?.assigned))) return Number(exceptionSummary.value.assigned)
  return exceptionTasks.value.filter((task) => Number(task?.route_meta?.exception?.assigned_handler_id || 0) > 0).length
})
const pendingUnassignedCount = computed(() => {
  if (Number.isFinite(Number(exceptionSummary.value?.unassigned))) return Number(exceptionSummary.value.unassigned)
  return Math.max(0, pendingTotalCount.value - pendingAssignedCount.value)
})
const pendingMyCount = computed(() => {
  if (Number.isFinite(Number(exceptionSummary.value?.my))) return Number(exceptionSummary.value.my)
  const currentUserId = Number(currentUser?.id || 0)
  if (!currentUserId) return 0
  return exceptionTasks.value.filter((task) => Number(task?.route_meta?.exception?.assigned_handler_id || 0) === currentUserId).length
})
const pendingNoFeedbackCount = computed(() => {
  if (Number.isFinite(Number(exceptionSummary.value?.no_feedback))) return Number(exceptionSummary.value.no_feedback)
  return exceptionTasks.value.filter((task) => !getLastFeedbackAt(task)).length
})
const pendingFeedbackTimeoutCount = computed(() => {
  if (Number.isFinite(Number(exceptionSummary.value?.feedback_timeout))) return Number(exceptionSummary.value.feedback_timeout)
  const threshold = Number(exceptionSummary.value?.feedback_timeout_threshold_minutes || 30)
  return exceptionTasks.value.filter((task) => {
    return isFeedbackTimeoutTask(task, threshold)
  }).length
})
const feedbackTimeoutThresholdMinutes = computed(() => Number(exceptionSummary.value?.feedback_timeout_threshold_minutes || 30))
const feedbackTimeoutRemindableTaskIds = computed(() => {
  const threshold = Number(filterForm.value.feedback_timeout_minutes || exceptionSummary.value?.feedback_timeout_threshold_minutes || 30)
  return exceptionTasks.value
    .filter((task) => task?.route_meta?.exception?.status === 'pending')
    .filter((task) => {
      const gap = getLastFeedbackGapMinutes(task)
      return gap !== null && gap >= threshold
    })
    .filter((task) => !isTaskInReminderCooldown(task))
    .map((task) => Number(task.id))
    .filter((id) => id > 0)
})
const assigneeFeedbackTimeoutRanking = computed(() => {
  if (Array.isArray(assigneeStats.value) && assigneeStats.value.some((item) => Number(item?.feedback_timeout_count || 0) > 0)) {
    return assigneeStats.value
      .map((item) => {
        const pendingCount = Number(item?.pending_count || 0)
        const timeoutCount = Number(item?.feedback_timeout_count || 0)
        const rateFromBackend = Number(item?.feedback_timeout_rate)
        const timeoutRate = Number.isFinite(rateFromBackend)
          ? rateFromBackend
          : (pendingCount > 0 ? timeoutCount / pendingCount : 0)
        return {
          assigned_handler_id: Number(item?.assigned_handler_id || 0),
          assigned_handler_name: item?.assigned_handler_name || '',
          assigned_handler_account: item?.assigned_handler_account || '',
          pending_count: pendingCount,
          feedback_timeout_count: timeoutCount,
          remindable_count: 0,
          timeout_rate: timeoutRate,
        }
      })
      .filter((item) => item.assigned_handler_id > 0 && item.feedback_timeout_count > 0)
      .sort((a, b) => {
        const rateGap = Number(b.timeout_rate || 0) - Number(a.timeout_rate || 0)
        if (Math.abs(rateGap) > 0.0001) return rateGap
        const timeoutGap = Number(b.feedback_timeout_count || 0) - Number(a.feedback_timeout_count || 0)
        if (timeoutGap !== 0) return timeoutGap
        return Number(b.pending_count || 0) - Number(a.pending_count || 0)
      })
      .slice(0, 5)
      .map((item) => {
        const assigneeId = Number(item.assigned_handler_id || 0)
        const remindableCount = exceptionTasks.value
          .filter((task) => task?.route_meta?.exception?.status === 'pending')
          .filter((task) => Number(task?.route_meta?.exception?.assigned_handler_id || 0) === assigneeId)
          .filter((task) => {
            return isFeedbackTimeoutTask(task, feedbackTimeoutThresholdMinutes.value)
          })
          .filter((task) => !isTaskInReminderCooldown(task))
          .length
        return { ...item, remindable_count: remindableCount }
      })
  }

  const threshold = feedbackTimeoutThresholdMinutes.value
  const map = new Map()
  exceptionTasks.value.forEach((task) => {
    const exception = task?.route_meta?.exception
    if (!exception || exception.status !== 'pending') return
    const assigneeId = Number(exception.assigned_handler_id || 0)
    if (assigneeId <= 0) return
    const key = String(assigneeId)
    const current = map.get(key) || {
      assigned_handler_id: assigneeId,
      assigned_handler_name: exception.assigned_handler_name || '',
      assigned_handler_account: exception.assigned_handler_account || '',
      pending_count: 0,
      feedback_timeout_count: 0,
      remindable_count: 0,
      timeout_rate: 0,
    }
    current.pending_count += 1
    if (isFeedbackTimeoutTask(task, threshold)) {
      current.feedback_timeout_count += 1
      if (!isTaskInReminderCooldown(task)) {
        current.remindable_count += 1
      }
    }
    current.timeout_rate = current.pending_count > 0 ? current.feedback_timeout_count / current.pending_count : 0
    map.set(key, current)
  })
  return [...map.values()]
    .filter((item) => Number(item.feedback_timeout_count || 0) > 0)
    .sort((a, b) => {
      const rateGap = Number(b.timeout_rate || 0) - Number(a.timeout_rate || 0)
      if (Math.abs(rateGap) > 0.0001) return rateGap
      const timeoutGap = Number(b.feedback_timeout_count || 0) - Number(a.feedback_timeout_count || 0)
      if (timeoutGap !== 0) return timeoutGap
      return Number(b.pending_count || 0) - Number(a.pending_count || 0)
    })
    .slice(0, 5)
})
const assigneeFeedbackTimelyRanking = computed(() => {
  if (!Array.isArray(assigneeStats.value)) return []
  const use30d = assigneeTimelyWindow.value === '30d'
  const countKey = use30d ? 'recent_feedback_30d_count' : 'recent_feedback_7d_count'
  const rateKey = use30d ? 'recent_feedback_30d_timely_rate' : 'recent_feedback_7d_timely_rate'
  return assigneeStats.value
    .map((item) => ({
      assigned_handler_id: Number(item?.assigned_handler_id || 0),
      assigned_handler_name: item?.assigned_handler_name || '',
      assigned_handler_account: item?.assigned_handler_account || '',
      pending_count: Number(item?.pending_count || 0),
      timely_feedback_count: Number(item?.[countKey] || 0),
      timely_feedback_rate: Number(item?.[rateKey] || 0),
      remindable_count: 0,
    }))
    .filter((item) => item.assigned_handler_id > 0 && item.timely_feedback_count > 0)
    .sort((a, b) => {
      const timelyGap = Number(a.timely_feedback_rate || 0) - Number(b.timely_feedback_rate || 0)
      if (Math.abs(timelyGap) > 0.0001) return timelyGap
      return Number(b.timely_feedback_count || 0) - Number(a.timely_feedback_count || 0)
    })
    .slice(0, 5)
    .map((item) => {
      const assigneeId = Number(item.assigned_handler_id || 0)
      const remindableCount = exceptionTasks.value
        .filter((task) => task?.route_meta?.exception?.status === 'pending')
        .filter((task) => Number(task?.route_meta?.exception?.assigned_handler_id || 0) === assigneeId)
        .filter((task) => !isTaskInReminderCooldown(task))
        .length
      return { ...item, remindable_count: remindableCount }
    })
})
const timelyRateThresholdValue = computed(() => Math.max(0, Math.min(100, Number(filterForm.value.timely_rate_threshold || 70))) / 100)
const isTimelyRateLow = (item) => Number(item?.timely_feedback_rate || 0) < timelyRateThresholdValue.value
const timelyRateThresholdText = computed(() => `${Math.round(timelyRateThresholdValue.value * 100)}%`)
const overtimeExceptionCount = computed(() => exceptionTasks.value.filter((task) => getPendingDurationMinutes(task) >= overtimeThresholdMinutes).length)
const longestPendingMinutes = computed(() => {
  const durationList = exceptionTasks.value.map((task) => getPendingDurationMinutes(task))
  return durationList.length ? Math.max(...durationList) : 0
})
const exceptionTypeStats = computed(() => Object.entries(exceptionTypeLabelMap).map(([value, label]) => ({
  value,
  label,
  count: exceptionTasks.value.filter((task) => task.route_meta?.exception?.type === value).length,
})).filter((item) => item.count > 0))
const feedbackTimeoutTypeStats = computed(() => {
  const threshold = Number(filterForm.value.feedback_timeout_minutes || exceptionSummary.value?.feedback_timeout_threshold_minutes || 30)
  return Object.entries(exceptionTypeLabelMap).map(([value, label]) => ({
    value,
    label,
    count: exceptionTasks.value.filter((task) => task?.route_meta?.exception?.status === 'pending')
      .filter((task) => task?.route_meta?.exception?.type === value)
      .filter((task) => isFeedbackTimeoutTask(task, threshold))
      .length,
  })).filter((item) => item.count > 0)
})
const recommendationStats = computed(() => {
  const map = new Map([
    ['reassign', { action: 'reassign', label: '建议改派', type: 'warning', count: 0 }],
    ['cancel', { action: 'cancel', label: '建议取消', type: 'danger', count: 0 }],
    ['continue', { action: 'continue', label: '建议继续', type: 'success', count: 0 }],
  ])
  exceptionTasks.value.forEach((task) => {
    if (task?.route_meta?.exception?.status !== 'pending') return
    const recommendation = getExceptionRecommendation(task)
    const current = map.get(recommendation.action)
    if (current) current.count += 1
  })
  return [...map.values()].filter((item) => item.count > 0)
})
const driverExceptionRanking = computed(() => {
  const map = new Map()
  exceptionTasks.value.forEach((task) => {
    const key = task.driver?.account || String(task.driver_id || 'unknown')
    const current = map.get(key) || {
      key,
      account: task.driver?.account || '',
      name: formatDriverDisplay(task.driver?.name, task.driver?.account, task.driver_id),
      count: 0,
    }
    current.count += 1
    map.set(key, current)
  })
  return [...map.values()].sort((a, b) => b.count - a.count).slice(0, 5)
})
const siteExceptionRanking = computed(() => {
  const map = new Map()
  exceptionTasks.value.forEach((task) => {
    const orders = Array.isArray(task.orders) ? task.orders : []
    orders.forEach((order) => {
      const key = order.pickup_address || '未识别站点'
      const current = map.get(key) || { key, name: key, count: 0 }
      current.count += 1
      map.set(key, current)
    })
  })
  return [...map.values()].sort((a, b) => b.count - a.count).slice(0, 5)
})
const assigneeRanking = computed(() => {
  if (Array.isArray(assigneeStats.value) && assigneeStats.value.length > 0) {
    return assigneeStats.value.slice(0, 5)
  }

  const map = new Map()
  exceptionTasks.value.forEach((task) => {
    const exception = task?.route_meta?.exception
    if (!exception || exception.status !== 'pending') return
    const assigneeId = Number(exception.assigned_handler_id || 0)
    if (assigneeId <= 0) return
    const key = String(assigneeId)
    const current = map.get(key) || {
      assigned_handler_id: assigneeId,
      assigned_handler_name: exception.assigned_handler_name || '',
      assigned_handler_account: exception.assigned_handler_account || '',
      pending_count: 0,
      overtime_count: 0,
      severe_count: 0,
    }
    current.pending_count += 1
    const pendingMinutes = getPendingDurationMinutes(task)
    if (pendingMinutes >= overtimeThresholdMinutes) current.overtime_count += 1
    if (pendingMinutes >= 120) current.severe_count += 1
    map.set(key, current)
  })
  return [...map.values()].sort((a, b) => Number(b.pending_count || 0) - Number(a.pending_count || 0)).slice(0, 5)
})
const getAssigneeRankingName = (item) => formatOperator(item?.assigned_handler_name, item?.assigned_handler_account, item?.assigned_handler_id)
const formatRatioPercent = (value) => `${(Number(value || 0) * 100).toFixed(0)}%`
const formatAssigneeRecentTimelyStats = (item) => {
  const feedback7dCount = Number(item?.recent_feedback_7d_count || 0)
  const feedback30dCount = Number(item?.recent_feedback_30d_count || 0)
  if (feedback7dCount <= 0 && feedback30dCount <= 0) return '近30天暂无反馈样本'
  const rate7d = formatRatioPercent(Number(item?.recent_feedback_7d_timely_rate || 0))
  const rate30d = formatRatioPercent(Number(item?.recent_feedback_30d_timely_rate || 0))
  return `近7天及时率 ${rate7d}（${feedback7dCount}）｜近30天及时率 ${rate30d}（${feedback30dCount}）`
}
const focusMatchedTask = (matcher) => {
  const matchedTask = displayedExceptionTasks.value.find(matcher)
  if (matchedTask) {
    selectedExceptionTask.value = matchedTask
    exceptionDetailDialogVisible.value = true
    return
  }
  selectedExceptionTask.value = null
  exceptionDetailDialogVisible.value = false
  ElMessage.info('当前筛选条件下暂无命中的异常任务')
}
const clearAggregationFilter = () => {
  filterForm.value.driver_focus = ''
  filterForm.value.site_focus = ''
}
const applyRecommendationFilter = async (action) => {
  const nextAction = filterForm.value.recommendation_action === action ? '' : action
  filterForm.value.recommendation_action = nextAction
  if (!nextAction) return
  await nextTick()
  const matchedTask = displayedExceptionTasks.value[0]
  if (!matchedTask) {
    ElMessage.info('当前建议动作下暂无命中的异常任务')
    return
  }
  selectedExceptionTask.value = matchedTask
  await openHandleDialog(matchedTask)
}
const applyFeedbackTimeoutTypeFilter = (exceptionType) => {
  const nextType = filterForm.value.exception_type === exceptionType ? '' : exceptionType
  filterForm.value.feedback_filter = 'feedback_timeout'
  filterForm.value.exception_type = nextType
}
const applyDriverRankingFilter = (item) => {
  filterForm.value.driver_focus = filterForm.value.driver_focus === item.account ? '' : (item.account || '')
  if (filterForm.value.driver_focus) {
    focusMatchedTask((task) => (task.driver?.account || '') === filterForm.value.driver_focus)
  }
}
const applySiteRankingFilter = (item) => {
  filterForm.value.site_focus = filterForm.value.site_focus === item.name ? '' : (item.name || '')
  if (filterForm.value.site_focus) {
    focusMatchedTask((task) => {
      const orders = Array.isArray(task.orders) ? task.orders : []
      return orders.some((order) => (order.pickup_address || '') === filterForm.value.site_focus)
    })
  }
}
const applyAssigneeRankingFilter = (item) => {
  const assigneeId = Number(item?.assigned_handler_id || 0)
  if (assigneeId <= 0) return
  filterForm.value.assigned_handler_id = filterForm.value.assigned_handler_id === assigneeId ? null : assigneeId
  if (filterForm.value.assigned_handler_id) {
    focusMatchedTask((task) => Number(task?.route_meta?.exception?.assigned_handler_id || 0) === assigneeId)
  }
}
const applyAssigneeFeedbackTimeoutFilter = (item) => {
  const assigneeId = Number(item?.assigned_handler_id || 0)
  if (assigneeId <= 0) return
  filterForm.value.assigned_handler_id = assigneeId
  filterForm.value.feedback_filter = 'feedback_timeout'
  focusMatchedTask((task) => Number(task?.route_meta?.exception?.assigned_handler_id || 0) === assigneeId)
}
const remindExceptionsByTaskIds = async (taskIds, remindNote = '') => {
  const ids = [...new Set((Array.isArray(taskIds) ? taskIds : []).map((id) => Number(id)).filter((id) => id > 0))]
  if (ids.length === 0) {
    ElMessage.warning('没有可催办的异常任务')
    return
  }

  remindingException.value = true
  try {
    const { data } = await api.post('/dispatch-task/exception-remind-batch', {
      task_ids: ids,
      remind_note: remindNote || null,
    })
    const updatedCount = Number(data?.updated_count || 0)
    const skippedCount = Number(data?.skipped_count || 0)
    const cooldownCount = Number(data?.cooldown_count || 0)
    if (updatedCount > 0) {
      ElMessage.success(`催办完成：成功 ${updatedCount} 条，跳过 ${skippedCount} 条（冷却中 ${cooldownCount} 条）`)
    } else if (cooldownCount > 0) {
      ElMessage.warning('当前任务处于催办冷却期，请稍后重试')
    } else {
      ElMessage.info(`未催办成功：跳过 ${skippedCount} 条`)
    }
    await loadExceptionTasks()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '批量催办失败')
  } finally {
    remindingException.value = false
  }
}
const batchRemindSelected = async () => {
  if (selectedTaskIds.value.length === 0) {
    ElMessage.warning('请先勾选异常任务')
    return
  }
  const availableTaskIds = selectedTaskIds.value.filter((taskId) => {
    const task = exceptionTasks.value.find((item) => Number(item.id) === Number(taskId))
    return task && !isTaskInReminderCooldown(task)
  })
  if (availableTaskIds.length === 0) {
    ElMessage.warning('所选任务均处于催办冷却期')
    return
  }
  await remindExceptionsByTaskIds(availableTaskIds, '请尽快处理并同步最新进展。')
}
const batchRemindFeedbackTimeout = async () => {
  const taskIds = feedbackTimeoutRemindableTaskIds.value
  if (taskIds.length === 0) {
    ElMessage.warning('当前没有可催办的反馈超时异常')
    return
  }
  const threshold = Number(filterForm.value.feedback_timeout_minutes || exceptionSummary.value?.feedback_timeout_threshold_minutes || 30)
  await remindExceptionsByTaskIds(taskIds, `反馈已超时（>=${threshold} 分钟），请尽快同步处理进展。`)
}
const remindFeedbackTimeoutByType = async (item) => {
  const threshold = Number(filterForm.value.feedback_timeout_minutes || exceptionSummary.value?.feedback_timeout_threshold_minutes || 30)
  const taskIds = exceptionTasks.value
    .filter((task) => task?.route_meta?.exception?.status === 'pending')
    .filter((task) => task?.route_meta?.exception?.type === item.value)
    .filter((task) => isFeedbackTimeoutTask(task, threshold))
    .filter((task) => !isTaskInReminderCooldown(task))
    .map((task) => Number(task.id))
    .filter((id) => id > 0)
  if (taskIds.length === 0) {
    ElMessage.warning(`当前没有可催办的${item.label}反馈超时异常`)
    return
  }
  await remindExceptionsByTaskIds(taskIds, `${item.label}异常反馈已超时（>=${threshold} 分钟），请优先跟进。`)
}
const remindAssigneeRanking = async (item) => {
  const assigneeId = Number(item?.assigned_handler_id || 0)
  if (assigneeId <= 0) return
  const taskIds = exceptionTasks.value
    .filter((task) => task?.route_meta?.exception?.status === 'pending')
    .filter((task) => !isTaskInReminderCooldown(task))
    .filter((task) => Number(task?.route_meta?.exception?.assigned_handler_id || 0) === assigneeId)
    .map((task) => Number(task.id))
    .filter((id) => id > 0)
  if (taskIds.length === 0) {
    ElMessage.warning('该责任人当前异常均处于催办冷却期')
    return
  }
  await remindExceptionsByTaskIds(taskIds, `请优先处理你负责的异常任务（${getAssigneeRankingName(item)}）。`)
}
const remindAssigneeFeedbackTimeout = async (item) => {
  const assigneeId = Number(item?.assigned_handler_id || 0)
  if (assigneeId <= 0) return
  const threshold = feedbackTimeoutThresholdMinutes.value
  const taskIds = exceptionTasks.value
    .filter((task) => task?.route_meta?.exception?.status === 'pending')
    .filter((task) => Number(task?.route_meta?.exception?.assigned_handler_id || 0) === assigneeId)
    .filter((task) => isFeedbackTimeoutTask(task, threshold))
    .filter((task) => !isTaskInReminderCooldown(task))
    .map((task) => Number(task.id))
    .filter((id) => id > 0)
  if (taskIds.length === 0) {
    ElMessage.warning('该责任人当前没有可催办的反馈超时异常')
    return
  }
  await remindExceptionsByTaskIds(taskIds, `你负责的异常反馈已超时（>=${threshold} 分钟），请优先反馈处理进展。`)
}
const remindAssigneeTimelyLow = async (item) => {
  const assigneeId = Number(item?.assigned_handler_id || 0)
  if (assigneeId <= 0) return
  const taskIds = exceptionTasks.value
    .filter((task) => task?.route_meta?.exception?.status === 'pending')
    .filter((task) => Number(task?.route_meta?.exception?.assigned_handler_id || 0) === assigneeId)
    .filter((task) => !isTaskInReminderCooldown(task))
    .map((task) => Number(task.id))
    .filter((id) => id > 0)
  if (taskIds.length === 0) {
    ElMessage.warning('该责任人当前异常均处于催办冷却期')
    return
  }
  const windowLabel = assigneeTimelyWindow.value === '30d' ? '近30天' : '近7天'
  await remindExceptionsByTaskIds(taskIds, `${windowLabel}反馈及时率偏低，请优先跟进并及时反馈处理进展。`)
}
const remindSingleTask = async (task) => {
  const taskId = Number(task?.id || 0)
  if (taskId <= 0) return
  if (isTaskInReminderCooldown(task)) {
    ElMessage.warning('该任务处于催办冷却期，请稍后重试')
    return
  }
  await remindExceptionsByTaskIds([taskId], `任务 ${task?.task_no || ''} 存在待处理异常，请尽快跟进。`)
}
const openFeedbackDialog = (task) => {
  feedbackTask.value = task
  exceptionFeedbackForm.value = {
    feedback_content: '',
  }
  exceptionFeedbackDialogVisible.value = true
}
const submitExceptionFeedback = async () => {
  const taskId = Number(feedbackTask.value?.id || 0)
  if (taskId <= 0) return
  const content = String(exceptionFeedbackForm.value.feedback_content || '').trim()
  if (!content) {
    ElMessage.warning('请填写反馈内容')
    return
  }

  submittingFeedback.value = true
  try {
    await api.post('/dispatch-task/exception-feedback', {
      task_id: taskId,
      feedback_content: content,
    })
    ElMessage.success('反馈已提交')
    exceptionFeedbackDialogVisible.value = false
    await loadExceptionTasks()
    if (selectedExceptionTask.value?.id) {
      const nextTask = exceptionTasks.value.find((item) => item.id === selectedExceptionTask.value.id)
      if (nextTask) {
        selectedExceptionTask.value = nextTask
      }
    }
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '提交反馈失败')
  } finally {
    submittingFeedback.value = false
  }
}

watch(() => filterForm.value.status, (status) => {
  if (status !== 'handled') {
    filterForm.value.handle_action = ''
    filterForm.value.handled_by_keyword = ''
    filterForm.value.handled_by_me = false
  } else {
    filterForm.value.overtime_only = false
    filterForm.value.overtime_level = ''
    filterForm.value.recommendation_action = ''
    filterForm.value.assigned_to_me = false
    filterForm.value.assigned_handler_id = null
    filterForm.value.feedback_filter = ''
    filterForm.value.sort_by_feedback = ''
    filterForm.value.timely_low_only = false
  }
})

const loadExceptionTasks = async () => {
  loadingExceptions.value = true
  try {
    const payload = {
      status: filterForm.value.status || 'pending',
    }
    if (filterForm.value.task_no.trim()) payload.task_no = filterForm.value.task_no.trim()
    if (filterForm.value.exception_type) payload.exception_type = filterForm.value.exception_type
    if (filterForm.value.handle_action && payload.status === 'handled') payload.handle_action = filterForm.value.handle_action
    if (payload.status === 'handled' && filterForm.value.handled_by_keyword.trim()) {
      payload.handled_by_keyword = filterForm.value.handled_by_keyword.trim()
    }
    if (payload.status === 'handled' && filterForm.value.handled_by_me) {
      payload.handled_by_me = true
    }
    if (payload.status === 'pending' && filterForm.value.assigned_to_me) {
      payload.assigned_to_me = true
    }
    if (payload.status === 'pending' && Number(filterForm.value.assigned_handler_id || 0) > 0) {
      payload.assigned_handler_id = Number(filterForm.value.assigned_handler_id)
    }

    const { data } = await api.post('/dispatch-task/exception-list', payload)
    exceptionTasks.value = Array.isArray(data?.data) ? data.data : []
    exceptionSummary.value = data?.summary && typeof data.summary === 'object' ? data.summary : null
    assigneeStats.value = Array.isArray(data?.assignee_stats) ? data.assignee_stats : []
    selectedTaskIds.value = []
    const maxPage = Math.max(1, Math.ceil(displayedExceptionTasks.value.length / pageSize.value))
    if (currentPage.value > maxPage) currentPage.value = maxPage
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取异常任务失败')
  } finally {
    loadingExceptions.value = false
  }
}

const loadVehicles = async () => {
  const { data } = await api.post('/resource/vehicle/list', {})
  vehicles.value = Array.isArray(data?.data) ? data.data : []
}

const loadAssignableHandlers = async () => {
  try {
    const { data } = await api.post('/resource/personnel/list', {
      status: 'active',
    })
    const list = Array.isArray(data?.data) ? data.data : []
    assignCandidates.value = list.filter((item) => ['admin', 'dispatcher'].includes(item?.role))
  } catch {
    assignCandidates.value = []
  }
}

const openHandleDialog = async (task) => {
  handlingTask.value = task
  const recommendation = getExceptionRecommendation(task)
  exceptionHandleForm.value = {
    action: recommendation.action || 'continue',
    handle_note: buildRecommendationNoteTemplate(task, recommendation),
    reassign_vehicle_id: null,
  }
  exceptionHandleDialogVisible.value = true
  await loadVehicles()
}

const openDetailDialog = (task) => {
  selectedExceptionTask.value = task
  detailOrderCurrentPage.value = 1
  exceptionDetailDialogVisible.value = true
}
const openAssignDialog = async (task, useCurrentUser = false) => {
  assigningTask.value = task
  await loadAssignableHandlers()
  const defaultAssignee = useCurrentUser
    ? Number(currentUser?.id || 0)
    : Number(task?.route_meta?.exception?.assigned_handler_id || currentUser?.id || 0)
  exceptionAssignForm.value = {
    assigned_handler_id: defaultAssignee > 0 ? defaultAssignee : null,
    assign_note: useCurrentUser ? '我接管该异常任务。' : '',
  }
  exceptionAssignDialogVisible.value = true
}
const assignToMe = async (task) => {
  if (!currentUser?.id) {
    ElMessage.warning('当前账号信息异常，请重新登录后重试')
    return
  }
  await openAssignDialog(task, true)
}
const handleSelectionChange = (rows) => {
  selectedTaskIds.value = (Array.isArray(rows) ? rows : []).map((item) => Number(item?.id || 0)).filter((id) => id > 0)
}
const batchAssignToMe = async () => {
  if (!currentUser?.id) {
    ElMessage.warning('当前账号信息异常，请重新登录后重试')
    return
  }
  if (selectedTaskIds.value.length === 0) {
    ElMessage.warning('请先勾选异常任务')
    return
  }

  assigningExceptionHandler.value = true
  try {
    const { data } = await api.post('/dispatch-task/exception-assign-batch', {
      task_ids: selectedTaskIds.value,
      assigned_handler_id: Number(currentUser.id),
      assign_note: '批量接管异常任务',
    })
    ElMessage.success(`批量接管完成：成功 ${Number(data?.updated_count || 0)} 条，跳过 ${Number(data?.skipped_count || 0)} 条`)
    await loadExceptionTasks()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '批量接管失败')
  } finally {
    assigningExceptionHandler.value = false
  }
}
const submitAssignExceptionHandler = async () => {
  if (!assigningTask.value?.id) return
  if (!exceptionAssignForm.value.assigned_handler_id) {
    ElMessage.warning('请选择责任人')
    return
  }

  assigningExceptionHandler.value = true
  try {
    await api.post('/dispatch-task/exception-assign', {
      task_id: assigningTask.value.id,
      assigned_handler_id: exceptionAssignForm.value.assigned_handler_id,
      assign_note: exceptionAssignForm.value.assign_note || null,
    })
    ElMessage.success('责任人已更新')
    exceptionAssignDialogVisible.value = false
    await loadExceptionTasks()
    if (selectedExceptionTask.value?.id) {
      const nextTask = exceptionTasks.value.find((item) => item.id === selectedExceptionTask.value.id)
      if (nextTask) {
        selectedExceptionTask.value = nextTask
      }
    }
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '责任人改派失败')
  } finally {
    assigningExceptionHandler.value = false
  }
}
const applyRecommendedHandleAction = () => {
  if (!currentHandlingRecommendation.value?.action) return
  exceptionHandleForm.value.action = currentHandlingRecommendation.value.action
  if (!exceptionHandleForm.value.handle_note.trim()) {
    exceptionHandleForm.value.handle_note = currentHandlingRecommendationNote.value
  }
}
watch(() => exceptionHandleForm.value.action, (action) => {
  if (!handlingTask.value) return
  const recommendation = getExceptionRecommendation(handlingTask.value)
  if (action !== recommendation.action) return
  if (!exceptionHandleForm.value.handle_note.trim()) {
    exceptionHandleForm.value.handle_note = buildRecommendationNoteTemplate(handlingTask.value, recommendation)
  }
})

const submitHandleException = async () => {
  if (!handlingTask.value?.id) return
  if (exceptionHandleForm.value.action === 'reassign' && !exceptionHandleForm.value.reassign_vehicle_id) {
    ElMessage.warning('请选择改派车辆')
    return
  }

  handlingException.value = true
  try {
    await api.post('/dispatch-task/exception-handle', {
      task_id: handlingTask.value.id,
      action: exceptionHandleForm.value.action,
      handle_note: exceptionHandleForm.value.handle_note || null,
      reassign_vehicle_id: exceptionHandleForm.value.action === 'reassign'
        ? exceptionHandleForm.value.reassign_vehicle_id
        : null,
    })
    ElMessage.success('异常处理完成')
    exceptionHandleDialogVisible.value = false
    await loadExceptionTasks()
    const nextTask = exceptionTasks.value.find((item) => item.id === handlingTask.value?.id)
    if (nextTask) {
      selectedExceptionTask.value = nextTask
    }
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '异常处理失败')
  } finally {
    handlingException.value = false
  }
}

const jumpToDispatchTask = async () => {
  if (!selectedExceptionTask.value?.id) return
  exceptionDetailDialogVisible.value = false
  await router.push({
    name: 'dispatch-workbench',
    query: {
      task_no: selectedExceptionTask.value.task_no || '',
      focus_task_id: String(selectedExceptionTask.value.id),
      open_orders: '1',
      open_exception_return: '1',
    },
  })
}

const jumpToPrePlanOrder = async () => {
  if (!primaryTaskOrder.value?.id) {
    ElMessage.warning('当前异常暂无关联订单')
    return
  }
  exceptionDetailDialogVisible.value = false
  await router.push({
    name: 'pre-plan-order-management',
    query: {
      keyword: primaryTaskOrder.value.order_no || '',
      focus_order_id: String(primaryTaskOrder.value.id),
      open_detail: '1',
    },
  })
}

const openExceptionDetailFromRoute = () => {
  if (route.query.open_detail !== '1') return
  const focusTaskId = Number(route.query.focus_task_id || 0)
  if (!focusTaskId) return
  const matchedTask = exceptionTasks.value.find((item) => item.id === focusTaskId)
  if (!matchedTask) return
  selectedExceptionTask.value = matchedTask
  exceptionDetailDialogVisible.value = true
}

onMounted(async () => {
  await loadAssignableHandlers()
  await loadExceptionTasks()
  openExceptionDetailFromRoute()
})

watch(displayedExceptionTasks, (list) => {
  const maxPage = Math.max(1, Math.ceil(list.length / pageSize.value))
  if (currentPage.value > maxPage) {
    currentPage.value = maxPage
  }
})
</script>

<template>
  <div class="page-content-shell">
  <el-card shadow="never" class="page-card">
    <template #header>
      <div class="table-header">
        <div class="card-title">{{ pageTitle }}</div>
        <el-button type="primary" plain @click="loadExceptionTasks">刷新异常</el-button>
      </div>
    </template>
    <template v-if="!isOperationsPage">
    <div class="analytics-layout">
      <div class="analytics-summary-grid">
        <el-card shadow="never" class="analytics-summary-card">
          <div class="analytics-kpi-label">当前异常总量</div>
          <div class="analytics-kpi-value">{{ exceptionTasks.length }}</div>
        </el-card>
        <el-card shadow="never" class="analytics-summary-card">
          <div class="analytics-kpi-label">超时异常数</div>
          <div class="analytics-kpi-value">{{ overtimeExceptionCount }}</div>
          <div class="text-secondary">阈值 {{ overtimeThresholdMinutes }} 分钟</div>
        </el-card>
        <el-card shadow="never" class="analytics-summary-card">
          <div class="analytics-kpi-label">最长待处理时长</div>
          <div class="analytics-kpi-value">{{ longestPendingMinutes > 0 ? formatPendingDurationMinutes(longestPendingMinutes) : '-' }}</div>
          <div class="text-secondary">待处理 {{ pendingExceptionCount }} 条</div>
        </el-card>
      </div>

      <div v-if="filterForm.status === 'pending'" class="analytics-sub-summary-grid">
        <el-card shadow="never" class="analytics-sub-summary-card">
          <div class="analytics-kpi-label">待处理总量</div>
          <div class="analytics-sub-value">{{ pendingTotalCount }}</div>
        </el-card>
        <el-card shadow="never" class="analytics-sub-summary-card">
          <div class="analytics-kpi-label">已指派责任人</div>
          <div class="analytics-sub-value">{{ pendingAssignedCount }}</div>
        </el-card>
        <el-card shadow="never" class="analytics-sub-summary-card">
          <div class="analytics-kpi-label">未指派责任人</div>
          <div class="analytics-sub-value">{{ pendingUnassignedCount }}</div>
        </el-card>
        <el-card shadow="never" class="analytics-sub-summary-card">
          <div class="analytics-kpi-label">我负责的异常</div>
          <div class="analytics-sub-value">{{ pendingMyCount }}</div>
        </el-card>
        <el-card shadow="never" class="analytics-sub-summary-card">
          <div class="analytics-kpi-label">无反馈异常</div>
          <div class="analytics-sub-value">{{ pendingNoFeedbackCount }}</div>
        </el-card>
        <el-card shadow="never" class="analytics-sub-summary-card">
          <div class="analytics-kpi-label">反馈超时异常</div>
          <div class="analytics-sub-value">{{ pendingFeedbackTimeoutCount }}</div>
        </el-card>
      </div>

      <div v-if="filterForm.status === 'pending'" class="analytics-distribution-grid">
        <el-card shadow="never" v-if="exceptionTypeStats.length" class="analytics-panel-card">
          <div class="table-header">
            <div class="mobile-section-title">异常类型分布</div>
            <div class="text-secondary">当前待处理池内统计</div>
          </div>
          <el-space wrap>
            <el-tag
              v-for="item in exceptionTypeStats"
              :key="item.value"
              :type="filterForm.exception_type === item.value ? 'primary' : 'info'"
              class="order-tag-clickable"
              @click="filterForm.exception_type = filterForm.exception_type === item.value ? '' : item.value"
            >
              {{ item.label }}：{{ item.count }}
            </el-tag>
          </el-space>
        </el-card>

        <el-card shadow="never" v-if="recommendationStats.length" class="analytics-panel-card">
          <div class="table-header">
            <div class="mobile-section-title">建议动作分布</div>
            <div class="text-secondary">点击卡片可按推荐动作分流</div>
          </div>
          <div class="analytics-recommend-grid">
            <el-card
              v-for="item in recommendationStats"
              :key="item.action"
              shadow="hover"
              class="order-tag-clickable analytics-recommend-card"
              @click="applyRecommendationFilter(item.action)"
            >
              <div class="table-header">
                <div>{{ item.label }}</div>
                <el-tag :type="filterForm.recommendation_action === item.action ? 'primary' : item.type">
                  {{ item.count }} 条
                </el-tag>
              </div>
              <div class="text-secondary">
                {{ filterForm.recommendation_action === item.action ? '当前已按该建议筛选' : '点击查看该建议对应异常' }}
              </div>
            </el-card>
          </div>
        </el-card>

        <el-card shadow="never" v-if="feedbackTimeoutTypeStats.length" class="analytics-panel-card">
          <div class="table-header">
            <div class="mobile-section-title">反馈超时原因分布</div>
            <div class="text-secondary">按异常类型聚合，点击可联动筛选并催办</div>
          </div>
          <div class="analytics-list">
            <div
              v-for="item in feedbackTimeoutTypeStats"
              :key="`feedback-timeout-${item.value}`"
              class="analytics-list-row"
            >
              <el-tag
                :type="filterForm.exception_type === item.value && filterForm.feedback_filter === 'feedback_timeout' ? 'danger' : 'warning'"
                class="order-tag-clickable"
                @click="applyFeedbackTimeoutTypeFilter(item.value)"
              >
                {{ item.label }}：{{ item.count }}
              </el-tag>
              <el-button
                size="small"
                link
                type="danger"
                :loading="remindingException"
                @click="remindFeedbackTimeoutByType(item)"
              >
                催办
              </el-button>
            </div>
          </div>
        </el-card>
      </div>

      <div v-if="filterForm.status === 'pending'" class="analytics-ranking-grid">
        <el-card shadow="never" class="analytics-panel-card analytics-span-2">
          <div class="table-header">
            <div class="mobile-section-title">责任人反馈超时占比</div>
            <div class="text-secondary">Top 5</div>
          </div>
          <el-empty v-if="!assigneeFeedbackTimeoutRanking.length" :image-size="52" description="暂无反馈超时排行" />
          <div v-else class="ranking-card-body">
            <div
              v-for="item in assigneeFeedbackTimeoutRanking"
              :key="`assignee-feedback-timeout-${item.assigned_handler_id}`"
              class="analytics-list-row"
            >
              <span class="order-tag-clickable" @click="applyAssigneeFeedbackTimeoutFilter(item)">
                {{ getAssigneeRankingName(item) }}：{{ Number(item.feedback_timeout_count || 0) }}/{{ Number(item.pending_count || 0) }}（{{ formatRatioPercent(item.timeout_rate) }}）
              </span>
              <el-button
                size="small"
                link
                type="danger"
                :disabled="remindingException || Number(item.remindable_count || 0) <= 0"
                :loading="remindingException"
                @click="remindAssigneeFeedbackTimeout(item)"
              >
                催办
              </el-button>
            </div>
          </div>
        </el-card>

        <el-card shadow="never" class="analytics-panel-card analytics-span-2">
          <div class="table-header">
            <div class="mobile-section-title">责任人反馈及时率</div>
            <el-space wrap class="analytics-controls">
              <el-segmented
                v-model="assigneeTimelyWindow"
                :options="[
                  { label: '近7天', value: '7d' },
                  { label: '近30天', value: '30d' },
                ]"
                size="small"
              />
              <el-select v-model="filterForm.timely_rate_threshold" style="width: 120px" size="small">
                <el-option label="阈值 60%" value="60" />
                <el-option label="阈值 70%" value="70" />
                <el-option label="阈值 80%" value="80" />
                <el-option label="阈值 90%" value="90" />
              </el-select>
              <el-button
                size="small"
                plain
                :type="filterForm.timely_low_only ? 'danger' : 'info'"
                @click="filterForm.timely_low_only = !filterForm.timely_low_only"
              >
                仅看低于{{ timelyRateThresholdText }}
              </el-button>
            </el-space>
          </div>
          <el-empty v-if="!assigneeFeedbackTimelyRanking.length" :image-size="52" description="暂无及时率样本" />
          <div v-else class="ranking-card-body">
            <div
              v-for="item in assigneeFeedbackTimelyRanking"
              :key="`assignee-feedback-timely-${item.assigned_handler_id}`"
              class="analytics-list-row"
            >
              <span class="order-tag-clickable" @click="applyAssigneeRankingFilter(item)">
                {{ getAssigneeRankingName(item) }}：
                <el-tag size="small" :type="isTimelyRateLow(item) ? 'danger' : 'success'">
                  {{ formatRatioPercent(item.timely_feedback_rate) }}
                </el-tag>
                （{{ Number(item.timely_feedback_count || 0) }}）
              </span>
              <el-button
                size="small"
                link
                type="warning"
                :disabled="remindingException || Number(item.remindable_count || 0) <= 0"
                :loading="remindingException"
                @click="remindAssigneeTimelyLow(item)"
              >
                催办
              </el-button>
            </div>
          </div>
        </el-card>

        <el-card shadow="never" class="analytics-panel-card analytics-span-2">
          <div class="table-header">
            <div class="mobile-section-title">责任人绩效分布</div>
            <div class="text-secondary">Top 5</div>
          </div>
          <el-empty v-if="!assigneeRanking.length" :image-size="52" description="暂无责任人数据" />
          <div v-else class="ranking-card-body">
            <div v-for="item in assigneeRanking" :key="`assignee-rank-${item.assigned_handler_id}`" class="analytics-performance-row">
              <div>
                <span class="order-tag-clickable" @click="applyAssigneeRankingFilter(item)">
                  {{ getAssigneeRankingName(item) }}：待处理 {{ Number(item.pending_count || 0) }} 条
                </span>
                <span class="text-secondary">
                  （超时 {{ Number(item.overtime_count || 0) }}，严重 {{ Number(item.severe_count || 0) }}）
                </span>
                <div class="text-secondary">{{ formatAssigneeRecentTimelyStats(item) }}</div>
              </div>
              <el-button
                size="small"
                link
                type="warning"
                :disabled="remindingException || Number(item.pending_count || 0) <= 0"
                :loading="remindingException"
                @click="remindAssigneeRanking(item)"
              >
                催办
              </el-button>
            </div>
          </div>
        </el-card>

        <el-card shadow="never" class="analytics-panel-card">
          <div class="table-header">
            <div class="mobile-section-title">司机异常排行</div>
            <div class="text-secondary">Top 5</div>
          </div>
          <el-empty v-if="!driverExceptionRanking.length" :image-size="52" description="暂无异常数据" />
          <div v-else class="ranking-card-body">
            <div v-for="item in driverExceptionRanking" :key="`driver-rank-${item.key}`" class="analytics-list-row">
              <span class="order-tag-clickable" @click="applyDriverRankingFilter(item)">{{ item.name }}：{{ item.count }} 次</span>
            </div>
          </div>
        </el-card>

        <el-card shadow="never" class="analytics-panel-card">
          <div class="table-header">
            <div class="mobile-section-title">装货地异常排行</div>
            <div class="text-secondary">Top 5</div>
          </div>
          <el-empty v-if="!siteExceptionRanking.length" :image-size="52" description="暂无异常数据" />
          <div v-else class="ranking-card-body">
            <div v-for="item in siteExceptionRanking" :key="`site-rank-${item.key}`" class="analytics-list-row">
              <span class="order-tag-clickable" @click="applySiteRankingFilter(item)">{{ item.name }}：{{ item.count }} 次</span>
            </div>
          </div>
        </el-card>
      </div>
    </div>
    </template>
    <template v-if="!isAnalyticsPage">
    <el-form inline class="mb-12">
      <el-form-item label="处理状态">
        <el-select v-model="filterForm.status" style="width: 140px">
          <el-option label="待处理" value="pending" />
          <el-option label="已处理" value="handled" />
        </el-select>
      </el-form-item>
      <el-form-item label="任务编号">
        <el-input v-model="filterForm.task_no" clearable placeholder="请输入任务编号" style="width: 220px" />
      </el-form-item>
      <el-form-item label="异常类型">
        <el-select v-model="filterForm.exception_type" clearable placeholder="全部类型" style="width: 160px">
          <el-option
            v-for="(label, value) in exceptionTypeLabelMap"
            :key="value"
            :label="label"
            :value="value"
          />
        </el-select>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'handled'" label="处理动作">
        <el-select v-model="filterForm.handle_action" clearable placeholder="全部动作" style="width: 160px">
          <el-option
            v-for="(label, value) in exceptionActionLabelMap"
            :key="value"
            :label="label"
            :value="value"
          />
        </el-select>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'handled'" label="处理人">
        <el-input
          v-model="filterForm.handled_by_keyword"
          clearable
          placeholder="账号或姓名"
          style="width: 180px"
        />
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'handled'">
        <el-checkbox v-model="filterForm.handled_by_me">
          仅看我处理
          <span v-if="currentUser?.name || currentUser?.account">
            （{{ currentUser?.name || currentUser?.account }}）
          </span>
        </el-checkbox>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'">
        <el-checkbox v-model="filterForm.overtime_only">
          仅看超时异常（>{{ overtimeThresholdMinutes }} 分钟）
        </el-checkbox>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'">
        <el-checkbox v-model="filterForm.assigned_to_me">
          仅看我负责
          <span v-if="currentUser?.name || currentUser?.account">
            （{{ currentUser?.name || currentUser?.account }}）
          </span>
        </el-checkbox>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'" label="责任人">
        <el-select
          v-model="filterForm.assigned_handler_id"
          clearable
          filterable
          placeholder="全部责任人"
          style="width: 200px"
        >
          <el-option
            v-for="user in assignCandidates"
            :key="user.id"
            :label="`${user.name || user.account}（${user.account}）`"
            :value="user.id"
          />
        </el-select>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'" label="反馈筛选">
        <el-select v-model="filterForm.feedback_filter" clearable placeholder="全部反馈状态" style="width: 180px">
          <el-option label="仅看无反馈" value="no_feedback" />
          <el-option label="仅看超时未反馈" value="feedback_timeout" />
        </el-select>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending' && filterForm.feedback_filter === 'feedback_timeout'" label="反馈超时">
        <el-select v-model="filterForm.feedback_timeout_minutes" style="width: 180px">
          <el-option
            v-for="item in feedbackTimeoutOptions"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </el-select>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'" label="反馈排序">
        <el-select v-model="filterForm.sort_by_feedback" clearable placeholder="默认排序" style="width: 180px">
          <el-option label="按最近反馈时间" value="latest_feedback" />
        </el-select>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'" label="及时率筛选">
        <el-space wrap>
          <el-checkbox v-model="filterForm.timely_low_only">仅看低及时率责任人</el-checkbox>
          <el-select v-model="filterForm.timely_rate_threshold" style="width: 130px">
            <el-option label="60%" value="60" />
            <el-option label="70%" value="70" />
            <el-option label="80%" value="80" />
            <el-option label="90%" value="90" />
          </el-select>
        </el-space>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'" label="超时分层">
        <el-select v-model="filterForm.overtime_level" clearable placeholder="全部时长" style="width: 160px">
          <el-option
            v-for="item in overtimeLevelOptions"
            :key="item.value"
            :label="item.label"
            :value="item.value"
          />
        </el-select>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending' && (filterForm.driver_focus || filterForm.site_focus)" label="聚合筛选">
        <el-space wrap>
          <el-tag v-if="filterForm.driver_focus" closable @close="filterForm.driver_focus = ''">
            司机：{{ filterForm.driver_focus }}（命中 {{ aggregationMatchedCount }} 条）
          </el-tag>
          <el-tag v-if="filterForm.site_focus" closable @close="filterForm.site_focus = ''">
            装货地：{{ filterForm.site_focus }}（命中 {{ aggregationMatchedCount }} 条）
          </el-tag>
          <span class="text-secondary">当前列表命中 {{ aggregationMatchedCount }} 条异常</span>
          <el-button link type="primary" @click="clearAggregationFilter">清空聚合筛选</el-button>
        </el-space>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending' && filterForm.recommendation_action" label="建议筛选">
        <el-space wrap>
          <el-tag closable @close="filterForm.recommendation_action = ''">
            {{ getLabel(exceptionActionLabelMap, filterForm.recommendation_action) }}
          </el-tag>
        </el-space>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending' && filterForm.feedback_filter" label="反馈筛选">
        <el-space wrap>
          <el-tag closable @close="filterForm.feedback_filter = ''">
            {{ filterForm.feedback_filter === 'no_feedback' ? '仅看无反馈' : `仅看超时未反馈（${filterForm.feedback_timeout_minutes} 分钟）` }}
          </el-tag>
        </el-space>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending' && filterForm.timely_low_only" label="及时率筛选">
        <el-space wrap>
          <el-tag closable @close="filterForm.timely_low_only = false">
            仅看低于{{ timelyRateThresholdText }}责任人
          </el-tag>
        </el-space>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="currentPage = 1; loadExceptionTasks()">查询</el-button>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'">
        <el-button
          type="success"
          plain
          :loading="assigningExceptionHandler"
          @click="batchAssignToMe"
        >
          批量接管（{{ selectedTaskIds.length }}）
        </el-button>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'">
        <el-button
          type="warning"
          plain
          :loading="remindingException"
          @click="batchRemindSelected"
        >
          批量催办（{{ selectedTaskIds.length }}）
        </el-button>
      </el-form-item>
      <el-form-item v-if="filterForm.status === 'pending'">
        <el-button
          type="danger"
          plain
          :loading="remindingException"
          @click="batchRemindFeedbackTimeout"
        >
          催办反馈超时（{{ feedbackTimeoutRemindableTaskIds.length }}）
        </el-button>
      </el-form-item>
    </el-form>
    <div class="page-table-section">
    <div class="page-table-wrap">
    <el-table
      :data="pagedExceptionTasks"
      stripe
      v-loading="loadingExceptions"
      height="100%"
      class="page-table"
      row-key="id"
      @selection-change="handleSelectionChange"
    >
      <el-table-column type="selection" width="48" :selectable="(row) => row.route_meta?.exception?.status === 'pending'" />
      <el-table-column prop="task_no" label="任务编号" min-width="180" />
      <el-table-column label="当前状态" min-width="110">
        <template #default="{ row }">
          {{ getLabel(taskStatusLabelMap, row.status) }}
        </template>
      </el-table-column>
      <el-table-column label="异常状态" min-width="100">
        <template #default="{ row }">
          <el-tag :type="exceptionStatusTagTypeMap[row.route_meta?.exception?.status] || 'info'">
            {{ getLabel(exceptionStatusLabelMap, row.route_meta?.exception?.status) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="司机" min-width="160">
        <template #default="{ row }">
          {{ row.driver?.name || '-' }}（{{ row.driver?.account || '-' }}）
        </template>
      </el-table-column>
      <el-table-column label="车辆" min-width="160">
        <template #default="{ row }">
          {{ row.vehicle?.plate_number || '-' }} {{ row.vehicle?.name || '' }}
        </template>
      </el-table-column>
      <el-table-column label="异常类型" min-width="120">
        <template #default="{ row }">
          {{ getLabel(exceptionTypeLabelMap, row.route_meta?.exception?.type) }}
        </template>
      </el-table-column>
      <el-table-column label="异常说明" min-width="260">
        <template #default="{ row }">
          {{ row.route_meta?.exception?.description || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="上报时间" min-width="180">
        <template #default="{ row }">
          {{ formatDateTime(row.route_meta?.exception?.reported_at) }}
        </template>
      </el-table-column>
      <el-table-column label="待处理时长" min-width="130">
        <template #default="{ row }">
          <el-tag
            v-if="row.route_meta?.exception?.status === 'pending'"
            :type="getPendingDurationTagType(row)"
          >
            {{ formatPendingDuration(row) }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="SLA 状态" min-width="120">
        <template #default="{ row }">
          <el-tag
            v-if="row.route_meta?.exception?.status === 'pending'"
            :type="getSlaLevel(row).type"
          >
            {{ getSlaLevel(row).label }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="SLA 剩余" min-width="120">
        <template #default="{ row }">
          <el-tag
            v-if="row.route_meta?.exception?.status === 'pending'"
            :type="getPendingDurationTagType(row)"
          >
            {{ formatSlaRemaining(row) }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="下次催办" min-width="120">
        <template #default="{ row }">
          <el-tag
            v-if="row.route_meta?.exception?.status === 'pending'"
            :type="getPendingDurationTagType(row)"
          >
            {{ formatNextReminder(row) }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="催办状态" min-width="120">
        <template #default="{ row }">
          <el-tag v-if="row.route_meta?.exception?.status === 'pending'" :type="isTaskInReminderCooldown(row) ? 'info' : 'success'">
            {{ isTaskInReminderCooldown(row) ? '冷却中' : '可催办' }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="反馈 SLA" min-width="120">
        <template #default="{ row }">
          <el-tag v-if="row.route_meta?.exception?.status === 'pending'" :type="getFeedbackSlaTag(row).type">
            {{ getFeedbackSlaTag(row).label }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="反馈 SLA 剩余" min-width="130">
        <template #default="{ row }">
          <el-tag
            v-if="row.route_meta?.exception?.status === 'pending'"
            :type="isFeedbackTimeoutTask(row) ? 'danger' : 'info'"
          >
            {{ formatFeedbackSlaRemaining(row) }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="最近催办时间" min-width="180">
        <template #default="{ row }">
          {{ formatLastReminder(row) }}
        </template>
      </el-table-column>
      <el-table-column label="最近催办人" min-width="150">
        <template #default="{ row }">
          {{ formatLastReminderOperator(row) }}
        </template>
      </el-table-column>
      <el-table-column label="处理建议" min-width="220">
        <template #default="{ row }">
          <el-tag :type="getExceptionRecommendation(row).type">
            {{ getExceptionRecommendation(row).label }}
          </el-tag>
          <div class="text-secondary">{{ getExceptionRecommendation(row).reason }}</div>
        </template>
      </el-table-column>
      <el-table-column label="处理动作" min-width="120">
        <template #default="{ row }">
          <el-tag
            v-if="row.route_meta?.exception?.handle_action"
            :type="exceptionActionTagTypeMap[row.route_meta?.exception?.handle_action] || 'info'"
          >
            {{ getLabel(exceptionActionLabelMap, row.route_meta?.exception?.handle_action) }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="处理人" min-width="160">
        <template #default="{ row }">
          {{
            formatOperator(
              row.route_meta?.exception?.handled_by_name,
              row.route_meta?.exception?.handled_by_account,
              row.route_meta?.exception?.handled_by,
            )
          }}
        </template>
      </el-table-column>
      <el-table-column label="当前责任人" min-width="170">
        <template #default="{ row }">
          {{
            formatOperator(
              row.route_meta?.exception?.assigned_handler_name,
              row.route_meta?.exception?.assigned_handler_account,
              row.route_meta?.exception?.assigned_handler_id,
            )
          }}
        </template>
      </el-table-column>
      <el-table-column label="操作" width="340" fixed="right">
        <template #default="{ row }">
          <el-button link type="info" @click="openDetailDialog(row)">详情</el-button>
          <el-button link type="warning" :disabled="row.route_meta?.exception?.status !== 'pending'" @click="openAssignDialog(row)">指派</el-button>
          <el-button link type="success" :disabled="row.route_meta?.exception?.status !== 'pending'" @click="assignToMe(row)">接管</el-button>
          <el-button link type="warning" :disabled="row.route_meta?.exception?.status !== 'pending' || isTaskInReminderCooldown(row)" @click="remindSingleTask(row)">催办</el-button>
          <el-button link type="primary" :disabled="row.route_meta?.exception?.status !== 'pending'" @click="openFeedbackDialog(row)">反馈</el-button>
          <el-button link type="primary" :disabled="row.route_meta?.exception?.status !== 'pending'" @click="openHandleDialog(row)">处理</el-button>
        </template>
      </el-table-column>
    </el-table>
    </div>
    <div class="page-pagination">
      <el-pagination
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        layout="sizes, prev, pager, next, jumper, total"
        :page-sizes="[10, 20, 50, 100]"
        :total="exceptionTaskTotal"
      />
    </div>
    </div>
    </template>
  </el-card>
  </div>

  <el-dialog
    v-if="!isAnalyticsPage"
    v-model="exceptionHandleDialogVisible"
    title="处理任务异常"
    width="620px"
    destroy-on-close
  >
    <el-form label-width="90px">
      <el-form-item v-if="currentHandlingRecommendation" label="推荐方案">
        <div style="width: 100%">
          <el-alert
            :closable="false"
            show-icon
            :type="currentHandlingRecommendation.type"
            :title="currentHandlingRecommendation.label"
            :description="currentHandlingRecommendation.reason"
          />
          <el-button class="mt-8" size="small" type="primary" plain @click="applyRecommendedHandleAction">
            套用推荐动作
          </el-button>
          <div class="text-secondary mt-8">
            推荐备注：{{ currentHandlingRecommendationNote || '-' }}
          </div>
        </div>
      </el-form-item>
      <el-form-item label="任务编号">
        <span>{{ handlingTask?.task_no || '-' }}</span>
      </el-form-item>
      <el-form-item label="处理动作">
        <el-radio-group v-model="exceptionHandleForm.action">
          <el-radio value="continue">继续执行</el-radio>
          <el-radio value="cancel">取消任务</el-radio>
          <el-radio value="reassign">改派车辆</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="exceptionHandleForm.action === 'reassign'" label="目标车辆">
        <el-select
          v-model="exceptionHandleForm.reassign_vehicle_id"
          style="width: 100%"
          placeholder="请选择空闲车辆"
        >
          <el-option
            v-for="vehicle in vehicles.filter((item) => item.status === 'idle')"
            :key="vehicle.id"
            :label="`${vehicle.plate_number}｜${vehicle.name}`"
            :value="vehicle.id"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="处理备注">
        <el-input
          v-model="exceptionHandleForm.handle_note"
          type="textarea"
          :rows="3"
          maxlength="500"
          show-word-limit
          placeholder="可选，建议记录处理原因"
        />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="exceptionHandleDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="handlingException" @click="submitHandleException">确认处理</el-button>
    </template>
  </el-dialog>

  <el-dialog
    v-if="!isAnalyticsPage"
    v-model="exceptionAssignDialogVisible"
    title="改派异常责任人"
    width="560px"
    destroy-on-close
  >
    <el-form label-width="90px">
      <el-form-item label="任务编号">
        <span>{{ assigningTask?.task_no || '-' }}</span>
      </el-form-item>
      <el-form-item label="责任人">
        <el-select
          v-model="exceptionAssignForm.assigned_handler_id"
          style="width: 100%"
          filterable
          placeholder="请选择责任人"
        >
          <el-option
            v-for="user in assignCandidates"
            :key="user.id"
            :label="`${user.name || user.account}（${user.account}）`"
            :value="user.id"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="备注">
        <el-input
          v-model="exceptionAssignForm.assign_note"
          type="textarea"
          :rows="3"
          maxlength="500"
          show-word-limit
          placeholder="可选，建议记录改派原因"
        />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="exceptionAssignDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="assigningExceptionHandler" @click="submitAssignExceptionHandler">确认改派</el-button>
    </template>
  </el-dialog>

  <el-dialog
    v-if="!isAnalyticsPage"
    v-model="exceptionFeedbackDialogVisible"
    title="提交异常反馈"
    width="560px"
    destroy-on-close
  >
    <el-form label-width="90px">
      <el-form-item label="任务编号">
        <span>{{ feedbackTask?.task_no || '-' }}</span>
      </el-form-item>
      <el-form-item label="反馈内容">
        <el-input
          v-model="exceptionFeedbackForm.feedback_content"
          type="textarea"
          :rows="4"
          maxlength="500"
          show-word-limit
          placeholder="请填写处理进展或下一步计划"
        />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="exceptionFeedbackDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="submittingFeedback" @click="submitExceptionFeedback">确认提交</el-button>
    </template>
  </el-dialog>

  <el-drawer
    v-if="!isAnalyticsPage"
    v-model="exceptionDetailDialogVisible"
    title="异常处理详情"
    size="720px"
    destroy-on-close
  >
    <template v-if="selectedExceptionTask && currentException">
      <el-descriptions :column="2" border size="small">
        <el-descriptions-item label="任务编号">{{ selectedExceptionTask.task_no || '-' }}</el-descriptions-item>
        <el-descriptions-item label="任务状态">{{ getLabel(taskStatusLabelMap, selectedExceptionTask.status) }}</el-descriptions-item>
        <el-descriptions-item label="异常状态">
          <el-tag :type="exceptionStatusTagTypeMap[currentException.status] || 'info'">
            {{ getLabel(exceptionStatusLabelMap, currentException.status) }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="异常类型">{{ getLabel(exceptionTypeLabelMap, currentException.type) }}</el-descriptions-item>
        <el-descriptions-item label="当前司机">
          {{ selectedExceptionTask.driver?.name || '-' }}（{{ selectedExceptionTask.driver?.account || '-' }}）
        </el-descriptions-item>
        <el-descriptions-item label="当前车辆">
          {{ selectedExceptionTask.vehicle?.plate_number || '-' }} {{ selectedExceptionTask.vehicle?.name || '' }}
        </el-descriptions-item>
        <el-descriptions-item label="上报时间">{{ formatDateTime(currentException.reported_at) }}</el-descriptions-item>
        <el-descriptions-item label="处理时间">{{ formatDateTime(currentException.handled_at) }}</el-descriptions-item>
        <el-descriptions-item label="SLA 阈值">{{ Number.isFinite(Number(currentException.sla?.policy_minutes)) ? `${currentException.sla.policy_minutes} 分钟` : '-' }}</el-descriptions-item>
        <el-descriptions-item label="已等待时长">{{ Number.isFinite(Number(currentException.sla?.pending_minutes)) ? formatPendingDurationMinutes(Number(currentException.sla.pending_minutes)) : '-' }}</el-descriptions-item>
        <el-descriptions-item label="催办间隔">{{ Number.isFinite(Number(currentException.sla?.reminder_interval_minutes)) ? `${currentException.sla.reminder_interval_minutes} 分钟` : '-' }}</el-descriptions-item>
        <el-descriptions-item label="下次催办">{{ formatNextReminderMinutes(currentException.sla?.next_reminder_minutes) }}</el-descriptions-item>
        <el-descriptions-item label="催办次数">{{ Number.isFinite(Number(currentException.sla?.reminder_count)) ? Number(currentException.sla.reminder_count) : 0 }}</el-descriptions-item>
        <el-descriptions-item label="最近催办时间">{{ formatDateTime(currentException.last_reminded_at || currentException.sla?.last_notice_at) }}</el-descriptions-item>
        <el-descriptions-item label="最近催办人">
          {{ formatOperator(currentException.last_reminded_by_name, currentException.last_reminded_by_account, currentException.last_reminded_by) }}
        </el-descriptions-item>
        <el-descriptions-item label="最近反馈时间">{{ formatDateTime(currentException.last_feedback_at) }}</el-descriptions-item>
        <el-descriptions-item label="最近反馈人">
          {{ formatOperator(currentException.last_feedback_by_name, currentException.last_feedback_by_account, currentException.last_feedback_by) }}
        </el-descriptions-item>
        <el-descriptions-item label="反馈 SLA 阈值">
          {{ Number.isFinite(Number(currentException.sla?.feedback_policy_minutes)) ? `${currentException.sla.feedback_policy_minutes} 分钟` : '-' }}
        </el-descriptions-item>
        <el-descriptions-item label="距上次反馈">
          {{ Number.isFinite(Number(currentException.sla?.feedback_pending_minutes)) ? formatPendingDurationMinutes(Number(currentException.sla.feedback_pending_minutes)) : '-' }}
        </el-descriptions-item>
        <el-descriptions-item label="反馈 SLA 剩余">{{ formatFeedbackSlaRemaining(selectedExceptionTask) }}</el-descriptions-item>
        <el-descriptions-item label="反馈 SLA 状态">
          <el-tag :type="getFeedbackSlaTag(selectedExceptionTask).type">
            {{ getFeedbackSlaTag(selectedExceptionTask).label }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="最近反馈内容" :span="2">{{ currentException.last_feedback_content || '-' }}</el-descriptions-item>
        <el-descriptions-item label="处理动作">
          {{ getLabel(exceptionActionLabelMap, currentException.handle_action) }}
        </el-descriptions-item>
        <el-descriptions-item label="上报人">
          {{ formatOperator(currentException.reported_by_name, currentException.reported_by_account, currentException.reported_by) }}
        </el-descriptions-item>
        <el-descriptions-item label="处理人">
          {{ formatOperator(currentException.handled_by_name, currentException.handled_by_account, currentException.handled_by) }}
        </el-descriptions-item>
        <el-descriptions-item label="当前责任人">
          {{ formatOperator(currentException.assigned_handler_name, currentException.assigned_handler_account, currentException.assigned_handler_id) }}
        </el-descriptions-item>
        <el-descriptions-item label="关联节点">{{ currentException.waypoint_id || '-' }}</el-descriptions-item>
        <el-descriptions-item label="异常说明" :span="2">{{ currentException.description || '-' }}</el-descriptions-item>
        <el-descriptions-item label="处理备注" :span="2">{{ currentException.handle_note || '-' }}</el-descriptions-item>
      </el-descriptions>

      <el-divider content-position="left">快捷联动</el-divider>
      <el-space wrap class="mb-12">
        <el-button type="primary" @click="jumpToDispatchTask">查看调度任务订单明细</el-button>
        <el-button @click="jumpToPrePlanOrder" :disabled="!primaryTaskOrder?.id">查看关联预计划单</el-button>
        <el-button
          v-if="currentException.status === 'pending'"
          type="warning"
          plain
          @click="openAssignDialog(selectedExceptionTask)"
        >
          改派责任人
        </el-button>
        <el-button
          v-if="currentException.status === 'pending'"
          type="success"
          plain
          @click="assignToMe(selectedExceptionTask)"
        >
          我来接管
        </el-button>
        <el-button
          v-if="currentException.status === 'pending'"
          type="primary"
          plain
          @click="openFeedbackDialog(selectedExceptionTask)"
        >
          提交反馈
        </el-button>
      </el-space>

      <el-divider content-position="left">处理建议</el-divider>
      <el-alert
        v-if="currentExceptionRecommendation"
        class="mb-12"
        :closable="false"
        show-icon
        :type="currentExceptionRecommendation.type"
        :title="currentExceptionRecommendation.label"
        :description="currentExceptionRecommendation.reason"
      />

      <el-divider content-position="left">处理前后变化</el-divider>
      <el-descriptions :column="1" border size="small">
        <el-descriptions-item label="任务状态">
          {{ formatEntityChange('状态', getLabel(taskStatusLabelMap, currentException.previous_task_status), getLabel(taskStatusLabelMap, currentException.current_task_status)) }}
        </el-descriptions-item>
        <el-descriptions-item label="车辆变更">
          {{
            formatEntityChange(
              '车辆',
              formatVehicleDisplay(currentException.previous_vehicle_plate_number, currentException.previous_vehicle_name, currentException.previous_vehicle_id),
              formatVehicleDisplay(currentException.current_vehicle_plate_number, currentException.current_vehicle_name, currentException.current_vehicle_id),
            )
          }}
        </el-descriptions-item>
        <el-descriptions-item label="司机变更">
          {{
            formatEntityChange(
              '司机',
              formatDriverDisplay(currentException.previous_driver_name, currentException.previous_driver_account, currentException.previous_driver_id),
              formatDriverDisplay(currentException.current_driver_name, currentException.current_driver_account, currentException.current_driver_id),
            )
          }}
        </el-descriptions-item>
      </el-descriptions>

      <el-divider content-position="left">反馈 SLA 趋势</el-divider>
      <el-empty v-if="!feedbackSlaTrendSegments.length" description="暂无反馈趋势数据" />
      <el-timeline v-else>
        <el-timeline-item
          v-for="item in feedbackSlaTrendSegments"
          :key="item.key"
          :timestamp="`${formatDateTime(item.start_at)} ~ ${formatDateTime(item.end_at)}`"
          placement="top"
        >
          <el-card shadow="never">
            <div class="table-header mb-8">
              <strong>{{ item.label }}</strong>
              <el-space>
                <el-tag :type="item.stage === 'pending' ? 'warning' : 'info'" effect="plain">
                  {{ item.stage === 'pending' ? '当前时段' : '历史时段' }}
                </el-tag>
                <el-tag :type="item.is_overtime ? 'danger' : 'success'">
                  {{ item.is_overtime ? '已超时' : '未超时' }}
                </el-tag>
              </el-space>
            </div>
            <div>间隔时长：{{ formatPendingDurationMinutes(Number(item.gap_minutes || 0)) }}（阈值 {{ Number(item.threshold_minutes || 0) }} 分钟）</div>
            <div v-if="item.start_detail">起点信息：{{ item.start_detail }}</div>
            <div v-if="item.end_detail">终点信息：{{ item.end_detail }}</div>
            <div v-if="item.operator">反馈人：{{ item.operator }}</div>
          </el-card>
        </el-timeline-item>
      </el-timeline>

      <el-divider content-position="left">关联订单明细</el-divider>
      <div class="page-table-section" style="height: 280px">
      <div class="page-table-wrap">
      <el-table :data="pagedSelectedTaskOrders" size="small" stripe height="100%" class="page-table">
        <el-table-column prop="order_no" label="订单号" min-width="160" />
        <el-table-column prop="client_name" label="客户" min-width="140" />
        <el-table-column prop="pickup_address" label="装货地" min-width="180" show-overflow-tooltip />
        <el-table-column prop="dropoff_address" label="卸货地" min-width="180" show-overflow-tooltip />
        <el-table-column label="审核状态" min-width="100">
          <template #default="{ row }">
            {{ getLabel(auditStatusLabelMap, row.audit_status) }}
          </template>
        </el-table-column>
        <el-table-column label="订单状态" min-width="100">
          <template #default="{ row }">
            {{ getLabel(taskStatusLabelMap, row.status) }}
          </template>
        </el-table-column>
      </el-table>
      </div>
      <div class="page-pagination">
        <el-pagination
          v-model:current-page="detailOrderCurrentPage"
          v-model:page-size="detailOrderPageSize"
          layout="sizes, prev, pager, next, jumper, total"
          :page-sizes="[10, 20, 50, 100]"
          :total="selectedTaskOrderTotal"
        />
      </div>
      </div>

      <el-divider content-position="left">异常处理轨迹</el-divider>
      <el-timeline>
        <el-timeline-item
          v-for="(item, index) in currentExceptionHistory"
          :key="`${item.event || 'event'}-${index}`"
          :timestamp="formatDateTime(item.occurred_at)"
          placement="top"
        >
          <el-card shadow="never">
            <div class="mb-8">
              <strong>{{ getHistoryEventLabel(item.event) }}</strong>
            </div>
            <div>异常类型：{{ getLabel(exceptionTypeLabelMap, item.type) }}</div>
            <div v-if="item.description">异常说明：{{ item.description }}</div>
            <div v-if="item.action">处理动作：{{ getLabel(exceptionActionLabelMap, item.action) }}</div>
            <div v-if="item.handle_note">处理备注：{{ item.handle_note }}</div>
            <div v-if="item.remind_note">催办说明：{{ item.remind_note }}</div>
            <div v-if="item.feedback_content">反馈内容：{{ item.feedback_content }}</div>
            <div v-if="item.feedback_policy_minutes">反馈SLA阈值：{{ item.feedback_policy_minutes }} 分钟</div>
            <div v-if="item.feedback_pending_minutes || item.feedback_pending_minutes === 0">
              触发时反馈间隔：{{ item.feedback_pending_minutes }} 分钟
            </div>
            <div v-if="item.level_label">预警等级：{{ item.level_label }}</div>
            <div v-if="item.threshold_minutes">触发阈值：{{ item.threshold_minutes }} 分钟</div>
            <div v-if="item.assigned_handler_name || item.assigned_handler_account">
              指派责任人：{{ formatOperator(item.assigned_handler_name, item.assigned_handler_account, item.assigned_handler_id) }}
            </div>
            <div>操作人：{{ formatOperator(item.operator_name, item.operator_account, item.operator_id) }}</div>
            <div v-if="item.previous_task_status || item.current_task_status">
              任务状态：{{ getLabel(taskStatusLabelMap, item.previous_task_status) }} -> {{ getLabel(taskStatusLabelMap, item.current_task_status) }}
            </div>
            <div v-if="item.previous_vehicle_id || item.current_vehicle_id">
              车辆变更：{{
                formatVehicleDisplay(item.previous_vehicle_plate_number, item.previous_vehicle_name, item.previous_vehicle_id)
              }} -> {{
                formatVehicleDisplay(item.current_vehicle_plate_number, item.current_vehicle_name, item.current_vehicle_id)
              }}
            </div>
            <div v-if="item.previous_driver_id || item.current_driver_id">
              司机变更：{{
                formatDriverDisplay(item.previous_driver_name, item.previous_driver_account, item.previous_driver_id)
              }} -> {{
                formatDriverDisplay(item.current_driver_name, item.current_driver_account, item.current_driver_id)
              }}
            </div>
          </el-card>
        </el-timeline-item>
      </el-timeline>
    </template>
    <el-empty v-else description="暂无异常详情" />
  </el-drawer>
</template>

<style scoped>
.analytics-layout {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.analytics-summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.analytics-summary-card {
  border-color: #e5ebf5;
}

.analytics-kpi-label {
  color: #6b7280;
  font-size: 12px;
}

.analytics-kpi-value {
  margin-top: 8px;
  color: #1f2937;
  font-size: 28px;
  font-weight: 600;
  line-height: 1.2;
}

.analytics-sub-summary-grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 12px;
}

.analytics-sub-summary-card {
  border-color: #e5ebf5;
}

.analytics-sub-value {
  margin-top: 6px;
  color: #111827;
  font-size: 24px;
  font-weight: 600;
  line-height: 1.2;
}

.analytics-distribution-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.analytics-panel-card {
  border-color: #e5ebf5;
}

.analytics-recommend-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.analytics-recommend-card {
  border: 1px solid #e9eef8;
}

.analytics-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.analytics-list-row {
  min-height: 30px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.analytics-ranking-grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 12px;
}

.analytics-span-2 {
  grid-column: span 2;
}

.analytics-controls {
  justify-content: flex-end;
}

.analytics-performance-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  padding: 2px 0;
}

.ranking-card-body {
  max-height: 188px;
  overflow-y: auto;
  padding-right: 4px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

@media (max-width: 1600px) {
  .analytics-sub-summary-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .analytics-ranking-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .analytics-span-2 {
    grid-column: span 1;
  }
}

@media (max-width: 1200px) {
  .analytics-summary-grid,
  .analytics-distribution-grid,
  .analytics-recommend-grid,
  .analytics-sub-summary-grid,
  .analytics-ranking-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 768px) {
  .analytics-summary-grid,
  .analytics-distribution-grid,
  .analytics-recommend-grid,
  .analytics-sub-summary-grid,
  .analytics-ranking-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .analytics-kpi-value {
    font-size: 24px;
  }

  .analytics-sub-value {
    font-size: 20px;
  }
}
</style>
