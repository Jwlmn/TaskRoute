<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'

const loading = ref(false)
const logs = ref([])
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(20)

const filterForm = reactive({
  keyword: '',
  action: '',
  operator_id: null,
})

const loadLogs = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/pre-plan-order/audit-log-list', {
      keyword: filterForm.keyword || undefined,
      action: filterForm.action || undefined,
      operator_id: filterForm.operator_id || undefined,
      page: currentPage.value,
      page_size: pageSize.value,
    })
    logs.value = Array.isArray(data?.data) ? data.data : []
    total.value = Number(data?.total || 0)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载审计日志失败')
  } finally {
    loading.value = false
  }
}

const onSearch = async () => {
  currentPage.value = 1
  await loadLogs()
}

const onPageSizeChange = async (size) => {
  pageSize.value = size
  currentPage.value = 1
  await loadLogs()
}

onMounted(loadLogs)
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <div class="card-title">操作审计查询</div>
        <el-button plain @click="loadLogs">刷新</el-button>
      </div>
    </template>

    <el-form inline class="mb-12">
      <el-form-item label="关键词">
        <el-input v-model="filterForm.keyword" clearable placeholder="订单号/客户" style="width: 220px" />
      </el-form-item>
      <el-form-item label="动作">
        <el-input v-model="filterForm.action" clearable placeholder="例如 dispatcher_void" style="width: 220px" />
      </el-form-item>
      <el-form-item label="操作人ID">
        <el-input-number v-model="filterForm.operator_id" :min="1" :controls="false" style="width: 140px" />
      </el-form-item>
      <el-form-item>
        <el-button type="primary" @click="onSearch">查询</el-button>
      </el-form-item>
    </el-form>

    <el-table :data="logs" stripe v-loading="loading">
      <el-table-column prop="at" label="时间" min-width="160" />
      <el-table-column prop="order_no" label="订单号" min-width="160" />
      <el-table-column prop="client_name" label="客户" min-width="140" />
      <el-table-column prop="action" label="动作" min-width="180" />
      <el-table-column label="操作人" min-width="180">
        <template #default="{ row }">
          {{ row.operator_name || row.operator_account || '-' }}（#{{ row.operator_id || '-' }}）
        </template>
      </el-table-column>
      <el-table-column label="附加信息" min-width="260">
        <template #default="{ row }">
          {{ row.extra ? JSON.stringify(row.extra) : '-' }}
        </template>
      </el-table-column>
    </el-table>

    <div class="mt-12" style="display: flex; justify-content: flex-end">
      <el-pagination
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        layout="sizes, prev, pager, next, jumper, total"
        :page-sizes="[10, 20, 50, 100]"
        :total="total"
        @current-change="loadLogs"
        @size-change="onPageSizeChange"
      />
    </div>
  </el-card>
</template>
