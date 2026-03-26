<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { getLabel, taskStatusLabelMap } from '../../utils/labels'

const prePlanOrders = ref([])
const loadingOrders = ref(false)
const createDialogVisible = ref(false)
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

onMounted(() => {
  loadPrePlanOrders()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <div class="card-title">预计划单管理</div>
        <el-button type="primary" @click="openCreateDialog">新建预计划单</el-button>
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
</template>
