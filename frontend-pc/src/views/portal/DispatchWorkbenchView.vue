<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
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

const loadingOrders = ref(false)
const loadingTasks = ref(false)
const previewLoading = ref(false)
const creatingTasks = ref(false)

const createDialogVisible = ref(false)
const previewDialogVisible = ref(false)
const creating = ref(false)

const createFormRef = ref()
const createForm = reactive({
  cargo_category_id: '',
  client_name: '',
  pickup_address: '',
  dropoff_address: '',
  cargo_weight_kg: null,
  cargo_volume_m3: null,
  expected_pickup_at: '',
  expected_delivery_at: '',
})

const rules = {
  cargo_category_id: [{ required: true, message: '请输入货品分类ID', trigger: 'blur' }],
  client_name: [{ required: true, message: '请输入客户名称', trigger: 'blur' }],
  pickup_address: [{ required: true, message: '请输入装货地', trigger: 'blur' }],
  dropoff_address: [{ required: true, message: '请输入卸货地', trigger: 'blur' }],
}

const statusTypeMap = {
  pending: 'info',
  scheduled: 'primary',
  in_progress: 'warning',
  completed: 'success',
  cancelled: 'danger',
}

const orderMap = computed(() => {
  const map = {}
  for (const item of prePlanOrders.value) {
    map[item.id] = item
  }
  return map
})

const resetCreateForm = () => {
  createForm.cargo_category_id = ''
  createForm.client_name = ''
  createForm.pickup_address = ''
  createForm.dropoff_address = ''
  createForm.cargo_weight_kg = null
  createForm.cargo_volume_m3 = null
  createForm.expected_pickup_at = ''
  createForm.expected_delivery_at = ''
  createFormRef.value?.clearValidate()
}

const openCreateDialog = () => {
  resetCreateForm()
  createDialogVisible.value = true
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

const loadPrePlanOrders = async () => {
  loadingOrders.value = true
  try {
    const { data } = await api.post('/pre-plan-order/list', {})
    prePlanOrders.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取预计划单失败')
  } finally {
    loadingOrders.value = false
  }
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

const createPrePlanOrder = async () => {
  if (!createFormRef.value) return
  const valid = await createFormRef.value.validate().catch(() => false)
  if (!valid) return

  creating.value = true
  try {
    const payload = {
      cargo_category_id: Number(createForm.cargo_category_id),
      client_name: createForm.client_name,
      pickup_address: createForm.pickup_address,
      dropoff_address: createForm.dropoff_address,
      cargo_weight_kg: createForm.cargo_weight_kg,
      cargo_volume_m3: createForm.cargo_volume_m3,
      expected_pickup_at: createForm.expected_pickup_at || null,
      expected_delivery_at: createForm.expected_delivery_at || null,
    }
    await api.post('/pre-plan-order/create', payload)
    ElMessage.success('预计划单创建成功')
    createDialogVisible.value = false
    await loadPrePlanOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '创建预计划单失败')
  } finally {
    creating.value = false
  }
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
    await Promise.all([loadPrePlanOrders(), loadDispatchTasks()])
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '下发失败，请检查调整结果')
  } finally {
    creatingTasks.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadPrePlanOrders(), loadDispatchTasks()])
})
</script>

<template>
  <el-card shadow="never" class="mb-12">
    <template #header>
      <div class="table-header">
        <div class="card-title">预计划单管理</div>
        <div>
          <el-button type="primary" plain class="mr-8" @click="openManualAdjustDialog">
            智能预览并手工下发
          </el-button>
          <el-button type="primary" @click="openCreateDialog">新建预计划单</el-button>
        </div>
      </div>
    </template>
    <el-table :data="prePlanOrders" stripe v-loading="loadingOrders">
      <el-table-column prop="order_no" label="预计划单号" min-width="180" />
      <el-table-column prop="client_name" label="客户" min-width="120" />
      <el-table-column prop="pickup_address" label="装货地" min-width="180" />
      <el-table-column prop="dropoff_address" label="卸货地" min-width="180" />
      <el-table-column prop="cargo_category_id" label="货品分类ID" min-width="110" />
      <el-table-column prop="cargo_weight_kg" label="重量(kg)" min-width="100" />
      <el-table-column prop="cargo_volume_m3" label="体积(m³)" min-width="100" />
      <el-table-column label="预计提货" min-width="160">
        <template #default="{ row }">
          {{ formatDateTime(row.expected_pickup_at) }}
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="100">
        <template #default="{ row }">
          <el-tag :type="statusTypeMap[row.status] || 'info'">
            {{ getLabel(taskStatusLabelMap, row.status) }}
          </el-tag>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-card shadow="never">
    <template #header>
      <div class="card-title">调度任务概览</div>
    </template>
    <el-table :data="dispatchTasks" stripe v-loading="loadingTasks">
      <el-table-column prop="task_no" label="任务编号" min-width="180" />
      <el-table-column label="派单模式" min-width="180">
        <template #default="{ row }">
          {{ getLabel(dispatchModeLabelMap, row.dispatch_mode) }}
        </template>
      </el-table-column>
      <el-table-column prop="vehicle_id" label="车辆ID" min-width="90" />
      <el-table-column prop="driver_id" label="司机ID" min-width="90" />
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
    v-model="createDialogVisible"
    title="新建预计划单"
    width="680px"
    destroy-on-close
  >
    <el-form ref="createFormRef" :model="createForm" :rules="rules" label-width="120px">
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="货品分类ID" prop="cargo_category_id">
            <el-input v-model="createForm.cargo_category_id" placeholder="例如 1" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="客户名称" prop="client_name">
            <el-input v-model="createForm.client_name" placeholder="请输入客户名称" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="装货地" prop="pickup_address">
        <el-input v-model="createForm.pickup_address" placeholder="请输入装货地地址" />
      </el-form-item>
      <el-form-item label="卸货地" prop="dropoff_address">
        <el-input v-model="createForm.dropoff_address" placeholder="请输入卸货地地址" />
      </el-form-item>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="重量(kg)">
            <el-input-number v-model="createForm.cargo_weight_kg" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="体积(m³)">
            <el-input-number v-model="createForm.cargo_volume_m3" :min="0" :precision="3" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="预计提货时间">
            <el-date-picker
              v-model="createForm.expected_pickup_at"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="请选择预计提货时间"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="预计送达时间">
            <el-date-picker
              v-model="createForm.expected_delivery_at"
              type="datetime"
              value-format="YYYY-MM-DD HH:mm:ss"
              placeholder="请选择预计送达时间"
              style="width: 100%"
            />
          </el-form-item>
        </el-col>
      </el-row>
    </el-form>
    <template #footer>
      <el-button @click="createDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="creating" @click="createPrePlanOrder">创建</el-button>
    </template>
  </el-dialog>

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
