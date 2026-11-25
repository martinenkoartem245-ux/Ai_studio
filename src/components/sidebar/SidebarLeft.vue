<script setup>
import { ref } from 'vue'
import { useChatStore } from '../../stores/chatStore'

const props = defineProps({
  isOpen: Boolean
})
const emit = defineEmits(['openNewPreset', 'editPreset', 'close'])

const store = useChatStore()
const openPresets = ref({})

function togglePreset(presetId) {
  openPresets.value[presetId] = !openPresets.value[presetId]
}

function isPresetOpen(presetId) {
  if (openPresets.value[presetId] !== undefined) {
    return openPresets.value[presetId]
  }
  return store.currentPreset === presetId
}

async function handleSelectSession(presetId, sessionId) {
  if (store.currentPreset === presetId && store.currentSession === sessionId) return
  await store.selectSession(presetId, sessionId)
  emit('close')
}

function handleEditPreset(preset) {
  emit('editPreset', preset)
  store.openPresetEditor(preset.id)
  emit('close') // на мобилке закрываем шторку сайдбара, как при выборе сессии
}

async function handleDeletePreset(preset) {
  const name = preset.name || preset.id
  if (confirm(`Удалить пресет "${name}" и все его сессии?`)) {
    await store.deletePreset(preset.id)
  }
}

async function handleDeleteSession(presetId, session) {
  const name = session.name || session.id
  if (confirm(`Удалить сессию "${name}"?`)) {
    await store.deleteSession(presetId, session.id)
  }
}
</script>

<template>
  <aside 
    class="fixed md:relative z-40 inset-y-0 left-0 w-72 md:w-64 bg-gray-900/95 md:bg-gray-900/80 backdrop-blur-md md:backdrop-blur-none border-r border-gray-800 flex flex-col h-full shrink-0 select-none transition-transform duration-300 ease-in-out"
    :class="isOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full md:translate-x-0'"
  >
    <!-- Заголовок панели с кнопками -->
    <div class="p-3 border-b border-gray-800/80 flex items-center justify-between">
      <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Пресеты и сессии</span>
      
      <div class="flex items-center space-x-1">
        <button 
          type="button"
          @click="emit('openNewPreset')" 
          class="text-gray-400 hover:text-indigo-400 transition p-1 rounded-md hover:bg-gray-800 cursor-pointer"
          title="Создать пресет"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
          </svg>
        </button>

        <button 
          type="button"
          @click="store.fetchStructure" 
          class="text-gray-500 hover:text-gray-300 transition p-1 rounded-md hover:bg-gray-800 cursor-pointer"
          title="Обновить список"
        >
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
          </svg>
        </button>

        <!-- Кнопка закрытия для мобилок -->
        <button 
          type="button" 
          @click="emit('close')"
          class="md:hidden text-gray-400 hover:text-white p-1 rounded-md ml-1"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Дерево пресетов и комнат -->
    <div class="flex-1 overflow-y-auto p-2 space-y-1">
      <div v-if="!store.globalStructure.length" class="p-4 text-center text-xs text-gray-500">
        Нет доступных пресетов
      </div>

      <div 
        v-for="preset in store.globalStructure" 
        :key="preset.id" 
        class="rounded-lg overflow-hidden group/preset"
      >
      
        <!-- Шапка пресета -->
        <button
          type="button"
          @click="togglePreset(preset.id)"
          class="w-full text-left px-2.5 py-2 flex items-center justify-between rounded-lg text-xs font-semibold transition cursor-pointer"
          :class="store.currentPreset === preset.id ? 'bg-indigo-950/40 text-indigo-300' : 'text-gray-300 hover:bg-gray-800/60'"
        >
          <div class="flex items-center space-x-2 truncate">
            <svg 
              class="w-3.5 h-3.5 transition-transform duration-200 shrink-0 text-gray-500"
              :class="{ 'rotate-90': isPresetOpen(preset.id) }"
              fill="none" 
              stroke="currentColor" 
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
            <span class="truncate">{{ preset.name || preset.id }}</span>
          </div>

         <div class="flex items-center space-x-1 shrink-0 ml-1">
            <button
              type="button"
              @click.stop="handleEditPreset(preset)"
              class="opacity-100 md:opacity-0 group-hover/preset:opacity-100 hover:text-indigo-400 text-gray-500 p-0.5 rounded transition cursor-pointer"
              title="Редактировать пресет"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
              </svg>
            </button>

            <button
              type="button"
              @click.stop="handleDeletePreset(preset)"
              class="opacity-100 md:opacity-0 group-hover/preset:opacity-100 hover:text-red-400 text-gray-500 p-0.5 rounded transition cursor-pointer"
              title="Удалить пресет"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
              </svg>
            </button>
            <span class="text-[10px] bg-gray-800 text-gray-400 px-1.5 py-0.5 rounded-full font-mono">
              {{ preset.sessions?.length || 0 }}
            </span>
          </div>
        </button>

        <!-- Список сессий -->
        <div v-show="isPresetOpen(preset.id)" class="mt-0.5 ml-3 pl-2 border-l border-gray-800 space-y-0.5 py-1">
          <div
            v-for="session in preset.sessions"
            :key="session.id"
            class="group/session flex items-center"
          >
            <button
              type="button"
              @click="handleSelectSession(preset.id, session.id)"
              class="w-full text-left px-2 py-1.5 rounded-md text-xs transition truncate flex items-center justify-between cursor-pointer"
              :class="[
                store.currentPreset === preset.id && store.currentSession === session.id
                  ? 'bg-indigo-600 text-white font-medium shadow-sm'
                  : 'text-gray-400 hover:bg-gray-800/80 hover:text-gray-200'
              ]"
            >
              <span class="truncate">{{ session.name || session.id }}</span>
              <div class="flex items-center space-x-1 shrink-0 ml-1">
              
                <button
                  type="button"
                  @click.stop="handleDeleteSession(preset.id, session)"
                  class="opacity-100 md:opacity-0 group-hover/session:opacity-100 hover:text-red-300 text-gray-400 p-0.5 rounded transition cursor-pointer"
                  title="Удалить сессию"
                >
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                  </svg>
                </button>
                <span 
                  v-if="store.currentPreset === preset.id && store.currentSession === session.id"
                  class="w-1.5 h-1.5 rounded-full bg-white"
                ></span>
              </div>
            </button>
          </div>
        </div>
      </div>
    </div>
  </aside>
</template>