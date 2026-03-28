<script setup>
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import * as XLSX from 'xlsx'
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
const orderDetailLoading = ref(false)
const taskOrdersLoading = ref(false)
const previewDialogVisible = ref(false)
const orderDetailDialogVisible = ref(false)
const taskOrdersDialogVisible = ref(false)
const selectedOrder = ref(null)
const selectedTask = ref(null)
const taskOrders = ref([])
const taskOrderFilter = ref({
  keyword: '',
  status: '',
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

const loadVehicles = async () => {
  const { data } = await api.post('/resource/vehicle/list', {})
  vehicles.value = Array.isArray(data?.data) ? data.data : []
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

const formatDateTime = (value) => {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString('zh-CN', { hour12: false })
}

const openOrderDetailDialog = async (order) => {
  if (!order?.id) return
  orderDetailDialogVisible.value = true
  orderDetailLoading.value = true
  selectedOrder.value = null
  try {
    const { data } = await api.post('/pre-plan-order/detail', { id: order.id })
    selectedOrder.value = data
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载订单详情失败')
    orderDetailDialogVisible.value = false
  } finally {
    orderDetailLoading.value = false
  }
}

const loadTaskOrders = async () => {
  if (!selectedTask.value?.id) return
  taskOrdersLoading.value = true
  try {
    const { data } = await api.post('/dispatch-task/order-list', {
      task_id: selectedTask.value.id,
      keyword: taskOrderFilter.value.keyword || undefined,
      status: taskOrderFilter.value.status || undefined,
    })
    taskOrders.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载任务订单明细失败')
  } finally {
    taskOrdersLoading.value = false
  }
}

const openTaskOrdersDialog = async (task) => {
  selectedTask.value = task
  taskOrderFilter.value.keyword = ''
  taskOrderFilter.value.status = ''
  taskOrdersDialogVisible.value = true
  await loadTaskOrders()
}

const exportTaskOrders = () => {
  if (!taskOrders.value.length) {
    ElMessage.warning('暂无可导出数据')
    return
  }
  const rows = taskOrders.value.map((item) => ({
    序号: item.sequence,
    订单号: item.order_no,
    客户: item.client_name,
    状态: getOrderTaskStatusLabel(item.status),
    装货地: item.pickup_address,
    卸货地: item.dropoff_address,
    装货联系人: `${item.pickup_contact_name || '-'} / ${item.pickup_contact_phone || '-'}`,
    收货联系人: `${item.dropoff_contact_name || '-'} / ${item.dropoff_contact_phone || '-'}`,
    重量kg: item.cargo_weight_kg ?? '',
    体积m3: item.cargo_volume_m3 ?? '',
  }))
  const sheet = XLSX.utils.json_to_sheet(rows)
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, sheet, '任务订单明细')
  XLSX.writeFile(workbook, `${selectedTask.value?.task_no || '调度任务'}-订单明细.xlsx`)
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
      <el-table-column label="订单详情" min-width="300">
        <template #default="{ row }">
          <el-space wrap>
            <el-tag
              v-for="order in row.orders || []"
              :key="order.id"
              size="small"
              :type="getOrderTaskStatusTagType(order.status)"
              class="order-tag-clickable"
              @click="openOrderDetailDialog(order)"
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
      <el-table-column label="操作" min-width="90" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openTaskOrdersDialog(row)">订单明细</el-button>
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
    v-model="orderDetailDialogVisible"
    title="订单详情"
    width="620px"
    destroy-on-close
  >
    <el-skeleton :loading="orderDetailLoading" animated :count="2">
      <template #template>
        <el-skeleton-item variant="text" style="height: 84px; margin-bottom: 10px" />
      </template>
      <template #default>
        <el-descriptions v-if="selectedOrder" :column="1" border size="small">
          <el-descriptions-item label="订单号">{{ selectedOrder.order_no || '-' }}</el-descriptions-item>
          <el-descriptions-item label="客户">{{ selectedOrder.client_name || '-' }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ getOrderTaskStatusLabel(selectedOrder.status) }}</el-descriptions-item>
          <el-descriptions-item label="货品分类ID">{{ selectedOrder.cargo_category_id || '-' }}</el-descriptions-item>
          <el-descriptions-item label="装货地址">{{ selectedOrder.pickup_address || '-' }}</el-descriptions-item>
          <el-descriptions-item label="装货联系人">
            {{ selectedOrder.pickup_contact_name || '-' }} / {{ selectedOrder.pickup_contact_phone || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="卸货地址">{{ selectedOrder.dropoff_address || '-' }}</el-descriptions-item>
          <el-descriptions-item label="收货联系人">
            {{ selectedOrder.dropoff_contact_name || '-' }} / {{ selectedOrder.dropoff_contact_phone || '-' }}
          </el-descriptions-item>
          <el-descriptions-item label="重量(kg)">{{ selectedOrder.cargo_weight_kg ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="体积(m³)">{{ selectedOrder.cargo_volume_m3 ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="期望提货时间">{{ formatDateTime(selectedOrder.expected_pickup_at) }}</el-descriptions-item>
          <el-descriptions-item label="期望送达时间">{{ formatDateTime(selectedOrder.expected_delivery_at) }}</el-descriptions-item>
          <el-descriptions-item label="创建时间">{{ formatDateTime(selectedOrder.created_at) }}</el-descriptions-item>
          <el-descriptions-item label="更新时间">{{ formatDateTime(selectedOrder.updated_at) }}</el-descriptions-item>
        </el-descriptions>
      </template>
    </el-skeleton>
    <template #footer>
      <el-button @click="orderDetailDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="taskOrdersDialogVisible" width="980px" destroy-on-close>
    <template #header>
      <div class="table-header">
        <div>调度任务订单明细：{{ selectedTask?.task_no || '-' }}</div>
        <div>
          <el-button class="mr-8" plain @click="exportTaskOrders">导出 XLSX</el-button>
          <el-button plain @click="loadTaskOrders">刷新</el-button>
        </div>
      </div>
    </template>
    <el-form inline class="mb-12">
      <el-form-item label="关键词">
        <el-input v-model="taskOrderFilter.keyword" clearable placeholder="订单号/客户/装卸地" style="width: 220px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="taskOrderFilter.status" clearable placeholder="全部状态" style="width: 140px">
          <el-option label="待接单" value="pending" />
          <el-option label="已排程" value="scheduled" />
          <el-option label="执行中" value="in_progress" />
          <el-option label="已完成" value="completed" />
          <el-option label="已取消" value="cancelled" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="loadTaskOrders">查询</el-button>
      </el-form-item>
    </el-form>
    <el-table :data="taskOrders" stripe v-loading="taskOrdersLoading">
      <el-table-column prop="sequence" label="序号" min-width="70" />
      <el-table-column prop="order_no" label="订单号" min-width="160" />
      <el-table-column prop="client_name" label="客户" min-width="120" />
      <el-table-column label="状态" min-width="100">
        <template #default="{ row }">
          <el-tag :type="getOrderTaskStatusTagType(row.status)">
            {{ getOrderTaskStatusLabel(row.status) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="pickup_address" label="装货地" min-width="180" />
      <el-table-column prop="dropoff_address" label="卸货地" min-width="180" />
      <el-table-column label="联系人" min-width="180">
        <template #default="{ row }">
          {{ row.pickup_contact_name || '-' }} / {{ row.dropoff_contact_name || '-' }}
        </template>
      </el-table-column>
    </el-table>
    <template #footer>
      <el-button @click="taskOrdersDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>
