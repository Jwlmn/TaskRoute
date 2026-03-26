<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { readCurrentUser } from '../../utils/auth'

const loading = ref(false)
const detailLoading = ref(false)
const actionLoading = ref(false)
const tasks = ref([])
const detail = ref(null)
const detailVisible = ref(false)
const uploadFile = ref(null)
const reportingLocation = ref(false)
let locationTimer = null

const uploadForm = reactive({
  waypoint_id: null,
  document_type: 'photo',
  remark: '',
})

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

const waypointStatusLabelMap = {
  pending: '未到达',
  arrived: '已到达',
  completed: '已完成',
}

const documentTypeOptions = [
  { label: '回单', value: 'receipt' },
  { label: '签收单', value: 'signoff' },
  { label: '现场照片', value: 'photo' },
  { label: '异常单据', value: 'exception' },
]

const getLabel = (map, value) => map[value] || value || '-'
const getWaypointLabel = (waypoint) => {
  if (!waypoint) return '-'
  const typeMap = {
    pickup: '装货点',
    dropoff: '卸货点',
    checkpoint: '途经点',
    finish: '收车点',
  }
  return `${waypoint.sequence}. ${typeMap[waypoint.node_type] || waypoint.node_type} - ${waypoint.address || ''}`
}

const syncUploadWaypoint = () => {
  const waypoints = detail.value?.waypoints || []
  if (!Array.isArray(waypoints) || waypoints.length === 0) {
    uploadForm.waypoint_id = null
    return
  }

  const existed = waypoints.some((item) => item.id === uploadForm.waypoint_id)
  if (existed) return

  const pending = waypoints.find((item) => item.status !== 'completed')
  uploadForm.waypoint_id = pending?.id || waypoints[0].id
}

const fetchTasks = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/dispatch-task/list', {})
    tasks.value = data.data || []
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
    // 定位失败不阻断任务流程
  } finally {
    reportingLocation.value = false
  }
}

const openDetail = async (taskId) => {
  if (!isDriver.value) return
  detailLoading.value = true
  detailVisible.value = true
  try {
    const { data } = await api.post('/driver-task/detail', { task_id: taskId })
    detail.value = data
    syncUploadWaypoint()
  } catch (error) {
    detailVisible.value = false
    ElMessage.error(error?.response?.data?.message || '加载任务详情失败')
  } finally {
    detailLoading.value = false
  }
}

const refreshCurrentDetail = async () => {
  if (!detail.value?.id) return
  const { data } = await api.post('/driver-task/detail', { task_id: detail.value.id })
  detail.value = data
  syncUploadWaypoint()
}

const startTask = async (taskId) => {
  actionLoading.value = true
  try {
    await api.post('/driver-task/start', { task_id: taskId })
    ElMessage.success('任务已开始')
    await fetchTasks()
    await reportCurrentLocation(taskId)
    if (detail.value?.id === taskId) {
      await refreshCurrentDetail()
    }
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '任务开始失败')
  } finally {
    actionLoading.value = false
  }
}

const arriveWaypoint = async (waypointId) => {
  if (!detail.value?.id) return
  actionLoading.value = true
  try {
    await api.post('/driver-task/waypoint-arrive', {
      task_id: detail.value.id,
      waypoint_id: waypointId,
    })
    ElMessage.success('节点已标记到达')
    await Promise.all([fetchTasks(), refreshCurrentDetail()])
    await reportCurrentLocation(detail.value.id)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '标记到达失败')
  } finally {
    actionLoading.value = false
  }
}

const completeWaypoint = async (waypointId) => {
  if (!detail.value?.id) return
  actionLoading.value = true
  try {
    await api.post('/driver-task/waypoint-complete', {
      task_id: detail.value.id,
      waypoint_id: waypointId,
    })
    ElMessage.success('节点已完成')
    await Promise.all([fetchTasks(), refreshCurrentDetail()])
    await reportCurrentLocation(detail.value.id)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '完成节点失败')
  } finally {
    actionLoading.value = false
  }
}

const onFileChange = (file) => {
  uploadFile.value = file.raw || null
}

