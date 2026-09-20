import { createRouter, createWebHashHistory } from 'vue-router'
import App from './App.vue'
import { generateUrl } from '@nextcloud/router'

export const router = createRouter({
  history: createWebHashHistory(generateUrl('/apps/mobilesheetsmanager/')),
  routes: [
    {
      path: '/library/:library/:view?',
      name: 'LibraryView',
      component: App,
      props: true,
    },
    {
      path: '/',
      name: 'Home',
      component: App,
    },
  ],
})
