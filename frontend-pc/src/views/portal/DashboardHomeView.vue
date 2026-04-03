<script setup>
import { computed, onMounted, ref } from 'vue'
import { onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../../services/api'

const router = useRouter()
const loading = ref(false)
const errorMessage = ref('')
let refreshTimer = null
const overview = ref({
  metrics: {
    pending_pre_plan_orders: 0,
    pending_approval_orders: 0,
    assigned_tasks: 0,
    in_progress_tasks: 0,
    online_drivers: 0,
    exception_alerts: 0,
    busy_vehicles: 0,
    total_vehicles: 0,
  },
  today: {
    created_tasks: 0,
    completed_tasks: 0,
    completed_orders: 0,
    receipt_uploaded_tasks: 0,
    total_freight_amount: 0,
  },
  rates: {
    task_completion_rate: 0,
    vehicle_utilization_rate: 0,
    on_time_order_rate: 0,
    receipt_upload_rate: 0,
    driver_fulfillment_rate: 0,
  },
  generated_at: '',
})

const formatRate = (value) => `${Number(value || 0).toFixed(2)}%`
const formatCurrency = (value) => `¥${Number(value || 0).toFixed(2)}`

const summaryCards = computed(() => [
  {
    label: '待调度计划单',
    value: overview.value.metrics.pending_pre_plan_orders,
    hint: '已审核通过，待进入调度池',
  },
  {
    label: '待审核计划单',
    value: overview.value.metrics.pending_approval_orders,
    hint: '客户或外部提报待处理',
  },
  {
    label: '待接单任务',
    value: overview.value.metrics.assigned_tasks,
    hint: '任务已下发，等待司机确认',
  },
  {
    label: '执行中任务',
    value: overview.value.metrics.in_progress_tasks,
    hint: `待处理异常 ${overview.value.metrics.exception_alerts} 条`,
  },
  {
    label: '在线司机',
    value: overview.value.metrics.online_drivers,
    hint: '15 分钟内有定位上报',
  },
  {
    label: '车辆利用率',
    value: formatRate(overview.value.rates.vehicle_utilization_rate),
    hint: `忙碌车辆 ${overview.value.metrics.busy_vehicles}/${overview.value.metrics.total_vehicles}`,
  },
])

const todayStats = computed(() => [
  { label: '今日创建任务', value: overview.value.today.created_tasks, hint: '按任务创建时间统计' },
  { label: '今日完成任务', value: overview.value.today.completed_tasks, hint: '按任务完成时间统计' },
  { label: '今日完成订单', value: overview.value.today.completed_orders, hint: '已完成并结算的订单数' },
  { label: '今日上传回单任务', value: overview.value.today.receipt_uploaded_tasks, hint: '含回单或签收单' },
  { label: '今日运费产出', value: formatCurrency(overview.value.today.total_freight_amount), hint: '按完成订单运费汇总' },
])

const rateCards = computed(() => [
  {
    label: '任务完成率',
    value: Number(overview.value.rates.task_completion_rate || 0),
    description: '今日完成任务 / 今日创建任务',
  },
  {
    label: '车辆利用率',
    value: Number(overview.value.rates.vehicle_utilization_rate || 0),
    description: '忙碌车辆 / 车辆总数',
  },
  {
    label: '准时履约率',
    value: Number(overview.value.rates.on_time_order_rate || 0),
    description: '按已完成且存在预计送达时间的订单统计',
  },
  {
    label: '回单上传率',
    value: Number(overview.value.rates.receipt_upload_rate || 0),
    description: '已完成任务中存在回单/签收单的占比',
  },
  {
    label: '司机履约率',
    value: Number(overview.value.rates.driver_fulfillment_rate || 0),
    description: '今日已分配司机的有效任务中已完成的占比',
  },
])

const openDetailPage = async () => {
  await router.push({ name: 'dashboard-detail' })
}

const openExceptionPage = async () => {
  await router.push({ name: 'exception-task-management' })
}

const fetchOverview = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const { data } = await api.post('/dashboard/overview', {})
    overview.value = {
      ...overview.value,
      ...data,
    }
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || '看板数据加载失败，请稍后重试'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchOverview()
  refreshTimer = window.setInterval(() => {
    fetchOverview()
  }, 30000)
})

onUnmounted(() => {
  if (refreshTimer) {
    window.clearInterval(refreshTimer)
    refreshTimer = null
  }
})
</script>

<template>
  <div>
    <div class="table-header mb-12">
      <div class="card-title">首页看板</div>
      <el-button plain size="small" :loading="loading" @click="fetchOverview">刷新数据</el-button>
    </div>
    <el-alert
      v-if="errorMessage"
      class="mb-12"
      :title="errorMessage"
      type="error"
      show-icon
      :closable="false"
    />
    <el-row :gutter="16">
      <el-col v-for="card in summaryCards" :key="card.label" :xs="12" :sm="8" :md="8" :lg="8" :xl="4">
        <el-card shadow="hover" class="metric-card" v-loading="loading">
          <div class="metric-label">{{ card.label }}</div>
          <div class="metric-value">{{ card.value }}</div>
          <div class="metric-hint">{{ card.hint }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card class="mt-16" shadow="never" v-loading="loading">
      <div class="card-title">今日运营概览</div>
      <el-row :gutter="16" class="mt-16">
        <el-col v-for="item in todayStats" :key="item.label" :xs="24" :sm="12" :md="8" :xl="4">
          <div class="dashboard-stat-block">
            <div class="metric-label">{{ item.label }}</div>
            <div class="overview-value">{{ item.value }}</div>
            <div class="text-secondary">{{ item.hint }}</div>
          </div>
        </el-col>
      </el-row>
    </el-card>

    <el-card class="mt-16" shadow="never" v-loading="loading">
      <div class="card-title">关键比率</div>
      <div class="dashboard-rate-grid mt-16">
        <div v-for="item in rateCards" :key="item.label" class="dashboard-rate-item">
          <div class="dashboard-rate-header">
            <span class="dashboard-rate-label">{{ item.label }}</span>
            <span class="dashboard-rate-value">{{ formatRate(item.value) }}</span>
          </div>
          <el-progress :percentage="item.value" :stroke-width="10" :show-text="false" />
          <div class="text-secondary mt-8">{{ item.description }}</div>
        </div>
      </div>
      <div class="text-secondary mt-16">数据更新时间：{{ overview.generated_at || '-' }}</div>
    </el-card>

    <el-card class="mt-16" shadow="never">
      <div class="table-header">
        <div class="card-title">运营明细入口</div>
        <div class="text-secondary">首页仅保留宏观指标，详细列表进入独立页面查看</div>
      </div>
      <div class="mt-16">
        <el-button type="primary" @click="openDetailPage">查看运营明细看板</el-button>
        <el-button plain class="ml-8" @click="openExceptionPage">进入异常任务管理</el-button>
      </div>
    </el-card>
  </div>
</template>
