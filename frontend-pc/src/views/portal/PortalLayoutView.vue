<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { hasPermission, readCurrentUser } from '../../utils/auth'

const route = useRoute()
const router = useRouter()

const user = computed(() => readCurrentUser())

const menuTree = computed(() => {
  const tree = [
    {
      index: 'ops-center',
      label: '运营中心',
      children: [
        { index: 'dashboard-home', label: '首页概览', permission: 'dashboard' },
        { index: 'pre-plan-order-management', label: '预计划单管理', permission: 'dispatch' },
        { index: 'settlement-management', label: '结算单管理', permission: 'settlement' },
        { index: 'dispatch-workbench', label: '调度任务管理', permission: 'dispatch' },
        { index: 'exception-task-management', label: '异常任务管理', permission: 'dispatch' },
        { index: 'mobile-task-center', label: '司机定位与轨迹回放', permission: 'mobile_tasks' },
        { index: 'order-audit-log', label: '操作审计查询', permission: 'audit_log' },
      ],
    },
    {
      index: 'resource-center',
      label: '资源维护',
      children: [
        { index: 'vehicle-management', label: '车辆管理', permission: 'resources' },
        { index: 'personnel-management', label: '人员管理', permission: 'resources' },
        { index: 'site-management', label: '站点管理', permission: 'resources' },
        { index: 'freight-template-management', label: '运费规则中心', permission: 'freight_templates' },
      ],
    },
    {
      index: 'system-center',
      label: '系统管理',
      children: [
        { index: 'user-management', label: '账号管理', permission: 'users' },
        { index: 'notification-center', label: '通知中心', permission: 'notifications' },
      ],
    },
    {
      index: 'customer-center',
      label: '客户协同',
      children: [
        { index: 'customer-pre-plan-order', label: '客户计划单', permission: 'customer_orders' },
        { index: 'notification-center', label: '通知中心', permission: 'notifications' },
      ],
    },
  ]

  const filterNodes = (nodes) => {
    return nodes
      .map((node) => {
        if (node.children) {
          const children = filterNodes(node.children)
          return { ...node, children }
        }
        return node
      })
      .filter((node) => {
        if (node.children) return node.children.length > 0
        return hasPermission(user.value, node.permission)
      })
  }

  return filterNodes(tree)
})

const activeMenu = computed(() => route.name)

const goMenu = async (index) => {
  if (!router.hasRoute(index)) return
  await router.push({ name: index })
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
        <el-menu
          :default-active="activeMenu"
          @select="goMenu"
        >
          <template v-for="item in menuTree" :key="item.index">
            <el-sub-menu :index="item.index">
              <template #title>{{ item.label }}</template>
              <el-menu-item v-for="child in item.children" :key="child.index" :index="child.index">
                {{ child.label }}
              </el-menu-item>
            </el-sub-menu>
          </template>
        </el-menu>
      </el-aside>

      <el-main class="portal-main">
        <router-view />
      </el-main>
    </el-container>
  </el-container>
</template>
