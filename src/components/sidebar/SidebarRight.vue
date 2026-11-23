<script setup>
import { ref, computed } from 'vue'
import { useChatStore } from '../../stores/chatStore'
import { AI_MODELS } from '../../config/models'
import ModelParamsManager from './ModelParamsManager.vue'

const props = defineProps({
  isOpen: Boolean
})
const emit = defineEmits(['close'])

const store = useChatStore()
const fileInputRef = ref(null)
const isUploading = ref(false)

const openSections = ref({
  model: true,
  files: true
})

function toggleSection(section) {
  openSections.value[section] = !openSections.value[section]
}

const currentModelConfig = computed(() => store.activeModelMeta)

async function handleModelChange(event) {
  const newModel = event.target.value
  await store.setModel(newModel)
}

function triggerFileInput() {
  fileInputRef.value?.click()
}

async function handleFileSelect(event) {
  const selectedFiles = Array.from(event.target.files || [])
  if (!selectedFiles.length) return

  isUploading.value = true
  try {
    const res = await store.uploadFiles(selectedFiles)
    if (res?.error) {
      alert(`Ошибка загрузки: ${res.error}`)
    }
  } catch (e) {
    alert('Не удалось загрузить файлы')
  } finally {
    if (fileInputRef.value) fileInputRef.value.value = ''
    isUploading.value = false
  }
}

async function handleDeleteFile(fileName) {
  if (confirm(`Удалить файл "${fileName}" из этой сессии?`)) {
    const res = await store.deleteFile(fileName)
    if (res?.error) {
      alert(`Ошибка удаления: ${res.error}`)
    }
  }
}
</script>

