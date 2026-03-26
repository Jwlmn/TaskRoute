<script setup>
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'

const loading = ref(false)
const users = ref([])

const form = ref({
  account: '',
  name: '',
  role: 'driver',
  password: '',
  phone: '',
})

const fetchUsers = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/users')
    users.value = data.data || []
  } finally {
    loading.value = false
  }
}

const createUser = async () => {
  try {
    await api.post('/users', form.value)
    ElMessage.success('账号创建成功')
    form.value = { account: '', name: '', role: 'driver', password: '', phone: '' }
    await fetchUsers()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '创建失败')
  }
}

onMounted(() => {
  fetchUsers()
})
</script>

<template>
  <el-row :gutter="16">
    <el-col :xs="24" :lg="10">
      <el-card shadow="never">
        <template #header>
          <div class="card-title">管理员分配账号</div>
        </template>
        <el-form label-position="top">
          <el-form-item label="账号">
            <el-input v-model="form.account" />
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
          <el-form-item label="手机号">
            <el-input v-model="form.phone" />
          </el-form-item>
          <el-form-item label="密码">
            <el-input v-model="form.password" type="password" show-password />
          </el-form-item>
          <el-button type="primary" @click="createUser">创建账号</el-button>
        </el-form>
      </el-card>
    </el-col>
    <el-col :xs="24" :lg="14">
      <el-card shadow="never">
        <template #header>
          <div class="card-title">账号列表</div>
        </template>
        <el-table :data="users" v-loading="loading" stripe>
          <el-table-column prop="account" label="账号" min-width="120" />
          <el-table-column prop="name" label="姓名" min-width="100" />
          <el-table-column prop="role" label="角色" min-width="100" />
          <el-table-column prop="status" label="状态" min-width="90" />
        </el-table>
      </el-card>
    </el-col>
  </el-row>
</template>

