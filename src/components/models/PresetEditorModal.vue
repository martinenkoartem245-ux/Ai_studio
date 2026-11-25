<script setup>
import { ref, watch } from 'vue'
import { useChatStore } from '../../stores/chatStore'
import { AI_MODELS } from '../../config/models'

const store = useChatStore()

const isLoading = ref(false)
const isSaving = ref(false)
const errorMsg = ref('')

const form = ref({
  name: '',
  model: AI_MODELS[0]?.id || '',
  system_prompt: '',
  temperature: 0.7,
  has_dictionary: false,
  language: 'en',
  history_limit: 10
})

async function loadPresetData(presetId) {
  if (!presetId) return
  isLoading.value = true
  errorMsg.value = ''

  try {
    // 1. Подставляем кэш из globalStructure, чтобы не мигало пустотой
    const cached = store.globalStructure.find(p => p.id === presetId)
    if (cached) {
      form.value.name = cached.name || cached.id || ''
      form.value.model = cached.model || AI_MODELS[0]?.id || ''
      form.value.system_prompt = cached.system_prompt || cached.systemPrompt || cached.system_context || ''
      form.value.temperature = Number(cached.temperature ?? 0.7)
      form.value.has_dictionary = !!cached.has_dictionary
      form.value.language = cached.language || 'en'
      form.value.history_limit = Number(cached.history_limit ?? 10)
    }

    // 2. Подтягиваем актуальные данные с бэка
    const remote = await store.fetchPreset(presetId)
    if (remote) {
      form.value.name = remote.name ?? remote.id ?? form.value.name
      form.value.model = remote.model ?? remote.model_id ?? form.value.model
      form.value.system_prompt = remote.system_prompt ?? remote.systemPrompt ?? remote.system_context ?? form.value.system_prompt
      form.value.temperature = Number(remote.temperature ?? form.value.temperature)
      form.value.has_dictionary = !!remote.has_dictionary
      form.value.language = remote.language || 'en'
      form.value.history_limit = Number(remote.history_limit ?? form.value.history_limit)
    } else if (!cached) {
      errorMsg.value = store.lastError || 'Не удалось получить данные пресета'
    }
  } finally {
    isLoading.value = false
  }
}

watch(
  () => [store.isPresetEditorOpen, store.editingPresetId],
  ([isOpen, presetId]) => {
    if (isOpen && presetId) {
      loadPresetData(presetId)
    }
  },
  { immediate: true }
)

async function handleSave() {
  if (!store.editingPresetId || isSaving.value) return
  isSaving.value = true
  errorMsg.value = ''

  try {
    const res = await store.updatePreset(store.editingPresetId, {
      name: form.value.name.trim(),
      model: form.value.model,
      system_prompt: form.value.system_prompt,
      temperature: form.value.temperature,
      has_dictionary: form.value.has_dictionary,
      language: form.value.language,
      history_limit: form.value.history_limit
    })

    if (res && !res.error) {
      store.closePresetEditor()
    } else {
      errorMsg.value = res?.message || 'Ошибка при сохранении пресета'
    }
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <div
    v-if="store.isPresetEditorOpen"
    class="fixed inset-0 z-50 bg-black/70 backdrop-blur-sm flex items-center justify-center p-4"
    @click.self="store.closePresetEditor()"
  >
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-md shadow-2xl p-5 space-y-4 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between">
        <div>
          <h3 class="text-sm font-bold text-gray-100">Редактирование пресета</h3>
          <p class="text-[11px] text-gray-500 font-mono">{{ store.editingPresetId }}</p>
        </div>
        <button
          type="button"
          @click="store.closePresetEditor()"
          class="text-gray-400 hover:text-gray-200 cursor-pointer"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <div v-if="isLoading" class="py-6 text-center text-xs text-gray-400">
        Загрузка данных пресета...
      </div>

      <form v-else @submit.prevent="handleSave" class="space-y-3">
        <div v-if="errorMsg" class="p-2.5 rounded-xl bg-red-950/50 border border-red-800/60 text-xs text-red-300">
          {{ errorMsg }}
        </div>

        <!-- Название -->
        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Название пресета</label>
          <input
            v-model="form.name"
            type="text"
            required
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 placeholder-gray-500 focus:outline-none focus:border-indigo-500 transition"
          />
        </div>

        <!-- Модель -->
        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Модель по умолчанию</label>
          <select
            v-model="form.model"
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 focus:outline-none focus:border-indigo-500 transition"
          >
            <option v-for="m in AI_MODELS" :key="m.id" :value="m.id">
              {{ m.name }} ({{ m.provider }})
            </option>
          </select>
        </div>

        <!-- Системный промпт -->
        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Системный контекст</label>
          <textarea
            v-model="form.system_prompt"
            rows="4"
            placeholder="Инструкция для модели в этом пресете..."
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 placeholder-gray-500 focus:outline-none focus:border-indigo-500 transition resize-y"
          ></textarea>
        </div>

        <!-- Temperature -->
        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Temperature</label>
          <input
            v-model.number="form.temperature"
            type="number"
            step="0.1"
            min="0"
            max="2"
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 focus:outline-none focus:border-indigo-500 transition"
          />
        </div>

        <!-- Язык словаря -->
        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Язык словаря</label>
          <select
            v-model="form.language"
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 focus:outline-none focus:border-indigo-500 transition"
          >
            <option value="en">English</option>
            <option value="pl">Polski</option>
          </select>
        </div>

        <!-- Лимит истории для словаря -->
        <div>
          <label class="block text-xs font-medium text-gray-400 mb-1">Лимит сообщений для словаря</label>
          <input
            v-model.number="form.history_limit"
            type="number"
            min="1"
            max="100"
            class="w-full bg-gray-950 border border-gray-700 rounded-xl px-3 py-2 text-xs text-gray-100 focus:outline-none focus:border-indigo-500 transition"
          />
        </div>

        <!-- Включён ли словарь -->
        <div class="flex items-center justify-between p-2 bg-gray-950 rounded-xl border border-gray-800">
          <span class="text-xs text-gray-300">Включить словарь</span>
          <button
            type="button"
            @click="form.has_dictionary = !form.has_dictionary"
            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors cursor-pointer"
            :class="form.has_dictionary ? 'bg-indigo-600' : 'bg-gray-700'"
          >
            <span
              class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform"
              :class="form.has_dictionary ? 'translate-x-5' : 'translate-x-1'"
            />
          </button>
        </div>

        <div class="flex justify-end space-x-2 pt-2">
          <button
            type="button"
            @click="store.closePresetEditor()"
            class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded-xl text-xs font-medium cursor-pointer"
          >
            Отмена
          </button>
          <button
            type="submit"
            :disabled="!form.name.trim() || isSaving"
            class="px-4 py-1.5 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 text-white rounded-xl text-xs font-medium cursor-pointer"
          >
            {{ isSaving ? 'Сохранение...' : 'Сохранить' }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
