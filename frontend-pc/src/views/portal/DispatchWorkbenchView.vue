<script setup>
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import {
  dispatchModeLabelMap,
  getLabel,
  taskStatusLabelMap,
} from '../../utils/labels'

const prePlanOrders = ref([])
const dispatchTasks = ref([])
const vehicles = ref([])
const unassignedOrders = ref([])
const previewAssignments = ref([])

const loadingTasks = ref(false)
const loadingExceptions = ref(false)
const previewLoading = ref(false)
const creatingTasks = ref(false)
const handlingException = ref(false)
const previewDialogVisible = ref(false)
const exceptionTasks = ref([])
const exceptionHandleDialogVisible = ref(false)
const handlingTask = ref(null)
const exceptionHandleForm = ref({
  action: 'continue',
  handle_note: '',
  reassign_vehicle_id: null,
})

const orderMap = computed(() => {
  const map = {}
  for (const item of prePlanOrders.value) {
    map[item.id] = item
  }
  return map
})

const loadPrePlanOrders = async () => {
  const { data } = await api.post('/pre-plan-order/list', {})
  prePlanOrders.value = Array.isArray(data?.data) ? data.data : []
}

const loadDispatchTasks = async () => {
  loadingTasks.value = true
  try {
    const { data } = await api.post('/dispatch-task/list', {})
    dispatchTasks.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取调度任务失败')
  } finally {
    loadingTasks.value = false
  }
}

const loadExceptionTasks = async () => {
  loadingExceptions.value = true
  try {
    const { data } = await api.post('/dispatch-task/exception-list', { status: 'pending' })
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
    await Promise.all([loadDispatchTasks(), loadExceptionTasks()])
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '异常处理失败')
  } finally {
    handlingException.value = false
  }
}

const getOrderText = (orderId) => {
  const order = orderMap.value[orderId]
  if (!order) return `订单#${orderId}`
  return `${order.order_no}｜${order.client_name}`
}

const orderTaskStatusLabelMap = {
  pending: '待接单',
  scheduled: '待接单',
  assigned: '待接单',
  accepted: '配送中',
  in_progress: '配送中',
  completed: '已完成',
  cancelled: '已取消',
}

const getOrderTaskStatusLabel = (status) => orderTaskStatusLabelMap[status] || status || '-'

const getOrderTaskStatusTagType = (status) => {
  if (status === 'completed') return 'success'
  if (status === 'accepted' || status === 'in_progress') return 'warning'
  if (status === 'cancelled') return 'danger'
  return 'info'
}

const moveOrder = (assignment, index, delta) => {
  const target = index + delta
  if (target < 0 || target >= assignment.order_ids.length) return
  const nextOrderIds = [...assignment.order_ids]
  const current = nextOrderIds[index]
  nextOrderIds[index] = nextOrderIds[target]
  nextOrderIds[target] = current
  assignment.order_ids = nextOrderIds
}

const removeOrder = (assignment, index) => {
  assignment.order_ids.splice(index, 1)
}

const openManualAdjustDialog = async () => {
  previewLoading.value = true
  previewDialogVisible.value = true
  try {
    await Promise.all([loadPrePlanOrders(), loadVehicles()])
    const { data } = await api.post('/dispatch/preview', {})
    previewAssignments.value = (data?.assignments || []).map((item) => ({
      vehicle_id: item.vehicle_id,
      order_ids: [...(item.order_ids || [])],
      estimated_distance_km: item.estimated_distance_km,
      estimated_fuel_l: item.estimated_fuel_l,
      estimated_duration_min: item.estimated_duration_min,
      route_meta: item.route_meta || {},
      compartment_plan: item.compartment_plan || [],
    }))
    unassignedOrders.value = data?.unassigned || []
  } catch (error) {
    previewDialogVisible.value = false
    ElMessage.error(error?.response?.data?.message || '生成智能预览失败')
  } finally {
    previewLoading.value = false
  }
}

