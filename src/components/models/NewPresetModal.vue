<script setup>
import { ref } from 'vue'
import { apiRequest } from '../../api/client'
import { useChatStore } from '../../stores/chatStore'

import { AI_MODELS } from '../../config/models'

const DEFAULT_SYSTEM_PROMPT = 'Ты — полезный ИИ-помощник.'

const emit = defineEmits(['close'])
const store = useChatStore()

const presetName = ref('')
const selectedModel = ref('kimi-k3')
const hasDictionary = ref(false)
const instructions = ref('')
const isSubmitting = ref(false)

async function handleCreate() {
  if (!presetName.value.trim() || isSubmitting.value) return

  isSubmitting.value = true
  try {
    const fd = new FormData()
    fd.append('preset_name', presetName.value.trim())
    fd.append('model', selectedModel.value || 'kimi-k3')
    fd.append('has_dictionary', hasDictionary.value ? '1' : '0')

    const systemPrompt = instructions.value.trim() || DEFAULT_SYSTEM_PROMPT
    fd.append('system_prompt', systemPrompt)

    const res = await apiRequest('create_preset', { method: 'POST', body: fd })
    if (!res.error) {
      await store.fetchStructure()

      const newPresetId = res.preset_id || res.id
      const defaultSessionId = res.session_id || res.default_session || null

      if (newPresetId && typeof store.setSession === 'function') {
        store.setSession(newPresetId, defaultSessionId)
      }
      if (newPresetId && defaultSessionId && typeof store.selectSession === 'function') {
        await store.selectSession(newPresetId, defaultSessionId)
      }
      emit('close')
    } else {
      alert('Ошибка создания пресета: ' + (res.message || res.error || 'Неизвестный сбой'))
    }
  } catch (err) {
    console.error('Ошибка создания пресета:', err)
    alert('Не удалось создать пресет')
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
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-lg shadow-2xl p-5 space-y-4">
      <div class="flex items-center justify-between">
        <h3 class="text-sm font-bold text-gray-100">Создать новый пресет</h3>
        <button type="button" @click="emit('close')" class="text-gray-400 hover:text-gray-200 cursor-pointer">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <form @submit.prevent="handleCreate" class="space-y-3">
        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Название пресета</label>
          <input
            v-model="presetName"
            type="text"
            placeholder="например: Новое рабочее окружение"
            required
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 placeholder-gray-500 focus:outline-none focus:border-indigo-500"
          />
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Модель по умолчанию</label>
          <select
            v-model="selectedModel"
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 focus:outline-none focus:border-indigo-500"
          >
            <option v-for="m in AI_MODELS" :key="m.id" :value="m.id">
              {{ m.name }}
            </option>
          </select>
        </div>

        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">
            Инструкции для ИИ (system prompt)
          </label>
          <textarea
            v-model="instructions"
            rows="4"
            placeholder="Например: Ты — строгий преподаватель польского языка..."
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 placeholder-gray-500 focus:outline-none focus:border-indigo-500 resize-none"
          ></textarea>
          <p class="text-[10px] text-gray-500 mt-1">
            Если оставить пустым, будет использована универсальная заглушка.
          </p>
        </div>

        <div class="flex items-center space-x-2 pt-1">
          <input
            id="presetHasDict"
            v-model="hasDictionary"
            type="checkbox"
            class="w-4 h-4 rounded bg-gray-950 border-gray-700 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-gray-900 cursor-pointer"
          />
          <label for="presetHasDict" class="text-xs text-gray-300 font-medium select-none cursor-pointer">
            Включить поддержку словаря
          </label>
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
            :disabled="!presetName.trim() || isSubmitting"
            class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white rounded-xl text-xs font-medium cursor-pointer"
          >
            Сохранить пресет
          </button>
        </div>
      </form>
    </div>
  </div>
</template>