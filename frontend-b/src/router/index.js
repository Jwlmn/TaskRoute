import { createRouter, createWebHistory } from 'vue-router'
import DispatchConsoleView from '../views/DispatchConsoleView.vue'
import LoginView from '../views/LoginView.vue'

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
      name: 'dispatch-console',
      component: DispatchConsoleView,
      meta: {
        requiresAuth: true,
      },
    },
  ],
})

router.beforeEach((to) => {
  const token = localStorage.getItem('taskroute_token')
  if (to.meta.requiresAuth && !token) {
    return { name: 'login' }
  }
  if (to.meta.guestOnly && token) {
    return { name: 'dispatch-console' }
  }
  return true
})

export default router
