<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { getLabel, taskStatusLabelMap } from '../../utils/labels'

const prePlanOrders = ref([])
const cargoCategories = ref([])
const sites = ref([])
const loadingOrders = ref(false)
const createDialogVisible = ref(false)
const creating = ref(false)
const loadingMeta = ref(false)
const loadingSites = ref(false)

const createFormRef = ref()
const createForm = reactive({
  cargo_category_id: null,
  client_name: '',
  pickup_address: '',
  dropoff_address: '',
  cargo_weight_kg: null,
  cargo_volume_m3: null,
  expected_pickup_at: '',
  expected_delivery_at: '',
})

const rules = {
  cargo_category_id: [{ required: true, message: '请选择货品分类', trigger: 'change' }],
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

const cargoCategoryMap = computed(() => {
  const map = {}
  for (const item of cargoCategories.value) {
    map[item.id] = item.name
  }
  return map
})

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
  createForm.cargo_category_id = null
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

const loadMeta = async () => {
  loadingMeta.value = true
  try {
    const { data } = await api.get('/meta')
    cargoCategories.value = Array.isArray(data?.cargo_categories) ? data.cargo_categories : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取基础字典失败')
  } finally {
    loadingMeta.value = false
  }
}

const loadSites = async () => {
  loadingSites.value = true
  try {
    const { data } = await api.post('/resource/site/list', {
      status: 'active',
    })
    sites.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取站点列表失败')
  } finally {
    loadingSites.value = false
  }
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
  loadMeta()
  loadSites()
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
      <el-table-column label="货品分类" min-width="130">
        <template #default="{ row }">
          {{ cargoCategoryMap[row.cargo_category_id] || `分类#${row.cargo_category_id}` }}
        </template>
      </el-table-column>
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
          <el-form-item label="货品分类" prop="cargo_category_id">
            <el-select
              v-model="createForm.cargo_category_id"
              :loading="loadingMeta"
              placeholder="请选择货品分类"
              style="width: 100%"
            >
              <el-option
                v-for="item in cargoCategories"
                :key="item.id"
                :label="`${item.name}（${item.code}）`"
                :value="item.id"
              />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="客户名称" prop="client_name">
            <el-input v-model="createForm.client_name" placeholder="请输入客户名称" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="装货地" prop="pickup_address">
        <el-select
          v-model="createForm.pickup_address"
          filterable
          allow-create
          default-first-option
          clearable
          :loading="loadingSites"
          placeholder="请选择或输入装货地地址"
          style="width: 100%"
        >
          <el-option
            v-for="site in sites"
            :key="site.id"
            :label="`${site.name}｜${site.address}`"
            :value="site.address"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="卸货地" prop="dropoff_address">
        <el-select
          v-model="createForm.dropoff_address"
          filterable
          allow-create
          default-first-option
          clearable
          :loading="loadingSites"
          placeholder="请选择或输入卸货地地址"
          style="width: 100%"
        >
          <el-option
            v-for="site in sites"
            :key="`dropoff-${site.id}`"
            :label="`${site.name}｜${site.address}`"
            :value="site.address"
          />
        </el-select>
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
