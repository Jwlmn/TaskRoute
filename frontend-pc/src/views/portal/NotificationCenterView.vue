<script setup>
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { hasPermission, readCurrentUser } from '../../utils/auth'
import { getLabel, taskStatusLabelMap } from '../../utils/labels'

const loading = ref(false)
const pinningId = ref(null)
const messages = ref([])
const selectedIds = ref([])
const detailDialogVisible = ref(false)
const detailLoading = ref(false)
const detailOrder = ref(null)
const detailCompareLoading = ref(false)
const detailCompareRows = ref([])

const currentUser = computed(() => readCurrentUser())

const filterForm = ref({
  keyword: '',
  read_status: 'all',
  message_type: '',
  pinned_only: false,
})

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

const messageTypeLabelMap = {
  audit_notice: '审核通知',
  audit_reminder: '审核催办',
}

const getFreightTemplateMeta = (row) => {
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

const formatDateTime = (value) => {
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

const detailHistory = computed(() => {
  const list = detailOrder.value?.meta?.history
  return Array.isArray(list) ? [...list].reverse() : []
})

const historyActionLabelMap = {
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

const sortMessages = (rawMessages) => rawMessages.sort((a, b) => {
  const aPinned = a?.is_pinned ? 1 : 0
  const bPinned = b?.is_pinned ? 1 : 0
  if (aPinned !== bPinned) return bPinned - aPinned

  const aUnread = a?.read_at ? 1 : 0
  const bUnread = b?.read_at ? 1 : 0
  if (aUnread !== bUnread) return aUnread - bUnread

  return String(b?.created_at || '').localeCompare(String(a?.created_at || ''))
})

const loadMessages = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/message/list', {
      keyword: filterForm.value.keyword || undefined,
      read_status: filterForm.value.read_status || 'all',
      message_type: filterForm.value.message_type || undefined,
      pinned_only: filterForm.value.pinned_only || false,
    })
    messages.value = sortMessages(Array.isArray(data?.data) ? data.data : [])
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载通知失败')
  } finally {
    loading.value = false
  }
}

const onSelectionChange = (rows) => {
  selectedIds.value = rows.map((item) => item.id)
}

const markRead = async (id) => {
  try {
    await api.post('/message/read', { id })
    await loadMessages()
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
    await loadMessages()
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
    const { data } = await api.post(endpoint, { id: orderId })
    detailOrder.value = data || null
    if (data?.audit_status === 'rejected') {
      detailCompareLoading.value = true
      try {
        const compareResponse = await api.post('/pre-plan-order/revision-compare', { id: orderId })
        detailCompareRows.value = Array.isArray(compareResponse?.data?.diffs) ? compareResponse.data.diffs : []
      } finally {
        detailCompareLoading.value = false
      }
    }
  } catch (error) {
    detailDialogVisible.value = false
    ElMessage.error(error?.response?.data?.message || '加载订单详情失败')
  } finally {
    detailLoading.value = false
  }
}

onMounted(loadMessages)
</script>

