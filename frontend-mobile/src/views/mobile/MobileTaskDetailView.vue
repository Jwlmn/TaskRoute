<script setup>
import { onMounted, onUnmounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'

const route = useRoute()
const router = useRouter()

const detailLoading = ref(false)
const actionLoading = ref(false)
const detail = ref(null)
const uploadForms = reactive({})
const exceptionDialogVisible = ref(false)
const exceptionForm = reactive({
  exception_type: 'traffic_jam',
  description: '',
})

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
  { label: '装货单', value: 'pickup_note' },
  { label: '卸货单', value: 'dropoff_note' },
  { label: '回单', value: 'receipt' },
  { label: '签收单', value: 'signoff' },
  { label: '现场照片', value: 'photo' },
  { label: '异常单据', value: 'exception' },
]

const exceptionTypeOptions = [
  { label: '交通拥堵', value: 'traffic_jam' },
  { label: '车辆故障', value: 'vehicle_breakdown' },
  { label: '客户拒收', value: 'customer_reject' },
  { label: '地址变更', value: 'address_change' },
  { label: '货损异常', value: 'goods_damage' },
  { label: '其他异常', value: 'other' },
]

const getLabel = (map, value) => map[value] || value || '-'
const getDocumentTypeLabel = (value) => documentTypeOptions.find((option) => option.value === value)?.label || value || '-'

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

const getWaypointDocuments = (waypoint) => {
  if (!waypoint) return []
  return Array.isArray(waypoint.documents) ? waypoint.documents : []
}

const getOrderWaypoint = (order) => {
  if (!order?.order_no) return null
  const waypoints = detail.value?.waypoints || []
  return waypoints.find((item) => String(item?.address || '').startsWith(`订单${order.order_no}｜`)) || null
}

const isImageUrl = (url) => {
  if (!url || typeof url !== 'string') return false
  return /\.(jpg|jpeg|png|gif|webp|bmp|svg)(\?.*)?$/i.test(url)
}

const getWaypointDocumentGroups = (waypoint) => {
  const groups = {}
  for (const doc of getWaypointDocuments(waypoint)) {
    const key = doc?.document_type || 'unknown'
    if (!groups[key]) {
      groups[key] = {
        key,
        label: getDocumentTypeLabel(key),
        docs: [],
        imageUrls: [],
      }
    }
    groups[key].docs.push(doc)
    if (isImageUrl(doc?.meta?.url)) {
      groups[key].imageUrls.push(doc.meta.url)
    }
  }
  return Object.values(groups)
}

const getGroupImageIndex = (group, doc) =>
  (group?.imageUrls || []).findIndex((url) => url === doc?.meta?.url)

const canOperateTask = () => ['accepted', 'in_progress'].includes(detail.value?.status)
const shouldShowAcceptTip = () => detail.value?.status === 'assigned'
const hasPendingException = () => detail.value?.route_meta?.exception?.status === 'pending'

const formatDateTime = (value) => {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return String(value)
  return date.toLocaleString('zh-CN', { hour12: false })
}

const formatCargoValue = (value, unit) => {
  if (value === null || value === undefined || value === '') return '-'
  return `${value}${unit}`
}

const getCargoCategoryName = (order) => {
  if (!order || typeof order !== 'object') return '-'
  return order.cargo_category?.name || order.cargo_category_name || '-'
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

const onFileChange = (waypointId, _file, fileList) => {
  const form = ensureUploadForm(waypointId)
  if (!form) return
  const nextList = Array.isArray(fileList) ? fileList.slice(-9) : []
  form.file_list = nextList
  form.files = nextList.map((item) => item.raw).filter(Boolean)
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

const reportCurrentLocation = async (taskId) => {
  if (!navigator.geolocation) return
  try {
    const position = await new Promise((resolve, reject) => {
      navigator.geolocation.getCurrentPosition(resolve, reject, {
        enableHighAccuracy: true,
        timeout: 8000,
      })
    })

    await api.post('/driver-location/report', {
      dispatch_task_id: taskId,
      lng: position.coords.longitude,
      lat: position.coords.latitude,
      speed_kmh: position.coords.speed ? Number(position.coords.speed) * 3.6 : null,
    })
  } catch {
    // ignore
  }
}

const taskId = () => Number(route.params.id)

const loadDetail = async () => {
  detailLoading.value = true
  try {
    const { data } = await api.post('/driver-task/detail', { task_id: taskId() })
    detail.value = data
    syncUploadForms()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '加载任务详情失败')
    await router.push({ name: 'mobile-tasks' })
  } finally {
    detailLoading.value = false
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
    await loadDetail()
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
    await loadDetail()
    await reportCurrentLocation(detail.value.id)
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '完成节点失败')
  } finally {
    actionLoading.value = false
  }
}

