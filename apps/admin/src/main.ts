import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router'
import { initSentry } from './sentry'

const app = createApp(App).use(createPinia()).use(router)

// Before mount, so an error thrown during the first render is still captured.
initSentry(app, router)

app.mount('#app')
