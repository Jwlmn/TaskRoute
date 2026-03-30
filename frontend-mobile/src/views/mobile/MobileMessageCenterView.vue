<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'

const router = useRouter()
const messageTypeLabelMap = {
  audit_notice: '审核通知',
  audit_reminder: '审核催办',
  dispatch_notice: '调度通知',
}

const auditStatusLabelMap = {
  pending_approval: '待审核',
  approved: '已审核',
  rejected: '已驳回',
}

const auditStatusTypeMap = {
  pending_approval: 'warning',
  approved: 'success',
  rejected: 'danger',
}

const loading = ref(false)
const messages = ref([])
const selectedIds = ref([])
const pinningId = ref(null)
const pagination = reactive({
  page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
})

const filterForm = reactive({
  keyword: '',
  read_status: 'all',
  message_type: '',
  pinned_only: false,
})
let filterDebounceTimer = null
let loadAbortController = null

const getLabel = (map, value) => map[value] || value || '-'
const getNotificationOrderNo = (message) => message?.meta?.order_no || '-'
const getNotificationAuditStatus = (message) => message?.meta?.audit_status || ''
const getNotificationTaskNo = (message) => message?.meta?.task_no || '-'
const getMessageRowIds = (row) => {
  if (Array.isArray(row?.aggregate_ids) && row.aggregate_ids.length) {
    return row.aggregate_ids.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0)
  }
  return row?.id ? [Number(row.id)] : []
}
const formatNotificationTime = (message) => {
  const value = message?.created_at
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString('zh-CN', { hour12: false })
}
const displayMessages = computed(() => {
  const grouped = new Map()

  messages.value.forEach((message) => {
    const taskId = Number(message?.meta?.task_id || 0)
    if (message?.message_type !== 'dispatch_notice' || !taskId) {
      grouped.set(`single-${message.id}`, {
        ...message,
        aggregate_count: 1,
        aggregate_ids: [message.id],
        unread_count: message?.read_at ? 0 : 1,
      })
      return
    }

    const key = `dispatch-${taskId}`
    const current = grouped.get(key)
    if (!current) {
      grouped.set(key, {
        ...message,
        aggregate_count: 1,
        aggregate_ids: [message.id],
        unread_count: message?.read_at ? 0 : 1,
      })
      return
    }

    current.aggregate_count += 1
    current.aggregate_ids.push(message.id)
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
      })
    }
  })

  return [...grouped.values()]
})

const openTaskDetail = async (row) => {
  const taskId = Number(row?.meta?.task_id || 0)
  if (!taskId) return
  await markRelatedTaskMessagesRead(taskId)
  await router.push({ name: 'mobile-task-detail', params: { id: taskId } })
}

const loadMessages = async (page = pagination.page) => {
  loadAbortController?.abort()
  loadAbortController = new AbortController()
  loading.value = true
  try {
    const { data } = await api.post('/message/list', {
      page,
      keyword: filterForm.keyword || undefined,
      read_status: filterForm.read_status || 'all',
      message_type: filterForm.message_type || undefined,
      pinned_only: filterForm.pinned_only || false,
    }, { signal: loadAbortController.signal })
    messages.value = Array.isArray(data?.data) ? data.data : []
    pagination.page = Number(data?.current_page || page || 1)
    pagination.per_page = Number(data?.per_page || 20)
    pagination.total = Number(data?.total || 0)
    pagination.last_page = Number(data?.last_page || 1)
    if (messages.value.length === 0 && pagination.page > 1 && pagination.total > 0) {
      await loadMessages(pagination.page - 1)
    }
  } catch (error) {
    if (error?.code === 'ERR_CANCELED') {
      return
    }
    ElMessage.error(error?.response?.data?.message || '加载消息失败')
  } finally {
    loading.value = false
  }
}

const searchMessages = async () => {
  pagination.page = 1
  await loadMessages(1)
}

const changePage = async (nextPage) => {
  await loadMessages(nextPage)
}

const onSelectionChange = (rows) => {
  selectedIds.value = [...new Set(rows.flatMap((item) => getMessageRowIds(item)))]
}

const markRead = async (id) => {
  try {
    await api.post('/message/read', { id })
    await loadMessages(pagination.page)
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
    await loadMessages(pagination.page)
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
    await loadMessages(pagination.page)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '批量已读失败')
  }
}

const togglePin = async (row) => {
  pinningId.value = row.id
  try {
    const ids = getMessageRowIds(row)
    await Promise.all(ids.map((id) => api.post('/message/pin', {
      id,
      is_pinned: !row.is_pinned,
    })))
    await loadMessages(pagination.page)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '置顶操作失败')
  } finally {
    pinningId.value = null
  }
}

