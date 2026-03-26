<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { hasPermission, readCurrentUser } from '../../utils/auth'

const route = useRoute()
const router = useRouter()
const user = computed(() => readCurrentUser())

const tabs = computed(() => {
  const list = [
    { label: '首页', name: 'mobile-home', permission: 'dashboard' },
    { label: '任务', name: 'mobile-tasks', permission: 'mobile_tasks' },
    { label: '账号', name: 'mobile-account', permission: 'dashboard' },
  ]
  return list.filter((item) => hasPermission(user.value, item.permission))
})

const activeTab = computed(() => {
  if (route.name === 'mobile-task-detail') return 'mobile-tasks'
  return route.name
})

const switchTab = async (name) => {
  await router.push({ name })
}

const logout = async () => {
  try {
    await api.post('/auth/logout')
  } catch {
    // ignore
  } finally {
    localStorage.removeItem('taskroute_token')
    localStorage.removeItem('taskroute_user')
    ElMessage.success('已退出登录')
    await router.push({ name: 'login' })
  }
}
</script>

<template>
  <div class="mobile-shell">
    <header class="mobile-header">
      <div class="mobile-title">TaskRoute 移动端</div>
      <el-button type="danger" size="small" plain @click="logout">退出</el-button>
    </header>

    <main class="mobile-main">
      <router-view />
    </main>

    <footer class="mobile-footer">
      <el-segmented
        :options="tabs.map((t) => ({ label: t.label, value: t.name }))"
        :model-value="activeTab"
        @change="switchTab"
      />
    </footer>
  </div>
</template>
