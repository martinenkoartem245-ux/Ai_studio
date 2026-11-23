<script setup>
import { ref, computed, onMounted } from 'vue'
import { useChatStore } from '../../stores/chatStore'

const store = useChatStore()

const currentFilter = ref('all') // 'all' | 'bug' | 'feature' | 'note'
const isAdding = ref(false)

const newTitle = ref('')
const newContent = ref('')
const newType = ref('bug')

onMounted(() => {
  store.fetchNotes()
})

const filteredNotes = computed(() => {
  const list = Array.isArray(store.notes) ? store.notes : []
  if (currentFilter.value === 'all') return list
  return list.filter(n => n.type === currentFilter.value)
})


async function handleCreate() {
  if (!newTitle.value.trim()) return
  await store.addNote({
    title: newTitle.value,
    content: newContent.value,
    type: newType.value
  })
  newTitle.value = ''
  newContent.value = ''
  isAdding.value = false
}

function formatDate(isoStr) {
  if (!isoStr) return ''
  const d = new Date(isoStr)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}
</script>

<template>
  <div 
    v-if="store.isNotesOpen" 
    class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-black/70 backdrop-blur-sm animate-fade-in"
    @click.self="store.isNotesOpen = false"
  >
    <div class="bg-gray-900 border border-gray-800 rounded-xl w-full max-w-2xl max-h-[85vh] flex flex-col shadow-2xl overflow-hidden">
      <!-- Заголовок модалки -->
      <div class="px-4 py-3 border-b border-gray-800 flex items-center justify-between bg-gray-900/80">
        <div class="flex items-center space-x-2">
          <div class="p-1.5 bg-emerald-500/20 text-emerald-400 rounded-lg">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
            </svg>
          </div>
          <div>
            <h2 class="text-sm font-semibold text-gray-100">Глобальные заметки</h2>
            <p class="text-[11px] text-gray-400">Трекер багов, фич и идей проекта</p>
          </div>
        </div>

        <button 
          @click="store.isNotesOpen = false" 
          class="p-1.5 text-gray-400 hover:text-white rounded-lg hover:bg-gray-800 transition"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Вкладки фильтрации и кнопка добавления -->
      <div class="px-4 py-2 border-b border-gray-800 bg-gray-950/40 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center space-x-1">
          <button 
            @click="currentFilter = 'all'"
            :class="currentFilter === 'all' ? 'bg-gray-800 text-white' : 'text-gray-400 hover:text-gray-200'"
            class="px-2.5 py-1 text-xs rounded-md font-medium transition cursor-pointer"
          >
            Все ({{ store.notes.length }})
          </button>
          <button 
            @click="currentFilter = 'bug'"
            :class="currentFilter === 'bug' ? 'bg-rose-500/20 text-rose-300 border border-rose-500/30' : 'text-gray-400 hover:text-gray-200'"
            class="px-2.5 py-1 text-xs rounded-md font-medium transition cursor-pointer flex items-center space-x-1"
          >
            <span>🐞 Баги</span>
          </button>
          <button 
            @click="currentFilter = 'feature'"
            :class="currentFilter === 'feature' ? 'bg-sky-500/20 text-sky-300 border border-sky-500/30' : 'text-gray-400 hover:text-gray-200'"
            class="px-2.5 py-1 text-xs rounded-md font-medium transition cursor-pointer flex items-center space-x-1"
          >
            <span>🚀 Фичи</span>
          </button>
          <button 
            @click="currentFilter = 'note'"
            :class="currentFilter === 'note' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/30' : 'text-gray-400 hover:text-gray-200'"
            class="px-2.5 py-1 text-xs rounded-md font-medium transition cursor-pointer flex items-center space-x-1"
          >
            <span>📝 Заметки</span>
          </button>
        </div>

        <button 
          @click="isAdding = !isAdding"
          class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 text-white text-xs rounded-md font-medium transition flex items-center space-x-1 cursor-pointer"
        >
          <span>{{ isAdding ? 'Отмена' : '+ Добавить' }}</span>
        </button>
      </div>

      <!-- Форма создания заметки -->
      <div v-if="isAdding" class="p-4 bg-gray-950/80 border-b border-gray-800 animate-slide-down">
        <div class="space-y-3">
          <div class="flex items-center space-x-2">
            <select 
              v-model="newType" 
              class="bg-gray-800 border border-gray-700 text-gray-200 text-xs rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-emerald-500"
            >
              <option value="bug">🐞 Баг</option>
              <option value="feature">🚀 Фича</option>
              <option value="note">📝 Заметка</option>
            </select>
            <input 
              v-model="newTitle" 
              type="text" 
              placeholder="Краткое описание задачи / бага..." 
              class="flex-1 bg-gray-800 border border-gray-700 text-gray-100 text-xs rounded-lg px-3 py-1.5 focus:outline-none focus:border-emerald-500"
              @keydown.enter="handleCreate"
            />
          </div>
          <textarea 
            v-model="newContent" 
            rows="2" 
            placeholder="Подробности (шаги воспроизведения, детали реализации)..." 
            class="w-full bg-gray-800 border border-gray-700 text-gray-200 text-xs rounded-lg p-2.5 focus:outline-none focus:border-emerald-500 resize-none"
          ></textarea>
          <div class="flex justify-end space-x-2">
            <button 
              @click="isAdding = false" 
              class="px-3 py-1 text-xs text-gray-400 hover:text-gray-200"
            >
              Отмена
            </button>
            <button 
              @click="handleCreate" 
              class="px-3 py-1 bg-emerald-600 hover:bg-emerald-500 text-white text-xs rounded-lg font-medium transition cursor-pointer"
            >
              Сохранить
            </button>
          </div>
        </div>
      </div>

      <!-- Список заметок -->
      <div class="flex-1 overflow-y-auto p-4 space-y-2.5 custom-scrollbar">
        <div v-if="!filteredNotes.length" class="text-center py-10 text-gray-500 text-xs">
          Заметок в этой категории пока нет.
        </div>

        <div 
          v-for="item in filteredNotes" 
          :key="item.id"
          :class="[
            'p-3 rounded-lg border transition flex flex-col space-y-1.5',
            item.done ? 'bg-gray-950/40 border-gray-800/60 opacity-60' : 'bg-gray-800/40 border-gray-700/60 hover:border-gray-600'
          ]"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="flex items-start space-x-2 min-w-0">
              <!-- Чекбокс завершения -->
              <input 
                type="checkbox" 
                :checked="item.done" 
                @change="store.toggleNoteStatus(item.id)"
                class="mt-0.5 rounded bg-gray-900 border-gray-700 text-emerald-500 focus:ring-0 cursor-pointer"
              />

              <div class="min-w-0">
                <div class="flex items-center space-x-2">
                  <span 
                    :class="{
                      'bg-rose-500/20 text-rose-300 border-rose-500/30': item.type === 'bug',
                      'bg-sky-500/20 text-sky-300 border-sky-500/30': item.type === 'feature',
                      'bg-amber-500/20 text-amber-300 border-amber-500/30': item.type === 'note'
                    }"
                    class="text-[10px] font-bold px-1.5 py-0.5 rounded border uppercase shrink-0"
                  >
                    {{ item.type === 'bug' ? 'BUG' : (item.type === 'feature' ? 'FEATURE' : 'NOTE') }}
                  </span>
                  
                  <span 
                    :class="item.done ? 'line-through text-gray-500' : 'text-gray-100 font-medium'"
                    class="text-xs break-words"
                  >
                    {{ item.title }}
                  </span>
                </div>

                <p 
                  v-if="item.content" 
                  class="text-[11px] text-gray-400 mt-1 whitespace-pre-wrap break-words pl-0.5"
                >
                  {{ item.content }}
                </p>
              </div>
            </div>

            <!-- Кнопка удаления и дата -->
            <div class="flex items-center space-x-2 shrink-0">
              <span class="text-[10px] text-gray-500">{{ formatDate(item.created_at) }}</span>
              <button 
                @click="store.deleteNote(item.id)" 
                class="text-gray-500 hover:text-rose-400 p-1 transition"
                title="Удалить"
              >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>