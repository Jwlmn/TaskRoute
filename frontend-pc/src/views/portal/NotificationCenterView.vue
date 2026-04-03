<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { hasPermission, readCurrentUser } from '../../utils/auth'
import { getLabel } from '../../utils/labels'
import PrePlanOrderDetailContent from '../../components/pre-plan-order/PrePlanOrderDetailContent.vue'
import {
  auditStatusLabelMap,
  auditStatusTypeMap,
  buildNotificationListPayload,
  formatNotificationTime,
  getNotificationAuditStatus,
  getNotificationOrderNo,
  getNotificationReadLabel,
  getNotificationReadTagType,
  loadNotificationOrderDetail,
  sortNotificationMessages,
} from '../../utils/prePlanOrder'

const router = useRouter()
const route = useRoute()
const loading = ref(false)
const pinningId = ref(null)
const messages = ref([])
const selectedIds = ref([])
const expandedMessageKeys = ref([])
const currentPage = ref(1)
const pageSize = ref(10)
const detailDialogVisible = ref(false)
const detailLoading = ref(false)
const detailOrder = ref(null)
const detailCompareLoading = ref(false)
const detailCompareRows = ref([])
const savedFilterViews = ref([])
const FILTER_VIEWS_STORAGE_KEY = 'taskroute.notification.filterViews'

const currentUser = computed(() => readCurrentUser())
const isDispatchNotificationUser = computed(() => hasPermission(currentUser.value, 'dispatch'))
const isCustomerNotificationUser = computed(() => hasPermission(currentUser.value, 'customer_orders'))

const filterForm = ref({
  keyword: '',
  read_status: 'all',
  message_type: '',
  dispatch_notice_type: '',
  unread_reminder_only: false,
  unread_feedback_only: false,
  pinned_only: false,
  task_focus: '',
})

const messageTypeLabelMap = {
  audit_notice: '审核通知',
  audit_reminder: '审核催办',
  dispatch_notice: '调度通知',
}
const dispatchNoticeTypeLabelMap = {
  exception_sla: '异常SLA预警',
  exception_sla_reminder: '异常SLA催办',
  exception_feedback_sla: '异常反馈SLA催办',
  exception_sla_assign: '异常自动指派',
  exception_manual_assign: '异常人工改派',
  exception_manual_reminder: '异常人工催办',
  exception_manual_feedback: '异常人工反馈',
}
const getDispatchNoticeType = (message) => String(message?.meta?.notice_type || '')
const reminderNoticeTypes = ['exception_manual_reminder', 'exception_sla_reminder', 'exception_feedback_sla']
const hasUnreadDispatchNoticeTypes = (row, noticeTypes) => {
  if (row?.message_type !== 'dispatch_notice') return false
  const typeSet = new Set((Array.isArray(noticeTypes) ? noticeTypes : []).map((item) => String(item)))
  if (!typeSet.size) return false
  const aggregateItems = Array.isArray(row?.aggregate_items) ? row.aggregate_items : []
  if (aggregateItems.length > 0) {
    return aggregateItems.some((entry) => !entry?.read_at && typeSet.has(getDispatchNoticeType(entry)))
  }
  return !row?.read_at && typeSet.has(getDispatchNoticeType(row))
}

const getMessageRowIds = (row) => {
  if (Array.isArray(row?.aggregate_ids) && row.aggregate_ids.length) {
    return row.aggregate_ids.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0)
  }
  return row?.id ? [Number(row.id)] : []
}