const uploadDocument = async (waypointId) => {
  if (!detail.value?.id) return
  if (!canOperateTask()) {
    ElMessage.warning('请先接单后再上传单据')
    return
  }
  const form = ensureUploadForm(waypointId)
  if (!form || !Array.isArray(form.files) || form.files.length === 0) {
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
    await loadDetail()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '上传失败')
  } finally {
    actionLoading.value = false
  }
}

const openExceptionDialog = () => {
  if (!canOperateTask()) {
    ElMessage.warning('请先接单后再上报异常')
    return
  }
  exceptionDialogVisible.value = true
}

const submitException = async () => {
  if (!detail.value?.id) return
  if (!String(exceptionForm.description || '').trim()) {
    ElMessage.warning('请填写异常说明')
    return
  }

  actionLoading.value = true
  try {
    await api.post('/driver-task/report-exception', {
      task_id: detail.value.id,
      exception_type: exceptionForm.exception_type,
      description: exceptionForm.description.trim(),
    })
    ElMessage.success('异常已上报，请等待调度处理')
    exceptionDialogVisible.value = false
    exceptionForm.description = ''
    await loadDetail()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '异常上报失败')
  } finally {
    actionLoading.value = false
  }
}

onMounted(() => {
  loadDetail()
})

onUnmounted(() => {
  for (const form of Object.values(uploadForms)) {
    resetPreviewUrls(form)
  }
})
</script>

