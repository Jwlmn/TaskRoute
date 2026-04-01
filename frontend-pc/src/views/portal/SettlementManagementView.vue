<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { exportRowsToXlsx } from '../../utils/spreadsheet'

const loading = ref(false)
const creating = ref(false)
const updating = ref(false)
const statements = ref([])
const detail = ref(null)
const currentPage = ref(1)
const pageSize = ref(10)

const filterForm = reactive({
  client_name: '',
  status: '',
})

const createDialogVisible = ref(false)
const detailDialogVisible = ref(false)
const createForm = reactive({
  client_name: '',
  period_start: '',
  period_end: '',
  remark: '',
})

const statusLabelMap = {
  draft: '草稿',
  confirmed: '已确认',
  invoiced: '已开票',
  paid: '已回款',
}

const statusTagTypeMap = {
  draft: 'info',
  confirmed: 'success',
  invoiced: 'warning',
  paid: 'primary',
}

const transitionMap = {
  draft: 'confirmed',
  confirmed: 'invoiced',
  invoiced: 'paid',
  paid: '',
}
const pagedStatements = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return statements.value.slice(start, start + pageSize.value)
})
const total = computed(() => statements.value.length)

const formatStatusLabel = (status) => statusLabelMap[status] || status || '-'

const formatOperator = (operator) => {
  if (!operator) return '-'
  return operator.name || operator.account || `#${operator.id}`
}

const canTransitionTo = (row, targetStatus) => transitionMap[row?.status] === targetStatus

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

const loadStatements = async () => {
  loading.value = true
  try {
    const payload = {}
    if (filterForm.client_name.trim()) payload.client_name = filterForm.client_name.trim()
    if (filterForm.status) payload.status = filterForm.status
    const { data } = await api.post('/settlement/list', payload)
    statements.value = Array.isArray(data?.data) ? data.data : []
    const maxPage = Math.max(1, Math.ceil(statements.value.length / pageSize.value))
    if (currentPage.value > maxPage) currentPage.value = maxPage
  } catch (error) {
    ElMessage.error(getErrorMessage(error, '加载结算单失败'))
  } finally {
    loading.value = false
  }
}

const openCreate = () => {
  createForm.client_name = ''
  createForm.period_start = ''
  createForm.period_end = ''
  createForm.remark = ''
  createDialogVisible.value = true
}

const createStatement = async () => {
  if (!createForm.client_name.trim()) {
    ElMessage.warning('请输入客户名称')
    return
  }
  if (!createForm.period_start || !createForm.period_end) {
    ElMessage.warning('请选择结算周期')
    return
  }
  creating.value = true
  try {
    await api.post('/settlement/create', {
      client_name: createForm.client_name.trim(),
      period_start: createForm.period_start,
      period_end: createForm.period_end,
      remark: createForm.remark.trim() || null,
    })
    ElMessage.success('结算单生成成功')
    createDialogVisible.value = false
    await loadStatements()
  } catch (error) {
    ElMessage.error(getErrorMessage(error, '生成结算单失败'))
  } finally {
    creating.value = false
  }
}

const openDetail = async (row) => {
  detailDialogVisible.value = true
  detail.value = null
  try {
    const { data } = await api.post('/settlement/detail', { id: row.id })
    detail.value = data
  } catch (error) {
    detailDialogVisible.value = false
    ElMessage.error(getErrorMessage(error, '加载结算单详情失败'))
  }
}

const updateStatus = async (row, status) => {
  updating.value = true
  try {
    await api.post('/settlement/update', { id: row.id, status })
    ElMessage.success('状态更新成功')
    await loadStatements()
  } catch (error) {
    ElMessage.error(getErrorMessage(error, '状态更新失败'))
  } finally {
    updating.value = false
  }
}

