<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../services/api'

const loading = ref(false)
const errorMessage = ref('')
const overview = ref({
  metrics: {
    pending_pre_plan_orders: 0,
    assigned_tasks: 0,
    in_progress_tasks: 0,
    online_drivers: 0,
    exception_alerts: 0,
  },
  today: {
    created_tasks: 0,
    completed_tasks: 0,
    task_completion_rate: 0,
  },
  generated_at: '',
})

const cards = computed(() => [
  { label: '待调度计划单', value: overview.value.metrics.pending_pre_plan_orders },
  { label: '待接单任务', value: overview.value.metrics.assigned_tasks },
  { label: '执行中任务', value: overview.value.metrics.in_progress_tasks },
  { label: '在线司机', value: overview.value.metrics.online_drivers },
  { label: '异常预警', value: overview.value.metrics.exception_alerts },
])

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
})
</script>

<template>
  <div>
    <el-alert
      v-if="errorMessage"
      class="mb-12"
      :title="errorMessage"
      type="error"
      show-icon
      :closable="false"
    />
    <el-row :gutter="16">
      <el-col v-for="card in cards" :key="card.label" :xs="12" :sm="8" :md="8" :lg="4">
        <el-card shadow="hover" class="metric-card" v-loading="loading">
          <div class="metric-label">{{ card.label }}</div>
          <div class="metric-value">{{ card.value }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card class="mt-16" shadow="never" v-loading="loading">
      <div class="card-title">今日运行概览</div>
      <el-row :gutter="16" class="mt-16">
        <el-col :xs="24" :sm="8">
          <div class="metric-label">今日创建任务</div>
          <div class="overview-value">{{ overview.today.created_tasks }}</div>
        </el-col>
        <el-col :xs="24" :sm="8">
          <div class="metric-label">今日完成任务</div>
          <div class="overview-value">{{ overview.today.completed_tasks }}</div>
        </el-col>
        <el-col :xs="24" :sm="8">
          <div class="metric-label">今日完成率</div>
          <div class="overview-value">{{ overview.today.task_completion_rate }}%</div>
        </el-col>
      </el-row>
      <div class="text-secondary mt-16">数据更新时间：{{ overview.generated_at || '-' }}</div>
    </el-card>

    <el-alert
      class="mt-16"
      title="系统已改为按权限分配功能，不再按 B/C 端区分入口。"
      type="info"
      show-icon
      :closable="false"
    />
  </div>
</template>