<template>
  <el-card shadow="never" class="mb-12">
    <div class="table-header">
      <div class="mobile-section-title">任务执行详情</div>
      <el-space>
        <el-button
          size="small"
          type="danger"
          plain
          :disabled="!canOperateTask()"
          @click="openExceptionDialog"
        >
          上报异常
        </el-button>
        <el-button plain size="small" @click="router.push({ name: 'mobile-tasks' })">返回任务列表</el-button>
      </el-space>
    </div>
  </el-card>

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
      <el-alert
        v-if="hasPendingException()"
        class="mb-12"
        type="warning"
        :closable="false"
        show-icon
        :title="`异常待处理：${detail?.route_meta?.exception?.description || '请等待调度处理'}`"
      />

      <el-card shadow="never" class="mb-12">
        <template #header>
          <div class="table-header">
            <div class="mobile-section-title">订单信息</div>
            <el-tag size="small" type="info">共 {{ (detail?.orders || []).length }} 单</el-tag>
          </div>
        </template>
        <el-empty v-if="(detail?.orders || []).length === 0" description="暂无订单信息" />
        <div v-else class="mobile-order-list">
          <div
            v-for="order in detail.orders"
            :key="order.id"
            class="mobile-order-item"
          >
            <div class="mobile-order-header">
              <strong>{{ order.order_no || `订单#${order.id}` }}</strong>
              <div class="mobile-order-tags">
                <el-tag size="small" type="info">{{ getCargoCategoryName(order) }}</el-tag>
                <el-tag v-if="getOrderWaypoint(order)" size="small">
                  {{ getLabel(waypointStatusLabelMap, getOrderWaypoint(order)?.status) }}
                </el-tag>
              </div>
            </div>
            <div class="mobile-order-line">客户：{{ order.client_name || '-' }}</div>
            <div class="mobile-order-line">装货地：{{ order.pickup_address || '-' }}</div>
            <div class="mobile-order-line">卸货地：{{ order.dropoff_address || '-' }}</div>
            <div class="mobile-order-line">
              重量/体积：{{ formatCargoValue(order.cargo_weight_kg, 'kg') }} / {{ formatCargoValue(order.cargo_volume_m3, 'm³') }}
            </div>
            <div class="mobile-order-line">
              时间窗：{{ formatDateTime(order.expected_pickup_at) }} ~ {{ formatDateTime(order.expected_delivery_at) }}
            </div>

            <template v-if="getOrderWaypoint(order)">
              <el-alert
                v-if="shouldShowAcceptTip()"
                class="mobile-order-operation-tip"
                type="warning"
                :closable="false"
                title="当前任务尚未接单，仅可查看详情；请先在任务列表点击“接单”后再执行节点和上传单据"
              />
              <div class="mobile-task-actions mobile-order-actions">
                <el-button
                  size="small"
                  plain
                  @click="arriveWaypoint(getOrderWaypoint(order).id)"
                  :disabled="!canOperateTask() || getOrderWaypoint(order).status !== 'pending'"
                  :loading="actionLoading"
                >
                  到达
                </el-button>
                <el-button
                  size="small"
                  type="primary"
                  @click="completeWaypoint(getOrderWaypoint(order).id)"
                  :disabled="!canOperateTask() || getOrderWaypoint(order).status === 'completed'"
                  :loading="actionLoading"
                >
                  完成
                </el-button>
              </div>

              <el-divider />
              <div v-if="getWaypointDocuments(getOrderWaypoint(order))?.length" class="mobile-waypoint-doc-list">
                <div
                  v-for="group in getWaypointDocumentGroups(getOrderWaypoint(order))"
                  :key="`${getOrderWaypoint(order).id}-${group.key}`"
                  class="mobile-waypoint-group"
                >
                  <div class="mobile-waypoint-doc-title">
                    {{ group.label }}（{{ group.docs.length }}）
                  </div>
                  <div class="mobile-image-preview-grid">
                    <div
                      v-for="doc in group.docs"
                      :key="`uploaded-thumb-${doc.id}`"
                      class="mobile-image-preview-item"
                    >
                      <el-image
                        v-if="isImageUrl(doc.meta?.url)"
                        class="mobile-image-preview"
                        :src="doc.meta?.url"
                        fit="cover"
                        :preview-src-list="group.imageUrls"
                        :initial-index="getGroupImageIndex(group, doc)"
                        :hide-on-click-modal="true"
                        preview-teleported
                      />
                      <div v-else class="mobile-image-preview-fallback">非图片</div>
                    </div>
                  </div>
                </div>
              </div>

              <el-form label-position="top" size="small">
                <el-form-item label="单据类型">
                  <el-select v-model="ensureUploadForm(getOrderWaypoint(order).id).document_type" style="width: 100%">
                    <el-option
                      v-for="option in documentTypeOptions"
                      :key="option.value"
                      :label="option.label"
                      :value="option.value"
                    />
                  </el-select>
                </el-form-item>
                <el-form-item label="备注">
                  <el-input v-model="ensureUploadForm(getOrderWaypoint(order).id).remark" placeholder="可选" />
                </el-form-item>
                <el-form-item label="文件">
                  <div class="mobile-upload-stack">
                    <el-upload
                      :disabled="!canOperateTask()"
                      :auto-upload="false"
                      :show-file-list="false"
                      multiple
                      :limit="9"
                      :file-list="ensureUploadForm(getOrderWaypoint(order).id).file_list"
                      :on-change="(file, fileList) => onFileChange(getOrderWaypoint(order).id, file, fileList)"
                      :on-remove="(_, fileList) => onFileRemove(getOrderWaypoint(order).id, fileList)"
                    >
                      <el-button type="primary" plain>选择文件</el-button>
                    </el-upload>
                    <div
                      v-if="ensureUploadForm(getOrderWaypoint(order).id).preview_urls?.length"
                      class="mobile-image-preview-grid"
                    >
                      <div
                        v-for="(url, idx) in ensureUploadForm(getOrderWaypoint(order).id).preview_urls"
                        :key="`${getOrderWaypoint(order).id}-preview-${idx}`"
                        class="mobile-image-preview-item"
                      >
                        <img class="mobile-image-preview" :src="url" alt="图片预览" />
                        <button
                          type="button"
                          class="mobile-image-remove"
                          @click="removePreviewAt(getOrderWaypoint(order).id, idx)"
                        >
                          ×
                        </button>
                      </div>
                    </div>
                  </div>
                </el-form-item>
                <el-button
                  type="primary"
                  :disabled="!canOperateTask()"
                  :loading="actionLoading"
                  @click="uploadDocument(getOrderWaypoint(order).id)"
                >
                  上传该订单单据
                </el-button>
              </el-form>
            </template>

            <div v-else class="mobile-waypoint-missing">
              当前订单未关联执行节点，暂不可上报到达/完成与上传单据，请联系调度员检查任务数据。
            </div>
          </div>
        </div>
      </el-card>
    </template>
  </el-skeleton>

  <el-dialog v-model="exceptionDialogVisible" title="上报任务异常" width="90%" destroy-on-close>
    <el-form label-position="top">
      <el-form-item label="异常类型">
        <el-select v-model="exceptionForm.exception_type" style="width: 100%">
          <el-option
            v-for="option in exceptionTypeOptions"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="异常说明">
        <el-input
          v-model="exceptionForm.description"
          type="textarea"
          :rows="4"
          maxlength="500"
          show-word-limit
          placeholder="请描述异常原因和现场情况"
        />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="exceptionDialogVisible = false">取消</el-button>
      <el-button type="danger" :loading="actionLoading" @click="submitException">提交异常</el-button>
    </template>
  </el-dialog>
</template>
