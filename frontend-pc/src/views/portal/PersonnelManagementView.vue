<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { readCurrentUser } from '../../utils/auth'

const loading = ref(false)
const rows = ref([])
const dialogVisible = ref(false)
const dialogMode = ref('create')
const user = computed(() => readCurrentUser())
const isAdmin = computed(() => user.value?.role === 'admin')

const form = reactive({
  id: null,
  account: '',
  name: '',
  role: 'driver',
  status: 'active',
  phone: '',
  password: '',
})

const resetForm = () => {
  form.id = null
  form.account = ''
  form.name = ''
  form.role = 'driver'
  form.status = 'active'
  form.phone = ''
  form.password = ''
}

const fetchRows = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/resource/personnel/list', {})
    rows.value = data.data || []
  } finally {
    loading.value = false
  }
}

const openCreate = () => {
  if (!isAdmin.value) return
  dialogMode.value = 'create'
  resetForm()
  dialogVisible.value = true
}

const openEdit = (row) => {
  if (!isAdmin.value) return
  dialogMode.value = 'edit'
  form.id = row.id
  form.account = row.account
  form.name = row.name
  form.role = row.role
  form.status = row.status
  form.phone = row.phone || ''
  form.password = ''
  dialogVisible.value = true
}

const submit = async () => {
  if (!isAdmin.value) return
  try {
    if (dialogMode.value === 'create') {
      await api.post('/resource/personnel/create', {
        account: form.account,
        name: form.name,
        role: form.role,
        status: form.status,
        phone: form.phone,
        password: form.password,
      })
      ElMessage.success('人员创建成功')
    } else {
      const payload = {
        id: form.id,
        account: form.account,
        name: form.name,
        role: form.role,
        status: form.status,
        phone: form.phone,
      }
      if (form.password) payload.password = form.password
      await api.post('/resource/personnel/update', payload)
      ElMessage.success('人员更新成功')
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
        <span class="card-title">人员资源管理</span>
        <div>
          <el-tag v-if="!isAdmin" type="warning" class="mr-8">仅管理员可新增/编辑</el-tag>
          <el-button type="primary" :disabled="!isAdmin" @click="openCreate">新增人员</el-button>
        </div>
      </div>
    </template>

    <el-table :data="rows" v-loading="loading" stripe>
      <el-table-column prop="account" label="账号" min-width="120" />
      <el-table-column prop="name" label="姓名" min-width="100" />
      <el-table-column prop="role" label="角色" min-width="100" />
      <el-table-column prop="phone" label="手机号" min-width="120" />
      <el-table-column prop="status" label="状态" min-width="90" />
      <el-table-column label="操作" min-width="110" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" :disabled="!isAdmin" @click="openEdit(row)">编辑</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog
    v-model="dialogVisible"
    :title="dialogMode === 'create' ? '新增人员' : '编辑人员'"
    width="520px"
  >
    <el-form label-position="top">
      <el-form-item label="账号">
        <el-input v-model="form.account" :disabled="dialogMode === 'edit'" />
      </el-form-item>
      <el-form-item label="姓名">
        <el-input v-model="form.name" />
      </el-form-item>
      <el-form-item label="角色">
        <el-select v-model="form.role" style="width: 100%">
          <el-option label="管理员" value="admin" />
          <el-option label="调度员" value="dispatcher" />
          <el-option label="司机" value="driver" />
        </el-select>
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="form.status" style="width: 100%">
          <el-option label="启用" value="active" />
          <el-option label="停用" value="inactive" />
        </el-select>
      </el-form-item>
      <el-form-item label="手机号">
        <el-input v-model="form.phone" />
      </el-form-item>
      <el-form-item :label="dialogMode === 'create' ? '登录密码' : '重置密码（留空不改）'">
        <el-input v-model="form.password" type="password" show-password />
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" @click="submit">保存</el-button>
    </template>
  </el-dialog>
</template>