const displayMessages = computed(() => {
  const grouped = new Map()

  messages.value.forEach((message) => {
    const taskId = Number(message?.meta?.task_id || 0)
    if (message?.message_type !== 'dispatch_notice' || !taskId) {
      grouped.set(`single-${message.id}`, {
        ...message,
        aggregate_key: `single-${message.id}`,
        aggregate_count: 1,
        aggregate_ids: [message.id],
        unread_count: message?.read_at ? 0 : 1,
        aggregate_items: [message],
      })
      return
    }

    const key = `dispatch-${taskId}`
    const current = grouped.get(key)
    if (!current) {
      grouped.set(key, {
        ...message,
        aggregate_key: key,
        aggregate_count: 1,
        aggregate_ids: [message.id],
        unread_count: message?.read_at ? 0 : 1,
        aggregate_items: [message],
      })
      return
    }

    current.aggregate_count += 1
    current.aggregate_ids.push(message.id)
    current.aggregate_items.push(message)
    current.unread_count += message?.read_at ? 0 : 1
    current.is_pinned = current.is_pinned || message.is_pinned

    if (!current.read_at || !message.read_at) {
      current.read_at = null
    }

    if (String(message?.created_at || '') > String(current?.created_at || '')) {
      Object.assign(current, {
        ...current,
        ...message,
        aggregate_count: current.aggregate_count,
        aggregate_ids: current.aggregate_ids,
        unread_count: current.unread_count,
        is_pinned: current.is_pinned,
        read_at: current.unread_count > 0 ? null : message.read_at,
        aggregate_items: current.aggregate_items,
      })
    }
  })

  const list = [...grouped.values()]
  const filteredByDispatchNoticeType = filterForm.value.dispatch_notice_type
    ? list.filter((item) => {
      if (item?.message_type !== 'dispatch_notice') return false
      const aggregateItems = Array.isArray(item?.aggregate_items) ? item.aggregate_items : []
      if (aggregateItems.length > 0) {
        return aggregateItems.some((entry) => getDispatchNoticeType(entry) === filterForm.value.dispatch_notice_type)
      }
      return getDispatchNoticeType(item) === filterForm.value.dispatch_notice_type
    })
    : list
  const filteredByQuickSwitch = filteredByDispatchNoticeType
    .filter((item) => (filterForm.value.unread_reminder_only ? hasUnreadDispatchNoticeTypes(item, reminderNoticeTypes) : true))
    .filter((item) => (filterForm.value.unread_feedback_only ? hasUnreadDispatchNoticeTypes(item, ['exception_manual_feedback']) : true))
  if (!filterForm.value.task_focus) return filteredByQuickSwitch
  return filteredByQuickSwitch.filter((item) => String(item?.meta?.task_id || '') === String(filterForm.value.task_focus))
})
const pagedMessages = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return displayMessages.value.slice(start, start + pageSize.value)
})
const totalMessages = computed(() => displayMessages.value.length)
const unreadReminderCount = computed(() => messages.value
  .filter((item) => item?.message_type === 'dispatch_notice')
  .filter((item) => !item?.read_at)
  .filter((item) => reminderNoticeTypes.includes(getDispatchNoticeType(item)))
  .length)
const unreadFeedbackCount = computed(() => messages.value
  .filter((item) => item?.message_type === 'dispatch_notice')
  .filter((item) => !item?.read_at)
  .filter((item) => getDispatchNoticeType(item) === 'exception_manual_feedback')
  .length)
