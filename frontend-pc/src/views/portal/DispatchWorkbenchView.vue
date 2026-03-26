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
const previewLoading = ref(false)
const creatingTasks = ref(false)
const previewDialogVisible = ref(false)

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

const loadVehicles = async () => {
  const { data } = await api.post('/resource/vehicle/list', {})
  vehicles.value = Array.isArray(data?.data) ? data.data : []
}

const getOrderText = (orderId) => {
  const order = orderMap.value[orderId]
  if (!order) return `订单#${orderId}`
  return `${order.order_no}｜${order.client_name}`
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
  await loadDispatchTasks()
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
      <el-table-column label="状态" min-width="100">
        <template #default="{ row }">
          {{ getLabel(taskStatusLabelMap, row.status) }}
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
</template>
