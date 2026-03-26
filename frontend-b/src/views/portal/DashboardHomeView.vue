<script setup>
const cards = [
  { label: '待调度计划单', value: 128 },
  { label: '执行中任务', value: 54 },
  { label: '在线司机', value: 83 },
  { label: '异常预警', value: 6 },
]
</script>

<template>
  <div>
    <el-row :gutter="16">
      <el-col v-for="card in cards" :key="card.label" :xs="12" :sm="12" :md="6">
        <el-card shadow="hover" class="metric-card">
          <div class="metric-label">{{ card.label }}</div>
          <div class="metric-value">{{ card.value }}</div>
        </el-card>
      </el-col>
    </el-row>
    <el-alert
      class="mt-16"
      title="系统已改为按权限分配功能，不再按 B/C 端区分入口。"
      type="info"
      show-icon
      :closable="false"
    />
  </div>
</template>