const isExpandedMessageRow = (row) => expandedMessageKeys.value.includes(row?.aggregate_key)
const toggleExpandedMessageRow = (row) => {
  if (!row?.aggregate_key || row.aggregate_count <= 1) return
  if (isExpandedMessageRow(row)) {
    expandedMessageKeys.value = expandedMessageKeys.value.filter((key) => key !== row.aggregate_key)
    return
  }
  expandedMessageKeys.value = [...expandedMessageKeys.value, row.aggregate_key]
}
const getAggregateItems = (row) => {
  const items = Array.isArray(row?.aggregate_items) ? row.aggregate_items : []
  return [...items].sort((a, b) => String(b?.created_at || '').localeCompare(String(a?.created_at || '')))
}
const focusTaskMessages = (row) => {
  const taskId = String(row?.meta?.task_id || '')
  if (!taskId) return
  filterForm.value.task_focus = taskId
}
const clearTaskFocus = () => {
  filterForm.value.task_focus = ''
}
const toggleUnreadReminderOnly = () => {
  filterForm.value.unread_reminder_only = !filterForm.value.unread_reminder_only
  if (filterForm.value.unread_reminder_only) {
    filterForm.value.unread_feedback_only = false
  }
}
const toggleUnreadFeedbackOnly = () => {
  filterForm.value.unread_feedback_only = !filterForm.value.unread_feedback_only
  if (filterForm.value.unread_feedback_only) {
    filterForm.value.unread_reminder_only = false
  }
}
const clearUnreadQuickFilters = () => {
  filterForm.value.unread_reminder_only = false
  filterForm.value.unread_feedback_only = false
}
const buildCurrentFilterSnapshot = () => ({
  keyword: String(filterForm.value.keyword || ''),
  read_status: String(filterForm.value.read_status || 'all'),
  message_type: String(filterForm.value.message_type || ''),
  dispatch_notice_type: String(filterForm.value.dispatch_notice_type || ''),
  unread_reminder_only: Boolean(filterForm.value.unread_reminder_only),
  unread_feedback_only: Boolean(filterForm.value.unread_feedback_only),
  pinned_only: Boolean(filterForm.value.pinned_only),
  task_focus: String(filterForm.value.task_focus || ''),
})
const loadSavedFilterViews = () => {
  try {
    const raw = window.localStorage.getItem(FILTER_VIEWS_STORAGE_KEY)
    const parsed = raw ? JSON.parse(raw) : []
    savedFilterViews.value = Array.isArray(parsed) ? parsed : []
  } catch {
    savedFilterViews.value = []
  }
}
const persistSavedFilterViews = () => {
  try {
    window.localStorage.setItem(FILTER_VIEWS_STORAGE_KEY, JSON.stringify(savedFilterViews.value))
  } catch {
    // ignore storage errors
  }
}
const saveCurrentFilterView = async () => {
  const name = window.prompt('请输入筛选视图名称（最多20字）')
  const normalizedName = String(name || '').trim().slice(0, 20)
  if (!normalizedName) {
    ElMessage.info('已取消保存筛选视图')
    return
  }
  const snapshot = buildCurrentFilterSnapshot()
  const existingIndex = savedFilterViews.value.findIndex((item) => String(item?.name || '') === normalizedName)
  if (existingIndex >= 0) {
    savedFilterViews.value[existingIndex] = {
      ...savedFilterViews.value[existingIndex],
      snapshot,
    }
  } else {
    savedFilterViews.value = [
      ...savedFilterViews.value,
      {
        id: Date.now(),
        name: normalizedName,
        snapshot,
      },
    ].slice(-10)
  }
  persistSavedFilterViews()
  ElMessage.success('筛选视图已保存')
}
const applySavedFilterView = async (view) => {
  const snapshot = view?.snapshot && typeof view.snapshot === 'object' ? view.snapshot : {}
  filterForm.value = {
    ...filterForm.value,
    keyword: String(snapshot.keyword || ''),
    read_status: String(snapshot.read_status || 'all'),
    message_type: String(snapshot.message_type || ''),
    dispatch_notice_type: String(snapshot.dispatch_notice_type || ''),
    unread_reminder_only: Boolean(snapshot.unread_reminder_only),
    unread_feedback_only: Boolean(snapshot.unread_feedback_only),
    pinned_only: Boolean(snapshot.pinned_only),
    task_focus: String(snapshot.task_focus || ''),
  }
  if (filterForm.value.unread_reminder_only && filterForm.value.unread_feedback_only) {
    filterForm.value.unread_feedback_only = false
  }
  currentPage.value = 1
  await loadMessages()
  ElMessage.success(`已应用视图：${String(view?.name || '-')}`)
}
const removeSavedFilterView = (viewId) => {
  savedFilterViews.value = savedFilterViews.value.filter((item) => Number(item?.id) !== Number(viewId))
  persistSavedFilterViews()
}
const renameSavedFilterView = (view) => {
  const currentName = String(view?.name || '')
  const name = window.prompt('请输入新的筛选视图名称（最多20字）', currentName)
  const normalizedName = String(name || '').trim().slice(0, 20)
  if (!normalizedName) {
    ElMessage.info('已取消重命名')
    return
  }
  const targetId = Number(view?.id || 0)
  savedFilterViews.value = savedFilterViews.value.map((item) => {
    if (Number(item?.id || 0) !== targetId) return item
    return {
      ...item,
      name: normalizedName,
    }
  })
  persistSavedFilterViews()
  ElMessage.success('筛选视图已重命名')
}
const applySystemPresetReminder = async () => {
  filterForm.value.message_type = 'dispatch_notice'
  filterForm.value.dispatch_notice_type = ''
  filterForm.value.unread_reminder_only = true
  filterForm.value.unread_feedback_only = false
  currentPage.value = 1
  await loadMessages()
}
const applySystemPresetFeedback = async () => {
  filterForm.value.message_type = 'dispatch_notice'
  filterForm.value.dispatch_notice_type = 'exception_manual_feedback'
  filterForm.value.unread_feedback_only = false
  filterForm.value.unread_reminder_only = false
  currentPage.value = 1
  await loadMessages()
}
const syncFiltersToRoute = async () => {
  const nextQuery = { ...route.query }
  if (filterForm.value.task_focus) {
    nextQuery.task_focus = String(filterForm.value.task_focus)
  } else {
    delete nextQuery.task_focus
  }
  if (filterForm.value.dispatch_notice_type) {
    nextQuery.dispatch_notice_type = String(filterForm.value.dispatch_notice_type)
  } else {
    delete nextQuery.dispatch_notice_type
  }
  if (filterForm.value.unread_reminder_only) {
    nextQuery.unread_reminder_only = '1'
  } else {
    delete nextQuery.unread_reminder_only
  }
  if (filterForm.value.unread_feedback_only) {
    nextQuery.unread_feedback_only = '1'
  } else {
    delete nextQuery.unread_feedback_only
  }
  await router.replace({ query: nextQuery })
}

