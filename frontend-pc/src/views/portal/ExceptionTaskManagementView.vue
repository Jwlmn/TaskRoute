<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { readCurrentUser } from '../../utils/auth'
import { getLabel, taskStatusLabelMap } from '../../utils/labels'

const router = useRouter()
const loadingExceptions = ref(false)
const handlingException = ref(false)
const exceptionTasks = ref([])
const vehicles = ref([])
const exceptionHandleDialogVisible = ref(false)
const exceptionDetailDialogVisible = ref(false)
const handlingTask = ref(null)
const selectedExceptionTask = ref(null)
const exceptionHandleForm = ref({
  action: 'continue',
  handle_note: '',
  reassign_vehicle_id: null,
})
const filterForm = ref({
  status: 'pending',
  task_no: '',
  exception_type: '',
  handle_action: '',
  handled_by_keyword: '',
  handled_by_me: false,
  overtime_only: false,
  overtime_level: '',
  driver_focus: '',
  site_focus: '',
})
const currentUser = readCurrentUser()
const overtimeThresholdMinutes = 30
const overtimeLevelOptions = [
  { label: '超时 30 分钟', value: '30', min: 30 },
  { label: '超时 60 分钟', value: '60', min: 60 },
  { label: '超时 120 分钟', value: '120', min: 120 },
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

const formatEntityChange = (label, beforeValue, afterValue) => `${label}：${beforeValue || '-'} -> ${afterValue || '-'}`
const formatOperator = (name, account, id) => name || account || (id ? `#${id}` : '-')
const formatVehicleDisplay = (plateNumber, name, id) => {
  if (plateNumber && name) return `${plateNumber}｜${name}`
  if (plateNumber) return plateNumber
  if (name) return name
  return id ? `#${id}` : '-'
}
const formatDriverDisplay = (name, account, id) => name || account || (id ? `#${id}` : '-')
const getPendingDurationMinutes = (task) => {
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
const getPendingDurationTagType = (task) => {
  const minutes = getPendingDurationMinutes(task)
  if (minutes >= overtimeThresholdMinutes * 2) return 'danger'
  if (minutes >= overtimeThresholdMinutes) return 'warning'
  return 'info'
}
const getSlaLevel = (task) => {
  const minutes = getPendingDurationMinutes(task)
  if (minutes >= 120) return { label: '严重超时', type: 'danger' }
  if (minutes >= 60) return { label: '高优先级', type: 'warning' }
  if (minutes >= 30) return { label: '临近超时', type: 'primary' }
  return { label: '正常', type: 'success' }
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
const selectedTaskOrders = computed(() => Array.isArray(selectedExceptionTask.value?.orders) ? selectedExceptionTask.value.orders : [])
const primaryTaskOrder = computed(() => selectedTaskOrders.value[0] || null)
const displayedExceptionTasks = computed(() => {
  if (filterForm.value.status !== 'pending') return exceptionTasks.value

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
    return isTaskMatchedOvertimeLevel(task)
  })
})
const pendingExceptionCount = computed(() => exceptionTasks.value.filter((task) => task.route_meta?.exception?.status === 'pending').length)
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
const applyDriverRankingFilter = (item) => {
  filterForm.value.driver_focus = filterForm.value.driver_focus === item.account ? '' : (item.account || '')
  if (filterForm.value.driver_focus) {
    const matchedTask = displayedExceptionTasks.value.find((task) => (task.driver?.account || '') === filterForm.value.driver_focus)
    if (matchedTask) {
      selectedExceptionTask.value = matchedTask
      exceptionDetailDialogVisible.value = true
    }
  }
}
const applySiteRankingFilter = (item) => {
  filterForm.value.site_focus = filterForm.value.site_focus === item.name ? '' : (item.name || '')
  if (filterForm.value.site_focus) {
    const matchedTask = displayedExceptionTasks.value.find((task) => {
      const orders = Array.isArray(task.orders) ? task.orders : []
      return orders.some((order) => (order.pickup_address || '') === filterForm.value.site_focus)
    })
    if (matchedTask) {
      selectedExceptionTask.value = matchedTask
      exceptionDetailDialogVisible.value = true
    }
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

    const { data } = await api.post('/dispatch-task/exception-list', payload)
    exceptionTasks.value = Array.isArray(data?.data) ? data.data : []
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

const openHandleDialog = async (task) => {
  handlingTask.value = task
  exceptionHandleForm.value = {
    action: 'continue',
    handle_note: '',
    reassign_vehicle_id: null,
  }
  exceptionHandleDialogVisible.value = true
  await loadVehicles()
}

const openDetailDialog = (task) => {
  selectedExceptionTask.value = task
  exceptionDetailDialogVisible.value = true
}

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

onMounted(async () => {
  await loadExceptionTasks()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <div class="card-title">异常任务管理</div>
        <el-button type="primary" plain @click="loadExceptionTasks">刷新异常</el-button>
      </div>
    </template>
    <el-row :gutter="12" class="mb-12">
      <el-col :span="8">
        <el-card shadow="never">
          <div class="text-secondary mb-8">当前异常总量</div>
          <div class="card-title">{{ exceptionTasks.length }}</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never">
          <div class="text-secondary mb-8">超时异常数</div>
          <div class="card-title">{{ overtimeExceptionCount }}</div>
          <div class="text-secondary">阈值 {{ overtimeThresholdMinutes }} 分钟</div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never">
          <div class="text-secondary mb-8">最长待处理时长</div>
          <div class="card-title">{{ longestPendingMinutes > 0 ? formatPendingDurationMinutes(longestPendingMinutes) : '-' }}</div>
          <div class="text-secondary">待处理 {{ pendingExceptionCount }} 条</div>
        </el-card>
      </el-col>
    </el-row>
    <el-card shadow="never" class="mb-12" v-if="filterForm.status === 'pending' && exceptionTypeStats.length">
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
    <el-row :gutter="12" class="mb-12" v-if="filterForm.status === 'pending'">
      <el-col :span="12">
        <el-card shadow="never">
          <div class="table-header">
            <div class="mobile-section-title">司机异常排行</div>
            <div class="text-secondary">Top 5</div>
          </div>
          <el-empty v-if="!driverExceptionRanking.length" description="暂无异常数据" />
          <div v-else>
            <div v-for="item in driverExceptionRanking" :key="`driver-rank-${item.key}`" class="mobile-exception-result-line">
              <span class="order-tag-clickable" @click="applyDriverRankingFilter(item)">{{ item.name }}：{{ item.count }} 次</span>
            </div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="12">
        <el-card shadow="never">
          <div class="table-header">
            <div class="mobile-section-title">装货地异常排行</div>
            <div class="text-secondary">Top 5</div>
          </div>
          <el-empty v-if="!siteExceptionRanking.length" description="暂无异常数据" />
          <div v-else>
            <div v-for="item in siteExceptionRanking" :key="`site-rank-${item.key}`" class="mobile-exception-result-line">
              <span class="order-tag-clickable" @click="applySiteRankingFilter(item)">{{ item.name }}：{{ item.count }} 次</span>
            </div>
          </div>
        </el-card>
      </el-col>
    </el-row>
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
            司机：{{ filterForm.driver_focus }}
          </el-tag>
          <el-tag v-if="filterForm.site_focus" closable @close="filterForm.site_focus = ''">
            装货地：{{ filterForm.site_focus }}
          </el-tag>
        </el-space>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="loadExceptionTasks">查询</el-button>
      </el-form-item>
    </el-form>
    <el-table :data="displayedExceptionTasks" stripe v-loading="loadingExceptions">
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
      <el-table-column label="操作" width="160" fixed="right">
        <template #default="{ row }">
          <el-button link type="info" @click="openDetailDialog(row)">详情</el-button>
          <el-button link type="primary" :disabled="row.route_meta?.exception?.status !== 'pending'" @click="openHandleDialog(row)">处理</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog
    v-model="exceptionHandleDialogVisible"
    title="处理任务异常"
    width="620px"
    destroy-on-close
  >
    <el-form label-width="90px">
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

  <el-drawer
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
        <el-descriptions-item label="处理动作">
          {{ getLabel(exceptionActionLabelMap, currentException.handle_action) }}
        </el-descriptions-item>
        <el-descriptions-item label="上报人">
          {{ formatOperator(currentException.reported_by_name, currentException.reported_by_account, currentException.reported_by) }}
        </el-descriptions-item>
        <el-descriptions-item label="处理人">
          {{ formatOperator(currentException.handled_by_name, currentException.handled_by_account, currentException.handled_by) }}
        </el-descriptions-item>
        <el-descriptions-item label="关联节点">{{ currentException.waypoint_id || '-' }}</el-descriptions-item>
        <el-descriptions-item label="异常说明" :span="2">{{ currentException.description || '-' }}</el-descriptions-item>
        <el-descriptions-item label="处理备注" :span="2">{{ currentException.handle_note || '-' }}</el-descriptions-item>
      </el-descriptions>

      <el-divider content-position="left">快捷联动</el-divider>
      <el-space wrap class="mb-12">
        <el-button type="primary" @click="jumpToDispatchTask">查看调度任务订单明细</el-button>
        <el-button @click="jumpToPrePlanOrder" :disabled="!primaryTaskOrder?.id">查看关联预计划单</el-button>
      </el-space>

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

      <el-divider content-position="left">关联订单明细</el-divider>
      <el-table :data="selectedTaskOrders" size="small" stripe>
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
              <strong>{{ item.event === 'reported' ? '司机上报异常' : '调度处理异常' }}</strong>
            </div>
            <div>异常类型：{{ getLabel(exceptionTypeLabelMap, item.type) }}</div>
            <div v-if="item.description">异常说明：{{ item.description }}</div>
            <div v-if="item.action">处理动作：{{ getLabel(exceptionActionLabelMap, item.action) }}</div>
            <div v-if="item.handle_note">处理备注：{{ item.handle_note }}</div>
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
