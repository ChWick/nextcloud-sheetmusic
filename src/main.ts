import './fonts/bravura.css'
import './styles/pwa.css'
import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router'
import { attachPreloadErrorRecovery } from './utils/pwaVersion'

attachPreloadErrorRecovery()

const app = createApp(App)
app.use(router)
app.mount('#sheetmusic')

