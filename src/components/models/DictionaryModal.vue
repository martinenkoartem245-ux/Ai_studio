<script setup>
import { ref, computed, nextTick } from 'vue'
import { useChatStore } from '../../stores/chatStore'

const emit = defineEmits(['close'])
const store = useChatStore()

const newWord = ref('')
const searchQuery = ref('')
const activeTab = ref('active') // 'active' | 'master'
const isSubmitting = ref(false)
const inputRef = ref(null)

// Вспомогательные функции
function getTerm(item) {
  if (!item) return ''
  if (typeof item === 'string') return item
  return item.term || item.word || ''
}

function getCategory(item) {
  if (typeof item !== 'object' || !item) return 'B1_B2'
  return item.category || 'B1_B2'
}

function getScore(item) {
  if (typeof item !== 'object' || !item) return 0
  return item.score ?? 0
}

function getStatus(item) {
  if (typeof item !== 'object' || !item) return 'new'
  return item.status || 'new'
}

// Подмена наименований категорий
function formatCategory(category) {
  switch (category) {
    case 'B1_B2': return 'B1-B2'
    case 'C1_PLUS': return 'C1+'
    case 'GRAMMAR': return 'Grammar'
    case 'BUSINESS': return 'Business'
    default: return category || 'B1-B2'
  }
}

// Цвета для категорий
function getCategoryBadgeClass(category) {
  switch (category) {
    case 'C1_PLUS':
      return 'bg-purple-500/15 text-purple-300 border-purple-500/30'
    case 'BUSINESS':
      return 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
    case 'GRAMMAR':
      return 'bg-rose-500/15 text-rose-300 border-rose-500/30'
    case 'B1_B2':
    default:
      return 'bg-amber-500/15 text-amber-300 border-amber-500/30'
  }
}

// Цвета для статусов
function getStatusBadgeClass(status) {
  switch (status) {
    case 'mastered':
      return 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
    case 'learning':
      return 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30'
    case 'new':
    default:
      return 'bg-gray-800 text-gray-400 border-gray-700'
  }
}

function getStatusLabel(status) {
  switch (status) {
    case 'mastered': return 'Изучено'
    case 'learning': return 'В процессе'
    case 'new': return 'Новое'
    default: return status
  }
}

// Фильтрация поиска
const filteredActiveBuffer = computed(() => {
  const list = store.dictionary?.active_buffer || []
  if (!searchQuery.value.trim()) return list
  const q = searchQuery.value.toLowerCase().trim()
  return list.filter(item => getTerm(item).toLowerCase().includes(q))
})

const filteredMasterList = computed(() => {
  const list = store.dictionary?.master || []
  if (!searchQuery.value.trim()) return list
  const q = searchQuery.value.toLowerCase().trim()
  return list.filter(item => getTerm(item).toLowerCase().includes(q))
})

async function handleAddWord() {
  const word = newWord.value.trim()
  if (!word || isSubmitting.value) return

  isSubmitting.value = true
  const success = await store.addWord(word)
  isSubmitting.value = false

  if (success) {
    newWord.value = ''
    await nextTick()
    inputRef.value?.focus()
  }
}
</script>