const exportCurrentList = async () => {
  const rows = statements.value.map((item) => ({
    结算单号: item.statement_no,
    客户: item.client_name,
    周期开始: item.period_start,
    周期结束: item.period_end,
    单量: item.order_count,
    基础运费: item.total_base_amount,
    亏吨扣减: item.total_loss_deduct_amount,
    应结运费: item.total_freight_amount,
    状态: formatStatusLabel(item.status),
    确认人: formatOperator(item.confirmer),
    确认时间: item.confirmed_at || '',
    开票人: formatOperator(item.invoicer),
    开票时间: item.invoiced_at || '',
    回款人: formatOperator(item.payer),
    回款时间: item.paid_at || '',
  }))
  await exportRowsToXlsx({
    filename: '结算单列表.xlsx',
    sheetName: '结算单',
    rows,
  })
}

const exportDetailOrders = async () => {
  if (!detail.value?.orders?.length) {
    ElMessage.warning('当前结算单没有可导出的订单明细')
    return
  }
  const rows = detail.value.orders.map((item) => ({
    订单ID: item.id,
    订单号: item.order_no,
    客户: item.client_name,
    装货地: item.pickup_address,
    卸货地: item.dropoff_address,
    状态: item.status,
    基础运费: item.freight_base_amount ?? 0,
    亏吨扣减: item.freight_loss_deduct_amount ?? 0,
    应结运费: item.freight_amount ?? 0,
    运费计算时间: item.freight_calculated_at || '',
  }))
  await exportRowsToXlsx({
    filename: `${detail.value.statement_no || '结算单'}-订单明细.xlsx`,
    sheetName: '结算订单明细',
    rows,
  })
}

onMounted(loadStatements)
</script>

