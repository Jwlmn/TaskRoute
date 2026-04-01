<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { dispatchModeLabelMap, getLabel, taskStatusLabelMap } from '../../utils/labels'

const loadingTasks = ref(false)
const loadingLocations = ref(false)
const loadingTrajectory = ref(false)
const tasks = ref([])
const latestLocations = ref([])
const trajectory = ref([])
const taskCurrentPage = ref(1)
const taskPageSize = ref(10)
const locationCurrentPage = ref(1)
const locationPageSize = ref(10)
const trajectoryDialogVisible = ref(false)
const selectedDriverName = ref('')

const mapContainerRef = ref(null)
const mapReady = ref(false)
const replayIndex = ref(0)
const replaying = ref(false)

let amapInstance = null
let trajectoryPolylines = []
let movingMarker = null
let replayTimer = null

const amapKey = import.meta.env.VITE_AMAP_WEB_KEY || ''
const amapSecurityJsCode = import.meta.env.VITE_AMAP_SECURITY_JS_CODE || ''
const pagedTasks = computed(() => {
  const start = (taskCurrentPage.value - 1) * taskPageSize.value
  return tasks.value.slice(start, start + taskPageSize.value)
})
const taskTotal = computed(() => tasks.value.length)
const pagedLatestLocations = computed(() => {
  const start = (locationCurrentPage.value - 1) * locationPageSize.value
  return latestLocations.value.slice(start, start + locationPageSize.value)
})
const locationTotal = computed(() => latestLocations.value.length)

const formatDateTime = (value) => {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  const yyyy = date.getFullYear()
  const mm = String(date.getMonth() + 1).padStart(2, '0')
  const dd = String(date.getDate()).padStart(2, '0')
  const hh = String(date.getHours()).padStart(2, '0')
  const mi = String(date.getMinutes()).padStart(2, '0')
  const ss = String(date.getSeconds()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`
}

const fetchTasks = async () => {
  loadingTasks.value = true
  try {
    const { data } = await api.post('/dispatch-task/list', {})
    tasks.value = data.data || []
    const maxPage = Math.max(1, Math.ceil(taskTotal.value / taskPageSize.value))
    if (taskCurrentPage.value > maxPage) taskCurrentPage.value = maxPage
  } finally {
    loadingTasks.value = false
  }
}

const fetchLatestLocations = async () => {
  loadingLocations.value = true
  try {
    const { data } = await api.post('/driver-location/latest', {})
    latestLocations.value = Array.isArray(data) ? data : []
    const maxPage = Math.max(1, Math.ceil(locationTotal.value / locationPageSize.value))
    if (locationCurrentPage.value > maxPage) locationCurrentPage.value = maxPage
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取司机定位失败')
  } finally {
    loadingLocations.value = false
  }
}

const openFirstTrajectory = async () => {
  if (!latestLocations.value.length) {
    ElMessage.warning('暂无可回放的轨迹数据，请先等待司机上报定位')
    return
  }
  await openTrajectory(latestLocations.value[0])
}

const points = computed(() =>
  trajectory.value
    .map((row) => [Number(row.lng), Number(row.lat)])
    .filter((item) => !Number.isNaN(item[0]) && !Number.isNaN(item[1])),
)

const currentPoint = computed(() => {
  if (points.value.length === 0) return null
  const idx = Math.min(replayIndex.value, points.value.length - 1)
  return points.value[idx]
})

const clearReplayTimer = () => {
  if (replayTimer) {
    window.clearInterval(replayTimer)
    replayTimer = null
  }
}

const stopReplay = () => {
  replaying.value = false
  clearReplayTimer()
}

const resetReplay = () => {
  stopReplay()
  replayIndex.value = 0
  updateMapByReplayIndex()
}

const playReplay = () => {
  if (points.value.length <= 1) return
  if (replaying.value) return
  replaying.value = true
  clearReplayTimer()
  replayTimer = window.setInterval(() => {
    if (replayIndex.value >= points.value.length - 1) {
      stopReplay()
      return
    }
    replayIndex.value += 1
    updateMapByReplayIndex()
  }, 1200)
}

const pauseReplay = () => {
  stopReplay()
}

const loadAmap = async () => {
  if (window.AMap) return window.AMap
  if (!amapKey) return null

  if (amapSecurityJsCode) {
    window._AMapSecurityConfig = {
      ...(window._AMapSecurityConfig || {}),
      securityJsCode: amapSecurityJsCode,
    }
  }

  await new Promise((resolve, reject) => {
    const existed = document.querySelector('script[data-amap="taskroute"]')
    if (existed) {
      existed.addEventListener('load', resolve, { once: true })
      existed.addEventListener('error', reject, { once: true })
      return
    }

    const script = document.createElement('script')
    script.setAttribute('data-amap', 'taskroute')
    script.src = `https://webapi.amap.com/maps?v=2.0&key=${amapKey}`
    script.async = true
    script.onload = () => resolve()
    script.onerror = () => reject(new Error('高德JS SDK加载失败'))
    document.head.appendChild(script)
  })

  return window.AMap || null
}