<template>
  <aside 
    class="fixed md:relative z-40 inset-y-0 right-0 w-72 bg-gray-900/95 md:bg-gray-900/90 backdrop-blur-md md:backdrop-blur-none border-l border-gray-800 flex flex-col h-full shrink-0 overflow-hidden transition-transform duration-300 ease-in-out text-xs"
    :class="isOpen ? 'translate-x-0 shadow-2xl' : 'translate-x-full md:translate-x-0'"
  >
    <!-- Шапка панельки для мобилок -->
    <div class="md:hidden p-2 border-b border-gray-800 flex items-center justify-between bg-gray-900 shrink-0">
      <span class="text-[11px] font-bold text-gray-300 uppercase tracking-wider">Параметры и сессия</span>
      <button 
        type="button" 
        aria-label="Закрыть панель"
        @click="emit('close')" 
        class="text-gray-400 hover:text-white p-1 cursor-pointer"
      >
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
      </button>
    </div>

    <!-- 1. ВЫБОР МОДЕЛИ И МОДУЛЬ ПАРАМЕТРОВ -->
    <div class="border-b border-gray-800 shrink-0">
      <button 
        type="button"
        @click="toggleSection('model')"
        :aria-expanded="openSections.model"
        class="w-full px-2.5 py-2 bg-gray-900 hover:bg-gray-800 flex items-center justify-between font-bold text-gray-300 uppercase tracking-wider transition cursor-pointer text-[11px]"
      >
        <span class="flex items-center space-x-1.5">
          <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
          </svg>
          <span>Модель и Опции</span>
        </span>
        <svg :class="{'rotate-180': openSections.model}" class="w-3.5 h-3.5 text-gray-500 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
        </svg>
      </button>

      <div v-show="openSections.model" class="p-2 space-y-2.5 bg-gray-950/40 max-h-[55vh] overflow-y-auto">
        <div>
          <label class="block text-[10px] font-medium text-gray-400 mb-1">Выбранная нейросеть</label>
          <select 
            :value="store.selectedModel"
            :disabled="store.isGenerating"
            @change="handleModelChange"
            class="w-full bg-gray-800 border border-gray-700 text-gray-200 rounded-lg p-1.5 text-[11px] focus:ring-1 focus:ring-indigo-500 focus:outline-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <option v-for="m in AI_MODELS" :key="m.id" :value="m.id">
              {{ m.name }}
            </option>
          </select>
        </div>

        <ModelParamsManager :modelConfig="currentModelConfig" />
      </div>
    </div>

    <!-- 2. ФАЙЛЫ СЕССИИ -->
    <div class="border-b border-gray-800 flex-1 flex flex-col min-h-0">
      <button 
        type="button"
        @click="toggleSection('files')"
        :aria-expanded="openSections.files"
        class="w-full px-2.5 py-2 bg-gray-900 hover:bg-gray-800 flex items-center justify-between font-bold text-gray-300 uppercase tracking-wider transition cursor-pointer text-[11px] shrink-0"
      >
        <span class="flex items-center space-x-1.5">
          <svg class="w-3.5 h-3.5 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
          </svg>
          <span>Файлы Сессии</span>
        </span>
        <div class="flex items-center space-x-1.5">
          <span class="text-[9px] bg-gray-800 text-gray-400 px-1 py-px rounded-full font-mono">
            {{ store.files.length }}
          </span>
          <svg :class="{'rotate-180': openSections.files}" class="w-3.5 h-3.5 text-gray-500 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </div>
      </button>

      <div v-show="openSections.files" class="flex-1 flex flex-col min-h-0 bg-gray-950/40">
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
          <div v-if="!store.files.length" class="text-center py-6 text-[10px] text-gray-500">
            Файлы еще не загружены
          </div>

          <div 
            v-for="file in store.files" 
            :key="typeof file === 'object' ? file.name : file"
            class="flex items-center justify-between p-1 bg-gray-800/60 border border-gray-700/40 rounded group hover:border-gray-600 transition"
          >
            <div class="flex items-center space-x-1.5 truncate pr-1">
              <svg class="w-3 h-3 text-indigo-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
              </svg>
              <span class="text-[10px] text-gray-200 truncate" :title="typeof file === 'object' ? file.name : file">
                {{ typeof file === 'object' ? file.name : file }}
              </span>
            </div>

            <button 
              type="button" 
              :disabled="!store.currentSession"
              :aria-label="`Удалить ${typeof file === 'object' ? file.name : file}`"
              @click="handleDeleteFile(typeof file === 'object' ? file.name : file)"
              class="text-gray-500 hover:text-rose-400 p-0.5 rounded transition cursor-pointer shrink-0 opacity-100 md:opacity-0 group-hover:opacity-100 focus:opacity-100 [@media(hover:none)]:opacity-100 disabled:hidden"
              title="Удалить файл"
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
            </button>
          </div>
        </div>

        <div class="p-2 border-t border-gray-800/60 shrink-0">
          <input 
            ref="fileInputRef" 
            type="file" 
            multiple 
            class="hidden" 
            @change="handleFileSelect"
          />
          <button 
            type="button" 
            @click="triggerFileInput"
            :disabled="isUploading || !store.currentSession"
            class="w-full py-1 px-2 bg-gray-800 hover:bg-gray-700 disabled:opacity-50 text-gray-200 border border-gray-700 rounded-lg text-[10px] font-medium transition flex items-center justify-center space-x-1 cursor-pointer"
          >
            <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            <span>{{ isUploading ? 'Загрузка...' : 'Загрузить файл' }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- 3. СТАТИСТИКА -->
    <div class="p-2 space-y-1.5 bg-gray-950/60 shrink-0">
      <span class="text-[9px] font-bold text-gray-500 uppercase tracking-wider block">Статистика ответа</span>
      
      <div class="grid grid-cols-2 gap-1 text-[10px]">
        <div class="bg-gray-800/40 border border-gray-800 p-1 rounded-lg">
          <span class="text-[8px] text-gray-500 block">Промпт</span>
          <span class="font-mono text-gray-200 font-semibold">{{ store.usageStats.promptTokens }}</span>
        </div>
        <div class="bg-gray-800/40 border border-gray-800 p-1 rounded-lg">
          <span class="text-[8px] text-gray-500 block">Ответ</span>
          <span class="font-mono text-gray-200 font-semibold">{{ store.usageStats.completionTokens }}</span>
        </div>
      </div>

      <div class="bg-indigo-950/30 border border-indigo-900/40 px-2 py-1 rounded-lg flex items-center justify-between">
        <span class="text-[9px] text-indigo-300">Стоимость:</span>
        <span class="font-mono text-[9px] font-bold text-indigo-200">${{ Number(store.usageStats.cost || 0).toFixed(5) }}</span>
      </div>
    </div>
  </aside>
</template>