const markReadSilently = async (id) => {
  if (!id) return
  try {
    await api.post('/message/read', { id })
  } catch {
    // ignore
  }
}

const markRelatedTaskMessagesReadSilently = async (taskId) => {
  if (!taskId) return
  const ids = messages.value
    .filter((item) => Number(item?.meta?.task_id || 0) === Number(taskId) && !item.read_at)
    .map((item) => item.id)
  if (!ids.length) return
  try {
    await api.post('/message/read-batch', { ids })
  } catch {
    // ignore
  }
}

const loadMessages = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/message/list', buildNotificationListPayload(filterForm.value))
    messages.value = sortNotificationMessages(Array.isArray(data?.data) ? data.data : [])
    const maxPage = Math.max(1, Math.ceil(displayMessages.value.length / pageSize.value))
    if (currentPage.value > maxPage) {
      currentPage.value = maxPage
    }
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载通知失败')
  } finally {
    loading.value = false
  }
}

const onSelectionChange = (rows) => {
  selectedIds.value = [...new Set(rows.flatMap((item) => getMessageRowIds(item)))]
}
const onSearch = async () => {
  currentPage.value = 1
  await loadMessages()
}

const markRead = async (id) => {
  try {
    await api.post('/message/read', { id })
    await loadMessages()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '标记已读失败')
  }
}

const markMessageRowRead = async (row) => {
  const ids = getMessageRowIds(row).filter((id) => {
    const message = messages.value.find((item) => Number(item.id) === Number(id))
    return message && !message.read_at
  })
  if (!ids.length) return
  if (ids.length === 1) {
    await markRead(ids[0])
    clearUnreadQuickFilters()
    return
  }
  try {
    await api.post('/message/read-batch', { ids })
    ElMessage.success('批量已读成功')
    clearUnreadQuickFilters()
    await loadMessages()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '批量已读失败')
  }
}

