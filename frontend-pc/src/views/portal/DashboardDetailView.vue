<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import api from '../../services/api'

const loading = ref(false)
const errorMessage = ref('')
const overview = ref({
  site_stats: [],
  generated_at: '',
})

const siteStats = computed(() => (Array.isArray(overview.value.site_stats) ? overview.value.site_stats : []))
const filters = ref({
  keyword: '',
  regionCode: '',
})
const currentPage = ref(1)
const pageSize = ref(10)

const regionOptions = computed(() => {
  return Array.from(new Set(siteStats.value.map((item) => String(item.region_code || '').trim()).filter(Boolean)))
})

const filteredSiteStats = computed(() => {
  const keyword = filters.value.keyword.trim().toLowerCase()
  const regionCode = (filters.value.regionCode ?? '').trim()

  return siteStats.value.filter((item) => {
    const siteName = String(item.site_name || '').toLowerCase()
    const region = String(item.region_code || '')
    const keywordMatched = keyword === '' || siteName.includes(keyword) || region.toLowerCase().includes(keyword)
    const regionMatched = !regionCode || region === regionCode
    return keywordMatched && regionMatched
  })
})

const pagedSiteStats = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  const end = start + pageSize.value
  return filteredSiteStats.value.slice(start, end)
})

watch([filteredSiteStats, pageSize], () => {
  const total = filteredSiteStats.value.length
  const maxPage = Math.max(1, Math.ceil(total / pageSize.value))
  if (currentPage.value > maxPage) {
    currentPage.value = maxPage
  }
})

const handleSearch = () => {
  currentPage.value = 1
}

const handleReset = () => {
  filters.value.keyword = ''
  filters.value.regionCode = ''
  currentPage.value = 1
}

const fetchOverview = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const { data } = await api.post('/dashboard/overview', {})
    overview.value = {
      ...overview.value,
      ...data,
    }
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || '运营明细加载失败，请稍后重试'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchOverview()
})
</script>

<template>
  <div class="page-content-shell">
    <el-alert
      v-if="errorMessage"
      class="mb-12"
      :title="errorMessage"
      type="error"
      show-icon
      :closable="false"
    />

    <el-card shadow="never" class="page-card">
      <template #header>
        <div class="table-header">
          <div class="card-title">站点维度概览</div>
          <div class="table-header">
            <span class="text-secondary">数据更新时间：{{ overview.generated_at || '-' }}</span>
            <el-button plain size="small" :loading="loading" @click="fetchOverview">刷新数据</el-button>
          </div>
        </div>
      </template>
      <div class="table-header mb-12">
        <el-space wrap>
          <el-input v-model="filters.keyword" placeholder="站点/区域关键词" clearable style="width: 220px" />
          <el-select v-model="filters.regionCode" placeholder="区域编码" clearable style="width: 180px">
            <el-option v-for="code in regionOptions" :key="code" :label="code" :value="code" />
          </el-select>
          <el-button type="primary" @click="handleSearch">查询</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-space>
      </div>
      <div class="page-table-section">
        <div v-if="filteredSiteStats.length === 0" class="page-table-wrap">
          <el-empty description="暂无站点统计数据" />
        </div>
        <div v-else class="page-table-wrap">
          <el-table :data="pagedSiteStats" stripe height="100%" class="page-table">
            <el-table-column prop="site_name" label="站点" min-width="180" />
            <el-table-column prop="region_code" label="区域" min-width="110" />
            <el-table-column prop="pending_pre_plan_orders" label="待调度计划单" min-width="130" />
            <el-table-column prop="assigned_tasks" label="待接单任务" min-width="120" />
            <el-table-column prop="in_progress_tasks" label="执行中任务" min-width="120" />
            <el-table-column prop="busy_vehicles" label="忙碌车辆" min-width="100" />
          </el-table>
        </div>
        <div class="page-pagination">
          <el-pagination
            v-model:current-page="currentPage"
            v-model:page-size="pageSize"
            layout="sizes, prev, pager, next, jumper, total"
            :page-sizes="[10, 20, 50, 100]"
            :total="filteredSiteStats.length"
          />
        </div>
      </div>
    </el-card>
  </div>
</template>