<template>
  <el-card shadow="never">
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
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-checkbox v-model="filterForm.pinned_only">仅看置顶</el-checkbox>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="loadMessages">查询</el-button>
      </el-form-item>
    </el-form>

    <el-table :data="messages" stripe v-loading="loading" @selection-change="onSelectionChange">
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
      <el-table-column prop="title" label="标题" min-width="160" />
      <el-table-column prop="content" label="内容" min-width="260" />
      <el-table-column label="关联订单" min-width="160">
        <template #default="{ row }">
          {{ row?.meta?.order_no || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="审核结果" min-width="110">
        <template #default="{ row }">
          <el-tag
            v-if="row?.meta?.audit_status"
            :type="auditStatusTypeMap[row.meta.audit_status] || 'info'"
          >
            {{ getLabel(auditStatusLabelMap, row.meta.audit_status) }}
          </el-tag>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="90">
        <template #default="{ row }">
          <el-tag :type="row.read_at ? 'info' : 'danger'">{{ row.read_at ? '已读' : '未读' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="通知时间" min-width="160">
        <template #default="{ row }">
          {{ formatDateTime(row.created_at) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="220" fixed="right">
        <template #default="{ row }">
          <el-button
            link
            type="info"
            :disabled="!row?.meta?.order_id || !resolveOrderDetailEndpoint()"
            @click="openOrderDetail(row)"
          >
            查看订单
          </el-button>
          <el-button link type="primary" :disabled="!!row.read_at" @click="markRead(row.id)">已读</el-button>
          <el-button link type="warning" :loading="pinningId === row.id" @click="togglePin(row)">
            {{ row.is_pinned ? '取消置顶' : '置顶' }}
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog v-model="detailDialogVisible" title="关联订单详情" width="860px" destroy-on-close>
    <el-skeleton :loading="detailLoading" animated :rows="6">
      <template #default>
        <el-descriptions v-if="detailOrder" :column="2" border size="small">
          <el-descriptions-item label="预计划单号">{{ detailOrder.order_no || '-' }}</el-descriptions-item>
          <el-descriptions-item label="客户">{{ detailOrder.client_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="装货地">{{ detailOrder.pickup_address || '-' }}</el-descriptions-item>
          <el-descriptions-item label="卸货地">{{ detailOrder.dropoff_address || '-' }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ getLabel(taskStatusLabelMap, detailOrder.status) }}</el-descriptions-item>
          <el-descriptions-item label="审核状态">{{ getLabel(auditStatusLabelMap, detailOrder.audit_status) }}</el-descriptions-item>
          <el-descriptions-item label="提报人">
            {{ detailOrder.submitter?.name || detailOrder.submitter?.account || (detailOrder.submitter_id ? `#${detailOrder.submitter_id}` : '-') }}
          </el-descriptions-item>
          <el-descriptions-item label="审核人">
            {{ detailOrder.auditor?.name || detailOrder.auditor?.account || (detailOrder.audited_by ? `#${detailOrder.audited_by}` : '-') }}
          </el-descriptions-item>
          <el-descriptions-item label="命中模板">
            {{ getFreightTemplateMeta(detailOrder)?.name || '未命中模板' }}
          </el-descriptions-item>
          <el-descriptions-item label="审核备注">{{ detailOrder.audit_remark || '-' }}</el-descriptions-item>
        </el-descriptions>
        <template v-if="detailOrder?.audit_status === 'rejected'">
          <el-divider content-position="left">驳回后版本对比</el-divider>
          <el-table :data="detailCompareRows" size="small" stripe v-loading="detailCompareLoading">
            <el-table-column prop="field" label="字段" min-width="180" />
            <el-table-column prop="before" label="驳回时值" min-width="220" />
            <el-table-column prop="after" label="当前值" min-width="220" />
          </el-table>
          <el-empty
            v-if="!detailCompareLoading && detailCompareRows.length === 0"
            description="暂无差异（可能尚未修改关键字段）"
          />
        </template>
        <el-divider content-position="left">操作轨迹</el-divider>
        <el-table :data="detailHistory" size="small" stripe>
          <el-table-column label="时间" min-width="160">
            <template #default="{ row }">
              {{ formatDateTime(row.at) }}
            </template>
          </el-table-column>
          <el-table-column label="动作" min-width="140">
            <template #default="{ row }">
              {{ historyActionLabelMap[row.action] || row.action || '-' }}
            </template>
          </el-table-column>
          <el-table-column label="操作人" min-width="140">
            <template #default="{ row }">
              {{ row.operator_name || row.operator_account || (row.operator_id ? `#${row.operator_id}` : '-') }}
            </template>
          </el-table-column>
          <el-table-column label="附加信息" min-width="260">
            <template #default="{ row }">
              {{ row.extra && Object.keys(row.extra).length ? JSON.stringify(row.extra) : '-' }}
            </template>
          </el-table-column>
        </el-table>
      </template>
    </el-skeleton>
    <template #footer>
      <el-button @click="detailDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>
