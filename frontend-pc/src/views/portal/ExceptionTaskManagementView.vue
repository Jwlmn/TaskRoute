<script setup>
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { getLabel, taskStatusLabelMap } from '../../utils/labels'

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
})

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

const formatDateTime = (value) => {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString('zh-CN', { hour12: false })
}

const formatEntityChange = (label, beforeValue, afterValue) => `${label}：${beforeValue || '-'} -> ${afterValue || '-'}`

const currentException = computed(() => selectedExceptionTask.value?.route_meta?.exception || null)
const currentExceptionHistory = computed(() => {
  const history = currentException.value?.history
  return Array.isArray(history) ? [...history].reverse() : []
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
      <el-form-item>
        <el-button type="primary" @click="loadExceptionTasks">查询</el-button>
      </el-form-item>
    </el-form>
    <el-table :data="exceptionTasks" stripe v-loading="loadingExceptions">
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
        <el-descriptions-item label="关联节点">{{ currentException.waypoint_id || '-' }}</el-descriptions-item>
        <el-descriptions-item label="异常说明" :span="2">{{ currentException.description || '-' }}</el-descriptions-item>
        <el-descriptions-item label="处理备注" :span="2">{{ currentException.handle_note || '-' }}</el-descriptions-item>
      </el-descriptions>

      <el-divider content-position="left">处理前后变化</el-divider>
      <el-descriptions :column="1" border size="small">
        <el-descriptions-item label="任务状态">
          {{ formatEntityChange('状态', getLabel(taskStatusLabelMap, currentException.previous_task_status), getLabel(taskStatusLabelMap, currentException.current_task_status)) }}
        </el-descriptions-item>
        <el-descriptions-item label="车辆变更">
          {{ formatEntityChange('车辆', currentException.previous_vehicle_id ? `#${currentException.previous_vehicle_id}` : '-', currentException.current_vehicle_id ? `#${currentException.current_vehicle_id}` : '-') }}
        </el-descriptions-item>
        <el-descriptions-item label="司机变更">
          {{ formatEntityChange('司机', currentException.previous_driver_id ? `#${currentException.previous_driver_id}` : '-', currentException.current_driver_id ? `#${currentException.current_driver_id}` : '-') }}
        </el-descriptions-item>
      </el-descriptions>

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
            <div>操作人ID：{{ item.operator_id || '-' }}</div>
            <div v-if="item.previous_task_status || item.current_task_status">
              任务状态：{{ getLabel(taskStatusLabelMap, item.previous_task_status) }} -> {{ getLabel(taskStatusLabelMap, item.current_task_status) }}
            </div>
            <div v-if="item.previous_vehicle_id || item.current_vehicle_id">
              车辆变更：{{ item.previous_vehicle_id ? `#${item.previous_vehicle_id}` : '-' }} -> {{ item.current_vehicle_id ? `#${item.current_vehicle_id}` : '-' }}
            </div>
            <div v-if="item.previous_driver_id || item.current_driver_id">
              司机变更：{{ item.previous_driver_id ? `#${item.previous_driver_id}` : '-' }} -> {{ item.current_driver_id ? `#${item.current_driver_id}` : '-' }}
            </div>
          </el-card>
        </el-timeline-item>
      </el-timeline>
    </template>
    <el-empty v-else description="暂无异常详情" />
  </el-drawer>
</template>