<template>
  <div class="page-content-shell">
  <el-card shadow="never" class="page-card">
    <template #header>
      <div class="table-header">
        <div class="card-title">结算单管理</div>
        <div>
          <el-button class="mr-8" plain @click="exportCurrentList">导出 XLSX</el-button>
          <el-button class="mr-8" plain @click="loadStatements">刷新</el-button>
          <el-button type="primary" @click="openCreate">生成结算单</el-button>
        </div>
      </div>
    </template>

    <el-form inline class="mb-12">
      <el-form-item label="客户">
        <el-input v-model="filterForm.client_name" clearable placeholder="客户名称" style="width: 220px" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="filterForm.status" clearable placeholder="全部状态" style="width: 140px">
          <el-option label="草稿" value="draft" />
          <el-option label="已确认" value="confirmed" />
          <el-option label="已开票" value="invoiced" />
          <el-option label="已回款" value="paid" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="currentPage = 1; loadStatements()">查询</el-button>
      </el-form-item>
    </el-form>

    <div class="page-table-section">
    <div class="page-table-wrap">
    <el-table :data="pagedStatements" stripe v-loading="loading" height="100%" class="page-table">
      <el-table-column prop="statement_no" label="结算单号" min-width="180" />
      <el-table-column prop="client_name" label="客户" min-width="140" />
      <el-table-column prop="period_start" label="周期开始" min-width="110" />
      <el-table-column prop="period_end" label="周期结束" min-width="110" />
      <el-table-column prop="order_count" label="订单数" min-width="90" />
      <el-table-column prop="total_base_amount" label="基础运费" min-width="100" />
      <el-table-column prop="total_loss_deduct_amount" label="亏吨扣减" min-width="100" />
      <el-table-column prop="total_freight_amount" label="应结运费" min-width="100" />
      <el-table-column label="状态" min-width="100">
        <template #default="{ row }">
          <el-tag :type="statusTagTypeMap[row.status] || 'info'" effect="light">
            {{ formatStatusLabel(row.status) }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="审计进度" min-width="260">
        <template #default="{ row }">
          <div>确认：{{ formatOperator(row.confirmer) }} {{ row.confirmed_at || '' }}</div>
          <div>开票：{{ formatOperator(row.invoicer) }} {{ row.invoiced_at || '' }}</div>
          <div>回款：{{ formatOperator(row.payer) }} {{ row.paid_at || '' }}</div>
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="260" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDetail(row)">详情</el-button>
          <el-button link type="success" :loading="updating" :disabled="!canTransitionTo(row, 'confirmed')" @click="updateStatus(row, 'confirmed')">确认</el-button>
          <el-button link type="warning" :loading="updating" :disabled="!canTransitionTo(row, 'invoiced')" @click="updateStatus(row, 'invoiced')">开票</el-button>
          <el-button link type="info" :loading="updating" :disabled="!canTransitionTo(row, 'paid')" @click="updateStatus(row, 'paid')">回款</el-button>
        </template>
      </el-table-column>
    </el-table>
    </div>
    <div class="page-pagination">
      <el-pagination
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        layout="prev, pager, next, total"
        :page-sizes="[10, 20, 50]"
        :total="total"
      />
    </div>
    </div>
  </el-card>
  </div>

  <el-dialog v-model="createDialogVisible" title="生成结算单" width="520px" destroy-on-close>
    <el-form label-width="100px">
      <el-form-item label="客户名称" required>
        <el-input v-model="createForm.client_name" />
      </el-form-item>
      <el-form-item label="开始日期" required>
        <el-date-picker v-model="createForm.period_start" value-format="YYYY-MM-DD" type="date" style="width: 100%" />
      </el-form-item>
      <el-form-item label="结束日期" required>
        <el-date-picker v-model="createForm.period_end" value-format="YYYY-MM-DD" type="date" style="width: 100%" />
      </el-form-item>
      <el-form-item label="备注">
        <el-input v-model="createForm.remark" type="textarea" :rows="2" />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="createDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="creating" @click="createStatement">生成</el-button>
    </template>
  </el-dialog>

  <el-dialog v-model="detailDialogVisible" title="结算单详情" width="680px" destroy-on-close>
    <div class="mb-12" style="display: flex; justify-content: flex-end">
      <el-button plain @click="exportDetailOrders">导出明细 XLSX</el-button>
    </div>
    <el-descriptions v-if="detail" border :column="2">
      <el-descriptions-item label="结算单号">{{ detail.statement_no }}</el-descriptions-item>
      <el-descriptions-item label="客户">{{ detail.client_name }}</el-descriptions-item>
      <el-descriptions-item label="周期">{{ detail.period_start }} ~ {{ detail.period_end }}</el-descriptions-item>
      <el-descriptions-item label="状态">{{ formatStatusLabel(detail.status) }}</el-descriptions-item>
      <el-descriptions-item label="订单数">{{ detail.order_count }}</el-descriptions-item>
      <el-descriptions-item label="应结运费">{{ detail.total_freight_amount }}</el-descriptions-item>
      <el-descriptions-item label="创建人">{{ formatOperator(detail.creator) }}</el-descriptions-item>
      <el-descriptions-item label="确认人">{{ formatOperator(detail.confirmer) }}</el-descriptions-item>
      <el-descriptions-item label="确认时间">{{ detail.confirmed_at || '-' }}</el-descriptions-item>
      <el-descriptions-item label="开票人">{{ formatOperator(detail.invoicer) }}</el-descriptions-item>
      <el-descriptions-item label="开票时间">{{ detail.invoiced_at || '-' }}</el-descriptions-item>
      <el-descriptions-item label="回款人">{{ formatOperator(detail.payer) }}</el-descriptions-item>
      <el-descriptions-item label="回款时间">{{ detail.paid_at || '-' }}</el-descriptions-item>
      <el-descriptions-item label="备注" :span="2">{{ detail.remark || '-' }}</el-descriptions-item>
    </el-descriptions>
    <el-table
      v-if="detail?.orders?.length"
      :data="detail.orders"
      size="small"
      class="mt-12"
    >
      <el-table-column prop="id" label="订单ID" width="90" />
      <el-table-column prop="order_no" label="订单号" min-width="150" />
      <el-table-column prop="client_name" label="客户" min-width="120" />
      <el-table-column prop="status" label="状态" min-width="100" />
      <el-table-column prop="freight_base_amount" label="基础运费" min-width="110" />
      <el-table-column prop="freight_loss_deduct_amount" label="亏吨扣减" min-width="110" />
      <el-table-column prop="freight_amount" label="应结运费" min-width="110" />
    </el-table>
    <template #footer>
      <el-button @click="detailDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>