const uploadDocument = async () => {
  if (!detail.value?.id) return
  if (!uploadForm.waypoint_id) {
    ElMessage.warning('请先选择节点')
    return
  }
  if (!uploadFile.value) {
    ElMessage.warning('请先选择单据文件')
    return
  }

  actionLoading.value = true
  try {
    const formData = new FormData()
    formData.append('task_id', String(detail.value.id))
    formData.append('waypoint_id', String(uploadForm.waypoint_id))
    formData.append('document_type', uploadForm.document_type)
    formData.append('document_file', uploadFile.value)
    if (uploadForm.remark) {
      formData.append('remark', uploadForm.remark)
    }

    await api.post('/driver-task/upload-document', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    ElMessage.success('电子单据上传成功')
    uploadFile.value = null
    uploadForm.remark = ''
    uploadForm.document_type = 'photo'
    await refreshCurrentDetail()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '上传失败')
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
        </div>
      </template>
    </el-skeleton>
  </el-card>

  <el-dialog v-model="detailVisible" title="任务执行详情" width="94%">
    <el-skeleton :loading="detailLoading" animated :count="2">
      <template #template>
        <el-skeleton-item variant="text" style="height: 72px; margin-bottom: 10px" />
      </template>
      <template #default>
        <el-descriptions :column="1" border size="small" class="mb-12">
          <el-descriptions-item label="任务号">{{ detail?.task_no || '-' }}</el-descriptions-item>
          <el-descriptions-item label="状态">{{ getLabel(taskStatusLabelMap, detail?.status) }}</el-descriptions-item>
          <el-descriptions-item label="派单模式">{{ getLabel(dispatchModeLabelMap, detail?.dispatch_mode) }}</el-descriptions-item>
        </el-descriptions>

        <el-card shadow="never" class="mb-12">
          <template #header>
            <div class="mobile-section-title">任务节点</div>
          </template>
          <div v-if="(detail?.waypoints || []).length === 0">
            <el-empty description="暂无节点" />
          </div>
          <div v-else>
            <div v-for="waypoint in detail.waypoints" :key="waypoint.id" class="mobile-waypoint-item">
              <div class="mobile-waypoint-main">
                <strong>{{ waypoint.sequence }}. {{ waypoint.node_type === 'pickup' ? '装货点' : '卸货点' }}</strong>
                <el-tag size="small">{{ getLabel(waypointStatusLabelMap, waypoint.status) }}</el-tag>
              </div>
              <div class="mobile-waypoint-address">{{ waypoint.address }}</div>
              <div class="mobile-task-actions">
                <el-button
                  size="small"
                  plain
                  @click="arriveWaypoint(waypoint.id)"
                  :disabled="waypoint.status !== 'pending'"
                  :loading="actionLoading"
                >
                  到达
                </el-button>
                <el-button
                  size="small"
                  type="primary"
                  @click="completeWaypoint(waypoint.id)"
                  :disabled="waypoint.status === 'completed'"
                  :loading="actionLoading"
                >
                  完成
                </el-button>
              </div>
            </div>
          </div>
        </el-card>

        <el-card shadow="never" class="mb-12">
          <template #header>
            <div class="mobile-section-title">上传电子单据</div>
          </template>
          <el-form label-position="top" size="small">
            <el-form-item label="任务节点">
              <el-select v-model="uploadForm.waypoint_id" style="width: 100%">
                <el-option
                  v-for="waypoint in detail?.waypoints || []"
                  :key="waypoint.id"
                  :label="getWaypointLabel(waypoint)"
                  :value="waypoint.id"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="单据类型">
              <el-select v-model="uploadForm.document_type" style="width: 100%">
                <el-option
                  v-for="option in documentTypeOptions"
                  :key="option.value"
                  :label="option.label"
                  :value="option.value"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="备注">
              <el-input v-model="uploadForm.remark" placeholder="可选" />
            </el-form-item>
            <el-form-item label="文件">
              <el-upload
                :auto-upload="false"
                :show-file-list="true"
                :limit="1"
                :on-change="onFileChange"
              >
                <el-button type="primary" plain>选择文件</el-button>
              </el-upload>
            </el-form-item>
            <el-button type="primary" :loading="actionLoading" @click="uploadDocument">上传单据</el-button>
          </el-form>
        </el-card>

        <el-card shadow="never">
          <template #header>
            <div class="mobile-section-title">已上传单据</div>
          </template>
          <el-empty v-if="(detail?.documents || []).length === 0" description="暂无单据" />
          <div v-else class="mobile-doc-list">
            <div v-for="doc in detail.documents" :key="doc.id" class="mobile-doc-item">
              <div>{{ getWaypointLabel(doc.waypoint) }}</div>
              <div>{{ doc.document_type }} / {{ doc.uploaded_at || '-' }}</div>
              <a :href="doc.meta?.url" target="_blank">查看文件</a>
            </div>
          </div>
        </el-card>
      </template>
    </el-skeleton>
  </el-dialog>
</template>
