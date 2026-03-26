import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import MobileLayoutView from '../views/mobile/MobileLayoutView.vue'
import MobileHomeView from '../views/mobile/MobileHomeView.vue'
import MobileTaskListView from '../views/mobile/MobileTaskListView.vue'
import MobileAccountView from '../views/mobile/MobileAccountView.vue'
import { readCurrentUser } from '../utils/auth'

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
        { path: 'account', name: 'mobile-account', component: MobileAccountView },
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

  if (to.meta.guestOnly && token && user) {
    return { name: 'mobile-home' }
  }

  return true
})

export default router

