<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../services/api'

const router = useRouter()

const metrics = [
  { label: '待调度计划单', value: 128 },
  { label: '执行中任务', value: 54 },
  { label: '在线司机', value: 83 },
  { label: '异常任务', value: 6 },
]

const upcomingTasks = [
  {
    taskNo: 'DT-20260326-A1B2C3',
    vehicle: '沪A12345',
    driver: '张师傅',
    mode: '单车多订单',
    status: '待确认',
  },
  {
    taskNo: 'DT-20260326-D4E5F6',
    vehicle: '沪B66889',
    driver: '李师傅',
    mode: '多车单订单',
    status: '执行中',
  },
]

const currentUser = computed(() => {
  const raw = localStorage.getItem('taskroute_user')
  if (!raw) {
    return null
  }
  try {
    return JSON.parse(raw)
  } catch {
    return null
  }
})

const logout = async () => {
  try {
    await api.post('/auth/logout')
  } catch {
    // ignore network errors on logout
  } finally {
    localStorage.removeItem('taskroute_token')
    localStorage.removeItem('taskroute_user')
    ElMessage.success('已退出登录')
    await router.push({ name: 'login' })
  }
}
</script>

<template>
  <el-container class="layout-shell">
    <el-header class="layout-header">
      <div class="brand">TaskRoute B端 智能调度台</div>
      <div class="header-actions">
        <el-tag type="primary" effect="dark">Element Plus 默认蓝白主题</el-tag>
        <el-tag type="info" effect="plain">{{ currentUser?.name || '未登录用户' }}</el-tag>
        <el-button type="danger" plain @click="logout">退出登录</el-button>
      </div>
    </el-header>

    <el-main class="layout-main">
      <el-row :gutter="16">
        <el-col v-for="metric in metrics" :key="metric.label" :span="6">
          <el-card shadow="hover" class="metric-card">
            <div class="metric-label">{{ metric.label }}</div>
            <div class="metric-value">{{ metric.value }}</div>
          </el-card>
        </el-col>
      </el-row>

      <el-card class="mt-16" shadow="never">
        <template #header>
          <div class="card-title">待执行任务</div>
        </template>
        <el-table :data="upcomingTasks">
          <el-table-column prop="taskNo" label="任务编号" min-width="200" />
          <el-table-column prop="vehicle" label="车辆" min-width="120" />
          <el-table-column prop="driver" label="司机" min-width="120" />
          <el-table-column prop="mode" label="派单模式" min-width="120" />
          <el-table-column prop="status" label="状态" min-width="100" />
        </el-table>
      </el-card>
    </el-main>
  </el-container>
</template>
