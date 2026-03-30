<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
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
const debouncedKeyword = ref('')
const pagination = ref({
  page: 1,
  per_page: 20,
  total: 0,
  last_page: 1,
})
let locationTimer = null
let keywordDebounceTimer = null
let fetchAbortController = null

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
const formatDateTime = (value) => {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString('zh-CN', { hour12: false })
}
const recentTaskNotices = computed(() => tasks.value
  .filter((task) => ['cancel', 'reassign'].includes(task?.route_meta?.exception?.handle_action))
  .sort((a, b) => String(b?.route_meta?.exception?.handled_at || '').localeCompare(String(a?.route_meta?.exception?.handled_at || '')))
  .slice(0, 3)
  .map((task) => ({
    id: task.id,
    task_no: task.task_no,
    action: task.route_meta?.exception?.handle_action,
    handled_at: task.route_meta?.exception?.handled_at,
    note: task.route_meta?.exception?.handle_note || '',
  })))

const normalizeTaskStatusGroup = (status) => {
  if (status === 'assigned') return 'assigned'
  if (status === 'accepted' || status === 'in_progress') return 'in_progress'
  if (status === 'completed' || status === 'cancelled') return 'completed'
  return 'assigned'
}

const fetchTasks = async (page = pagination.value.page) => {
  fetchAbortController?.abort()
  fetchAbortController = new AbortController()
  loading.value = true
  try {
    const { data } = await api.post(`/dispatch-task/list?page=${page}`, {
      keyword: debouncedKeyword.value || undefined,
      status_group: taskStatusFilter.value === 'all' ? undefined : taskStatusFilter.value,
    }, { signal: fetchAbortController.signal })
    const rawTasks = Array.isArray(data?.data) ? data.data : []
    tasks.value = filterTasksByDataScope(user.value, data.data || [])
    pagination.value = {
      page: Number(data?.current_page || page || 1),
      per_page: Number(data?.per_page || 20),
      total: Number(data?.total || 0),
      last_page: Number(data?.last_page || 1),
    }
    if (rawTasks.length === 0 && pagination.value.page > 1 && pagination.value.total > 0) {
      await fetchTasks(pagination.value.page - 1)
    }
  } catch (error) {
    if (error?.code === 'ERR_CANCELED') {
      return
    }
    ElMessage.error(error?.response?.data?.message || '任务加载失败')
  } finally {
    loading.value = false
  }
}

const changePage = async (nextPage) => {
  await fetchTasks(nextPage)
}

const searchTasks = async () => {
  pagination.value.page = 1
  await fetchTasks(1)
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
  fetchAbortController?.abort()
  fetchAbortController = null
  if (keywordDebounceTimer) {
    clearTimeout(keywordDebounceTimer)
    keywordDebounceTimer = null
  }
  if (locationTimer) {
    window.clearInterval(locationTimer)
    locationTimer = null
  }
})

watch(taskKeyword, (value) => {
  if (keywordDebounceTimer) {
    clearTimeout(keywordDebounceTimer)
  }
  keywordDebounceTimer = setTimeout(() => {
    debouncedKeyword.value = value
  }, 250)
})

watch(debouncedKeyword, () => {
  searchTasks()
})

watch(taskStatusFilter, () => {
  searchTasks()
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
              <el-tab-pane label="全部" name="all" />
              <el-tab-pane label="待接单" name="assigned" />
              <el-tab-pane label="配送中" name="in_progress" />
              <el-tab-pane label="已完成" name="completed" />
            </el-tabs>
            <el-input
              v-model.trim="taskKeyword"
              clearable
              size="small"
              placeholder="搜索任务号/车牌/司机"
            />
          </div>
          <el-alert
            v-for="notice in recentTaskNotices"
            :key="`task-notice-${notice.id}-${notice.action}`"
            class="mobile-order-operation-tip"
            :type="notice.action === 'cancel' ? 'error' : 'warning'"
            :closable="false"
            show-icon
            :title="notice.action === 'cancel' ? `任务 ${notice.task_no} 已取消` : `任务 ${notice.task_no} 已改派`"
            :description="`${notice.note || (notice.action === 'cancel' ? '该任务已停止执行。' : '请停止执行并关注新任务安排。')} 处理时间：${formatDateTime(notice.handled_at)}`"
          />
          <el-empty v-if="tasks.length === 0" description="当前筛选条件下无任务" />
          <div v-for="task in tasks" :key="task.id" class="mobile-task-item">
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
          <div class="mobile-task-pagination">
            <el-pagination
              small
              background
              layout="prev, pager, next, total"
              :current-page="pagination.page"
              :page-size="pagination.per_page"
              :total="pagination.total"
              @current-change="changePage"
            />
          </div>
        </div>
      </template>
    </el-skeleton>
  </el-card>
</template>
