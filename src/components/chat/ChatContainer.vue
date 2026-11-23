<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useChatStore } from '../../stores/chatStore'
import ChatMessage from './ChatMessage.vue'

const store = useChatStore()
const chatContainerRef = ref(null)

// Флаг: находится ли пользователь внизу страницы
const isAtBottom = ref(true)

function handleScroll() {
  const el = chatContainerRef.value
  if (!el) return
  
  // Порог в пикселях (100px) от низа, при котором считаем, что мы "прикованы"
  const threshold = 100
  const distanceFromBottom = el.scrollHeight - el.scrollTop - el.clientHeight
  
  isAtBottom.value = distanceFromBottom <= threshold
}

function scrollToBottom(force = false) {
  nextTick(() => {
    if (!chatContainerRef.value) return
    // Скроллим вниз принудительно (новое сообщение от юзера) или если юзер находится внизу
    if (force || isAtBottom.value) {
      chatContainerRef.value.scrollTop = chatContainerRef.value.scrollHeight
    }
  })
}

// При старте генерации или новом сообщении в истории - прокручиваем принудительно
watch(() => store.history.length, () => scrollToBottom(true))

// При потоковом тексте / мыслях - прокручиваем только если пользователь внизу
watch(() => [store.draftText, store.draftThinking], () => scrollToBottom(false))

onMounted(() => {
  if (chatContainerRef.value) {
    chatContainerRef.value.addEventListener('scroll', handleScroll, { passive: true })
  }
})

onUnmounted(() => {
  if (chatContainerRef.value) {
    chatContainerRef.value.removeEventListener('scroll', handleScroll)
  }
})
</script>

<template>
  <div 
    ref="chatContainerRef" 
    class="flex-1 overflow-y-auto p-2 sm:p-4 space-y-3 sm:space-y-4"
  >
    <div v-if="!store.history.length && !store.isGenerating" class="text-center text-xs text-gray-600 my-8 select-none">
      Выберите пресет и комнату слева для начала диалога
    </div>

    <!-- История сообщений -->
    <ChatMessage 
      v-for="(msg, idx) in store.history" 
      :key="idx"
      :role="msg.role"
      :content="msg.content"
      :reasoning-content="msg.reasoning_content"
      :is-error="msg.is_error"
    />

    <!-- Потоковый черновик генерации -->
    <ChatMessage 
      v-if="store.isGenerating"
      role="assistant"
      :content="store.draftText || 'ИИ размышляет...'"
      :reasoning-content="store.draftThinking"
    />
  </div>
</template>
