<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'

const loading = ref(false)
const saving = ref(false)
const dialogVisible = ref(false)
const dialogMode = ref('create')
const templates = ref([])
const cargoCategories = ref([])
const sites = ref([])
const currentPage = ref(1)
const pageSize = ref(10)

const filterForm = reactive({
  keyword: '',
  is_active: '',
  pickup_site_id: null,
  dropoff_site_id: null,
})

const form = reactive({
  id: null,
  name: '',
  client_name: '',
  cargo_category_id: null,
  pickup_site_id: null,
  pickup_address: '',
  dropoff_site_id: null,
  dropoff_address: '',
  freight_calc_scheme: 'by_weight',
  freight_unit_price: null,
  freight_trip_count: 1,
  loss_allowance_kg: 0,
  loss_deduct_unit_price: null,
  priority: 100,
  is_active: true,
  remark: '',
})

const schemeOptions = [
  { label: '按重量', value: 'by_weight' },
  { label: '按体积', value: 'by_volume' },
  { label: '按趟', value: 'by_trip' },
]
const pagedTemplates = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return templates.value.slice(start, start + pageSize.value)
})
const total = computed(() => templates.value.length)

const resetForm = () => {
  form.id = null
  form.name = ''
  form.client_name = ''
  form.cargo_category_id = null
  form.pickup_site_id = null
  form.pickup_address = ''
  form.dropoff_site_id = null
  form.dropoff_address = ''
  form.freight_calc_scheme = 'by_weight'
  form.freight_unit_price = null
  form.freight_trip_count = 1
  form.loss_allowance_kg = 0
  form.loss_deduct_unit_price = null
  form.priority = 100
  form.is_active = true
  form.remark = ''
}

const loadMeta = async () => {
  const { data } = await api.get('/meta')
  cargoCategories.value = Array.isArray(data?.cargo_categories) ? data.cargo_categories : []
}

const loadSites = async () => {
  try {
    const { data } = await api.post('/resource/site/list', { status: 'active' })
    sites.value = Array.isArray(data?.data) ? data.data : []
  } catch {
    sites.value = []
  }
}

const getErrorMessage = (error, fallback) => {
  const validationErrors = error?.response?.data?.errors
  if (validationErrors && typeof validationErrors === 'object') {
    const firstMessage = Object.values(validationErrors).flat().find(Boolean)
    if (typeof firstMessage === 'string' && firstMessage.trim()) {
      return firstMessage
    }
  }
  return error?.response?.data?.message || fallback
}

const loadTemplates = async () => {
  loading.value = true
  try {
    const payload = {}
    if (filterForm.keyword.trim()) payload.keyword = filterForm.keyword.trim()
    if (filterForm.is_active !== '') payload.is_active = filterForm.is_active
    if (filterForm.pickup_site_id) payload.pickup_site_id = filterForm.pickup_site_id
    if (filterForm.dropoff_site_id) payload.dropoff_site_id = filterForm.dropoff_site_id
    const { data } = await api.post('/freight-template/list', payload)
    templates.value = Array.isArray(data?.data) ? data.data : []
    const maxPage = Math.max(1, Math.ceil(templates.value.length / pageSize.value))
    if (currentPage.value > maxPage) currentPage.value = maxPage
  } catch (error) {
    ElMessage.error(getErrorMessage(error, '加载运费模板失败'))
  } finally {
    loading.value = false
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
  form.name = row.name || ''
  form.client_name = row.client_name || ''
  form.cargo_category_id = row.cargo_category_id
  form.pickup_site_id = row.pickup_site_id || null
  form.pickup_address = row.pickup_address || ''
  form.dropoff_site_id = row.dropoff_site_id || null
  form.dropoff_address = row.dropoff_address || ''
  form.freight_calc_scheme = row.freight_calc_scheme || 'by_weight'
  form.freight_unit_price = row.freight_unit_price != null ? Number(row.freight_unit_price) : null
  form.freight_trip_count = row.freight_trip_count != null ? Number(row.freight_trip_count) : 1
  form.loss_allowance_kg = row.loss_allowance_kg != null ? Number(row.loss_allowance_kg) : 0
  form.loss_deduct_unit_price = row.loss_deduct_unit_price != null ? Number(row.loss_deduct_unit_price) : null
  form.priority = row.priority != null ? Number(row.priority) : 100
  form.is_active = !!row.is_active
  form.remark = row.remark || ''
  dialogVisible.value = true
}

const saveTemplate = async () => {
  if (!form.name.trim()) {
    ElMessage.warning('请输入模板名称')
    return
  }
  saving.value = true
  try {
    const payload = {
      id: form.id,
      name: form.name.trim(),
      client_name: form.client_name.trim() || null,
      cargo_category_id: form.cargo_category_id || null,
      pickup_site_id: form.pickup_site_id || null,
      pickup_address: form.pickup_address.trim() || null,
      dropoff_site_id: form.dropoff_site_id || null,
      dropoff_address: form.dropoff_address.trim() || null,
      freight_calc_scheme: form.freight_calc_scheme,
      freight_unit_price: form.freight_unit_price,
      freight_trip_count: form.freight_calc_scheme === 'by_trip' ? form.freight_trip_count : null,
      loss_allowance_kg: form.loss_allowance_kg ?? 0,
      loss_deduct_unit_price: form.loss_deduct_unit_price,
      priority: form.priority,
      is_active: form.is_active,
      remark: form.remark.trim() || null,
    }
    if (dialogMode.value === 'create') {
      await api.post('/freight-template/create', payload)
      ElMessage.success('模板创建成功')
    } else {
      await api.post('/freight-template/update', payload)
      ElMessage.success('模板更新成功')
    }
    dialogVisible.value = false
    await loadTemplates()
  } catch (error) {
    ElMessage.error(getErrorMessage(error, '保存模板失败'))
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadMeta(), loadSites(), loadTemplates()])
})
</script>