const markReadBatch = async () => {
  if (!selectedIds.value.length) {
    ElMessage.warning('请先勾选消息')
    return
  }
  try {
    await api.post('/message/read-batch', { ids: selectedIds.value })
    ElMessage.success('批量已读成功')
    clearUnreadQuickFilters()
    await loadMessages()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '批量已读失败')
  }
}

const togglePin = async (row) => {
  pinningId.value = row.id
  try {
    const ids = getMessageRowIds(row)
    const nextPinned = !row.is_pinned
    await Promise.all(ids.map((id) => api.post('/message/pin', {
      id,
      is_pinned: nextPinned,
    })))
    ElMessage.success(
      ids.length > 1
        ? `${nextPinned ? '已置顶' : '已取消置顶'} ${ids.length} 条同任务通知`
        : (nextPinned ? '置顶成功' : '取消置顶成功'),
    )
    await loadMessages()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '置顶操作失败')
  } finally {
    pinningId.value = null
  }
}

const resolveOrderDetailEndpoint = () => {
  if (hasPermission(currentUser.value, 'dispatch')) {
    return '/pre-plan-order/detail'
  }
  if (hasPermission(currentUser.value, 'customer_orders')) {
    return '/pre-plan-order/customer-detail'
  }
  return null
}

const openOrderDetail = async (row) => {
  const orderId = row?.meta?.order_id
  const endpoint = resolveOrderDetailEndpoint()
  if (!orderId || !endpoint) return

  detailDialogVisible.value = true
  detailLoading.value = true
  detailOrder.value = null
  detailCompareRows.value = []
  detailCompareLoading.value = false
  try {
    detailCompareLoading.value = true
    const result = await loadNotificationOrderDetail({
      httpClient: api,
      orderId,
      detailEndpoint: endpoint,
    })
    detailOrder.value = result.order
    detailCompareRows.value = result.compareRows
  } catch (error) {
    detailDialogVisible.value = false
    ElMessage.error(error?.response?.data?.message || '加载订单详情失败')
  } finally {
    detailLoading.value = false
    detailCompareLoading.value = false
  }
}

const openDispatchTaskOrders = async (row) => {
  const taskId = Number(row?.meta?.task_id || 0)
  const taskNo = row?.meta?.task_no || ''
  if (!taskId) return
  await router.push({
    name: 'dispatch-workbench',
    query: {
      task_no: taskNo,
      focus_task_id: String(taskId),
      open_orders: '1',
    },
  })
}

const goToAuditPendingOrders = async () => {
  await router.push({
    name: 'pre-plan-order-management',
    query: {
      audit_status: 'pending_approval',
    },
  })
}

const handleNotificationAction = async (row) => {
  if (row?.id && !row?.read_at) {
    await markReadSilently(row.id)
  }

  if (row?.message_type === 'audit_reminder') {
    await goToAuditPendingOrders()
    await loadMessages()
    return
  }
  if (row?.message_type === 'dispatch_notice' && isDispatchNotificationUser.value) {
    await markRelatedTaskMessagesReadSilently(row?.meta?.task_id)
    await openDispatchTaskOrders(row)
    await loadMessages()
    return
  }

  const orderId = Number(row?.meta?.order_id || 0)
  const orderNo = row?.meta?.order_no || ''
  const auditStatus = row?.meta?.audit_status || ''
  if (!orderId) return

  if (isDispatchNotificationUser.value && auditStatus === 'pending_approval') {
    await router.push({
      name: 'pre-plan-order-management',
      query: {
        audit_status: 'pending_approval',
        keyword: orderNo,
        focus_order_id: String(orderId),
        open_detail: '1',
      },
    })
    await loadMessages()
    return
  }

  if (isCustomerNotificationUser.value && auditStatus === 'rejected') {
    await router.push({
      name: 'customer-pre-plan-order',
      query: {
        focus_order_id: String(orderId),
        open_edit: '1',
      },
    })
    await loadMessages()
    return
  }

  await openOrderDetail(row)
  await loadMessages()
}