const ensureMap = async () => {
  if (amapInstance) return true
  const AMap = await loadAmap()
  if (!AMap) {
    mapReady.value = false
    ElMessage.warning('未配置高德JS Key，轨迹地图不可用（仅显示表格）')
    return false
  }

  if (!mapContainerRef.value) return false
  amapInstance = new AMap.Map(mapContainerRef.value, {
    zoom: 12,
    center: [121.4737, 31.2304],
    mapStyle: 'amap://styles/normal',
  })
  mapReady.value = true
  return true
}

const renderMapTrajectory = async () => {
  const ok = await ensureMap()
  if (!ok || !amapInstance) return

  const AMap = window.AMap
  if (!AMap) return

  if (trajectoryPolylines.length) {
    amapInstance.remove(trajectoryPolylines)
    trajectoryPolylines = []
  }
  if (movingMarker) {
    amapInstance.remove(movingMarker)
    movingMarker = null
  }

  if (points.value.length === 0) return

  const groupedPaths = {}
  for (const row of trajectory.value) {
    const lng = Number(row?.lng)
    const lat = Number(row?.lat)
    if (Number.isNaN(lng) || Number.isNaN(lat)) continue
    const taskKey = row?.dispatch_task_id || 'unknown'
    if (!groupedPaths[taskKey]) {
      groupedPaths[taskKey] = []
    }
    groupedPaths[taskKey].push([lng, lat])
  }

  const colors = ['#2563eb', '#16a34a', '#f59e0b', '#7c3aed', '#dc2626', '#0891b2']
  trajectoryPolylines = Object.values(groupedPaths)
    .filter((path) => Array.isArray(path) && path.length > 1)
    .map((path, index) => new AMap.Polyline({
      path,
      strokeColor: colors[index % colors.length],
      strokeWeight: 5,
      lineJoin: 'round',
      lineCap: 'round',
    }))

  if (trajectoryPolylines.length === 0) {
    trajectoryPolylines = [
      new AMap.Polyline({
        path: points.value,
        strokeColor: '#2563eb',
        strokeWeight: 5,
        lineJoin: 'round',
        lineCap: 'round',
      }),
    ]
  }

  movingMarker = new AMap.Marker({
    position: points.value[0],
    zIndex: 110,
    title: '当前位置',
  })

  amapInstance.add([...trajectoryPolylines, movingMarker])
  amapInstance.setFitView([...trajectoryPolylines], false, [60, 60, 60, 60])
  amapInstance.resize()
}

const updateMapByReplayIndex = () => {
  if (!movingMarker || !currentPoint.value) return
  movingMarker.setPosition(currentPoint.value)
}

const openTrajectory = async (row) => {
  loadingTrajectory.value = true
  trajectoryDialogVisible.value = true
  selectedDriverName.value = row?.driver?.name || '-'
  stopReplay()
  replayIndex.value = 0

  try {
    const { data } = await api.post('/driver-location/trajectory', {
      driver_id: row.driver_id,
      limit: 500,
    })
    trajectory.value = Array.isArray(data) ? data : []
    await nextTick()
    await renderMapTrajectory()
    window.setTimeout(() => {
      amapInstance?.resize?.()
    }, 60)
  } catch (error) {
    trajectoryDialogVisible.value = false
    ElMessage.error(error?.response?.data?.message || '获取轨迹失败')
  } finally {
    loadingTrajectory.value = false
  }
}

const handleTrajectoryDialogClosed = () => {
  stopReplay()
  trajectory.value = []
  replayIndex.value = 0
  if (amapInstance && trajectoryPolylines.length) {
    amapInstance.remove(trajectoryPolylines)
    trajectoryPolylines = []
  }
  if (amapInstance && movingMarker) {
    amapInstance.remove(movingMarker)
    movingMarker = null
  }
}

onMounted(async () => {
  await Promise.all([fetchTasks(), fetchLatestLocations()])
})

onBeforeUnmount(() => {
  stopReplay()
  if (amapInstance) {
    amapInstance.destroy()
    amapInstance = null
  }
})
</script>

