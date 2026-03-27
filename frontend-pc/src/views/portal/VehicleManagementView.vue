<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import {
  getLabel,
  vehicleStatusLabelMap,
  vehicleTypeLabelMap,
} from '../../utils/labels'

const loading = ref(false)
const rows = ref([])
const dialogVisible = ref(false)
const dialogMode = ref('create')
const cargoCategoryOptions = ref([])
const driverOptions = ref([])
const prePlanOrderMap = ref({})
const dispatchDialogVisible = ref(false)
const dispatchLoading = ref(false)
const dispatchSubmitting = ref(false)
const selectedVehicle = ref(null)
const vehicleAssignment = ref(null)
const vehicleUnassigned = ref([])

const form = reactive({
  id: null,
  plate_number: '',
  name: '',
  vehicle_type: 'van',
  driver_id: null,
  max_weight_kg: 0,
  max_volume_m3: 0,
  status: 'idle',
  compartment_enabled: false,
  compartments: [],
})

const createCompartment = () => ({
  no: 1,
  capacity_m3: 0,
  allowed_cargo_category_ids: [],
})

const normalizeCompartments = (items) => {
  if (!Array.isArray(items)) return []
  return items.map((item, idx) => ({
    no: Number(item?.no || idx + 1),
    capacity_m3: Number(item?.capacity_m3 || 0),
    allowed_cargo_category_ids: Array.isArray(item?.allowed_cargo_category_ids)
      ? item.allowed_cargo_category_ids.map((id) => Number(id)).filter((id) => !Number.isNaN(id))
      : [],
  }))
}

const resetForm = () => {
  form.id = null
  form.plate_number = ''
  form.name = ''
  form.vehicle_type = 'van'
  form.driver_id = null
  form.max_weight_kg = 0
  form.max_volume_m3 = 0
  form.status = 'idle'
  form.compartment_enabled = false
  form.compartments = []
}

const fetchRows = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/resource/vehicle/list', {})
    rows.value = data.data || []
  } finally {
    loading.value = false
  }
}

const fetchCargoCategories = async () => {
  try {
    const { data } = await api.get('/meta')
    cargoCategoryOptions.value = Array.isArray(data?.cargo_categories) ? data.cargo_categories : []
  } catch {
    cargoCategoryOptions.value = []
  }
}

const fetchDriverOptions = async () => {
  try {
    const { data } = await api.post('/resource/personnel/list', {
      role: 'driver',
      status: 'active',
    })
    driverOptions.value = Array.isArray(data?.data) ? data.data : []
  } catch {
    driverOptions.value = []
  }
}

const fetchPrePlanOrderMap = async () => {
  try {
    const { data } = await api.post('/pre-plan-order/list', {})
    const list = Array.isArray(data?.data) ? data.data : []
    const nextMap = {}
    for (const item of list) {
      nextMap[item.id] = item
    }
    prePlanOrderMap.value = nextMap
  } catch {
    prePlanOrderMap.value = {}
  }
}

const openCreate = () => {
  dialogMode.value = 'create'
  resetForm()
  dialogVisible.value = true
}

const openEdit = (row) => {
  dialogMode.value = 'edit'
  form.id = row.id
  form.plate_number = row.plate_number
  form.name = row.name
  form.vehicle_type = row.vehicle_type
  form.driver_id = row.driver_id || null
  form.max_weight_kg = Number(row.max_weight_kg || 0)
  form.max_volume_m3 = Number(row.max_volume_m3 || 0)
  form.status = row.status
  form.compartment_enabled = Boolean(row.meta?.compartment_enabled)
  form.compartments = normalizeCompartments(row.meta?.compartments || [])
  dialogVisible.value = true
}

const addCompartment = () => {
  form.compartments.push({
    ...createCompartment(),
    no: form.compartments.length + 1,
  })
}

const removeCompartment = (index) => {
  form.compartments.splice(index, 1)
  form.compartments = form.compartments.map((item, idx) => ({ ...item, no: idx + 1 }))
}

