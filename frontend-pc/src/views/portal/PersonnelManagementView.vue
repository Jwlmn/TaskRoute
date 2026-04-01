<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { readCurrentUser } from '../../utils/auth'
import { getLabel, roleLabelMap, userStatusLabelMap } from '../../utils/labels'

const loading = ref(false)
const rows = ref([])
const sites = ref([])
const currentPage = ref(1)
const pageSize = ref(10)
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
  permissions: [],
  data_scope_type: 'all',
  region_codes: [],
  site_ids: [],
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

const regionOptions = computed(() => {
  const map = new Map()
  for (const item of sites.value) {
    if (!item?.region_code) continue
    map.set(item.region_code, item.region_code)
  }
  return Array.from(map.keys()).map((value) => ({ label: value, value }))
})

const siteOptions = computed(() =>
  sites.value.map((item) => ({
    label: `${item.name} (${item.region_code || '-'})`,
    value: Number(item.id),
  })),
)
const pagedRows = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return rows.value.slice(start, start + pageSize.value)
})
const total = computed(() => rows.value.length)

const buildScopePayload = () => {
  if (form.data_scope_type === 'region') {
    return { data_scope_type: 'region', data_scope: { region_codes: form.region_codes } }
  }
  if (form.data_scope_type === 'site') {
    return { data_scope_type: 'site', data_scope: { site_ids: form.site_ids } }
  }
  return { data_scope_type: 'all', data_scope: null }
}

const dataScopeLabel = (row) => {
  if (row.data_scope_type === 'region') {
    const codes = Array.isArray(row.data_scope?.region_codes) ? row.data_scope.region_codes : []
    return codes.length ? `区域：${codes.join('、')}` : '区域：未配置'
  }
  if (row.data_scope_type === 'site') {
    const ids = Array.isArray(row.data_scope?.site_ids) ? row.data_scope.site_ids : []
    const labels = ids.map((id) => siteOptions.value.find((item) => item.value === Number(id))?.label || `站点#${id}`)
    return labels.length ? `站点：${labels.join('、')}` : '站点：未配置'
  }
  return '全部数据'
}

const resetForm = () => {
  form.id = null
  form.account = ''
  form.name = ''
  form.role = 'driver'
  form.status = 'active'
  form.phone = ''
  form.permissions = []
  form.data_scope_type = 'all'
  form.region_codes = []
  form.site_ids = []
  form.password = ''
}

const fetchRows = async () => {
  loading.value = true
  try {
    const { data } = await api.post('/resource/personnel/list', {})
    rows.value = data.data || []
    const maxPage = Math.max(1, Math.ceil(rows.value.length / pageSize.value))
    if (currentPage.value > maxPage) currentPage.value = maxPage
  } finally {
    loading.value = false
  }
}

const fetchSites = async () => {
  try {
    const { data } = await api.post('/resource/site/list', { status: 'active' })
    sites.value = Array.isArray(data?.data) ? data.data : []
  } catch {
    sites.value = []
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
  form.permissions = Array.isArray(row.permissions) ? row.permissions : []
  form.data_scope_type = row.data_scope_type || 'all'
  form.region_codes = Array.isArray(row.data_scope?.region_codes) ? [...row.data_scope.region_codes] : []
  form.site_ids = Array.isArray(row.data_scope?.site_ids) ? row.data_scope.site_ids.map((id) => Number(id)) : []
  form.password = ''
  dialogVisible.value = true
}

const submit = async () => {
  if (!isAdmin.value) return
  try {
    const scopePayload = buildScopePayload()
    if (dialogMode.value === 'create') {
      await api.post('/resource/personnel/create', {
        account: form.account,
        name: form.name,
        role: form.role,
        status: form.status,
        phone: form.phone,
        permissions: form.permissions,
        password: form.password,
        ...scopePayload,
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
        permissions: form.permissions,
        ...scopePayload,
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

onMounted(async () => {
  await Promise.all([fetchSites(), fetchRows()])
})
</script>

<template>
  <div class="page-content-shell">
  <el-card shadow="never" class="page-card">
    <template #header>
      <div class="table-header">
        <span class="card-title">人员与账号管理</span>
        <div>
          <el-tag v-if="!isAdmin" type="warning" class="mr-8">仅管理员可新增/编辑</el-tag>
          <el-button type="primary" :disabled="!isAdmin" @click="openCreate">新增人员</el-button>
        </div>
      </div>
    </template>

    <div class="page-table-section">
    <div class="page-table-wrap">
    <el-table :data="pagedRows" v-loading="loading" stripe height="100%" class="page-table">
      <el-table-column prop="account" label="账号" min-width="120" />
      <el-table-column prop="name" label="姓名" min-width="100" />
      <el-table-column label="角色" min-width="100">
        <template #default="{ row }">
          {{ getLabel(roleLabelMap, row.role) }}
        </template>
      </el-table-column>
      <el-table-column prop="phone" label="手机号" min-width="120" />
      <el-table-column label="数据范围" min-width="240" show-overflow-tooltip>
        <template #default="{ row }">
          {{ dataScopeLabel(row) }}
        </template>
      </el-table-column>
      <el-table-column label="状态" min-width="90">
        <template #default="{ row }">
          {{ getLabel(userStatusLabelMap, row.status) }}
        </template>
      </el-table-column>
      <el-table-column label="操作" min-width="110" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" :disabled="!isAdmin" @click="openEdit(row)">编辑</el-button>
        </template>
      </el-table-column>
    </el-table>
    </div>
    <div class="page-pagination">
      <el-pagination
        v-model:current-page="currentPage"
        v-model:page-size="pageSize"
        layout="prev, pager, next, total"
        :page-sizes="[10, 20, 50]"
        :total="total"
      />
    </div>
    </div>
  </el-card>
  </div>

  <el-dialog
    v-model="dialogVisible"
    :title="dialogMode === 'create' ? '新增人员' : '编辑人员'"
    width="560px"
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
      <el-form-item label="数据范围">
        <el-radio-group v-model="form.data_scope_type">
          <el-radio-button label="all">全部数据</el-radio-button>
          <el-radio-button label="region">按区域</el-radio-button>
          <el-radio-button label="site">按站点</el-radio-button>
        </el-radio-group>
      </el-form-item>
      <el-form-item v-if="form.data_scope_type === 'region'" label="可见区域">
        <el-select v-model="form.region_codes" multiple clearable collapse-tags style="width: 100%">
          <el-option v-for="item in regionOptions" :key="item.value" :label="item.label" :value="item.value" />
        </el-select>
      </el-form-item>
      <el-form-item v-if="form.data_scope_type === 'site'" label="可见站点">
        <el-select v-model="form.site_ids" multiple clearable collapse-tags style="width: 100%">
          <el-option v-for="item in siteOptions" :key="item.value" :label="item.label" :value="item.value" />
        </el-select>
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
