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
const reportingLocation = ref(false)
let locationTimer = null

const uploadForms = reactive({})

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

const getWaypointTypeLabel = (waypoint) => {
  if (!waypoint) return '节点'
  if ((waypoint.address || '').includes('装货:') && (waypoint.address || '').includes('卸货:')) {
    return '订单节点'
  }
  const map = {
    pickup: '装货点',
    dropoff: '卸货点',
    checkpoint: '途经点',
    finish: '收车点',
  }
  return map[waypoint.node_type] || '节点'
}

const documentTypeOptions = [
  { label: '回单', value: 'receipt' },
  { label: '签收单', value: 'signoff' },
  { label: '现场照片', value: 'photo' },
  { label: '异常单据', value: 'exception' },
]

const getLabel = (map, value) => map[value] || value || '-'
const getDocumentTypeLabel = (value) => {
  const item = documentTypeOptions.find((option) => option.value === value)
  return item?.label || value || '-'
}

const ensureUploadForm = (waypointId) => {
  if (!waypointId) return null
  if (!uploadForms[waypointId]) {
    uploadForms[waypointId] = {
      document_type: 'photo',
      remark: '',
      files: [],
      file_list: [],
      preview_urls: [],
    }
  }

  return uploadForms[waypointId]
}

const syncUploadForms = () => {
  const waypoints = detail.value?.waypoints || []
  if (!Array.isArray(waypoints) || waypoints.length === 0) return
  for (const waypoint of waypoints) {
    ensureUploadForm(waypoint.id)
  }
}

const getWaypointDocuments = (waypoint) => {
  if (!waypoint) return []
  const docs = Array.isArray(waypoint.documents) ? waypoint.documents : []
  return docs
}

const isImageUrl = (url) => {
  if (!url || typeof url !== 'string') return false
  return /\.(jpg|jpeg|png|gif|webp|bmp|svg)(\?.*)?$/i.test(url)
}

const getWaypointImageUrls = (waypoint) =>
  getWaypointDocuments(waypoint)
    .map((doc) => doc?.meta?.url)
    .filter((url) => isImageUrl(url))

const getWaypointImageIndex = (waypoint, doc) =>
  getWaypointImageUrls(waypoint).findIndex((url) => url === doc?.meta?.url)

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
    syncUploadForms()
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
  syncUploadForms()
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

const resetPreviewUrls = (form) => {
  if (!Array.isArray(form?.preview_urls)) return
  for (const url of form.preview_urls) {
    try {
      URL.revokeObjectURL(url)
    } catch {
      // ignore
    }
  }
  form.preview_urls = []
}

const rebuildPreviewUrls = (form) => {
  resetPreviewUrls(form)
  const imageFiles = (form.files || []).filter((f) => String(f?.type || '').startsWith('image/'))
  form.preview_urls = imageFiles.map((f) => URL.createObjectURL(f))
}

const onFileChange = (waypointId, file, fileList) => {
  const form = ensureUploadForm(waypointId)
  if (!form) return
  const nextList = Array.isArray(fileList) ? fileList.slice(-9) : []
  form.file_list = nextList
  form.files = nextList
    .map((item) => item.raw)
    .filter(Boolean)
  rebuildPreviewUrls(form)
}

const onFileRemove = (waypointId, fileList) => {
  const form = ensureUploadForm(waypointId)
  if (!form) return
  form.file_list = Array.isArray(fileList) ? fileList.slice(-9) : []
  form.files = form.file_list.map((item) => item.raw).filter(Boolean)
  rebuildPreviewUrls(form)
}

const removePreviewAt = (waypointId, previewIndex) => {
  const form = ensureUploadForm(waypointId)
  if (!form) return

  const imageFileListIndexes = (form.file_list || [])
    .map((item, index) => ({ item, index }))
    .filter(({ item }) => String(item?.raw?.type || '').startsWith('image/'))
    .map(({ index }) => index)

  const targetFileListIndex = imageFileListIndexes[previewIndex]
  if (targetFileListIndex === undefined) return

  const nextFileList = [...(form.file_list || [])]
  nextFileList.splice(targetFileListIndex, 1)
  onFileRemove(waypointId, nextFileList)
}

