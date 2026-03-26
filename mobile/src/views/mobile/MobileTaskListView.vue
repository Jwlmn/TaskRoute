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
      <div class="mobile-section-title">我的任务</div>
    </template>
    <el-skeleton :loading="loading" animated :count="2">
      <template #template>
        <el-skeleton-item variant="text" style="height: 72px; margin-bottom: 10px" />
      </template>
      <template #default>
        <el-empty v-if="tasks.length === 0" description="暂无任务" />
        <div v-else class="mobile-task-list">
          <div v-for="task in tasks" :key="task.id" class="mobile-task-item">
            <div class="mobile-task-no">{{ task.task_no }}</div>
            <div class="mobile-task-meta">
              <span>{{ task.dispatch_mode }}</span>
              <el-tag size="small" type="primary">{{ task.status }}</el-tag>
            </div>
          </div>
        </div>
      </template>
    </el-skeleton>
  </el-card>
</template>

