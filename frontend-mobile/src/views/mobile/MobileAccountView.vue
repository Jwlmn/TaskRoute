<script setup>
import { computed } from 'vue'
import { readCurrentUser } from '../../utils/auth'
import { formatDataScopeSummary } from '../../utils/dataScope'

const user = computed(() => readCurrentUser())
const dataScopeSummary = computed(() => formatDataScopeSummary(user.value))
</script>

<template>
  <el-card shadow="never">
    <template #header>
      <div class="mobile-section-title">账号信息</div>
    </template>
    <el-descriptions :column="1" border>
      <el-descriptions-item label="账号">{{ user?.account || '-' }}</el-descriptions-item>
      <el-descriptions-item label="姓名">{{ user?.name || '-' }}</el-descriptions-item>
      <el-descriptions-item label="角色">{{ user?.role || '-' }}</el-descriptions-item>
      <el-descriptions-item label="状态">{{ user?.status || '-' }}</el-descriptions-item>
      <el-descriptions-item label="数据范围">{{ dataScopeSummary }}</el-descriptions-item>
    </el-descriptions>
  </el-card>
</template>