const uploadDocument = async (waypointId) => {
  if (!detail.value?.id) return
  const form = ensureUploadForm(waypointId)
  if (!form) {
    ElMessage.warning('节点参数无效')
    return
  }
  if (!Array.isArray(form.files) || form.files.length === 0) {
    ElMessage.warning('请先选择单据文件')
    return
  }

  actionLoading.value = true
  try {
    for (const file of form.files) {
      const formData = new FormData()
      formData.append('task_id', String(detail.value.id))
      formData.append('waypoint_id', String(waypointId))
      formData.append('document_type', form.document_type)
      formData.append('document_file', file)
      if (form.remark) {
        formData.append('remark', form.remark)
      }
      await api.post('/driver-task/upload-document', formData)
    }
    ElMessage.success('电子单据上传成功')
    resetPreviewUrls(form)
    form.files = []
    form.file_list = []
    form.remark = ''
    form.document_type = 'photo'
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
  for (const form of Object.values(uploadForms)) {
    resetPreviewUrls(form)
  }
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
                <strong>{{ waypoint.sequence }}. {{ getWaypointTypeLabel(waypoint) }}</strong>
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
              <el-divider />
              <div v-if="getWaypointDocuments(waypoint)?.length" class="mobile-waypoint-doc-list">
                <div
                  v-for="doc in getWaypointDocuments(waypoint)"
                  :key="doc.id"
                  class="mobile-waypoint-doc"
                >
                  <div class="mobile-waypoint-doc-text">
                    {{ getDocumentTypeLabel(doc.document_type) }} / {{ doc.uploaded_at || '-' }}
                  </div>
                  <a :href="doc.meta?.url" target="_blank" class="mobile-doc-link">查看文件</a>
                </div>
                <div class="mobile-uploaded-preview-grid">
                  <div
                    v-for="doc in getWaypointDocuments(waypoint)"
                    :key="`thumb-${doc.id}`"
                    class="mobile-uploaded-preview-item"
                  >
                    <el-image
                      v-if="isImageUrl(doc.meta?.url)"
                      class="mobile-uploaded-preview-img"
                      :src="doc.meta?.url"
                      fit="cover"
                      :preview-src-list="getWaypointImageUrls(waypoint)"
                      :initial-index="getWaypointImageIndex(waypoint, doc)"
                      preview-teleported
                    />
                    <a v-else class="mobile-doc-link" :href="doc.meta?.url" target="_blank">查看文件</a>
                  </div>
                </div>
              </div>
              <el-form label-position="top" size="small">
                <el-form-item label="单据类型">
                  <el-select v-model="ensureUploadForm(waypoint.id).document_type" style="width: 100%">
                    <el-option
                      v-for="option in documentTypeOptions"
                      :key="option.value"
                      :label="option.label"
                      :value="option.value"
                    />
                  </el-select>
                </el-form-item>
                <el-form-item label="备注">
                  <el-input v-model="ensureUploadForm(waypoint.id).remark" placeholder="可选" />
                </el-form-item>
                <el-form-item label="文件">
                  <div class="mobile-upload-stack">
                    <el-upload
                      :auto-upload="false"
                      :show-file-list="false"
                      multiple
                      :limit="9"
                      :file-list="ensureUploadForm(waypoint.id).file_list"
                      :on-change="(file, fileList) => onFileChange(waypoint.id, file, fileList)"
                      :on-remove="(_, fileList) => onFileRemove(waypoint.id, fileList)"
                    >
                      <el-button type="primary" plain>选择文件</el-button>
                    </el-upload>
                    <div
                      v-if="ensureUploadForm(waypoint.id).preview_urls?.length"
                      class="mobile-image-preview-grid"
                    >
                      <div
                        v-for="(url, idx) in ensureUploadForm(waypoint.id).preview_urls"
                        :key="`${waypoint.id}-preview-${idx}`"
                        class="mobile-image-preview-item"
                      >
                        <img
                          class="mobile-image-preview"
                          :src="url"
                          alt="图片预览"
                        />
                        <button
                          type="button"
                          class="mobile-image-remove"
                          @click="removePreviewAt(waypoint.id, idx)"
                        >
                          ×
                        </button>
                      </div>
                    </div>
                  </div>
                </el-form-item>
                <el-button type="primary" :loading="actionLoading" @click="uploadDocument(waypoint.id)">
                  上传该节点单据
                </el-button>
              </el-form>
            </div>
          </div>
        </el-card>
      </template>
    </el-skeleton>
  </el-dialog>
</template>
