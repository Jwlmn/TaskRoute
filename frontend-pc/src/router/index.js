import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import PortalLayoutView from '../views/portal/PortalLayoutView.vue'
import DashboardHomeView from '../views/portal/DashboardHomeView.vue'
import DispatchWorkbenchView from '../views/portal/DispatchWorkbenchView.vue'
import ExceptionTaskManagementView from '../views/portal/ExceptionTaskManagementView.vue'
import PrePlanOrderManagementView from '../views/portal/PrePlanOrderManagementView.vue'
import CustomerPrePlanOrderView from '../views/portal/CustomerPrePlanOrderView.vue'
import MobileTaskCenterView from '../views/portal/MobileTaskCenterView.vue'
import UserManagementView from '../views/portal/UserManagementView.vue'
import VehicleManagementView from '../views/portal/VehicleManagementView.vue'
import PersonnelManagementView from '../views/portal/PersonnelManagementView.vue'
import SiteManagementView from '../views/portal/SiteManagementView.vue'
import FreightTemplateManagementView from '../views/portal/FreightTemplateManagementView.vue'
import SettlementManagementView from '../views/portal/SettlementManagementView.vue'
import NotificationCenterView from '../views/portal/NotificationCenterView.vue'
import OrderAuditLogView from '../views/portal/OrderAuditLogView.vue'
import api from '../services/api'
import { ensureAuthSession, hasPermission, readAuthToken, readCurrentUser } from '../utils/auth'

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
          path: 'settlements',
          name: 'settlement-management',
          component: SettlementManagementView,
          meta: { permission: 'settlement' },
        },
        {
          path: 'customer/pre-plan-orders',
          name: 'customer-pre-plan-order',
          component: CustomerPrePlanOrderView,
          meta: { permission: 'customer_orders' },
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
          path: 'resources/freight-templates',
          name: 'freight-template-management',
          component: FreightTemplateManagementView,
          meta: { permission: 'freight_templates' },
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
        {
          path: 'notifications',
          name: 'notification-center',
          component: NotificationCenterView,
          meta: { permission: 'notifications' },
        },
        {
          path: 'audit-logs',
          name: 'order-audit-log',
          component: OrderAuditLogView,
          meta: { permission: 'audit_log' },
        },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  const token = readAuthToken()
  let user = readCurrentUser()

  if (token) {
    user = (await ensureAuthSession(api)) || readCurrentUser()
  }

  if (to.meta.requiresAuth && !user) {
    return { name: 'login' }
  }
  if (to.meta.guestOnly && user) {
    return { name: 'dashboard-home' }
  }

  if (to.meta.permission && !hasPermission(user, to.meta.permission)) {
    return { name: 'dashboard-home' }
  }
  return true
})

export default router
