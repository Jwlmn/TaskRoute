<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { hasPermission, readCurrentUser } from '../../utils/auth'

const route = useRoute()
const router = useRouter()

const user = computed(() => readCurrentUser())

const menuItems = computed(() => {
  const list = [
    { label: '首页概览', routeName: 'dashboard-home', permission: 'dashboard' },
    { label: '调度工作台', routeName: 'dispatch-workbench', permission: 'dispatch' },
    { label: '移动任务中心', routeName: 'mobile-task-center', permission: 'mobile_tasks' },
    { label: '账号管理', routeName: 'user-management', permission: 'users' },
  ]
  return list.filter((item) => hasPermission(user.value, item.permission))
})

const activeMenu = computed(() => route.name)

const goMenu = async (name) => {
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
  <el-container class="portal-shell">
    <el-header class="portal-header">
      <div class="portal-brand">TaskRoute 统一调度门户</div>
      <div class="portal-actions">
        <el-tag type="primary">{{ user?.role || 'guest' }}</el-tag>
        <el-tag type="info">{{ user?.name || '未登录' }}</el-tag>
        <el-button type="danger" plain @click="logout">退出登录</el-button>
      </div>
    </el-header>

    <el-container>
      <el-aside class="portal-aside">
        <el-menu :default-active="activeMenu" @select="goMenu">
          <el-menu-item
            v-for="item in menuItems"
            :key="item.routeName"
            :index="item.routeName"
          >
            {{ item.label }}
          </el-menu-item>
        </el-menu>
      </el-aside>

      <el-main class="portal-main">
        <router-view />
      </el-main>
    </el-container>
  </el-container>
</template>

