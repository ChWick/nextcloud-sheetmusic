import { createRouter, createWebHistory } from 'vue-router'
import App from './App.vue'
import { generateUrl } from '@nextcloud/router'

export const router = createRouter({
  history: createWebHistory(generateUrl('/apps/mobilesheetsmanager/')),
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
