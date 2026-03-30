<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import api from '../../services/api'
import { readCurrentUser } from '../../utils/auth'
import { filterTasksByDataScope } from '../../utils/dataScope'

const router = useRouter()
const user = computed(() => readCurrentUser())
const loading = ref(false)
const generatedAt = ref('')
const driverTasks = ref([])
const driverMessages = ref([])
const stats = ref([
  { label: '待接单', value: 0 },
  { label: '执行中', value: 0 },
  { label: '已完成', value: 0 },
])

const normalizeTaskStatusGroup = (status) => {
  if (status === 'assigned') return 'assigned'
  if (status === 'accepted' || status === 'in_progress') return 'in_progress'
  if (status === 'completed' || status === 'cancelled') return 'completed'
  return 'assigned'
}

const buildDriverStats = (tasks) => {
  const map = {
    assigned: 0,
    in_progress: 0,
    completed: 0,
  }
  for (const task of tasks) {
    const group = normalizeTaskStatusGroup(task?.status)
    map[group] += 1
  }
  return [
    { label: '待接单', value: map.assigned },
    { label: '执行中', value: map.in_progress },
    { label: '已完成', value: map.completed },
  ]
}
const pendingAcceptTask = computed(() => driverTasks.value.find((task) => task?.status === 'assigned') || null)
const activeTask = computed(() => driverTasks.value.find((task) => ['accepted', 'in_progress'].includes(task?.status)) || null)
const recentHandledExceptionTask = computed(() => driverTasks.value
  .filter((task) => task?.route_meta?.exception?.status === 'handled')
  .sort((a, b) => String(b?.route_meta?.exception?.handled_at || '').localeCompare(String(a?.route_meta?.exception?.handled_at || '')))
  [0] || null)
const unreadMessageCount = computed(() => driverMessages.value.filter((item) => !item?.read_at).length)
const recentUnreadDispatchMessage = computed(() => driverMessages.value
  .filter((item) => item?.message_type === 'dispatch_notice' && !item?.read_at)
  .sort((a, b) => String(b?.created_at || '').localeCompare(String(a?.created_at || '')))
  [0] || null)
const driverHomeShortcuts = computed(() => {
  if (user.value?.role !== 'driver') return []

  const items = [
    {
      key: 'assigned',
      title: '待接单',
      description: pendingAcceptTask.value
        ? `当前优先任务：${pendingAcceptTask.value.task_no}`
        : '当前没有待接单任务',
      count: stats.value.find((item) => item.label === '待接单')?.value || 0,
      type: pendingAcceptTask.value ? 'warning' : 'info',
      actionLabel: '去待接单',
      disabled: !pendingAcceptTask.value,
      action: () => router.push({ name: 'mobile-tasks', query: { status_group: 'assigned' } }),
    },
    {
      key: 'active',
      title: '执行中',
      description: activeTask.value
        ? `继续处理任务：${activeTask.value.task_no}`
        : '当前没有执行中的任务',
      count: stats.value.find((item) => item.label === '执行中')?.value || 0,
      type: activeTask.value ? 'primary' : 'info',
      actionLabel: activeTask.value ? '查看当前任务' : '查看任务列表',
      disabled: false,
      action: () => (activeTask.value
        ? router.push({ name: 'mobile-task-detail', params: { id: activeTask.value.id } })
        : router.push({ name: 'mobile-tasks', query: { status_group: 'in_progress' } })),
    },
    {
      key: 'handled-exception',
      title: '异常处理结果',
      description: recentHandledExceptionTask.value
        ? `最近处理：${recentHandledExceptionTask.value.task_no}｜${recentHandledExceptionTask.value.route_meta?.exception?.handle_note || '请查看处理结果'}`
        : '当前没有新的异常处理结果',
      count: recentHandledExceptionTask.value ? 1 : 0,
      type: recentHandledExceptionTask.value ? 'success' : 'info',
      actionLabel: recentHandledExceptionTask.value ? '查看处理结果' : '查看任务列表',
      disabled: false,
      action: () => (recentHandledExceptionTask.value
        ? router.push({ name: 'mobile-task-detail', params: { id: recentHandledExceptionTask.value.id } })
        : router.push({ name: 'mobile-tasks' })),
    },
    {
      key: 'messages',
      title: '消息提醒',
      description: recentUnreadDispatchMessage.value
        ? `最新未读：${recentUnreadDispatchMessage.value.title || '调度通知'}`
        : '当前没有新的未读通知',
      count: unreadMessageCount.value,
      type: unreadMessageCount.value > 0 ? 'warning' : 'info',
      actionLabel: unreadMessageCount.value > 0 ? '查看消息' : '打开消息中心',
      disabled: false,
      action: () => router.push({ name: 'mobile-messages' }),
    },
  ]

  return items
})
const jumpToStat = async (label) => {
  if (user.value?.role !== 'driver') return
  if (label === '待接单') {
    await router.push({ name: 'mobile-tasks', query: { status_group: 'assigned' } })
    return
  }
  if (label === '执行中') {
    await router.push({ name: 'mobile-tasks', query: { status_group: 'in_progress' } })
    return
  }
  await router.push({ name: 'mobile-tasks', query: { status_group: 'completed' } })
}

