<script setup>
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { readCurrentUser } from '../../utils/auth'
import { filterTasksByDataScope } from '../../utils/dataScope'

const user = computed(() => readCurrentUser())
const loading = ref(false)
const generatedAt = ref('')
const stats = ref([
  { label: '待接单', value: 0 },
  { label: '执行中', value: 0 },
  { label: '已完成', value: 0 },
])

const normalizeTaskStatusGroup = (status) => {
  if (status === 'assigned') return 'assigned'
  if (status === 'accepted' || status === 'in_progress') return 'in_progress'
  if (status === 'completed' || status === 'cancelled') return 'completed'
  return 'assigned'
}

const buildDriverStats = (tasks) => {
  const map = {
    assigned: 0,
    in_progress: 0,
    completed: 0,
  }
  for (const task of tasks) {
    const group = normalizeTaskStatusGroup(task?.status)
    map[group] += 1
  }
  return [
    { label: '待接单', value: map.assigned },
    { label: '执行中', value: map.in_progress },
    { label: '已完成', value: map.completed },
  ]
}

const fetchHomeStats = async () => {
  loading.value = true
  try {
    if (user.value?.role === 'driver') {
      const { data } = await api.post('/dispatch-task/list', {})
      const tasks = filterTasksByDataScope(
        user.value,
        Array.isArray(data?.data) ? data.data : [],
      )
      stats.value = buildDriverStats(tasks)
      generatedAt.value = new Date().toLocaleString('zh-CN', { hour12: false })
      return
    }

    if (user.value?.role === 'customer') {
      const { data } = await api.post('/pre-plan-order/customer-list', {})
      const list = Array.isArray(data?.data) ? data.data : []
      const counters = {
        pending_approval: 0,
        approved: 0,
        rejected: 0,
      }
      for (const item of list) {
        const key = item?.audit_status || 'pending_approval'
        if (counters[key] !== undefined) counters[key] += 1
      }
      stats.value = [
        { label: '待审核', value: counters.pending_approval },
        { label: '已通过', value: counters.approved },
        { label: '已驳回', value: counters.rejected },
      ]
      generatedAt.value = new Date().toLocaleString('zh-CN', { hour12: false })
      return
    }

    const { data } = await api.post('/dashboard/overview', {})
    stats.value = [
      { label: '待调度', value: data?.metrics?.pending_pre_plan_orders || 0 },
      { label: '待接单', value: data?.metrics?.assigned_tasks || 0 },
      { label: '执行中', value: data?.metrics?.in_progress_tasks || 0 },
    ]
    generatedAt.value = data?.generated_at || ''
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '首页数据加载失败')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchHomeStats()
})
</script>

<template>
  <div>
    <el-card shadow="never" class="mb-12">
      <div class="mobile-user-name">{{ user?.name || '未登录' }}</div>
      <div class="mobile-user-role">角色：{{ user?.role || '-' }}</div>
      <div class="mobile-user-role">数据更新时间：{{ generatedAt || '-' }}</div>
    </el-card>

    <el-row :gutter="10">
      <el-col v-for="item in stats" :key="item.label" :span="8">
        <el-card shadow="hover" v-loading="loading">
          <div class="mobile-stat-label">{{ item.label }}</div>
          <div class="mobile-stat-value">{{ item.value }}</div>
        </el-card>
      </el-col>
    </el-row>
    <el-button class="mt-12" plain size="small" :loading="loading" @click="fetchHomeStats">刷新数据</el-button>
  </div>
</template>
