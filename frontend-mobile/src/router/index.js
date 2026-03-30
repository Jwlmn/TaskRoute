import { createRouter, createWebHistory } from 'vue-router'
import api from '../services/api'
import { ensureAuthSession, hasPermission, readCurrentUser, readAuthToken } from '../utils/auth'
import { createAuthGuard } from './guard'

const LoginView = () => import('../views/LoginView.vue')
const MobileLayoutView = () => import('../views/mobile/MobileLayoutView.vue')
const MobileHomeView = () => import('../views/mobile/MobileHomeView.vue')
const MobileTaskListView = () => import('../views/mobile/MobileTaskListView.vue')
const MobileTaskDetailView = () => import('../views/mobile/MobileTaskDetailView.vue')
const MobileAccountView = () => import('../views/mobile/MobileAccountView.vue')
const MobileMessageCenterView = () => import('../views/mobile/MobileMessageCenterView.vue')

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
