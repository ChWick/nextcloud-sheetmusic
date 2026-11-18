import '@fontsource/noto-music'
import './fonts/century-schoolbook.css'
import './styles/pwa.css'
import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router'

const app = createApp(App)
app.use(router)
app.mount('#sheetmusic')

