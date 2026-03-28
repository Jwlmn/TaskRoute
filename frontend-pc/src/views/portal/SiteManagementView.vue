<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { getLabel, siteTypeLabelMap, userStatusLabelMap } from '../../utils/labels'

const loading = ref(false)
const rows = ref([])
const dialogVisible = ref(false)
const dialogMode = ref('create')

const form = reactive({
  id: null,
  name: '',
  site_type: 'both',
  organization_code: 'SH',
  region_code: 'SH-PD',
  address: '',
  contact_person: '',
  contact_phone: '',
  status: 'active',
})

const resetForm = () => {
  form.id = null
  form.name = ''
  form.site_type = 'both'
  form.organization_code = 'SH'
  form.region_code = 'SH-PD'
  form.address = ''
  form.contact_person = ''
  form.contact_phone = ''
  form.status = 'active'
}

const fetchRows = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/resource/site/list', {})
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
  form.name = row.name
  form.site_type = row.site_type
  form.organization_code = row.organization_code || 'SH'
  form.region_code = row.region_code || 'SH-PD'
  form.address = row.address
  form.contact_person = row.contact_person || ''
  form.contact_phone = row.contact_phone || ''
  form.status = row.status
  dialogVisible.value = true
}

const submit = async () => {
  try {
    const payload = {
      name: form.name,
      site_type: form.site_type,
      organization_code: form.organization_code,
      region_code: form.region_code,
      address: form.address,
      contact_person: form.contact_person,
      contact_phone: form.contact_phone,
      status: form.status,
    }
    if (dialogMode.value === 'create') {
      await api.post('/resource/site/create', payload)
      ElMessage.success('站点创建成功')
    } else {
      await api.post('/resource/site/update', { id: form.id, ...payload })
      ElMessage.success('站点更新成功')
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
        <span class="card-title">站点资源管理（提货点/收货点）</span>
        <el-button type="primary" @click="openCreate">新增站点</el-button>
      </div>
    </template>

    <el-table :data="rows" v-loading="loading" stripe>
      <el-table-column prop="site_no" label="站点编号" min-width="140" />
      <el-table-column prop="name" label="站点名称" min-width="120" />
      <el-table-column label="站点类型" min-width="110">
        <template #default="{ row }">
          {{ getLabel(siteTypeLabelMap, row.site_type) }}
        </template>
      </el-table-column>
      <el-table-column prop="region_code" label="区域编码" min-width="110" />
      <el-table-column prop="contact_person" label="联系人" min-width="100" />
      <el-table-column prop="contact_phone" label="联系电话" min-width="120" />
      <el-table-column prop="address" label="地址" min-width="200" />
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
    :title="dialogMode === 'create' ? '新增站点' : '编辑站点'"
    width="560px"
  >
    <el-form label-position="top">
      <el-form-item label="站点名称">
        <el-input v-model="form.name" />
      </el-form-item>
      <el-form-item label="站点类型">
        <el-select v-model="form.site_type" style="width: 100%">
          <el-option label="提货点" value="pickup" />
          <el-option label="收货点" value="dropoff" />
          <el-option label="提货+收货" value="both" />
        </el-select>
      </el-form-item>
      <el-form-item label="组织编码">
        <el-input v-model="form.organization_code" />
      </el-form-item>
      <el-form-item label="区域编码">
        <el-input v-model="form.region_code" placeholder="例如 SH-PD" />
      </el-form-item>
      <el-form-item label="地址">
        <el-input v-model="form.address" />
      </el-form-item>
      <el-form-item label="联系人">
        <el-input v-model="form.contact_person" />
      </el-form-item>
      <el-form-item label="联系电话">
        <el-input v-model="form.contact_phone" />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="form.status" style="width: 100%">
          <el-option label="启用" value="active" />
          <el-option label="停用" value="inactive" />
        </el-select>
      </el-form-item>
    </el-form>
    <template #footer>
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="primary" @click="submit">保存</el-button>
    </template>
  </el-dialog>
</template>