const getNotificationActionLabel = (row) => {
  if (row?.message_type === 'audit_reminder') return '前往待审核清单'
  if (row?.message_type === 'dispatch_notice' && isDispatchNotificationUser.value) return '查看任务'
  if (isDispatchNotificationUser.value && row?.meta?.audit_status === 'pending_approval') return '去审核'
  if (isCustomerNotificationUser.value && row?.meta?.audit_status === 'rejected') return '去重提'
  return '查看订单'
}

const isNotificationActionDisabled = (row) => {
  if (row?.message_type === 'audit_reminder') return false
  if (row?.message_type === 'dispatch_notice') return !isDispatchNotificationUser.value || !row?.meta?.task_id
  return !row?.meta?.order_id || !resolveOrderDetailEndpoint()
}

onMounted(async () => {
  loadSavedFilterViews()
  filterForm.value.task_focus = typeof route.query.task_focus === 'string' ? route.query.task_focus : ''
  filterForm.value.dispatch_notice_type = typeof route.query.dispatch_notice_type === 'string' ? route.query.dispatch_notice_type : ''
  filterForm.value.unread_reminder_only = route.query.unread_reminder_only === '1'
  filterForm.value.unread_feedback_only = route.query.unread_feedback_only === '1'
  if (filterForm.value.unread_reminder_only && filterForm.value.unread_feedback_only) {
    filterForm.value.unread_feedback_only = false
  }
  if ((filterForm.value.unread_reminder_only || filterForm.value.unread_feedback_only) && !filterForm.value.message_type) {
    filterForm.value.message_type = 'dispatch_notice'
  }
  if (filterForm.value.dispatch_notice_type && !filterForm.value.message_type) {
    filterForm.value.message_type = 'dispatch_notice'
  }
  await loadMessages()
})

watch(() => filterForm.value.task_focus, () => {
  syncFiltersToRoute()
})

watch(() => filterForm.value.dispatch_notice_type, (value) => {
  if (value && !filterForm.value.message_type) {
    filterForm.value.message_type = 'dispatch_notice'
  }
  syncFiltersToRoute()
})

watch(() => filterForm.value.unread_reminder_only, (value) => {
  if (value) {
    filterForm.value.unread_feedback_only = false
  }
  if ((value || filterForm.value.unread_feedback_only) && !filterForm.value.message_type) {
    filterForm.value.message_type = 'dispatch_notice'
  }
  syncFiltersToRoute()
})

watch(() => filterForm.value.unread_feedback_only, (value) => {
  if (value) {
    filterForm.value.unread_reminder_only = false
  }
  if ((value || filterForm.value.unread_reminder_only) && !filterForm.value.message_type) {
    filterForm.value.message_type = 'dispatch_notice'
  }
  syncFiltersToRoute()
})

watch(displayMessages, (list) => {
  const maxPage = Math.max(1, Math.ceil(list.length / pageSize.value))
  if (currentPage.value > maxPage) {
    currentPage.value = maxPage
  }
})

watch([unreadReminderCount, unreadFeedbackCount], ([reminderCount, feedbackCount]) => {
  if (filterForm.value.unread_reminder_only && reminderCount <= 0) {
    filterForm.value.unread_reminder_only = false
  }
  if (filterForm.value.unread_feedback_only && feedbackCount <= 0) {
    filterForm.value.unread_feedback_only = false
  }
})
</script>

