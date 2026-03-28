<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { getLabel, roleLabelMap, userStatusLabelMap } from '../../utils/labels'

const loading = ref(false)
const users = ref([])
const dialogVisible = ref(false)
const dialogMode = ref('create')

const form = reactive({
  id: null,
  account: '',
  name: '',
  role: 'driver',
  status: 'active',
  phone: '',
  permissions: [],
  password: '',
})

const permissionOptions = [
  { label: '首页概览', value: 'dashboard' },
  { label: '预计划/调度', value: 'dispatch' },
  { label: '资源管理', value: 'resources' },
  { label: '账号管理', value: 'users' },
  { label: '移动任务', value: 'mobile_tasks' },
  { label: '客户计划单', value: 'customer_orders' },
  { label: '运费规则', value: 'freight_templates' },
  { label: '结算单', value: 'settlement' },
  { label: '通知中心', value: 'notifications' },
  { label: '操作审计', value: 'audit_log' },
]

const resetForm = () => {
  form.id = null
  form.account = ''
  form.name = ''
  form.role = 'driver'
  form.status = 'active'
  form.phone = ''
  form.permissions = []
  form.password = ''
}

const fetchUsers = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/user/list', {})
    users.value = data.data || []
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
  form.account = row.account
  form.name = row.name
  form.role = row.role
  form.status = row.status
  form.phone = row.phone || ''
  form.permissions = Array.isArray(row.permissions) ? row.permissions : []
  form.password = ''
  dialogVisible.value = true
}

const submit = async () => {
  try {
    if (dialogMode.value === 'create') {
      await api.post('/user/create', {
        account: form.account,
        name: form.name,
        role: form.role,
        status: form.status,
        phone: form.phone,
        permissions: form.permissions,
        password: form.password,
      })
      ElMessage.success('账号创建成功')
    } else {
      const payload = {
        id: form.id,
        account: form.account,
        name: form.name,
        role: form.role,
        status: form.status,
        phone: form.phone,
        permissions: form.permissions,
      }
      if (form.password) {
        payload.password = form.password
      }
      await api.post('/user/update', payload)
      ElMessage.success('账号更新成功')
    }
    dialogVisible.value = false
    await fetchUsers()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '保存失败')
  }
}

onMounted(() => {
  fetchUsers()
})
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="table-header">
        <span class="card-title">账号管理</span>
        <el-button type="primary" @click="openCreate">新增账号</el-button>
      </div>
    </template>

    <el-table :data="users" v-loading="loading" stripe>
      <el-table-column prop="account" label="账号" min-width="120" />
      <el-table-column prop="name" label="姓名" min-width="100" />
      <el-table-column label="角色" min-width="100">
        <template #default="{ row }">
          {{ getLabel(roleLabelMap, row.role) }}
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="90">
        <template #default="{ row }">
          {{ getLabel(userStatusLabelMap, row.status) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="110" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
        </template>
      </el-table-column>
    </el-table>
  </el-card>

  <el-dialog
    v-model="dialogVisible"
    :title="dialogMode === 'create' ? '新增账号' : '编辑账号'"
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
          <el-option label="客户" value="customer" />
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
      <el-form-item label="附加权限">
        <el-select v-model="form.permissions" multiple clearable collapse-tags style="width: 100%">
          <el-option v-for="item in permissionOptions" :key="item.value" :label="item.label" :value="item.value" />
        </el-select>
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
