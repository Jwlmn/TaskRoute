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

const currentUser = computed(() => readCurrentUser())
const isDispatchNotificationUser = computed(() => hasPermission(currentUser.value, 'dispatch'))
const isCustomerNotificationUser = computed(() => hasPermission(currentUser.value, 'customer_orders'))

const filterForm = ref({
  keyword: '',
  read_status: 'all',
  message_type: '',
  pinned_only: false,
  task_focus: '',
})

const messageTypeLabelMap = {
  audit_notice: '审核通知',
  audit_reminder: '审核催办',
  dispatch_notice: '调度通知',
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
  if (!filterForm.value.task_focus) return list
  return list.filter((item) => String(item?.meta?.task_id || '') === String(filterForm.value.task_focus))
})
const pagedMessages = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return displayMessages.value.slice(start, start + pageSize.value)
})
const totalMessages = computed(() => displayMessages.value.length)
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
const syncTaskFocusToRoute = async () => {
  const nextQuery = { ...route.query }
  if (filterForm.value.task_focus) {
    nextQuery.task_focus = String(filterForm.value.task_focus)
  } else {
    delete nextQuery.task_focus
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
    return
  }
  try {
    await api.post('/message/read-batch', { ids })
    ElMessage.success('批量已读成功')
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
  filterForm.value.task_focus = typeof route.query.task_focus === 'string' ? route.query.task_focus : ''
  await loadMessages()
})

watch(() => filterForm.value.task_focus, () => {
  syncTaskFocusToRoute()
})

watch(displayMessages, (list) => {
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
      <el-form-item>
        <el-checkbox v-model="filterForm.pinned_only">仅看置顶</el-checkbox>
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