const compartmentSummary = (row) => {
  const enabled = Boolean(row.meta?.compartment_enabled)
  if (!enabled) return '未启用'
  const list = normalizeCompartments(row.meta?.compartments)
  if (list.length === 0) return '已启用（未配置）'
  return `已启用（${list.length}仓）`
}

const orderOf = (orderId) => {
  const order = prePlanOrderMap.value[orderId]
  if (!order) {
    return {
      id: orderId,
      order_no: `订单#${orderId}`,
      client_name: '-',
      pickup_address: '-',
      dropoff_address: '-',
      cargo_weight_kg: '-',
      cargo_volume_m3: '-',
    }
  }
  return order
}

const assignedOrders = () => {
  const directOrders = vehicleAssignment.value?.orders
  if (Array.isArray(directOrders) && directOrders.length > 0) {
    return directOrders
  }
  return (vehicleAssignment.value?.order_ids || []).map((id) => orderOf(id))
}

const openVehicleDispatch = async (row) => {
  selectedVehicle.value = row
  vehicleAssignment.value = null
  vehicleUnassigned.value = []
  dispatchDialogVisible.value = true
  dispatchLoading.value = true
  try {
    await fetchPrePlanOrderMap()
    const { data } = await api.post('/dispatch/preview', {
      vehicle_ids: [row.id],
    })
    const assignments = Array.isArray(data?.assignments) ? data.assignments : []
    vehicleAssignment.value = assignments.find((item) => Number(item.vehicle_id) === Number(row.id)) || null
    vehicleUnassigned.value = Array.isArray(data?.unassigned) ? data.unassigned : []
    if (!vehicleAssignment.value || (vehicleAssignment.value.order_ids || []).length === 0) {
      ElMessage.warning('该车辆当前未匹配到可派订单，请检查车辆状态、司机绑定或禁混规则')
    }
  } catch (error) {
    dispatchDialogVisible.value = false
    ElMessage.error(error?.response?.data?.message || '单车智能匹配失败')
  } finally {
    dispatchLoading.value = false
  }
}

const submitVehicleDispatch = async () => {
  if (!selectedVehicle.value || !vehicleAssignment.value || (vehicleAssignment.value.order_ids || []).length === 0) {
    ElMessage.warning('当前没有可下发的订单')
    return
  }

  dispatchSubmitting.value = true
  try {
    await api.post('/dispatch/manual-create-tasks', {
      assignments: [
        {
          vehicle_id: selectedVehicle.value.id,
          order_ids: vehicleAssignment.value.order_ids,
          estimated_distance_km: vehicleAssignment.value.estimated_distance_km,
          estimated_fuel_l: vehicleAssignment.value.estimated_fuel_l,
          estimated_duration_min: vehicleAssignment.value.estimated_duration_min,
          route_meta: vehicleAssignment.value.route_meta || {},
          compartment_plan: vehicleAssignment.value.compartment_plan || [],
        },
      ],
    })
    ElMessage.success('该车辆派单下发成功')
    dispatchDialogVisible.value = false
    await fetchRows()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '下发失败，请检查订单与车辆约束')
  } finally {
    dispatchSubmitting.value = false
  }
}

const submit = async () => {
  try {
    const payload = {
      plate_number: form.plate_number,
      name: form.name,
      vehicle_type: form.vehicle_type,
      driver_id: form.driver_id,
      max_weight_kg: form.max_weight_kg,
      max_volume_m3: form.max_volume_m3,
      status: form.status,
      meta: {
        compartment_enabled: form.compartment_enabled,
        compartments: form.compartment_enabled
          ? normalizeCompartments(form.compartments)
          : [],
      },
    }

    if (dialogMode.value === 'create') {
      await api.post('/resource/vehicle/create', payload)
      ElMessage.success('车辆创建成功')
    } else {
      await api.post('/resource/vehicle/update', { id: form.id, ...payload })
      ElMessage.success('车辆更新成功')
    }
    dialogVisible.value = false
    await fetchRows()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '保存失败')
  }
}

