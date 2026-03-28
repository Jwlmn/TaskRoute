<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { getLabel } from '../../utils/labels'

const loading = ref(false)
const creating = ref(false)
const dialogVisible = ref(false)
const dialogMode = ref('create')
const orders = ref([])
const cargoCategories = ref([])

const freightSchemeLabelMap = {
  by_weight: '按重量',
  by_volume: '按体积',
  by_trip: '按趟',
}

const auditStatusLabelMap = {
  pending_approval: '待审核',
  approved: '已审核',
  rejected: '已驳回',
}

const auditStatusTypeMap = {
  pending_approval: 'warning',
  approved: 'success',
  rejected: 'danger',
}

const form = reactive({
  id: null,
  cargo_category_id: null,
  client_name: '',
  pickup_address: '',
  pickup_contact_name: '',
  pickup_contact_phone: '',
  dropoff_address: '',
  dropoff_contact_name: '',
  dropoff_contact_phone: '',
  cargo_weight_kg: null,
  cargo_volume_m3: null,
  expected_pickup_at: '',
  expected_delivery_at: '',
  freight_calc_scheme: '',
  freight_unit_price: null,
  freight_trip_count: 1,
  actual_delivered_weight_kg: null,
  loss_allowance_kg: 0,
  loss_deduct_unit_price: null,
})

const resetForm = () => {
  form.id = null
  form.cargo_category_id = null
  form.client_name = ''
  form.pickup_address = ''
  form.pickup_contact_name = ''
  form.pickup_contact_phone = ''
  form.dropoff_address = ''
  form.dropoff_contact_name = ''
  form.dropoff_contact_phone = ''
  form.cargo_weight_kg = null
  form.cargo_volume_m3 = null
  form.expected_pickup_at = ''
  form.expected_delivery_at = ''
  form.freight_calc_scheme = ''
  form.freight_unit_price = null
  form.freight_trip_count = 1
  form.actual_delivered_weight_kg = null
  form.loss_allowance_kg = 0
  form.loss_deduct_unit_price = null
}

const buildPayload = () => ({
  id: form.id,
  cargo_category_id: Number(form.cargo_category_id),
  client_name: form.client_name,
  pickup_address: form.pickup_address,
  pickup_contact_name: form.pickup_contact_name || null,
  pickup_contact_phone: form.pickup_contact_phone || null,
  dropoff_address: form.dropoff_address,
  dropoff_contact_name: form.dropoff_contact_name || null,
  dropoff_contact_phone: form.dropoff_contact_phone || null,
  cargo_weight_kg: form.cargo_weight_kg,
  cargo_volume_m3: form.cargo_volume_m3,
  expected_pickup_at: form.expected_pickup_at || null,
  expected_delivery_at: form.expected_delivery_at || null,
  freight_calc_scheme: form.freight_calc_scheme || null,
  freight_unit_price: form.freight_calc_scheme ? form.freight_unit_price : null,
  freight_trip_count: form.freight_calc_scheme === 'by_trip' ? form.freight_trip_count : null,
  actual_delivered_weight_kg: form.actual_delivered_weight_kg,
  loss_allowance_kg: form.loss_allowance_kg ?? 0,
  loss_deduct_unit_price: form.loss_deduct_unit_price,
})

const openCreate = () => {
  dialogMode.value = 'create'
  resetForm()
  dialogVisible.value = true
}

const openEdit = (row) => {
  if (row.audit_status !== 'rejected') return
  dialogMode.value = 'edit'
  form.id = row.id
  form.cargo_category_id = row.cargo_category_id
  form.client_name = row.client_name || ''
  form.pickup_address = row.pickup_address || ''
  form.pickup_contact_name = row.pickup_contact_name || ''
  form.pickup_contact_phone = row.pickup_contact_phone || ''
  form.dropoff_address = row.dropoff_address || ''
  form.dropoff_contact_name = row.dropoff_contact_name || ''
  form.dropoff_contact_phone = row.dropoff_contact_phone || ''
  form.cargo_weight_kg = Number(row.cargo_weight_kg || 0)
  form.cargo_volume_m3 = Number(row.cargo_volume_m3 || 0)
  form.expected_pickup_at = row.expected_pickup_at || ''
  form.expected_delivery_at = row.expected_delivery_at || ''
  form.freight_calc_scheme = row.freight_calc_scheme || ''
  form.freight_unit_price = row.freight_unit_price != null ? Number(row.freight_unit_price) : null
  form.freight_trip_count = row.freight_trip_count != null ? Number(row.freight_trip_count) : 1
  form.actual_delivered_weight_kg = row.actual_delivered_weight_kg != null ? Number(row.actual_delivered_weight_kg) : null
  form.loss_allowance_kg = row.loss_allowance_kg != null ? Number(row.loss_allowance_kg) : 0
  form.loss_deduct_unit_price = row.loss_deduct_unit_price != null ? Number(row.loss_deduct_unit_price) : null
  dialogVisible.value = true
}

const loadMeta = async () => {
  const { data } = await api.get('/meta')
  cargoCategories.value = Array.isArray(data?.cargo_categories) ? data.cargo_categories : []
}

const loadOrders = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/pre-plan-order/customer-list', {})
    orders.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载客户计划单失败')
  } finally {
    loading.value = false
  }
}

