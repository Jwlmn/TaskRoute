import { createRouter, createWebHistory } from 'vue-router'
import api from '../services/api'
import { ensureAuthSession, hasPermission, readAuthToken, readCurrentUser } from '../utils/auth'

const LoginView = () => import('../views/LoginView.vue')
const PortalLayoutView = () => import('../views/portal/PortalLayoutView.vue')
const DashboardHomeView = () => import('../views/portal/DashboardHomeView.vue')
const DispatchWorkbenchView = () => import('../views/portal/DispatchWorkbenchView.vue')
const ExceptionTaskManagementView = () => import('../views/portal/ExceptionTaskManagementView.vue')
const PrePlanOrderManagementView = () => import('../views/portal/PrePlanOrderManagementView.vue')
const CustomerPrePlanOrderView = () => import('../views/portal/CustomerPrePlanOrderView.vue')
const MobileTaskCenterView = () => import('../views/portal/MobileTaskCenterView.vue')
const VehicleManagementView = () => import('../views/portal/VehicleManagementView.vue')
const PersonnelManagementView = () => import('../views/portal/PersonnelManagementView.vue')
const SiteManagementView = () => import('../views/portal/SiteManagementView.vue')
const FreightTemplateManagementView = () => import('../views/portal/FreightTemplateManagementView.vue')
const SettlementManagementView = () => import('../views/portal/SettlementManagementView.vue')
const NotificationCenterView = () => import('../views/portal/NotificationCenterView.vue')
const OrderAuditLogView = () => import('../views/portal/OrderAuditLogView.vue')

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
