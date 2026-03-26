<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'

const loading = ref(false)
const rows = ref([])
const dialogVisible = ref(false)
const dialogMode = ref('create')

const form = reactive({
  id: null,
  plate_number: '',
  name: '',
  vehicle_type: 'van',
  max_weight_kg: 0,
  max_volume_m3: 0,
  status: 'idle',
})

const resetForm = () => {
  form.id = null
  form.plate_number = ''
  form.name = ''
  form.vehicle_type = 'van'
  form.max_weight_kg = 0
  form.max_volume_m3 = 0
  form.status = 'idle'
}

const fetchRows = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/resource/vehicle/list', {})
    rows.value = data.data || []
  } finally {
    loading.value = false
  }
}

const openCreate = () => {
  dialogMode.value = 'create'
  resetForm()
  dialogVisible.value = true
}

const openEdit = (row) => {
  dialogMode.value = 'edit'
  form.id = row.id
  form.plate_number = row.plate_number
  form.name = row.name
  form.vehicle_type = row.vehicle_type
  form.max_weight_kg = Number(row.max_weight_kg || 0)
  form.max_volume_m3 = Number(row.max_volume_m3 || 0)
  form.status = row.status
  dialogVisible.value = true
}

const submit = async () => {
  try {
    const payload = {
      plate_number: form.plate_number,
      name: form.name,
      vehicle_type: form.vehicle_type,
      max_weight_kg: form.max_weight_kg,
      max_volume_m3: form.max_volume_m3,
      status: form.status,
    }

    if (dialogMode.value === 'create') {
      await api.post('/resource/vehicle/create', payload)
      ElMessage.success('车辆创建成功')
    } else {
      await api.post('/resource/vehicle/update', { id: form.id, ...payload })
      ElMessage.success('车辆更新成功')
    }
    dialogVisible.value = false
    await fetchRows()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '保存失败')
  }
}

onMounted(() => {
  fetchRows()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <span class="card-title">车辆资源管理</span>
        <el-button type="primary" @click="openCreate">新增车辆</el-button>
      </div>
    </template>

    <el-table :data="rows" v-loading="loading" stripe>
      <el-table-column prop="plate_number" label="车牌号" min-width="120" />
      <el-table-column prop="name" label="车辆名称" min-width="130" />
      <el-table-column prop="vehicle_type" label="车型" min-width="100" />
      <el-table-column prop="max_weight_kg" label="载重(kg)" min-width="100" />
      <el-table-column prop="max_volume_m3" label="容积(m3)" min-width="100" />
      <el-table-column prop="status" label="状态" min-width="90" />
      <el-table-column label="操作" min-width="110" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog
    v-model="dialogVisible"
    :title="dialogMode === 'create' ? '新增车辆' : '编辑车辆'"
    width="560px"
  >
    <el-form label-position="top">
      <el-form-item label="车牌号">
        <el-input v-model="form.plate_number" :disabled="dialogMode === 'edit'" />
      </el-form-item>
      <el-form-item label="车辆名称">
        <el-input v-model="form.name" />
      </el-form-item>
      <el-form-item label="车型">
        <el-input v-model="form.vehicle_type" />
      </el-form-item>
      <el-form-item label="最大载重(kg)">
        <el-input-number v-model="form.max_weight_kg" :min="0" />
      </el-form-item>
      <el-form-item label="最大容积(m3)">
        <el-input-number v-model="form.max_volume_m3" :min="0" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="form.status" style="width: 100%">
          <el-option label="空闲" value="idle" />
          <el-option label="执行中" value="busy" />
          <el-option label="维护中" value="maintenance" />
        </el-select>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" @click="submit">保存</el-button>
    </template>
  </el-dialog>
</template>