const markRelatedTaskMessagesRead = async (taskId) => {
  const ids = messages.value
    .filter((item) => Number(item?.meta?.task_id || 0) === Number(taskId) && !item.read_at)
    .map((item) => item.id)
  if (!ids.length) return
  if (ids.length === 1) {
    await markRead(ids[0])
    return
  }
  try {
    await api.post('/message/read-batch', { ids })
    await loadMessages(pagination.page)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '批量已读失败')
  }
}

onMounted(loadMessages)

onUnmounted(() => {
  loadAbortController?.abort()
  loadAbortController = null
  if (filterDebounceTimer) {
    clearTimeout(filterDebounceTimer)
    filterDebounceTimer = null
  }
})

watch(
  () => [filterForm.keyword, filterForm.read_status, filterForm.message_type, filterForm.pinned_only],
  () => {
    if (filterDebounceTimer) {
      clearTimeout(filterDebounceTimer)
    }
    filterDebounceTimer = setTimeout(() => {
      searchMessages()
    }, 300)
  }
)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="mobile-section-title">消息中心</div>
    </template>

    <div class="mobile-message-toolbar">
      <el-input
        v-model.trim="filterForm.keyword"
        clearable
        size="small"
        placeholder="搜索标题或内容"
      />
      <el-select v-model="filterForm.message_type" clearable size="small" placeholder="消息类型">
        <el-option label="审核通知" value="audit_notice" />
        <el-option label="审核催办" value="audit_reminder" />
        <el-option label="调度通知" value="dispatch_notice" />
      </el-select>
      <el-select v-model="filterForm.read_status" size="small">
        <el-option label="全部" value="all" />
        <el-option label="未读" value="unread" />
        <el-option label="已读" value="read" />
      </el-select>
      <div class="mobile-message-actions">
        <el-checkbox v-model="filterForm.pinned_only">仅看置顶</el-checkbox>
        <el-button size="small" type="primary" @click="searchMessages">查询</el-button>
        <el-button size="small" plain @click="searchMessages">刷新</el-button>
        <el-button size="small" plain @click="markReadBatch">批量已读</el-button>
      </div>
    </div>

    <el-table
      :data="displayMessages"
      stripe
      v-loading="loading"
      class="mobile-message-table"
      @selection-change="onSelectionChange"
    >
      <el-table-column type="selection" width="46" />
      <el-table-column label="标题" min-width="180">
        <template #default="{ row }">
          <div class="mobile-message-title">
            <el-tag v-if="row.is_pinned" size="small" type="warning">置顶</el-tag>
            <el-tag v-if="!row.read_at" size="small" type="danger">未读</el-tag>
            <el-tag v-if="row.message_type === 'dispatch_notice' && row.aggregate_count > 1" size="small" type="primary">
              同任务 {{ row.aggregate_count }} 条
            </el-tag>
            <span>{{ row.title || '-' }}</span>
          </div>
          <div class="mobile-message-meta">
            <el-tag size="small" effect="plain">{{ getLabel(messageTypeLabelMap, row.message_type) }}</el-tag>
            <el-tag
              v-if="getNotificationAuditStatus(row)"
              size="small"
              :type="auditStatusTypeMap[getNotificationAuditStatus(row)] || 'info'"
              effect="plain"
            >
              {{ getLabel(auditStatusLabelMap, getNotificationAuditStatus(row)) }}
            </el-tag>
          </div>
          <div class="mobile-message-content">{{ row.content || '-' }}</div>
          <div
            v-if="row.message_type === 'dispatch_notice' && row.aggregate_count > 1"
            class="mobile-message-content text-secondary"
          >
            已合并同任务调度通知，进入任务时会统一批量已读。
          </div>
          <div class="mobile-message-extra">
            <span v-if="row.meta?.task_id">关联任务：{{ getNotificationTaskNo(row) }}</span>
            <span v-else>关联订单：{{ getNotificationOrderNo(row) }}</span>
            <span>通知时间：{{ formatNotificationTime(row) }}</span>
          </div>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <div class="mobile-message-row-actions">
            <el-button
              v-if="row.meta?.task_id"
              link
              type="info"
              @click="openTaskDetail(row)"
            >
              查看任务
            </el-button>
            <el-button
              v-if="row.message_type === 'dispatch_notice' && !row.read_at"
              link
              type="primary"
              @click="markMessageRowRead(row)"
            >
              {{ row.aggregate_count > 1 ? '本组已读' : '已读本条' }}
            </el-button>
            <el-button link type="primary" :disabled="!!row.read_at" @click="markMessageRowRead(row)">已读</el-button>
            <el-button link type="warning" :loading="pinningId === row.id" @click="togglePin(row)">
              {{ row.is_pinned ? '取消置顶' : '置顶' }}
            </el-button>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <div class="mobile-message-pagination">
      <el-pagination
        small
        background
        layout="prev, pager, next, total"
        :current-page="pagination.page"
        :page-size="pagination.per_page"
        :total="pagination.total"
        @current-change="changePage"
      />
    </div>
  </el-card>
</template>
