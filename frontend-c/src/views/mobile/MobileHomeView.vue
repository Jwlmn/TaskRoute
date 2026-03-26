<script setup>
import { computed } from 'vue'
import { readCurrentUser } from '../../utils/auth'

const user = computed(() => readCurrentUser())

const stats = [
  { label: '待执行', value: 15 },
  { label: '执行中', value: 8 },
  { label: '已完成', value: 42 },
]
</script>

<template>
  <div>
    <el-card shadow="never" class="mb-12">
      <div class="mobile-user-name">{{ user?.name || '未登录' }}</div>
      <div class="mobile-user-role">角色：{{ user?.role || '-' }}</div>
    </el-card>

    <el-row :gutter="10">
      <el-col v-for="item in stats" :key="item.label" :span="8">
        <el-card shadow="hover">
          <div class="mobile-stat-label">{{ item.label }}</div>
          <div class="mobile-stat-value">{{ item.value }}</div>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

