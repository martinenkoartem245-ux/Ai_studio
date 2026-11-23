<template>
  <div v-if="isOpen" class="auth-overlay">
    <div class="auth-card">
      <div class="auth-header">
        <div class="auth-icon">🔒</div>
        <h2>{{ isConfigured ? 'Авторизация в туннеле' : 'Первичная регистрация' }}</h2>
        <p class="auth-desc">
          {{ isConfigured 
              ? 'Введите токен доступа к туннелю. Он сохранится в браузере, и повторный ввод не потребуется.' 
              : 'Придумайте секретный токен для доступа к серверу. Он будет зарегистрирован для этого туннеля.' 
          }}
        </p>
      </div>

      <form @submit.prevent="handleSubmit" class="auth-form">
        <div class="input-group">
          <label for="tunnel-token">Секретный токен / Ключ</label>
          <input
            id="tunnel-token"
            v-model="tokenInput"
            type="password"
            placeholder="Введите секретный ключ..."
            autocomplete="current-password"
            :disabled="isLoading"
            required
            autofocus
          />
        </div>

        <div v-if="errorMessage" class="error-banner">
          {{ errorMessage }}
        </div>

        <button type="submit" class="submit-btn" :disabled="isLoading || !tokenInput.trim()">
          <span v-if="isLoading">Проверка...</span>
          <span v-else>{{ isConfigured ? 'Войти' : 'Сохранить и зарегистрировать' }}</span>
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { apiRequest } from '../../api/client'
import { useChatStore } from '../../stores/chatStore'

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false
  }
})

const store = useChatStore()
const tokenInput = ref('')
const isConfigured = ref(true)
const isLoading = ref(false)
const errorMessage = ref('')

async function checkStatus() {
  const res = await apiRequest('auth_status')
  if (res && !res.error) {
    isConfigured.value = !!res.is_configured
  }
}

async function handleSubmit() {
  if (!tokenInput.value.trim()) return

  isLoading.value = true
  errorMessage.value = ''

  try {
    const res = await apiRequest('auth_register', {
      method: 'POST',
      params: { token: tokenInput.value.trim() }
    })

    if (res && res.status === 'success') {
      store.handleAuthSuccess(tokenInput.value.trim())
      tokenInput.value = ''
    } else {
      errorMessage.value = res?.message || 'Неверный токен'
    }
  } catch (err) {
    errorMessage.value = err.message || 'Ошибка сети'
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  checkStatus()
})
</script>

<style scoped>
.auth-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.75);
  backdrop-filter: blur(8px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: 16px;
}

.auth-card {
  background: #1e1e24;
  border: 1px solid #2e2e38;
  border-radius: 16px;
  width: 100%;
  max-width: 420px;
  padding: 28px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
  color: #fff;
}

.auth-header {
  text-align: center;
  margin-bottom: 24px;
}

.auth-icon {
  font-size: 36px;
  margin-bottom: 12px;
}

.auth-header h2 {
  font-size: 20px;
  font-weight: 600;
  margin: 0 0 8px 0;
}

.auth-desc {
  font-size: 13px;
  color: #9e9ea7;
  line-height: 1.5;
  margin: 0;
}

.auth-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.input-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  text-align: left;
}

.input-group label {
  font-size: 13px;
  color: #c4c4cc;
}

.input-group input {
  background: #141418;
  border: 1px solid #363642;
  border-radius: 8px;
  padding: 12px 14px;
  color: #fff;
  font-size: 14px;
  outline: none;
  transition: border-color 0.2s;
}

.input-group input:focus {
  border-color: #6366f1;
}

.error-banner {
  background: rgba(239, 68, 68, 0.15);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #f87171;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
}

.submit-btn {
  background: #6366f1;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 12px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.2s, opacity 0.2s;
}

.submit-btn:hover:not(:disabled) {
  background: #4f46e5;
}

.submit-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
