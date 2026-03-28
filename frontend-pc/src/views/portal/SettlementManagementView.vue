<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import * as XLSX from 'xlsx'
import api from '../../services/api'

const loading = ref(false)
const creating = ref(false)
const updating = ref(false)
const statements = ref([])
const detail = ref(null)

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

const loadStatements = async () => {
  loading.value = true
  try {
    const payload = {}
    if (filterForm.client_name.trim()) payload.client_name = filterForm.client_name.trim()
    if (filterForm.status) payload.status = filterForm.status
    const { data } = await api.post('/settlement/list', payload)
    statements.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载结算单失败')
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
    ElMessage.error(error?.response?.data?.message || '生成结算单失败')
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
    ElMessage.error(error?.response?.data?.message || '加载结算单详情失败')
  }
}

const updateStatus = async (row, status) => {
  updating.value = true
  try {
    await api.post('/settlement/update', { id: row.id, status })
    ElMessage.success('状态更新成功')
    await loadStatements()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '状态更新失败')
  } finally {
    updating.value = false
  }
}

const exportCurrentList = () => {
  const rows = statements.value.map((item) => ({
    结算单号: item.statement_no,
    客户: item.client_name,
    周期开始: item.period_start,
    周期结束: item.period_end,
    单量: item.order_count,
    基础运费: item.total_base_amount,
    亏吨扣减: item.total_loss_deduct_amount,
    应结运费: item.total_freight_amount,
    状态: item.status,
  }))
  const sheet = XLSX.utils.json_to_sheet(rows)
  const workbook = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(workbook, sheet, '结算单')
  XLSX.writeFile(workbook, '结算单列表.xlsx')
}

onMounted(loadStatements)
</script>

<template>
  <el-card shadow="never">
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
        <el-button type="primary" @click="loadStatements">查询</el-button>
      </el-form-item>
    </el-form>

    <el-table :data="statements" stripe v-loading="loading">
      <el-table-column prop="statement_no" label="结算单号" min-width="180" />
      <el-table-column prop="client_name" label="客户" min-width="140" />
      <el-table-column prop="period_start" label="周期开始" min-width="110" />
      <el-table-column prop="period_end" label="周期结束" min-width="110" />
      <el-table-column prop="order_count" label="订单数" min-width="90" />
      <el-table-column prop="total_base_amount" label="基础运费" min-width="100" />
      <el-table-column prop="total_loss_deduct_amount" label="亏吨扣减" min-width="100" />
      <el-table-column prop="total_freight_amount" label="应结运费" min-width="100" />
      <el-table-column prop="status" label="状态" min-width="100" />
      <el-table-column label="操作" min-width="260" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openDetail(row)">详情</el-button>
          <el-button link type="success" :loading="updating" @click="updateStatus(row, 'confirmed')">确认</el-button>
          <el-button link type="warning" :loading="updating" @click="updateStatus(row, 'invoiced')">开票</el-button>
          <el-button link type="info" :loading="updating" @click="updateStatus(row, 'paid')">回款</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

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
    <el-descriptions v-if="detail" border :column="2">
      <el-descriptions-item label="结算单号">{{ detail.statement_no }}</el-descriptions-item>
      <el-descriptions-item label="客户">{{ detail.client_name }}</el-descriptions-item>
      <el-descriptions-item label="周期">{{ detail.period_start }} ~ {{ detail.period_end }}</el-descriptions-item>
      <el-descriptions-item label="状态">{{ detail.status }}</el-descriptions-item>
      <el-descriptions-item label="订单数">{{ detail.order_count }}</el-descriptions-item>
      <el-descriptions-item label="应结运费">{{ detail.total_freight_amount }}</el-descriptions-item>
      <el-descriptions-item label="备注" :span="2">{{ detail.remark || '-' }}</el-descriptions-item>
    </el-descriptions>
    <el-table
      v-if="detail?.meta?.order_ids?.length"
      :data="detail.meta.order_ids.map((id, idx) => ({ idx: idx + 1, id }))"
      size="small"
      class="mt-12"
    >
      <el-table-column prop="idx" label="#" width="60" />
      <el-table-column prop="id" label="关联订单ID" min-width="120" />
    </el-table>
    <template #footer>
      <el-button @click="detailDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>