<template>
  <div class="page-content-shell">
  <el-card shadow="never" class="page-card">
    <template #header>
      <div class="table-header">
        <div class="card-title">通知中心</div>
        <div>
          <el-button class="mr-8" plain @click="markReadBatch">批量已读</el-button>
          <el-button plain @click="loadMessages">刷新</el-button>
        </div>
      </div>
    </template>

    <el-form inline class="mb-12">
      <el-form-item label="关键词">
        <el-input v-model="filterForm.keyword" clearable placeholder="标题/内容" style="width: 220px" />
      </el-form-item>
      <el-form-item label="已读状态">
        <el-select v-model="filterForm.read_status" style="width: 130px">
          <el-option label="全部" value="all" />
          <el-option label="未读" value="unread" />
          <el-option label="已读" value="read" />
        </el-select>
      </el-form-item>
      <el-form-item label="类型">
        <el-select v-model="filterForm.message_type" clearable style="width: 160px">
          <el-option label="审核通知" value="audit_notice" />
          <el-option label="审核催办" value="audit_reminder" />
          <el-option label="调度通知" value="dispatch_notice" />
        </el-select>
      </el-form-item>
      <el-form-item label="调度场景">
        <el-select v-model="filterForm.dispatch_notice_type" clearable style="width: 200px">
          <el-option
            v-for="(label, value) in dispatchNoticeTypeLabelMap"
            :key="value"
            :label="label"
            :value="value"
          />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-checkbox v-model="filterForm.pinned_only">仅看置顶</el-checkbox>
      </el-form-item>
      <el-form-item>
        <el-button
          size="small"
          plain
          :type="filterForm.unread_reminder_only ? 'warning' : 'info'"
          @click="toggleUnreadReminderOnly"
        >
          未读催办（{{ unreadReminderCount }}）
        </el-button>
      </el-form-item>
      <el-form-item>
        <el-button
          size="small"
          plain
          :type="filterForm.unread_feedback_only ? 'warning' : 'info'"
          @click="toggleUnreadFeedbackOnly"
        >
          未读反馈（{{ unreadFeedbackCount }}）
        </el-button>
      </el-form-item>
      <el-form-item v-if="filterForm.task_focus" label="任务聚焦">
        <el-space wrap>
          <el-tag closable @close="clearTaskFocus">
            任务 ID：{{ filterForm.task_focus }}
          </el-tag>
          <el-button link type="primary" @click="clearTaskFocus">清空</el-button>
        </el-space>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="onSearch">查询</el-button>
      </el-form-item>
      <el-form-item>
        <el-button plain @click="saveCurrentFilterView">保存当前筛选</el-button>
      </el-form-item>
      <el-form-item label="系统视图">
        <el-space wrap>
          <el-button size="small" plain type="warning" @click="applySystemPresetReminder">全部催办</el-button>
          <el-button size="small" plain type="primary" @click="applySystemPresetFeedback">全部反馈</el-button>
        </el-space>
      </el-form-item>
      <el-form-item v-if="savedFilterViews.length" label="已保存视图">
        <el-space wrap>
          <span v-for="view in savedFilterViews" :key="`saved-filter-view-${view.id}`" class="mobile-exception-result-line">
            <el-tag
              closable
              class="order-tag-clickable"
              @click="applySavedFilterView(view)"
              @close="removeSavedFilterView(view.id)"
            >
              {{ view.name }}
            </el-tag>
            <el-button size="small" link type="primary" @click="renameSavedFilterView(view)">重命名</el-button>
          </span>
        </el-space>
      </el-form-item>
    </el-form>

    <div class="page-table-section">
    <div class="page-table-wrap">
    <el-table :data="pagedMessages" stripe v-loading="loading" height="100%" class="page-table" @selection-change="onSelectionChange">
      <el-table-column type="selection" width="50" />
      <el-table-column label="置顶" width="70">
        <template #default="{ row }">
          <el-tag v-if="row.is_pinned" type="warning">置顶</el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="类型" min-width="120">
        <template #default="{ row }">
          {{ getLabel(messageTypeLabelMap, row.message_type) }}
        </template>
      </el-table-column>
      <el-table-column label="标题" min-width="200">
        <template #default="{ row }">
          <el-space wrap size="small">
            <span>{{ row.title || '-' }}</span>
            <el-tag v-if="row.message_type === 'dispatch_notice' && row.aggregate_count > 1" size="small" type="primary">
              同任务 {{ row.aggregate_count }} 条
            </el-tag>
            <el-tag v-if="row.message_type === 'dispatch_notice' && row.unread_count > 1" size="small" type="danger">
              未读 {{ row.unread_count }} 条
            </el-tag>
          </el-space>
        </template>
      </el-table-column>
      <el-table-column label="内容" min-width="320">
        <template #default="{ row }">
          <div>{{ row.content || '-' }}</div>
          <div v-if="row.message_type === 'dispatch_notice' && row.aggregate_count > 1" class="text-secondary">
            已合并展示同一任务的调度通知，点击后统一跳转并批量处理已读。
          </div>
          <div v-if="row.message_type === 'dispatch_notice' && row.aggregate_count > 1" class="mt-8">
            <el-button link type="primary" @click="toggleExpandedMessageRow(row)">
              {{ isExpandedMessageRow(row) ? '收起本组明细' : '展开本组明细' }}
            </el-button>
            <el-button link type="primary" @click="focusTaskMessages(row)">只看本任务通知</el-button>
            <div v-if="isExpandedMessageRow(row)" class="text-secondary">
              <div
                v-for="item in getAggregateItems(row)"
                :key="`message-detail-${item.id}`"
                class="mobile-exception-result-line"
              >
                {{ formatNotificationTime(item) }}｜{{ item.title || '-' }}｜{{ item.content || '-' }}
              </div>
            </div>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="关联对象" min-width="160">
        <template #default="{ row }">
          {{ row?.meta?.task_no || getNotificationOrderNo(row) }}
        </template>
      </el-table-column>
      <el-table-column label="审核结果" min-width="110">
        <template #default="{ row }">
          <el-tag
            v-if="getNotificationAuditStatus(row)"
            :type="auditStatusTypeMap[getNotificationAuditStatus(row)] || 'info'"
          >
            {{ getLabel(auditStatusLabelMap, getNotificationAuditStatus(row)) }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="调度场景" min-width="150">
        <template #default="{ row }">
          <el-tag
            v-if="row.message_type === 'dispatch_notice' && getDispatchNoticeType(row)"
            type="warning"
            effect="plain"
          >
            {{ getLabel(dispatchNoticeTypeLabelMap, getDispatchNoticeType(row)) }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="90">
        <template #default="{ row }">
          <el-tag :type="getNotificationReadTagType(row)">{{ getNotificationReadLabel(row) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="通知时间" min-width="160">
        <template #default="{ row }">
          {{ formatNotificationTime(row) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="220" fixed="right">
        <template #default="{ row }">
          <el-button
            link
            :type="row.message_type === 'audit_reminder' ? 'primary' : 'info'"
            :disabled="isNotificationActionDisabled(row)"
            @click="handleNotificationAction(row)"
          >
            {{ getNotificationActionLabel(row) }}
          </el-button>
          <el-button link type="primary" :disabled="!!row.read_at" @click="markMessageRowRead(row)">
            {{ row.aggregate_count > 1 ? '本组已读' : '已读' }}
          </el-button>
          <el-button link type="warning" :loading="pinningId === row.id" @click="togglePin(row)">
            {{ row.aggregate_count > 1 ? (row.is_pinned ? '本组取消置顶' : '本组置顶') : (row.is_pinned ? '取消置顶' : '置顶') }}
          </el-button>
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
        :total="totalMessages"
      />
    </div>
    </div>
  </el-card>
  </div>

  <el-dialog v-model="detailDialogVisible" title="关联订单详情" width="860px" destroy-on-close>
    <PrePlanOrderDetailContent
      :order="detailOrder"
      :loading="detailLoading"
      :compare-rows="detailCompareRows"
      :compare-loading="detailCompareLoading"
      show-compare
    />
    <template #footer>
      <el-button @click="detailDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>
