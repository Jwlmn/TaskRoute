<script setup>
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { getLabel, taskStatusLabelMap } from '../../utils/labels'

const loadingExceptions = ref(false)
const handlingException = ref(false)
const exceptionTasks = ref([])
const vehicles = ref([])
const exceptionHandleDialogVisible = ref(false)
const handlingTask = ref(null)
const exceptionHandleForm = ref({
  action: 'continue',
  handle_note: '',
  reassign_vehicle_id: null,
})

const exceptionTypeLabelMap = {
  vehicle_breakdown: '车辆故障',
  traffic_jam: '交通拥堵',
  customer_reject: '客户拒收',
  address_change: '地址变更',
  goods_damage: '货损异常',
  other: '其他异常',
}

const loadExceptionTasks = async () => {
  loadingExceptions.value = true
  try {
    const { data } = await api.post('/dispatch-task/exception-list', { status: 'pending' })
    exceptionTasks.value = Array.isArray(data?.data) ? data.data : []
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '获取异常任务失败')
  } finally {
    loadingExceptions.value = false
  }
}

const loadVehicles = async () => {
  const { data } = await api.post('/resource/vehicle/list', {})
  vehicles.value = Array.isArray(data?.data) ? data.data : []
}

const openHandleDialog = async (task) => {
  handlingTask.value = task
  exceptionHandleForm.value = {
    action: 'continue',
    handle_note: '',
    reassign_vehicle_id: null,
  }
  exceptionHandleDialogVisible.value = true
  await loadVehicles()
}

const submitHandleException = async () => {
  if (!handlingTask.value?.id) return
  if (exceptionHandleForm.value.action === 'reassign' && !exceptionHandleForm.value.reassign_vehicle_id) {
    ElMessage.warning('请选择改派车辆')
    return
  }

  handlingException.value = true
  try {
    await api.post('/dispatch-task/exception-handle', {
      task_id: handlingTask.value.id,
      action: exceptionHandleForm.value.action,
      handle_note: exceptionHandleForm.value.handle_note || null,
      reassign_vehicle_id: exceptionHandleForm.value.action === 'reassign'
        ? exceptionHandleForm.value.reassign_vehicle_id
        : null,
    })
    ElMessage.success('异常处理完成')
    exceptionHandleDialogVisible.value = false
    await loadExceptionTasks()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '异常处理失败')
  } finally {
    handlingException.value = false
  }
}

onMounted(async () => {
  await loadExceptionTasks()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <div class="card-title">异常任务管理</div>
        <el-button type="primary" plain @click="loadExceptionTasks">刷新异常</el-button>
      </div>
    </template>
    <el-table :data="exceptionTasks" stripe v-loading="loadingExceptions">
      <el-table-column prop="task_no" label="任务编号" min-width="180" />
      <el-table-column label="当前状态" min-width="110">
        <template #default="{ row }">
          {{ getLabel(taskStatusLabelMap, row.status) }}
        </template>
      </el-table-column>
      <el-table-column label="司机" min-width="160">
        <template #default="{ row }">
          {{ row.driver?.name || '-' }}（{{ row.driver?.account || '-' }}）
        </template>
      </el-table-column>
      <el-table-column label="车辆" min-width="160">
        <template #default="{ row }">
          {{ row.vehicle?.plate_number || '-' }} {{ row.vehicle?.name || '' }}
        </template>
      </el-table-column>
      <el-table-column label="异常类型" min-width="120">
        <template #default="{ row }">
          {{ getLabel(exceptionTypeLabelMap, row.route_meta?.exception?.type) }}
        </template>
      </el-table-column>
      <el-table-column label="异常说明" min-width="260">
        <template #default="{ row }">
          {{ row.route_meta?.exception?.description || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="上报时间" min-width="180">
        <template #default="{ row }">
          {{ row.route_meta?.exception?.reported_at || '-' }}
        </template>
      </el-table-column>
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openHandleDialog(row)">处理</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog
    v-model="exceptionHandleDialogVisible"
    title="处理任务异常"
    width="620px"
    destroy-on-close
  >
    <el-form label-width="90px">
      <el-form-item label="任务编号">
        <span>{{ handlingTask?.task_no || '-' }}</span>
      </el-form-item>
      <el-form-item label="处理动作">
        <el-radio-group v-model="exceptionHandleForm.action">
          <el-radio value="continue">继续执行</el-radio>
          <el-radio value="cancel">取消任务</el-radio>
          <el-radio value="reassign">改派车辆</el-radio>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="exceptionHandleForm.action === 'reassign'" label="目标车辆">
        <el-select
          v-model="exceptionHandleForm.reassign_vehicle_id"
          style="width: 100%"
          placeholder="请选择空闲车辆"
        >
          <el-option
            v-for="vehicle in vehicles.filter((item) => item.status === 'idle')"
            :key="vehicle.id"
            :label="`${vehicle.plate_number}｜${vehicle.name}`"
            :value="vehicle.id"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="处理备注">
        <el-input
          v-model="exceptionHandleForm.handle_note"
          type="textarea"
          :rows="3"
          maxlength="500"
          show-word-limit
          placeholder="可选，建议记录处理原因"
        />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="exceptionHandleDialogVisible = false">取消</el-button>
      <el-button type="primary" :loading="handlingException" @click="submitHandleException">确认处理</el-button>
    </template>
  </el-dialog>
</template>
