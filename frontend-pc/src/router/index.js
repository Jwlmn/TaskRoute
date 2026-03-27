import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import PortalLayoutView from '../views/portal/PortalLayoutView.vue'
import DashboardHomeView from '../views/portal/DashboardHomeView.vue'
import DispatchWorkbenchView from '../views/portal/DispatchWorkbenchView.vue'
import ExceptionTaskManagementView from '../views/portal/ExceptionTaskManagementView.vue'
import PrePlanOrderManagementView from '../views/portal/PrePlanOrderManagementView.vue'
import MobileTaskCenterView from '../views/portal/MobileTaskCenterView.vue'
import UserManagementView from '../views/portal/UserManagementView.vue'
import VehicleManagementView from '../views/portal/VehicleManagementView.vue'
import PersonnelManagementView from '../views/portal/PersonnelManagementView.vue'
import SiteManagementView from '../views/portal/SiteManagementView.vue'
import { hasPermission, readCurrentUser } from '../utils/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: {
        guestOnly: true,
      },
    },
    {
      path: '/',
      component: PortalLayoutView,
      meta: {
        requiresAuth: true,
      },
      children: [
        {
          path: '',
          name: 'dashboard-home',
          component: DashboardHomeView,
          meta: { permission: 'dashboard' },
        },
        {
          path: 'pre-plan-orders',
          name: 'pre-plan-order-management',
          component: PrePlanOrderManagementView,
          meta: { permission: 'dispatch' },
        },
        {
          path: 'dispatch',
          name: 'dispatch-workbench',
          component: DispatchWorkbenchView,
          meta: { permission: 'dispatch' },
        },
        {
          path: 'dispatch/exceptions',
          name: 'exception-task-management',
          component: ExceptionTaskManagementView,
          meta: { permission: 'dispatch' },
        },
        {
          path: 'resources/vehicles',
          name: 'vehicle-management',
          component: VehicleManagementView,
          meta: { permission: 'resources' },
        },
        {
          path: 'resources/personnel',
          name: 'personnel-management',
          component: PersonnelManagementView,
          meta: { permission: 'resources' },
        },
        {
          path: 'resources/sites',
          name: 'site-management',
          component: SiteManagementView,
          meta: { permission: 'resources' },
        },
        {
          path: 'mobile-tasks',
          name: 'mobile-task-center',
          component: MobileTaskCenterView,
          meta: { permission: 'mobile_tasks' },
        },
        {
          path: 'users',
          name: 'user-management',
          component: UserManagementView,
          meta: { permission: 'users' },
        },
      ],
    },
  ],
})

router.beforeEach((to) => {
  const token = localStorage.getItem('taskroute_token')
  const user = readCurrentUser()

  if (to.meta.requiresAuth && !token) {
    return { name: 'login' }
  }
  if (to.meta.guestOnly && token) {
    return { name: 'dashboard-home' }
  }

  if (to.meta.permission && !hasPermission(user, to.meta.permission)) {
    return { name: 'dashboard-home' }
  }
  return true
})

export default router