const submit = async () => {
  creating.value = true
  try {
    if (dialogMode.value === 'create') {
      await api.post('/pre-plan-order/customer-submit', buildPayload())
      ElMessage.success('计划单提交成功，等待审核')
    } else {
      await api.post('/pre-plan-order/customer-update', buildPayload())
      ElMessage.success('驳回计划单已更新，请重新提报')
    }
    dialogVisible.value = false
    resetForm()
    await loadOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '提交失败')
  } finally {
    creating.value = false
  }
}

const resubmitOrder = async (row) => {
  if (row.audit_status !== 'rejected') return
  try {
    await api.post('/pre-plan-order/customer-resubmit', { id: row.id })
    ElMessage.success('已重新提报，等待审核')
    await loadOrders()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '重提失败')
  }
}

onMounted(async () => {
  await Promise.all([loadMeta(), loadOrders()])
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <div class="card-title">客户计划单</div>
        <div>
          <el-button class="mr-8" plain @click="loadOrders">刷新</el-button>
          <el-button type="primary" @click="openCreate">提交计划单</el-button>
        </div>
      </div>
    </template>

    <el-table :data="orders" stripe v-loading="loading">
      <el-table-column prop="order_no" label="订单号" min-width="170" />
      <el-table-column prop="client_name" label="客户" min-width="120" />
      <el-table-column prop="pickup_address" label="装货地" min-width="170" />
      <el-table-column label="装货联系人" min-width="150">
        <template #default="{ row }">
          {{ row.pickup_contact_name || '-' }} / {{ row.pickup_contact_phone || '-' }}
        </template>
      </el-table-column>
      <el-table-column prop="dropoff_address" label="卸货地" min-width="170" />
      <el-table-column label="收货联系人" min-width="150">
        <template #default="{ row }">
          {{ row.dropoff_contact_name || '-' }} / {{ row.dropoff_contact_phone || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="运价方式" min-width="100">
        <template #default="{ row }">
          {{ getLabel(freightSchemeLabelMap, row.freight_calc_scheme) }}
        </template>
      </el-table-column>
      <el-table-column label="审核状态" min-width="100">
        <template #default="{ row }">
          <el-tag :type="auditStatusTypeMap[row.audit_status] || 'info'">
            {{ getLabel(auditStatusLabelMap, row.audit_status) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="audit_remark" label="审核备注" min-width="180" />
      <el-table-column label="操作" min-width="180" fixed="right">
        <template #default="{ row }">
          <el-button
            link
            type="primary"
            :disabled="row.audit_status !== 'rejected'"
            @click="openEdit(row)"
          >
            编辑
          </el-button>
          <el-button
            link
            type="warning"
            :disabled="row.audit_status !== 'rejected'"
            @click="resubmitOrder(row)"
          >
            重新提报
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog
    v-model="dialogVisible"
    :title="dialogMode === 'create' ? '提交客户计划单' : '编辑驳回计划单'"
    width="760px"
    destroy-on-close
  >
    <el-form label-width="120px">
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="货品分类">
            <el-select v-model="form.cargo_category_id" style="width: 100%" placeholder="请选择货品分类">
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
          <el-form-item label="客户名称">
            <el-input v-model="form.client_name" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="装货地">
        <el-input v-model="form.pickup_address" />
      </el-form-item>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="装货联系人">
            <el-input v-model="form.pickup_contact_name" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="装货联系电话">
            <el-input v-model="form.pickup_contact_phone" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-form-item label="卸货地">
        <el-input v-model="form.dropoff_address" />
      </el-form-item>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="收货联系人">
            <el-input v-model="form.dropoff_contact_name" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="收货联系电话">
            <el-input v-model="form.dropoff_contact_phone" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="重量(kg)">
            <el-input-number v-model="form.cargo_weight_kg" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="体积(m³)">
            <el-input-number v-model="form.cargo_volume_m3" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12">
        <el-col :span="12">
          <el-form-item label="运价方式">
            <el-select v-model="form.freight_calc_scheme" style="width: 100%">
              <el-option label="按重量（元/吨）" value="by_weight" />
              <el-option label="按体积（元/m³）" value="by_volume" />
              <el-option label="按趟（元/趟）" value="by_trip" />
            </el-select>
          </el-form-item>
        </el-col>
        <el-col :span="12">
          <el-form-item label="运价单价">
            <el-input-number v-model="form.freight_unit_price" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-row :gutter="12" v-if="form.freight_calc_scheme === 'by_trip'">
        <el-col :span="12">
          <el-form-item label="趟数">
            <el-input-number v-model="form.freight_trip_count" :min="1" :precision="0" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
      <el-divider content-position="left">亏吨扣减配置</el-divider>
      <el-row :gutter="12">
        <el-col :span="8">
          <el-form-item label="实送重量kg">
            <el-input-number v-model="form.actual_delivered_weight_kg" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="允许亏吨kg">
            <el-input-number v-model="form.loss_allowance_kg" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
        <el-col :span="8">
          <el-form-item label="扣减单价(元/吨)">
            <el-input-number v-model="form.loss_deduct_unit_price" :min="0" :precision="2" :controls="false" style="width: 100%" />
          </el-form-item>
        </el-col>
      </el-row>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="creating" @click="submit">
        {{ dialogMode === 'create' ? '提交审核' : '保存修改' }}
      </el-button>
    </template>
  </el-dialog>
</template>
