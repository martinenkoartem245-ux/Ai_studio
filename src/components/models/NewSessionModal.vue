<script setup>
import { ref, computed, watch } from 'vue'
import { apiRequest } from '../../api/client'
import { useChatStore } from '../../stores/chatStore'

const emit = defineEmits(['close'])
const store = useChatStore()

const presetsList = computed(() => store.globalStructure || store.presets || [])

const selectedPreset = ref(store.currentPreset || presetsList.value[0]?.id || 'polish_chronos')
const sessionTitle = ref('')
const mode = ref('full_mix')
const goal = ref('code_review')
const format = ref('markdown')

const isSubmitting = ref(false)

watch(() => store.currentPreset, (newVal) => {
  if (newVal) selectedPreset.value = newVal
}, { immediate: true })

async function handleCreate() {
  const title = sessionTitle.value.trim() || 'Новая сессия'
  if (isSubmitting.value || !selectedPreset.value) return

  isSubmitting.value = true
  try {
    const fd = new FormData()
    fd.append('preset', selectedPreset.value)
    fd.append('title', title)

    // Динамические параметры под конкретные пресеты
    if (selectedPreset.value === 'polish_chronos') {
      fd.append('mode', mode.value || 'full_mix')
    } else if (selectedPreset.value === 'deep_analysis') {
      fd.append('goal', goal.value || 'code_review')
      fd.append('format', format.value || 'markdown')
    }

    const res = await apiRequest('create_session', { method: 'POST', body: fd })
    if (!res.error) {
      await store.fetchStructure()
      const createdSessionId = res.session_id || res.id || null
      const targetPreset = res.preset || selectedPreset.value

      if (typeof store.setSession === 'function') {
        store.setSession(targetPreset, createdSessionId)
      }
      if (createdSessionId && typeof store.selectSession === 'function') {
        await store.selectSession(targetPreset, createdSessionId)
      }
      emit('close')
    } else {
      alert('Ошибка создания сессии: ' + (res.message || res.error || 'Неизвестный сбой'))
    }
  } catch (err) {
    console.error('Ошибка создания сессии:', err)
    alert('Не удалось создать сессию')
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div 
    class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4"
    @click.self="emit('close')"
  >
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-md shadow-2xl p-5 space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-bold text-gray-100">Создать новую сессию</h3>
        <button type="button" @click="emit('close')" class="text-gray-400 hover:text-gray-200 cursor-pointer">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>

      <form @submit.prevent="handleCreate" class="space-y-3">
        <!-- Выбор пресета -->
        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Пресет</label>
          <select 
            v-model="selectedPreset"
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 focus:outline-none focus:border-indigo-500 transition"
          >
            <option v-for="p in presetsList" :key="p.id" :value="p.id">
              {{ p.name }}
            </option>
          </select>
        </div>

        <!-- Название сессии -->
        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Название комнаты/сессии</label>
          <input 
            v-model="sessionTitle"
            type="text" 
            placeholder="Например: Обсуждение архитектуры" 
            required 
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 placeholder-gray-500 focus:outline-none focus:border-indigo-500 transition"
          />
        </div>

        <!-- Опции для Polish Chronos -->
        <div v-if="selectedPreset === 'polish_chronos'" class="space-y-3 pt-1 border-t border-gray-800/60">
          <div>
            <label class="block text-xs font-medium text-gray-400 mb-1">Режим тренировки</label>
            <select 
              v-model="mode"
              class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 focus:outline-none focus:border-indigo-500"
            >
              <option value="full_mix">Полный микс (Разговор + Работа)</option>
              <option value="grammar">Упор на грамматику</option>
              <option value="vocab">Словарный запас</option>
            </select>
          </div>
        </div>

        <!-- Опции для Deep Analysis -->
        <div v-if="selectedPreset === 'deep_analysis'" class="space-y-3 pt-1 border-t border-gray-800/60">
          <div>
            <label class="block text-xs font-medium text-gray-400 mb-1">Цель анализа</label>
            <select 
              v-model="goal"
              class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 focus:outline-none focus:border-indigo-500"
            >
              <option value="code_review">Ревью кода</option>
              <option value="architecture">Анализ архитектуры</option>
              <option value="refactoring">Поиск узких мест и рефакторинг</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-400 mb-1">Формат отчета</label>
            <select 
              v-model="format"
              class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 focus:outline-none focus:border-indigo-500"
            >
              <option value="markdown">Markdown отчёт</option>
              <option value="summary">Краткая выжимка</option>
              <option value="json">Структурированный JSON</option>
            </select>
          </div>
        </div>

        <div class="flex justify-end space-x-2 pt-2">
          <button 
            type="button" 
            @click="emit('close')"
            class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl text-xs font-medium cursor-pointer"
          >
            Отмена
          </button>
          <button 
            type="submit" 
            :disabled="!sessionTitle.trim() || isSubmitting"
            class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white rounded-xl text-xs font-medium cursor-pointer"
          >
            Создать
          </button>
        </div>
      </form>
    </div>
  </div>
</template>