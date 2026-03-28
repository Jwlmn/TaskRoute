<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { readCurrentUser } from '../../utils/auth'
import { filterTasksByDataScope } from '../../utils/dataScope'

const router = useRouter()
const loading = ref(false)
const actionLoading = ref(false)
const reportingLocation = ref(false)
const tasks = ref([])
const taskStatusFilter = ref('all')
const taskKeyword = ref('')
let locationTimer = null

const user = computed(() => readCurrentUser())
const isDriver = computed(() => user.value?.role === 'driver')

const dispatchModeLabelMap = {
  single_vehicle_single_order: '单车单订单',
  single_vehicle_multi_order: '单车多订单',
  multi_vehicle_single_order: '多车单订单',
  multi_vehicle_multi_order: '多车多订单',
}

const taskStatusLabelMap = {
  assigned: '待接单',
  accepted: '已接单',
  in_progress: '执行中',
  completed: '已完成',
  cancelled: '已取消',
}

const getLabel = (map, value) => map[value] || value || '-'

const normalizeTaskStatusGroup = (status) => {
  if (status === 'assigned') return 'assigned'
  if (status === 'accepted' || status === 'in_progress') return 'in_progress'
  if (status === 'completed' || status === 'cancelled') return 'completed'
  return 'assigned'
}

const taskStats = computed(() => {
  const stats = {
    all: tasks.value.length,
    assigned: 0,
    in_progress: 0,
    completed: 0,
  }
  for (const task of tasks.value) {
    const group = normalizeTaskStatusGroup(task?.status)
    if (stats[group] !== undefined) {
      stats[group] += 1
    }
  }
  return stats
})

const filteredTasks = computed(() => {
  const keyword = String(taskKeyword.value || '').trim().toLowerCase()
  return tasks.value.filter((task) => {
    const matchStatus = taskStatusFilter.value === 'all'
      ? true
      : normalizeTaskStatusGroup(task?.status) === taskStatusFilter.value
    if (!matchStatus) return false
    if (!keyword) return true
    const source = [
      task?.task_no,
      task?.dispatch_mode,
      task?.vehicle?.plate_number,
      task?.driver?.name,
    ]
      .map((item) => String(item || '').toLowerCase())
      .join(' ')
    return source.includes(keyword)
  })
})

const fetchTasks = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/dispatch-task/list', {})
    tasks.value = filterTasksByDataScope(user.value, data.data || [])
  } finally {
    loading.value = false
  }
}

const resolveCurrentTaskId = () => {
  const task = tasks.value.find((item) => ['assigned', 'accepted', 'in_progress'].includes(item.status))
  return task?.id || null
}

const reportCurrentLocation = async (taskId = null) => {
  if (!isDriver.value || reportingLocation.value) return
  if (!navigator.geolocation) return

  reportingLocation.value = true
  try {
    const position = await new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: true,
        timeout: 8000,
      })
    })

    await api.post('/driver-location/report', {
      dispatch_task_id: taskId || resolveCurrentTaskId(),
      lng: position.coords.longitude,
      lat: position.coords.latitude,
      speed_kmh: position.coords.speed ? Number(position.coords.speed) * 3.6 : null,
    })
  } catch {
    // ignore
  } finally {
    reportingLocation.value = false
  }
}

const openDetail = async (taskId) => {
  await router.push({ name: 'mobile-task-detail', params: { id: taskId } })
}

const startTask = async (taskId) => {
  actionLoading.value = true
  try {
    await api.post('/driver-task/start', { task_id: taskId })
    ElMessage.success('任务已开始')
    await fetchTasks()
    await reportCurrentLocation(taskId)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '任务开始失败')
  } finally {
    actionLoading.value = false
  }
}

onMounted(() => {
  fetchTasks().then(() => {
    reportCurrentLocation()
  })
  if (isDriver.value) {
    locationTimer = window.setInterval(() => {
      reportCurrentLocation()
    }, 30000)
  }
})

onUnmounted(() => {
  if (locationTimer) {
    window.clearInterval(locationTimer)
    locationTimer = null
  }
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
          <div class="mobile-task-filter">
            <el-tabs v-model="taskStatusFilter" class="mobile-task-tabs">
              <el-tab-pane :label="`全部（${taskStats.all}）`" name="all" />
              <el-tab-pane :label="`待接单（${taskStats.assigned}）`" name="assigned" />
              <el-tab-pane :label="`配送中（${taskStats.in_progress}）`" name="in_progress" />
              <el-tab-pane :label="`已完成（${taskStats.completed}）`" name="completed" />
            </el-tabs>
            <el-input
              v-model.trim="taskKeyword"
              clearable
              size="small"
              placeholder="搜索任务号/车牌/司机"
            />
          </div>
          <el-empty v-if="filteredTasks.length === 0" description="当前筛选条件下无任务" />
          <div v-for="task in filteredTasks" :key="task.id" class="mobile-task-item">
            <div class="mobile-task-no">{{ task.task_no }}</div>
            <div class="mobile-task-meta">
              <span>{{ getLabel(dispatchModeLabelMap, task.dispatch_mode) }}</span>
              <el-tag size="small" type="primary">{{ getLabel(taskStatusLabelMap, task.status) }}</el-tag>
            </div>
            <div class="mobile-task-actions">
              <el-button size="small" plain @click="openDetail(task.id)" :disabled="!isDriver">详情</el-button>
              <el-button
                v-if="isDriver && task.status === 'assigned'"
                size="small"
                type="primary"
                @click="startTask(task.id)"
                :loading="actionLoading"
              >
                接单
              </el-button>
            </div>
          </div>
        </div>
      </template>
    </el-skeleton>
  </el-card>
</template>
