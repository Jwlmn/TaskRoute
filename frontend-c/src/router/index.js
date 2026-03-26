import { createRouter, createWebHistory } from 'vue-router'
import CustomerPortalView from '../views/CustomerPortalView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'customer-portal',
      component: CustomerPortalView,
    },
  ],
})

export default router