const submitManualAdjustedTasks = async () => {
  const assignments = previewAssignments.value
    .filter((item) => Array.isArray(item.order_ids) && item.order_ids.length > 0)
    .map((item) => ({
      vehicle_id: item.vehicle_id,
      order_ids: item.order_ids,
      estimated_distance_km: item.estimated_distance_km,
      estimated_fuel_l: item.estimated_fuel_l,
      estimated_duration_min: item.estimated_duration_min,
      route_meta: item.route_meta,
      compartment_plan: item.compartment_plan,
    }))

  if (assignments.length === 0) {
    ElMessage.warning('请至少保留一条有效派单')
    return
  }

  creatingTasks.value = true
  try {
    await api.post('/dispatch/manual-create-tasks', { assignments })
    ElMessage.success('任务下发成功')
    previewDialogVisible.value = false
    await loadDispatchTasks()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '下发失败，请检查调整结果')
  } finally {
    creatingTasks.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadDispatchTasks(), loadExceptionTasks()])
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <div class="card-title">调度任务管理</div>
        <el-button type="primary" @click="openManualAdjustDialog">智能预览并手工下发</el-button>
      </div>
    </template>
    <el-table :data="dispatchTasks" stripe v-loading="loadingTasks">
      <el-table-column prop="task_no" label="任务编号" min-width="180" />
      <el-table-column label="派单模式" min-width="180">
        <template #default="{ row }">
          {{ getLabel(dispatchModeLabelMap, row.dispatch_mode) }}
        </template>
      </el-table-column>
      <el-table-column label="车辆" min-width="180">
        <template #default="{ row }">
          {{ row.vehicle?.plate_number || '-' }} {{ row.vehicle?.name || '' }}
          <span class="text-secondary">（ID: {{ row.vehicle_id || '-' }}）</span>
        </template>
      </el-table-column>
      <el-table-column label="司机" min-width="170">
        <template #default="{ row }">
          {{ row.driver?.name || '-' }}（{{ row.driver?.account || '-' }}）
          <span class="text-secondary">（ID: {{ row.driver_id || '-' }}）</span>
        </template>
      </el-table-column>
      <el-table-column prop="estimated_distance_km" label="里程(km)" min-width="100" />
      <el-table-column prop="estimated_fuel_l" label="油耗(L)" min-width="100" />
      <el-table-column label="订单详情" min-width="300">
        <template #default="{ row }">
          <el-space wrap>
            <el-tag
              v-for="order in row.orders || []"
              :key="order.id"
              size="small"
              :type="getOrderTaskStatusTagType(order.status)"
            >
              {{ order.order_no }}｜{{ order.client_name }}｜{{ getOrderTaskStatusLabel(order.status) }}
            </el-tag>
          </el-space>
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="100">
        <template #default="{ row }">
          {{ getLabel(taskStatusLabelMap, row.status) }}
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-card shadow="never" class="mt-16">
    <template #header>
      <div class="table-header">
        <div class="card-title">异常任务池</div>
        <el-button type="primary" plain @click="loadExceptionTasks">刷新异常</el-button>
      </div>
    </template>
    <el-table :data="exceptionTasks" stripe v-loading="loadingExceptions">
      <el-table-column prop="task_no" label="任务编号" min-width="180" />
      <el-table-column label="当前状态" min-width="110">
        <template #default="{ row }">
          {{ getLabel(taskStatusLabelMap, row.status) }}
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
          {{ row.route_meta?.exception?.type || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="异常说明" min-width="260">
        <template #default="{ row }">
          {{ row.route_meta?.exception?.description || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="上报时间" min-width="180">
        <template #default="{ row }">
          {{ row.route_meta?.exception?.reported_at || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openHandleDialog(row)">处理</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog
    v-model="previewDialogVisible"
    title="智能派单预览（可手工调整）"
    width="980px"
    destroy-on-close
  >
    <el-skeleton :loading="previewLoading" animated :count="2">
      <template #template>
        <el-skeleton-item variant="text" style="height: 88px; margin-bottom: 10px" />
      </template>
      <template #default>
        <el-empty v-if="previewAssignments.length === 0" description="暂无可调整的派单结果" />
        <div v-for="(assignment, index) in previewAssignments" :key="index" class="mb-12">
          <el-card shadow="never">
            <div class="table-header mb-12">
              <strong>派单方案 {{ index + 1 }}</strong>
              <el-tag type="primary">订单数：{{ assignment.order_ids.length }}</el-tag>
            </div>
            <el-row :gutter="12">
              <el-col :span="10">
                <el-form-item label="车辆">
                  <el-select v-model="assignment.vehicle_id" style="width: 100%">
                    <el-option
                      v-for="vehicle in vehicles"
                      :key="vehicle.id"
                      :label="`${vehicle.plate_number}｜${vehicle.name}`"
                      :value="vehicle.id"
                    />
                  </el-select>
                </el-form-item>
              </el-col>
              <el-col :span="7">
                <el-form-item label="预计里程(km)">
                  <el-input-number v-model="assignment.estimated_distance_km" :min="0" :precision="2" style="width: 100%" />
                </el-form-item>
              </el-col>
              <el-col :span="7">
                <el-form-item label="预计油耗(L)">
                  <el-input-number v-model="assignment.estimated_fuel_l" :min="0" :precision="2" style="width: 100%" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-table :data="assignment.order_ids.map((id) => ({ id }))" size="small" stripe>
              <el-table-column label="顺序" width="70">
                <template #default="{ $index }">{{ $index + 1 }}</template>
              </el-table-column>
              <el-table-column label="订单" min-width="260">
                <template #default="{ row }">{{ getOrderText(row.id) }}</template>
              </el-table-column>
              <el-table-column label="操作" width="200">
                <template #default="{ $index }">
                  <el-button link type="primary" @click="moveOrder(assignment, $index, -1)">上移</el-button>
                  <el-button link type="primary" @click="moveOrder(assignment, $index, 1)">下移</el-button>
                  <el-button link type="danger" @click="removeOrder(assignment, $index)">移除</el-button>
                </template>
              </el-table-column>
            </el-table>
          </el-card>
        </div>

        <el-alert
          v-if="unassignedOrders.length > 0"
          type="warning"
          show-icon
          :closable="false"
          title="以下订单仍未分配，请按资源规则补充车辆或调整计划"
          class="mb-12"
        />
        <el-table v-if="unassignedOrders.length > 0" :data="unassignedOrders" size="small" stripe>
          <el-table-column prop="order_no" label="订单号" min-width="160" />
          <el-table-column prop="reason" label="未分配原因" min-width="260" />
        </el-table>
      </template>
    </el-skeleton>
    <template #footer>
      <el-button @click="previewDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="creatingTasks" @click="submitManualAdjustedTasks">确认下发</el-button>
    </template>
  </el-dialog>

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
</template>