<template>
  <div class="page-content-shell">
  <el-card shadow="never" class="page-card">
    <template #header>
      <div class="table-header">
        <div class="card-title">运费规则中心</div>
        <div>
          <el-button class="mr-8" plain @click="loadTemplates">刷新</el-button>
          <el-button type="primary" @click="openCreate">新增模板</el-button>
        </div>
      </div>
    </template>

    <el-form inline class="mb-12">
      <el-form-item label="关键词">
        <el-input v-model="filterForm.keyword" clearable placeholder="模板名/客户/装卸地" style="width: 260px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="filterForm.is_active" clearable placeholder="全部" style="width: 120px">
          <el-option label="启用" :value="true" />
          <el-option label="停用" :value="false" />
        </el-select>
      </el-form-item>
      <el-form-item label="装货站点">
        <el-select v-model="filterForm.pickup_site_id" clearable placeholder="全部站点" style="width: 180px">
          <el-option v-for="item in sites" :key="item.id" :label="item.name" :value="item.id" />
        </el-select>
      </el-form-item>
      <el-form-item label="卸货站点">
        <el-select v-model="filterForm.dropoff_site_id" clearable placeholder="全部站点" style="width: 180px">
          <el-option v-for="item in sites" :key="item.id" :label="item.name" :value="item.id" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="currentPage = 1; loadTemplates()">查询</el-button>
      </el-form-item>
    </el-form>

    <div class="page-table-section">
    <div class="page-table-wrap">
    <el-table :data="pagedTemplates" stripe v-loading="loading" height="100%" class="page-table">
      <el-table-column prop="name" label="模板名称" min-width="160" />
      <el-table-column prop="client_name" label="客户" min-width="130" />
      <el-table-column label="货品分类" min-width="140">
        <template #default="{ row }">
          {{ cargoCategories.find((item) => item.id === row.cargo_category_id)?.name || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="装货站点" min-width="140">
        <template #default="{ row }">
          {{ row.pickup_site?.name || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="卸货站点" min-width="140">
        <template #default="{ row }">
          {{ row.dropoff_site?.name || '-' }}
        </template>
      </el-table-column>
      <el-table-column prop="freight_calc_scheme" label="运价方式" min-width="100" />
      <el-table-column prop="freight_unit_price" label="运价单价" min-width="100" />
      <el-table-column prop="loss_allowance_kg" label="允许亏吨kg" min-width="110" />
      <el-table-column prop="loss_deduct_unit_price" label="亏吨扣减单价" min-width="120" />
      <el-table-column label="状态" min-width="90">
        <template #default="{ row }">
          <el-tag :type="row.is_active ? 'success' : 'info'">{{ row.is_active ? '启用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="priority" label="优先级" min-width="90" />
      <el-table-column label="操作" min-width="90" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
        </template>
      </el-table-column>
    </el-table>
    </div>
    <div class="page-pagination">
      <el-pagination
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        layout="sizes, prev, pager, next, jumper, total"
        :page-sizes="[10, 20, 50, 100]"
        :total="total"
      />
    </div>
    </div>
  </el-card>
  </div>

  <el-dialog v-model="dialogVisible" :title="dialogMode === 'create' ? '新增运费模板' : '编辑运费模板'" width="760px" destroy-on-close>
    <el-form label-width="120px">
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="模板名称" required>
            <el-input v-model="form.name" placeholder="例如：油品客户默认模板" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="客户名称">
            <el-input v-model="form.client_name" placeholder="空表示通用" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="货品分类">
            <el-select v-model="form.cargo_category_id" clearable placeholder="空表示通用" style="width: 100%">
              <el-option v-for="item in cargoCategories" :key="item.id" :label="item.name" :value="item.id" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="优先级">
            <el-input-number v-model="form.priority" :min="0" :max="9999" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="装货站点">
            <el-select v-model="form.pickup_site_id" clearable placeholder="空表示通用" style="width: 100%">
              <el-option v-for="item in sites" :key="item.id" :label="item.name" :value="item.id" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="卸货站点">
            <el-select v-model="form.dropoff_site_id" clearable placeholder="空表示通用" style="width: 100%">
              <el-option v-for="item in sites" :key="item.id" :label="item.name" :value="item.id" />
            </el-select>
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="装货地">
            <el-input v-model="form.pickup_address" placeholder="空表示通用" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="卸货地">
            <el-input v-model="form.dropoff_address" placeholder="空表示通用" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="运价方式">
            <el-select v-model="form.freight_calc_scheme" style="width: 100%">
              <el-option v-for="item in schemeOptions" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="运价单价">
            <el-input-number v-model="form.freight_unit_price" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="趟数(按趟)">
            <el-input-number v-model="form.freight_trip_count" :min="1" :precision="0" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="启用状态">
            <el-switch v-model="form.is_active" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="允许亏吨kg">
            <el-input-number v-model="form.loss_allowance_kg" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="亏吨扣减单价">
            <el-input-number v-model="form.loss_deduct_unit_price" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="备注">
        <el-input v-model="form.remark" type="textarea" :rows="2" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="saving" @click="saveTemplate">保存</el-button>
    </template>
  </el-dialog>
</template>
