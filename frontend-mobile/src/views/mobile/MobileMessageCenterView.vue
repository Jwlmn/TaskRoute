<script setup>
import { onMounted, onUnmounted, reactive, ref, watch } from 'vue'
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
const formatNotificationTime = (message) => {
  const value = message?.created_at
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString('zh-CN', { hour12: false })
}

const openTaskDetail = async (taskId) => {
  if (!taskId) return
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
  selectedIds.value = rows.map((item) => item.id)
}

const markRead = async (id) => {
  try {
    await api.post('/message/read', { id })
    await loadMessages(pagination.page)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '标记已读失败')
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
    await api.post('/message/pin', {
      id: row.id,
      is_pinned: !row.is_pinned,
    })
    await loadMessages(pagination.page)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '置顶操作失败')
  } finally {
    pinningId.value = null
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
      :data="messages"
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
              @click="openTaskDetail(row.meta.task_id)"
            >
              查看任务
            </el-button>
            <el-button link type="primary" :disabled="!!row.read_at" @click="markRead(row.id)">已读</el-button>
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