onMounted(async () => {
  await Promise.all([fetchRows(), fetchCargoCategories(), fetchDriverOptions()])
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <span class="card-title">车辆资源管理</span>
        <el-button type="primary" @click="openCreate">新增车辆</el-button>
      </div>
    </template>

    <el-table :data="rows" v-loading="loading" stripe>
      <el-table-column prop="plate_number" label="车牌号" min-width="120" />
      <el-table-column prop="name" label="车辆名称" min-width="130" />
      <el-table-column label="车辆类型" min-width="110">
        <template #default="{ row }">
          {{ getLabel(vehicleTypeLabelMap, row.vehicle_type) }}
        </template>
      </el-table-column>
      <el-table-column label="绑定司机" min-width="150">
        <template #default="{ row }">
          {{ row.driver?.name ? `${row.driver.name}（${row.driver.account}）` : '未绑定' }}
        </template>
      </el-table-column>
      <el-table-column prop="max_weight_kg" label="载重(kg)" min-width="100" />
      <el-table-column prop="max_volume_m3" label="容积(m3)" min-width="100" />
      <el-table-column label="分仓配置" min-width="120">
        <template #default="{ row }">
          {{ compartmentSummary(row) }}
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="90">
        <template #default="{ row }">
          {{ getLabel(vehicleStatusLabelMap, row.status) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="200" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
          <el-button
            link
            type="success"
            :disabled="row.status !== 'idle' || !row.driver_id"
            @click="openVehicleDispatch(row)"
          >
            智能派单
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog
    v-model="dialogVisible"
    :title="dialogMode === 'create' ? '新增车辆' : '编辑车辆'"
    width="760px"
  >
    <el-form label-position="top">
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="车牌号">
            <el-input v-model="form.plate_number" :disabled="dialogMode === 'edit'" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="车辆名称">
            <el-input v-model="form.name" />
          </el-form-item>
        </el-col>
      </el-row>

      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="车辆类型">
            <el-select v-model="form.vehicle_type" style="width: 100%">
              <el-option label="厢式货车" value="van" />
              <el-option label="平板车" value="flatbed" />
              <el-option label="卡车" value="truck" />
              <el-option label="罐车" value="tank" />
              <el-option label="冷链车" value="coldchain" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="绑定司机">
            <el-select v-model="form.driver_id" style="width: 100%" placeholder="请选择司机">
              <el-option
                v-for="item in driverOptions"
                :key="item.id"
                :label="`${item.name}（${item.account}）`"
                :value="item.id"
              />
            </el-select>
          </el-form-item>
        </el-col>
      </el-row>

      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="状态">
            <el-select v-model="form.status" style="width: 100%">
              <el-option label="空闲" value="idle" />
              <el-option label="执行中" value="busy" />
              <el-option label="维护中" value="maintenance" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12" />
      </el-row>

      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="最大载重(kg)">
            <el-input-number v-model="form.max_weight_kg" :min="0" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="最大容积(m3)">
            <el-input-number v-model="form.max_volume_m3" :min="0" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>

      <el-divider>分仓能力（拼单精细化）</el-divider>
      <el-form-item label="启用分仓">
        <el-switch v-model="form.compartment_enabled" />
      </el-form-item>

      <template v-if="form.compartment_enabled">
        <div class="table-header mb-12">
          <span>分仓明细</span>
          <el-button type="primary" plain @click="addCompartment">新增仓位</el-button>
        </div>

        <el-empty v-if="form.compartments.length === 0" description="请新增仓位配置" />
        <el-card v-for="(compartment, index) in form.compartments" :key="index" shadow="never" class="mb-12">
          <div class="table-header mb-12">
            <strong>仓位 {{ index + 1 }}</strong>
            <el-button type="danger" link @click="removeCompartment(index)">删除仓位</el-button>
          </div>
          <el-row :gutter="12">
            <el-col :span="8">
              <el-form-item label="仓位序号">
                <el-input-number v-model="compartment.no" :min="1" style="width: 100%" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="仓位容量(m3)">
                <el-input-number v-model="compartment.capacity_m3" :min="0" :precision="2" style="width: 100%" />
              </el-form-item>
            </el-col>
            <el-col :span="8">
              <el-form-item label="可承运货品">
                <el-select
                  v-model="compartment.allowed_cargo_category_ids"
                  multiple
                  collapse-tags
                  collapse-tags-tooltip
                  style="width: 100%"
                  placeholder="可选，不选表示不限制"
                >
                  <el-option
                    v-for="item in cargoCategoryOptions"
                    :key="item.id"
                    :label="item.name"
                    :value="item.id"
                  />
                </el-select>
              </el-form-item>
            </el-col>
          </el-row>
        </el-card>
      </template>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" @click="submit">保存</el-button>
    </template>
  </el-dialog>

  <el-dialog
    v-model="dispatchDialogVisible"
    title="单车智能派单"
    width="920px"
    align-center
    class="vehicle-dispatch-dialog"
    destroy-on-close
  >
    <div class="vehicle-dispatch-dialog-content">
      <el-skeleton :loading="dispatchLoading" animated :count="2">
        <template #template>
          <el-skeleton-item variant="text" style="height: 88px; margin-bottom: 10px" />
        </template>
        <template #default>
          <el-descriptions :column="2" border size="small" class="mb-12">
            <el-descriptions-item label="车辆">
              {{ selectedVehicle?.plate_number || '-' }}｜{{ selectedVehicle?.name || '-' }}
            </el-descriptions-item>
            <el-descriptions-item label="绑定司机">
              {{ selectedVehicle?.driver?.name ? `${selectedVehicle.driver.name}（${selectedVehicle.driver.account}）` : '未绑定' }}
            </el-descriptions-item>
          </el-descriptions>

          <el-empty
            v-if="!vehicleAssignment || !(vehicleAssignment.order_ids || []).length"
            description="当前未匹配到可派订单"
          />
          <template v-else>
            <el-row :gutter="12" class="mb-12">
              <el-col :span="6"><el-tag type="primary">订单数：{{ vehicleAssignment.order_ids.length }}</el-tag></el-col>
              <el-col :span="6"><el-tag>里程：{{ vehicleAssignment.estimated_distance_km || 0 }} km</el-tag></el-col>
              <el-col :span="6"><el-tag>油耗：{{ vehicleAssignment.estimated_fuel_l || 0 }} L</el-tag></el-col>
              <el-col :span="6"><el-tag>耗时：{{ vehicleAssignment.estimated_duration_min || 0 }} min</el-tag></el-col>
            </el-row>
            <el-table
              :data="assignedOrders()"
              size="small"
              stripe
              max-height="260"
            >
              <el-table-column prop="order_no" label="订单号" min-width="120" show-overflow-tooltip />
              <el-table-column prop="client_name" label="客户" min-width="120" show-overflow-tooltip />
              <el-table-column prop="pickup_address" label="装货地" min-width="160" show-overflow-tooltip />
              <el-table-column prop="dropoff_address" label="卸货地" min-width="160" show-overflow-tooltip />
              <el-table-column prop="cargo_weight_kg" label="重量(kg)" min-width="90" />
              <el-table-column prop="cargo_volume_m3" label="体积(m3)" min-width="90" />
            </el-table>
          </template>

          <el-alert
            v-if="vehicleUnassigned.length > 0"
            style="margin-top: 12px"
            title="以下订单本次未能分配到该车辆"
            type="warning"
            :closable="false"
            show-icon
          />
          <el-table
            v-if="vehicleUnassigned.length > 0"
            :data="vehicleUnassigned"
            size="small"
            stripe
            style="margin-top: 12px"
            max-height="280"
          >
          <el-table-column prop="order_no" label="订单号" min-width="150" />
          <el-table-column prop="reason" label="未分配原因" min-width="240" show-overflow-tooltip />
        </el-table>
        </template>
      </el-skeleton>
    </div>
    <template #footer>
      <el-button @click="dispatchDialogVisible = false">取消</el-button>
      <el-button
        type="primary"
        :loading="dispatchSubmitting"
        :disabled="!vehicleAssignment || !(vehicleAssignment.order_ids || []).length"
        @click="submitVehicleDispatch"
      >
        确认下发
      </el-button>
    </template>
  </el-dialog>
</template>
