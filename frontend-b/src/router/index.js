import { createRouter, createWebHistory } from 'vue-router'
import DispatchConsoleView from '../views/DispatchConsoleView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'dispatch-console',
      component: DispatchConsoleView,
    },
  ],
})

export default router