<template>
  <!-- Внешний оверлей -->
  <div 
    class="fixed inset-0 z-50 bg-black/75 backdrop-blur-md flex items-center justify-center p-4"
    @click.self="emit('close')"
  >
    <!-- Контейнер модального окна -->
    <div class="bg-gray-900 border border-gray-800 rounded-2xl w-full max-w-xl shadow-2xl overflow-hidden flex flex-col max-h-[85vh]">
      
      <!-- Шапка -->
      <div class="px-5 py-4 border-b border-gray-800 flex items-center justify-between bg-gray-900/80">
        <div class="flex items-center space-x-3">
          <div class="p-2 bg-amber-500/10 border border-amber-500/20 rounded-xl text-amber-400">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
            </svg>
          </div>
          <div>
            <h3 class="text-sm font-bold text-gray-100">Словарь пресета</h3>
            <p class="text-[11px] text-gray-400">Управление терминологией и активным буфером</p>
          </div>
        </div>
        
        <button 
          type="button" 
          @click="emit('close')"
          class="p-1.5 text-gray-400 hover:text-gray-200 hover:bg-gray-800 rounded-lg transition cursor-pointer"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>

      <!-- Вкладки и поиск -->
      <div class="px-5 pt-3 pb-2 border-b border-gray-800 bg-gray-950/40 flex flex-col gap-3">
        <div class="flex items-center justify-between">
          <div class="flex gap-4">
            <button
              type="button"
              @click="activeTab = 'active'"
              class="pb-2 text-xs font-semibold border-b-2 transition cursor-pointer flex items-center space-x-1.5"
              :class="activeTab === 'active' ? 'border-amber-500 text-amber-400' : 'border-transparent text-gray-400 hover:text-gray-200'"
            >
              <span>Активный буфер</span>
              <span class="bg-amber-500/20 text-amber-300 text-[10px] px-2 py-0.5 rounded-full font-bold">
                {{ store.dictionary.active_buffer?.length || 0 }}
              </span>
            </button>

            <button
              type="button"
              @click="activeTab = 'master'"
              class="pb-2 text-xs font-semibold border-b-2 transition cursor-pointer flex items-center space-x-1.5"
              :class="activeTab === 'master' ? 'border-indigo-500 text-indigo-400' : 'border-transparent text-gray-400 hover:text-gray-200'"
            >
              <span>Мастер-словарь</span>
              <span class="bg-gray-800 text-gray-400 text-[10px] px-2 py-0.5 rounded-full font-bold">
                {{ store.dictionary.master?.length || 0 }}
              </span>
            </button>
          </div>
        </div>

        <div class="relative">
          <input 
            v-model="searchQuery"
            type="text"
            placeholder="Поиск по термину..."
            class="w-full bg-gray-900/90 border border-gray-800 rounded-lg pl-8 pr-3 py-1.5 text-xs text-gray-200 placeholder-gray-500 focus:outline-none focus:border-gray-700 transition"
          />
          <svg class="w-3.5 h-3.5 text-gray-500 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
        </div>
      </div>

      <!-- Содержимое списка -->
      <div class="p-4 flex-1 overflow-y-auto space-y-2 min-h-[260px] max-h-[420px] custom-scrollbar">
        
        <!-- Активный Буфер -->
        <div v-if="activeTab === 'active'">
          <div v-if="!filteredActiveBuffer.length" class="text-center py-12 text-xs text-gray-500">
            {{ searchQuery ? 'Ничего не найдено' : 'Активный буфер пока пуст' }}
          </div>
          <div v-else class="grid grid-cols-1 gap-2">
            <div 
              v-for="(item, idx) in filteredActiveBuffer" 
              :key="idx"
              class="p-3 bg-gray-950/60 border border-gray-800/80 hover:border-amber-500/30 rounded-xl transition flex items-center justify-between group"
            >
              <!-- Слева: Термин -->
              <div class="flex flex-col min-w-0 pr-3">
                <span class="text-xs font-semibold text-amber-200 group-hover:text-amber-100 transition truncate">
                  {{ getTerm(item) }}
                </span>
              </div>

              <!-- Справа: Уровень + Отступ + Очки -->
              <div class="flex items-center gap-2 shrink-0">
                <span 
                  class="w-[68px] inline-flex items-center justify-center py-1 border text-[10px] rounded-md font-semibold tracking-wider uppercase"
                  :class="getCategoryBadgeClass(getCategory(item))"
                >
                  {{ formatCategory(getCategory(item)) }}
                </span>
                
                <div class="w-20 inline-flex items-center justify-center space-x-1 bg-amber-500/10 border border-amber-500/20 py-1 rounded-md text-[11px] text-amber-300 font-mono">
                  <span class="text-[10px]">★</span>
                  <span class="font-bold">{{ getScore(item) }}</span>
                  <span class="text-amber-500/60 text-[9px]">/10</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Мастер-Словарь -->
        <div v-if="activeTab === 'master'">
          <div v-if="!filteredMasterList.length" class="text-center py-12 text-xs text-gray-500">
            {{ searchQuery ? 'Ничего не найдено' : 'Мастер-словарь пуст' }}
          </div>
          <div v-else class="grid grid-cols-1 gap-2">
            <div 
              v-for="(item, idx) in filteredMasterList" 
              :key="idx"
              class="p-3 bg-gray-950/40 border border-gray-800/60 hover:border-indigo-500/30 rounded-xl transition flex items-center justify-between group"
            >
              <!-- Слева: Термин -->
              <div class="flex flex-col min-w-0 pr-3">
                <span class="text-xs font-semibold text-gray-200 group-hover:text-gray-100 transition truncate">
                  {{ getTerm(item) }}
                </span>
              </div>

              <!-- Справа: Уровень + Отступ + Статус -->
              <div class="flex items-center gap-2 shrink-0">
                <span 
                  class="w-[68px] inline-flex items-center justify-center py-1 border text-[10px] rounded-md font-semibold tracking-wider uppercase"
                  :class="getCategoryBadgeClass(getCategory(item))"
                >
                  {{ formatCategory(getCategory(item)) }}
                </span>

                <span 
                  class="w-24 inline-flex items-center justify-center py-1 border text-[10px] rounded-md font-medium text-center"
                  :class="getStatusBadgeClass(getStatus(item))"
                >
                  {{ getStatusLabel(getStatus(item)) }}
                </span>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- Форма добавления нового слова -->
      <div class="p-4 border-t border-gray-800 bg-gray-950/80">
        <form @submit.prevent="handleAddWord" class="flex items-center gap-2">
          <input 
            ref="inputRef"
            v-model="newWord"
            type="text" 
            :disabled="isSubmitting"
            placeholder="Введите новое слово или термин..." 
            class="flex-1 bg-gray-900 border border-gray-700 focus:border-amber-500 rounded-xl px-3.5 py-2 text-xs text-gray-100 placeholder-gray-500 focus:outline-none transition"
          />
          <button 
            type="submit" 
            :disabled="!newWord.trim() || isSubmitting"
            class="px-4 py-2 bg-amber-600 hover:bg-amber-500 disabled:opacity-40 text-white rounded-xl text-xs font-semibold transition shrink-0 cursor-pointer flex items-center space-x-1"
          >
            <span>{{ isSubmitting ? 'Сохранение...' : 'Добавить' }}</span>
          </button>
        </form>
      </div>

    </div>
  </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: rgba(0, 0, 0, 0.2);
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.15);
  border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.25);
}
</style>