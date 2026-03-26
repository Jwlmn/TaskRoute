<script setup>
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { readCurrentUser } from '../../utils/auth'

const user = computed(() => readCurrentUser())
const isAdmin = computed(() => user.value?.role === 'admin')

const loading = ref(false)
const vehicles = ref([])
const personnel = ref([])
const sites = ref([])

const vehicleForm = ref({
  plate_number: '',
  name: '',
  vehicle_type: 'van',
  max_weight_kg: 0,
  max_volume_m3: 0,
})

const personnelForm = ref({
  account: '',
  name: '',
  role: 'driver',
  password: '',
  phone: '',
})

const siteForm = ref({
  name: '',
  site_type: 'both',
  address: '',
  contact_person: '',
  contact_phone: '',
})

const fetchAll = async () => {
  loading.value = true
  try {
    const [v, p, s] = await Promise.all([
      api.post('/resource/vehicle/list', {}),
      api.post('/resource/personnel/list', {}),
      api.post('/resource/site/list', {}),
    ])
    vehicles.value = v.data.data || []
    personnel.value = p.data.data || []
    sites.value = s.data.data || []
  } finally {
    loading.value = false
  }
}

const createVehicle = async () => {
  try {
    await api.post('/resource/vehicle/create', vehicleForm.value)
    ElMessage.success('车辆资源已创建')
    vehicleForm.value = { plate_number: '', name: '', vehicle_type: 'van', max_weight_kg: 0, max_volume_m3: 0 }
    await fetchAll()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '创建车辆失败')
  }
}

const createPersonnel = async () => {
  try {
    await api.post('/resource/personnel/create', personnelForm.value)
    ElMessage.success('人员资源已创建')
    personnelForm.value = { account: '', name: '', role: 'driver', password: '', phone: '' }
    await fetchAll()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '创建人员失败')
  }
}

const createSite = async () => {
  try {
    await api.post('/resource/site/create', siteForm.value)
    ElMessage.success('站点资源已创建')
    siteForm.value = { name: '', site_type: 'both', address: '', contact_person: '', contact_phone: '' }
    await fetchAll()
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '创建站点失败')
  }
}

onMounted(() => {
  fetchAll()
})
</script>

<template>
  <el-tabs type="border-card" v-loading="loading">
    <el-tab-pane label="车辆资源">
      <el-row :gutter="16">
        <el-col :span="9">
          <el-card shadow="never">
            <template #header><div class="card-title">新增车辆</div></template>
            <el-form label-position="top">
              <el-form-item label="车牌号"><el-input v-model="vehicleForm.plate_number" /></el-form-item>
              <el-form-item label="车辆名称"><el-input v-model="vehicleForm.name" /></el-form-item>
              <el-form-item label="车型"><el-input v-model="vehicleForm.vehicle_type" /></el-form-item>
              <el-form-item label="最大载重(kg)"><el-input-number v-model="vehicleForm.max_weight_kg" :min="0" /></el-form-item>
              <el-form-item label="最大容积(m3)"><el-input-number v-model="vehicleForm.max_volume_m3" :min="0" /></el-form-item>
              <el-button type="primary" @click="createVehicle">创建车辆</el-button>
            </el-form>
          </el-card>
        </el-col>
        <el-col :span="15">
          <el-card shadow="never">
            <template #header><div class="card-title">车辆列表</div></template>
            <el-table :data="vehicles" stripe>
              <el-table-column prop="plate_number" label="车牌" min-width="110" />
              <el-table-column prop="name" label="名称" min-width="120" />
              <el-table-column prop="vehicle_type" label="车型" min-width="100" />
              <el-table-column prop="status" label="状态" min-width="90" />
            </el-table>
          </el-card>
        </el-col>
      </el-row>
    </el-tab-pane>

    <el-tab-pane label="人员资源">
      <el-row :gutter="16">
        <el-col :span="9">
          <el-card shadow="never">
            <template #header><div class="card-title">新增人员</div></template>
            <el-form label-position="top">
              <el-alert
                v-if="!isAdmin"
                title="仅管理员可新增人员账号"
                type="warning"
                show-icon
                :closable="false"
                class="mb-12"
              />
              <el-form-item label="账号"><el-input v-model="personnelForm.account" :disabled="!isAdmin" /></el-form-item>
              <el-form-item label="姓名"><el-input v-model="personnelForm.name" :disabled="!isAdmin" /></el-form-item>
              <el-form-item label="角色">
                <el-select v-model="personnelForm.role" :disabled="!isAdmin" style="width: 100%">
                  <el-option label="管理员" value="admin" />
                  <el-option label="调度员" value="dispatcher" />
                  <el-option label="司机" value="driver" />
                </el-select>
              </el-form-item>
              <el-form-item label="手机号"><el-input v-model="personnelForm.phone" :disabled="!isAdmin" /></el-form-item>
              <el-form-item label="密码"><el-input v-model="personnelForm.password" :disabled="!isAdmin" type="password" /></el-form-item>
              <el-button type="primary" :disabled="!isAdmin" @click="createPersonnel">创建人员</el-button>
            </el-form>
          </el-card>
        </el-col>
        <el-col :span="15">
          <el-card shadow="never">
            <template #header><div class="card-title">人员列表</div></template>
            <el-table :data="personnel" stripe>
              <el-table-column prop="account" label="账号" min-width="100" />
              <el-table-column prop="name" label="姓名" min-width="100" />
              <el-table-column prop="role" label="角色" min-width="100" />
              <el-table-column prop="status" label="状态" min-width="90" />
            </el-table>
          </el-card>
        </el-col>
      </el-row>
    </el-tab-pane>

    <el-tab-pane label="站点资源">
      <el-row :gutter="16">
        <el-col :span="9">
          <el-card shadow="never">
            <template #header><div class="card-title">新增站点</div></template>
            <el-form label-position="top">
              <el-form-item label="站点名称"><el-input v-model="siteForm.name" /></el-form-item>
              <el-form-item label="站点类型">
                <el-select v-model="siteForm.site_type" style="width: 100%">
                  <el-option label="提货点" value="pickup" />
                  <el-option label="收货点" value="dropoff" />
                  <el-option label="提货+收货" value="both" />
                </el-select>
              </el-form-item>
              <el-form-item label="地址"><el-input v-model="siteForm.address" /></el-form-item>
              <el-form-item label="联系人"><el-input v-model="siteForm.contact_person" /></el-form-item>
              <el-form-item label="联系电话"><el-input v-model="siteForm.contact_phone" /></el-form-item>
              <el-button type="primary" @click="createSite">创建站点</el-button>
            </el-form>
          </el-card>
        </el-col>
        <el-col :span="15">
          <el-card shadow="never">
            <template #header><div class="card-title">站点列表</div></template>
            <el-table :data="sites" stripe>
              <el-table-column prop="site_no" label="站点编号" min-width="140" />
              <el-table-column prop="name" label="站点名称" min-width="120" />
              <el-table-column prop="site_type" label="类型" min-width="100" />
              <el-table-column prop="address" label="地址" min-width="180" />
            </el-table>
          </el-card>
        </el-col>
      </el-row>
    </el-tab-pane>
  </el-tabs>
</template>

