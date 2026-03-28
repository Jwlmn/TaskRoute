import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import MobileLayoutView from '../views/mobile/MobileLayoutView.vue'
import MobileHomeView from '../views/mobile/MobileHomeView.vue'
import MobileTaskListView from '../views/mobile/MobileTaskListView.vue'
import MobileTaskDetailView from '../views/mobile/MobileTaskDetailView.vue'
import MobileAccountView from '../views/mobile/MobileAccountView.vue'
import api from '../services/api'
import { ensureAuthSession, readCurrentUser, readAuthToken } from '../utils/auth'

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
        { path: '', name: 'mobile-home', component: MobileHomeView },
        { path: 'tasks', name: 'mobile-tasks', component: MobileTaskListView },
        { path: 'tasks/:id', name: 'mobile-task-detail', component: MobileTaskDetailView },
        { path: 'account', name: 'mobile-account', component: MobileAccountView },
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
    return { name: 'mobile-home' }
  }

  return true
})

export default router
