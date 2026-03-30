<script setup>
import { onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { getLabel } from '../../utils/labels'

const loading = ref(false)
const loadingMessages = ref(false)
const creating = ref(false)
const markingMessageId = ref(null)
const dialogVisible = ref(false)
const dialogMode = ref('create')
const compareDialogVisible = ref(false)
const compareLoading = ref(false)
const compareRows = ref([])
const orders = ref([])
const messages = ref([])
const unreadOnly = ref(true)
const cargoCategories = ref([])
const templatePreview = ref(null)
const previewingTemplate = ref(false)

let templatePreviewTimer = null

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
  templatePreview.value = null
}

const buildTemplatePreviewPayload = () => ({
  client_name: form.client_name?.trim() || null,
  cargo_category_id: form.cargo_category_id || null,
  pickup_address: form.pickup_address?.trim() || null,
  dropoff_address: form.dropoff_address?.trim() || null,
})

const canPreviewTemplate = (payload) => (
  !!payload.client_name
  && !!payload.cargo_category_id
  && !!payload.pickup_address
  && !!payload.dropoff_address
)

const formatTemplatePreviewText = (template) => {
  if (!template) return '未命中模板'
  const siteTags = [
    template.pickup_site?.name ? `装货站点:${template.pickup_site.name}` : null,
    template.dropoff_site?.name ? `卸货站点:${template.dropoff_site.name}` : null,
  ].filter(Boolean)
  return siteTags.length ? `${template.name}（${siteTags.join(' / ')}）` : template.name
}

const getFreightTemplateMeta = (row) => {
  const meta = row?.meta
  if (!meta || typeof meta !== 'object') return null
  const templateId = meta.freight_template_id
  const templateName = meta.freight_template_name
  if (!templateId && !templateName) return null
  return {
    id: templateId || null,
    name: templateName || '未命名模板',
  }
}

const requestTemplatePreview = async () => {
  const payload = buildTemplatePreviewPayload()
  if (!canPreviewTemplate(payload)) {
    templatePreview.value = null
    return
  }

  previewingTemplate.value = true
  try {
    const { data } = await api.post('/freight-template/match-preview', payload)
    templatePreview.value = data?.matched ? data.template : null
  } catch {
    templatePreview.value = null
  } finally {
    previewingTemplate.value = false
  }
}

const scheduleTemplatePreview = () => {
  if (templatePreviewTimer) clearTimeout(templatePreviewTimer)
  templatePreviewTimer = setTimeout(() => {
    requestTemplatePreview()
  }, 250)
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
  templatePreview.value = row?.meta?.freight_template_id || row?.meta?.freight_template_name
    ? {
        id: row.meta.freight_template_id || null,
        name: row.meta.freight_template_name || '未命名模板',
      }
    : null
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

const loadMessages = async () => {
  loadingMessages.value = true
  try {
    const { data } = await api.post('/message/list', {
      unread_only: unreadOnly.value,
    })
    messages.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载审核通知失败')
  } finally {
    loadingMessages.value = false
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

const markMessageRead = async (row) => {
  if (!row?.id || row?.read_at) return
  markingMessageId.value = row.id
  try {
    await api.post('/message/read', { id: row.id })
    ElMessage.success('已标记为已读')
    await loadMessages()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '标记已读失败')
  } finally {
    markingMessageId.value = null
  }
}

const openRevisionCompare = async (row) => {
  compareDialogVisible.value = true
  compareLoading.value = true
  compareRows.value = []
  try {
    const { data } = await api.post('/pre-plan-order/revision-compare', { id: row.id })
    compareRows.value = Array.isArray(data?.diffs) ? data.diffs : []
  } catch (error) {
    compareDialogVisible.value = false
    ElMessage.error(error?.response?.data?.message || '加载版本差异失败')
  } finally {
    compareLoading.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadMeta(), loadOrders(), loadMessages()])
})

watch(
  () => [
    dialogVisible.value,
    form.client_name,
    form.cargo_category_id,
    form.pickup_address,
    form.dropoff_address,
  ],
  ([visible]) => {
    if (!visible) return
    scheduleTemplatePreview()
  }
)

onUnmounted(() => {
  if (templatePreviewTimer) clearTimeout(templatePreviewTimer)
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
      <el-table-column label="命中模板" min-width="180">
        <template #default="{ row }">
          <el-tag v-if="getFreightTemplateMeta(row)" type="info">
            {{ getFreightTemplateMeta(row).name }}
          </el-tag>
          <span v-else class="text-secondary">未命中模板</span>
        </template>
      </el-table-column>
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
          <el-button
            link
            type="info"
            :disabled="row.audit_status !== 'rejected'"
            @click="openRevisionCompare(row)"
          >
            版本对比
          </el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-card shadow="never" class="mt-12">
    <template #header>
      <div class="table-header">
        <div class="card-title">审核通知</div>
        <div>
          <el-switch
            v-model="unreadOnly"
            active-text="仅看未读"
            class="mr-8"
            @change="loadMessages"
          />
          <el-button plain @click="loadMessages">刷新通知</el-button>
        </div>
      </div>
    </template>

    <el-table :data="messages" stripe v-loading="loadingMessages">
      <el-table-column prop="title" label="标题" min-width="140" />
      <el-table-column prop="content" label="内容" min-width="260" show-overflow-tooltip />
      <el-table-column label="关联订单" min-width="160">
        <template #default="{ row }">
          {{ row?.meta?.order_no || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="90">
        <template #default="{ row }">
          <el-tag :type="row.read_at ? 'info' : 'danger'">
            {{ row.read_at ? '已读' : '未读' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="通知时间" min-width="160">
        <template #default="{ row }">
          {{ row.created_at || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="120" fixed="right">
        <template #default="{ row }">
          <el-button
            link
            type="primary"
            :disabled="Boolean(row.read_at)"
            :loading="markingMessageId === row.id"
            @click="markMessageRead(row)"
          >
            标记已读
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
      <el-alert
        class="mb-12"
        type="info"
        :closable="false"
        show-icon
        :title="previewingTemplate ? '正在预览命中模板...' : `预计命中模板：${formatTemplatePreviewText(templatePreview)}`"
        :description="dialogMode === 'edit' ? '重新提报前，系统会按客户、地址和货品分类重新匹配运价模板。' : '填写完整的客户、装卸地址和货品分类后，系统会自动预览可能命中的运价模板。'"
      />
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

  <el-dialog v-model="compareDialogVisible" title="驳回后版本对比" width="760px" destroy-on-close>
    <el-table :data="compareRows" v-loading="compareLoading" size="small" stripe>
      <el-table-column prop="field" label="字段" min-width="180" />
      <el-table-column prop="before" label="驳回时值" min-width="220" />
      <el-table-column prop="after" label="当前值" min-width="220" />
    </el-table>
    <el-empty v-if="!compareLoading && compareRows.length === 0" description="暂无差异（可能未修改关键字段）" />
    <template #footer>
      <el-button @click="compareDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>