const fetchHomeStats = async () => {
  loading.value = true
  try {
    if (user.value?.role === 'driver') {
      const [{ data }, messageResponse] = await Promise.all([
        api.post('/dispatch-task/list', {}),
        api.post('/message/list', {
          page: 1,
          per_page: 20,
          read_status: 'all',
        }),
      ])
      const tasks = filterTasksByDataScope(
        user.value,
        Array.isArray(data?.data) ? data.data : [],
      )
      driverMessages.value = Array.isArray(messageResponse?.data?.data) ? messageResponse.data.data : []
      driverTasks.value = tasks
      stats.value = buildDriverStats(tasks)
      generatedAt.value = new Date().toLocaleString('zh-CN', { hour12: false })
      return
    }

    if (user.value?.role === 'customer') {
      const { data } = await api.post('/pre-plan-order/customer-list', {})
      const list = Array.isArray(data?.data) ? data.data : []
      const counters = {
        pending_approval: 0,
        approved: 0,
        rejected: 0,
      }
      for (const item of list) {
        const key = item?.audit_status || 'pending_approval'
        if (counters[key] !== undefined) counters[key] += 1
      }
      driverMessages.value = []
      stats.value = [
        { label: '待审核', value: counters.pending_approval },
        { label: '已通过', value: counters.approved },
        { label: '已驳回', value: counters.rejected },
      ]
      generatedAt.value = new Date().toLocaleString('zh-CN', { hour12: false })
      return
    }

    const { data } = await api.post('/dashboard/overview', {})
    driverTasks.value = []
    driverMessages.value = []
    stats.value = [
      { label: '待调度', value: data?.metrics?.pending_pre_plan_orders || 0 },
      { label: '待接单', value: data?.metrics?.assigned_tasks || 0 },
      { label: '执行中', value: data?.metrics?.in_progress_tasks || 0 },
    ]
    generatedAt.value = data?.generated_at || ''
  } catch (error) {
    ElMessage.error(error?.response?.data?.message || '首页数据加载失败')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchHomeStats()
})
</script>

<template>
  <div>
    <el-card shadow="never" class="mb-12">
      <div class="mobile-user-name">{{ user?.name || '未登录' }}</div>
      <div class="mobile-user-role">角色：{{ user?.role || '-' }}</div>
      <div class="mobile-user-role">数据更新时间：{{ generatedAt || '-' }}</div>
    </el-card>

    <el-row :gutter="10">
      <el-col v-for="item in stats" :key="item.label" :span="8">
        <el-card shadow="hover" v-loading="loading" class="order-tag-clickable" @click="jumpToStat(item.label)">
          <div class="mobile-stat-label">{{ item.label }}</div>
          <div class="mobile-stat-value">{{ item.value }}</div>
        </el-card>
      </el-col>
    </el-row>
    <el-card v-if="driverHomeShortcuts.length" shadow="never" class="mt-12">
      <template #header>
        <div class="mobile-section-title">司机任务提醒</div>
      </template>
      <div
        v-for="item in driverHomeShortcuts"
        :key="item.key"
        class="mb-12"
      >
        <el-alert
          :closable="false"
          show-icon
          :type="item.type"
          :title="`${item.title}（${item.count}）`"
          :description="item.description"
        />
        <div class="mt-8">
          <el-button size="small" type="primary" :disabled="item.disabled" @click="item.action()">
            {{ item.actionLabel }}
          </el-button>
        </div>
      </div>
    </el-card>
    <el-button class="mt-12" plain size="small" :loading="loading" @click="fetchHomeStats">刷新数据</el-button>
  </div>
</template>