<template>
  <div class="page-content-shell">
  <el-card shadow="never" class="page-section-card mb-12">
    <template #header>
      <div class="table-header">
        <div class="card-title">司机定位与轨迹回放</div>
        <el-button type="primary" plain @click="fetchLatestLocations">刷新定位</el-button>
      </div>
    </template>
    <el-alert
      type="info"
      :closable="false"
      show-icon
      class="mb-12"
      title="轨迹回放模块：可在下方“司机实时定位”中查看单司机轨迹，或直接点击“打开轨迹回放”快速进入。"
    />
    <div class="table-header mb-12">
      <span>当前可回放司机数：{{ latestLocations.length }}</span>
      <el-button type="primary" @click="openFirstTrajectory">打开轨迹回放</el-button>
    </div>
    <div class="page-table-card" style="height: 320px">
    <div class="page-table-wrap">
    <el-table :data="pagedTasks" v-loading="loadingTasks" stripe height="100%" class="page-table">
      <el-table-column prop="task_no" label="任务编号" min-width="160" />
      <el-table-column label="派单模式" min-width="140">
        <template #default="{ row }">
          {{ getLabel(dispatchModeLabelMap, row.dispatch_mode) }}
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="100">
        <template #default="{ row }">
          {{ getLabel(taskStatusLabelMap, row.status) }}
        </template>
      </el-table-column>
      <el-table-column label="车辆" min-width="170">
        <template #default="{ row }">
          {{ row.vehicle?.plate_number || '-' }} {{ row.vehicle?.name || '' }}
          <span class="text-secondary">（ID: {{ row.vehicle_id || '-' }}）</span>
        </template>
      </el-table-column>
      <el-table-column label="司机" min-width="170">
        <template #default="{ row }">
          {{ row.driver?.name || '-' }}（{{ row.driver?.account || '-' }}）
          <span class="text-secondary">（ID: {{ row.driver_id || '-' }}）</span>
        </template>
      </el-table-column>
      <el-table-column prop="estimated_distance_km" label="里程(km)" min-width="100" />
      <el-table-column prop="estimated_fuel_l" label="油耗(L)" min-width="100" />
    </el-table>
    </div>
    <div class="page-pagination">
      <el-pagination
        v-model:current-page="taskCurrentPage"
        v-model:page-size="taskPageSize"
        layout="prev, pager, next, total"
        :page-sizes="[10, 20, 50]"
        :total="taskTotal"
      />
    </div>
    </div>
  </el-card>

  <el-card shadow="never" class="page-section-card">
    <template #header>
      <div class="card-title">司机实时定位</div>
    </template>
    <div class="page-table-card" style="height: 360px">
    <div class="page-table-wrap">
    <el-table :data="pagedLatestLocations" v-loading="loadingLocations" stripe height="100%" class="page-table">
      <el-table-column label="司机" min-width="140">
        <template #default="{ row }">
          {{ row.driver?.name || '-' }}（{{ row.driver?.account || '-' }}）
        </template>
      </el-table-column>
      <el-table-column label="任务号" min-width="150">
        <template #default="{ row }">
          {{ row.task?.task_no || '-' }}
        </template>
      </el-table-column>
      <el-table-column prop="lng" label="经度" min-width="120" />
      <el-table-column prop="lat" label="纬度" min-width="120" />
      <el-table-column prop="speed_kmh" label="速度(km/h)" min-width="110" />
      <el-table-column label="定位时间" min-width="170">
        <template #default="{ row }">
          {{ formatDateTime(row.located_at) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="120" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openTrajectory(row)">查看轨迹</el-button>
        </template>
      </el-table-column>
    </el-table>
    </div>
    <div class="page-pagination">
      <el-pagination
        v-model:current-page="locationCurrentPage"
        v-model:page-size="locationPageSize"
        layout="prev, pager, next, total"
        :page-sizes="[10, 20, 50]"
        :total="locationTotal"
      />
    </div>
    </div>
  </el-card>
  </div>

  <el-dialog
    v-model="trajectoryDialogVisible"
    :title="`轨迹详情 - ${selectedDriverName}`"
    class="trajectory-dialog"
    width="900px"
    top="4vh"
    append-to-body
    @closed="handleTrajectoryDialogClosed"
  >
    <div class="trajectory-dialog-content">
      <div class="trajectory-toolbar mb-12">
        <el-button type="primary" plain @click="playReplay" :disabled="points.length <= 1 || replaying">播放</el-button>
        <el-button plain @click="pauseReplay" :disabled="!replaying">暂停</el-button>
        <el-button plain @click="resetReplay" :disabled="points.length === 0">重置</el-button>
        <span class="trajectory-tip">当前点位：{{ points.length === 0 ? 0 : replayIndex + 1 }}/{{ points.length }}</span>
      </div>

      <div ref="mapContainerRef" class="trajectory-map mb-12">
        <div v-if="!mapReady" class="trajectory-map-empty">未配置高德JS Key，当前仅显示轨迹表格</div>
      </div>

      <div class="trajectory-table-wrap">
        <el-table :data="trajectory" v-loading="loadingTrajectory" size="small" stripe height="100%">
          <el-table-column label="定位时间" min-width="170">
            <template #default="{ row }">
              {{ formatDateTime(row.located_at) }}
            </template>
          </el-table-column>
          <el-table-column prop="lng" label="经度" min-width="120" />
          <el-table-column prop="lat" label="纬度" min-width="120" />
          <el-table-column prop="speed_kmh" label="速度(km/h)" min-width="110" />
        </el-table>
      </div>
    </div>
    <template #footer>
      <el-button @click="trajectoryDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>
