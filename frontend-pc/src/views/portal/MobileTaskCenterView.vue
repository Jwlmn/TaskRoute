<script setup>
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { dispatchModeLabelMap, getLabel, taskStatusLabelMap } from '../../utils/labels'

const loadingTasks = ref(false)
const loadingLocations = ref(false)
const loadingTrajectory = ref(false)
const tasks = ref([])
const latestLocations = ref([])
const trajectory = ref([])
const trajectoryDialogVisible = ref(false)
const selectedDriverName = ref('')

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
  } finally {
    loadingTasks.value = false
  }
}

const fetchLatestLocations = async () => {
  loadingLocations.value = true
  try {
    const { data } = await api.post('/driver-location/latest', {})
    latestLocations.value = Array.isArray(data) ? data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取司机定位失败')
  } finally {
    loadingLocations.value = false
  }
}

const openTrajectory = async (row) => {
  loadingTrajectory.value = true
  trajectoryDialogVisible.value = true
  selectedDriverName.value = row?.driver?.name || '-'
  try {
    const { data } = await api.post('/driver-location/trajectory', {
      driver_id: row.driver_id,
      dispatch_task_id: row.dispatch_task_id || null,
      limit: 200,
    })
    trajectory.value = Array.isArray(data) ? data : []
  } catch (error) {
    trajectoryDialogVisible.value = false
    ElMessage.error(error?.response?.data?.message || '获取轨迹失败')
  } finally {
    loadingTrajectory.value = false
  }
}

onMounted(async () => {
  await Promise.all([fetchTasks(), fetchLatestLocations()])
})
</script>

<template>
  <el-card shadow="never" class="mb-12">
    <template #header>
      <div class="table-header">
        <div class="card-title">移动任务中心</div>
        <el-button type="primary" plain @click="fetchLatestLocations">刷新定位</el-button>
      </div>
    </template>
    <el-table :data="tasks" v-loading="loadingTasks" stripe>
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
      <el-table-column prop="driver_id" label="司机ID" min-width="90" />
      <el-table-column prop="estimated_distance_km" label="里程(km)" min-width="100" />
      <el-table-column prop="estimated_fuel_l" label="油耗(L)" min-width="100" />
    </el-table>
  </el-card>

  <el-card shadow="never">
    <template #header>
      <div class="card-title">司机实时定位</div>
    </template>
    <el-table :data="latestLocations" v-loading="loadingLocations" stripe>
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
  </el-card>

  <el-dialog
    v-model="trajectoryDialogVisible"
    :title="`轨迹详情 - ${selectedDriverName}`"
    width="760px"
  >
    <el-table :data="trajectory" v-loading="loadingTrajectory" size="small" stripe>
      <el-table-column label="定位时间" min-width="170">
        <template #default="{ row }">
          {{ formatDateTime(row.located_at) }}
        </template>
      </el-table-column>
      <el-table-column prop="lng" label="经度" min-width="120" />
      <el-table-column prop="lat" label="纬度" min-width="120" />
      <el-table-column prop="speed_kmh" label="速度(km/h)" min-width="110" />
    </el-table>
    <template #footer>
      <el-button @click="trajectoryDialogVisible = false">关闭</el-button>
    </template>
  </el-dialog>
</template>
