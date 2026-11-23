<script setup>
import { computed } from 'vue'
import { useChatStore } from '../stores/chatStore'

const store = useChatStore()
const emit = defineEmits(['openDict', 'openNewSession', 'openNotes', 'toggleLeftSidebar', 'toggleRightSidebar'])

// Количество активных (незакрытых) багов и задач
const activeNotesCount = computed(() => {
  return store.notes.filter(n => !n.done).length
})

function handleOpenNotes() {
  store.isNotesOpen = true
  emit('openNotes')
  if (!store.notes.length) {
    store.fetchNotes()
  }
}
</script>

<template>
  <header class="h-14 border-b border-gray-800 bg-gray-900/50 backdrop-blur px-3 md:px-4 flex items-center justify-between shrink-0 z-20">
    <div class="flex items-center space-x-2 md:space-x-3 min-w-0">
      <!-- Гамбургер для левого сайдбара на мобилках -->
      <button 
        type="button" 
        @click="emit('toggleLeftSidebar')"
        class="md:hidden p-1.5 text-gray-400 hover:text-white hover:bg-gray-800 rounded-lg transition shrink-0"
        title="Пресеты и сессии"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <div class="w-8 h-8 rounded-lg bg-indigo-600 flex items-center justify-center font-bold text-white shadow-lg shadow-indigo-500/30 shrink-0">
        AI
      </div>
      
      <div class="min-w-0">
        <h1 class="text-xs md:text-sm font-semibold text-gray-200 truncate max-w-[100px] sm:max-w-[200px]">
          {{ store.activePresetObj?.name || 'Загрузка...' }}
        </h1>
        <p class="text-[10px] md:text-[11px] text-gray-500 truncate max-w-[100px] sm:max-w-[180px]">
          {{ store.activeSessionObj?.name || 'Выберите сессию' }}
        </p>
      </div>
    </div>

    <div class="flex items-center space-x-1.5 md:space-x-2.5 shrink-0">
      <!-- Кнопка: Заметки (Баги / Фичи) -->
      <button 
        type="button" 
        @click="handleOpenNotes"
        class="px-2 md:px-3 py-1.5 bg-emerald-500/10 hover:bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-lg text-xs font-medium transition flex items-center space-x-1.5 cursor-pointer"
        title="Заметки по проекту, багам и фичам"
      >
        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
        </svg>
        <span class="hidden sm:inline">Заметки</span>
        <span 
          v-if="activeNotesCount > 0"
          class="bg-emerald-500/30 text-emerald-300 text-[10px] px-1.5 py-0.2 rounded-full font-bold"
        >
          {{ activeNotesCount }}
        </span>
      </button>

      <!-- Кнопка: Словарь -->
            <button 
        v-if="store.hasDictionary"
        type="button" 
        @click="emit('openDict')"
        class="px-2 md:px-3 py-1.5 bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/30 rounded-lg text-xs font-medium transition flex items-center space-x-1 cursor-pointer"
      >
        <span>Словарь</span>
        <span class="bg-amber-500/30 text-amber-300 text-[10px] px-1.5 py-0.5 rounded-full font-bold">
          {{ store.dictionary.active_buffer?.length || 0 }}
        </span>
      </button>

      <!-- Кнопка: Новая сессия -->
      <button 
        type="button" 
        @click="emit('openNewSession')"
        class="p-1.5 sm:px-3 sm:py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-medium shadow-md shadow-indigo-600/20 transition flex items-center space-x-1 cursor-pointer shrink-0"
        title="Новая сессия"
      >
        <svg class="w-5 h-5 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/>
        </svg>
        <span class="hidden sm:inline">Новая сессия</span>
      </button>

      <!-- Кнопка правого сайдбара на мобилках -->
      <button 
        type="button" 
        @click="emit('toggleRightSidebar')"
        class="md:hidden p-1.5 text-indigo-400 hover:bg-indigo-950/50 border border-indigo-500/30 rounded-lg transition shrink-0"
        title="Параметры и файлы"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
        </svg>
      </button>
    </div>
  </header>
</template>