import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/apps/mobilesheetsmanager/:library/:view?',
      name: 'LibraryView',
      component: App,
      props: true,
    },
    {
      path: '/apps/mobilesheetsmanager',
      name: 'Home',
      component: App,
    },
  ],
})
