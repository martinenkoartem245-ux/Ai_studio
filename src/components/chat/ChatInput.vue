<script setup>
import { ref, nextTick, watch } from 'vue'
import { useChatStore } from '../../stores/chatStore'

const store = useChatStore()
const messageText = ref('')
const textareaRef = ref(null)

// Определяем устройство с основным сенсорным вводом (телефон/планшет)
const isTouchDevice = window.matchMedia('(pointer: coarse)').matches

function adjustHeight() {
  const el = textareaRef.value
  if (!el) return
  
  // Сбрасываем высоту для корректного перерасчета на Android при удалении текста
  el.style.height = 'auto'
  
  // Ограничиваем максимальную высоту 160px
  const maxHeight = 160
  const newHeight = Math.min(el.scrollHeight, maxHeight)
  
  el.style.height = `${newHeight}px`
}

watch(messageText, () => {
  nextTick(adjustHeight)
})

function onEnterKey(e) {
  // Не отправляем во время IME-композиции (когда печатается иероглиф/автодополнение) и при Shift+Enter
  if (e.isComposing || e.shiftKey) return

  // На телефонах Enter дает перенос строки
  if (isTouchDevice) return

  // На десктопе Enter отправляет сообщение
  e.preventDefault()
  handleSend()
}

function handleSend() {
  if (!messageText.value.trim() || store.isGenerating) return
  const text = messageText.value
  messageText.value = ''
  store.sendMessage(text)
  
  nextTick(adjustHeight)
}
</script>

<template>
  <div class="shrink-0 p-2 sm:p-3 pb-[calc(0.5rem+env(safe-area-inset-bottom))] md:pb-3 bg-gray-900 border-t border-gray-800/80 z-20 shadow-2xl sticky bottom-0">
    <div class="max-w-4xl mx-auto relative flex items-end space-x-2 bg-gray-950 border border-gray-700/60 rounded-xl p-1.5 sm:p-2 focus-within:border-indigo-500 transition shadow-lg">
      <textarea 
        ref="textareaRef"
        v-model="messageText"
        rows="1" 
        enterkeyhint="enter"
        placeholder="Напишите сообщение..." 
        @keydown.enter="onEnterKey"
        class="flex-1 bg-transparent text-base sm:text-sm text-gray-100 placeholder-gray-500 focus:outline-none resize-none px-2 py-1.5 leading-6 max-h-[160px] overflow-y-auto custom-scrollbar touch-manipulation"
        style="min-height: 38px;"
      ></textarea>

      <button 
        type="button" 
        @click="store.isGenerating ? store.abortStream() : handleSend()"
        :disabled="!store.isGenerating && !messageText.trim()"
        class="p-2 sm:p-2.5 text-white rounded-lg transition shrink-0 cursor-pointer disabled:opacity-50 touch-manipulation mb-0.5"
        :class="store.isGenerating ? 'bg-rose-600 hover:bg-rose-500' : 'bg-indigo-600 hover:bg-indigo-500'"
        :title="store.isGenerating ? 'Остановить генерацию' : 'Отправить сообщение'"
      >
        <svg v-if="store.isGenerating" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
          <rect x="6" y="6" width="12" height="12" rx="2"/>
        </svg>
        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/>
        </svg>
      </button>
    </div>
  </div>
</template>
