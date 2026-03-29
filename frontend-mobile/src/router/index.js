import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import MobileLayoutView from '../views/mobile/MobileLayoutView.vue'
import MobileHomeView from '../views/mobile/MobileHomeView.vue'
import MobileTaskListView from '../views/mobile/MobileTaskListView.vue'
import MobileTaskDetailView from '../views/mobile/MobileTaskDetailView.vue'
import MobileAccountView from '../views/mobile/MobileAccountView.vue'
import MobileMessageCenterView from '../views/mobile/MobileMessageCenterView.vue'
import api from '../services/api'
import { ensureAuthSession, hasPermission, readCurrentUser, readAuthToken } from '../utils/auth'
import { createAuthGuard } from './guard'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { guestOnly: true },
    },
    {
      path: '/',
      component: MobileLayoutView,
      meta: { requiresAuth: true },
      children: [
        { path: '', name: 'mobile-home', component: MobileHomeView, meta: { permission: 'dashboard' } },
        { path: 'tasks', name: 'mobile-tasks', component: MobileTaskListView, meta: { permission: 'mobile_tasks' } },
        { path: 'tasks/:id', name: 'mobile-task-detail', component: MobileTaskDetailView, meta: { permission: 'mobile_tasks' } },
        { path: 'messages', name: 'mobile-messages', component: MobileMessageCenterView, meta: { permission: 'notifications' } },
        { path: 'account', name: 'mobile-account', component: MobileAccountView, meta: { permission: 'dashboard' } },
      ],
    },
  ],
})

router.beforeEach(
  createAuthGuard({
    apiClient: api,
    ensureAuthSessionFn: ensureAuthSession,
    hasPermissionFn: hasPermission,
    readAuthTokenFn: readAuthToken,
    readCurrentUserFn: readCurrentUser,
  })
)

export default router
