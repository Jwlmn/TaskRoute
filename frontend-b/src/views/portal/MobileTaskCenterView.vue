<script setup>
import { onMounted, ref } from 'vue'
import api from '../../services/api'

const loading = ref(false)
const tasks = ref([])

const fetchTasks = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/dispatch-tasks')
    tasks.value = data.data || []
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchTasks()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="card-title">移动任务中心</div>
    </template>
    <el-table :data="tasks" v-loading="loading" stripe>
      <el-table-column prop="task_no" label="任务编号" min-width="160" />
      <el-table-column prop="dispatch_mode" label="派单模式" min-width="130" />
      <el-table-column prop="status" label="状态" min-width="100" />
      <el-table-column prop="estimated_distance_km" label="里程(km)" min-width="100" />
      <el-table-column prop="estimated_fuel_l" label="油耗(L)" min-width="100" />
    </el-table>
  </el-card>
</template>

