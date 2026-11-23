<script setup>
import { computed, onMounted, ref, watch, nextTick } from 'vue'
import { renderMarkdown, highlightCode } from '../../utils/formatters'

const props = defineProps({
  role: String,
  content: String,
  reasoningContent: String,
  isError: Boolean
})

const messageRef = ref(null)

const formattedContent = computed(() => renderMarkdown(props.content))
const formattedThinking = computed(() => props.reasoningContent ? renderMarkdown(props.reasoningContent) : '')

// Конвертация HTML-таблицы в TSV формат (удобно для вставки в Excel/заметки)
function tableToText(table) {
  const rows = Array.from(table.querySelectorAll('tr'))
  return rows.map(row => {
    const cells = Array.from(row.querySelectorAll('th, td'))
    return cells.map(c => c.textContent.trim()).join('\t')
  }).join('\n')
}

// Добавление кнопок копирования в нижнюю панель (футер) блоков
function enhanceElements() {
  if (!messageRef.value) return

  // 1. Подсветка кода
  highlightCode(messageRef.value)

  // 2. Оформление блоков кода (<pre>) с плашкой внизу
  const pres = messageRef.value.querySelectorAll('pre')
  pres.forEach(pre => {
    if (pre.parentElement?.classList.contains('code-block-wrapper')) return

    // Обёртка блока кода
    const wrapper = document.createElement('div')
    wrapper.className = 'code-block-wrapper my-3 rounded-xl border border-gray-700/60 overflow-hidden bg-gray-950/80 shadow-md'

    // Нижняя панель с кнопкой
    const footer = document.createElement('div')
    footer.className = 'flex items-center justify-end px-3 py-1.5 bg-gray-900/90 border-t border-gray-800/80'

    const btn = document.createElement('button')
    btn.className = 'copy-code-btn px-2.5 py-1 text-[11px] font-sans bg-gray-800 hover:bg-gray-700 text-gray-300 hover:text-white rounded-md transition cursor-pointer flex items-center gap-1.5 border border-gray-700/50 touch-manipulation'
    btn.innerHTML = '📋 <span>Копировать код</span>'

    btn.onclick = async (e) => {
      e.stopPropagation()
      const code = pre.querySelector('code')?.innerText || pre.innerText
      await navigator.clipboard.writeText(code)
      btn.innerHTML = '✅ <span>Скопировано!</span>'
      setTimeout(() => {
        btn.innerHTML = '📋 <span>Копировать код</span>'
      }, 2000)
    }

    pre.parentNode.insertBefore(wrapper, pre)
    wrapper.appendChild(pre)
    footer.appendChild(btn)
    wrapper.appendChild(footer)
  })

  // 3. Оформление таблиц (<table>) с плашкой внизу
  const tables = messageRef.value.querySelectorAll('table')
  tables.forEach(table => {
    if (table.closest('.table-wrapper')) return

    const wrapper = document.createElement('div')
    wrapper.className = 'table-wrapper my-3 rounded-xl border border-gray-700/60 overflow-hidden bg-gray-950/40 shadow-md'

    const scrollContainer = document.createElement('div')
    scrollContainer.className = 'table-container overflow-x-auto custom-scrollbar'
    table.className = 'w-full text-left border-collapse text-xs sm:text-sm min-w-full'

    // Нижняя панель для таблицы
    const footer = document.createElement('div')
    footer.className = 'flex items-center justify-end px-3 py-1.5 bg-gray-900/90 border-t border-gray-800/80'

    const btn = document.createElement('button')
    btn.className = 'copy-table-btn px-2.5 py-1 text-[11px] font-sans bg-gray-800 hover:bg-gray-700 text-gray-300 hover:text-white rounded-md transition cursor-pointer flex items-center gap-1.5 border border-gray-700/50 touch-manipulation'
    btn.innerHTML = '📊 <span>Скопировать таблицу</span>'

    btn.onclick = async (e) => {
      e.stopPropagation()
      const text = tableToText(table)
      await navigator.clipboard.writeText(text)
      btn.innerHTML = '✅ <span>Скопировано!</span>'
      setTimeout(() => {
        btn.innerHTML = '📊 <span>Скопировать таблицу</span>'
      }, 2000)
    }

    table.parentNode.insertBefore(wrapper, table)
    scrollContainer.appendChild(table)
    wrapper.appendChild(scrollContainer)
    footer.appendChild(btn)
    wrapper.appendChild(footer)
  })
}

function updateDOM() {
  nextTick(enhanceElements)
}

onMounted(updateDOM)
watch(() => props.content, updateDOM)
</script>

<template>
  <div 
    ref="messageRef"
    class="flex items-start max-w-full md:max-w-3xl my-2 sm:my-3"
    :class="role === 'user' ? 'justify-end ml-auto' : 'space-x-2 sm:space-x-3'"
  >
    <div v-if="role !== 'user'" class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg bg-indigo-600/20 text-indigo-400 border border-indigo-500/30 flex items-center justify-center shrink-0 font-bold text-[10px] sm:text-xs select-none">
      AI
    </div>

    <div 
      class="rounded-2xl p-3 sm:p-4 text-xs sm:text-sm leading-relaxed shadow-sm overflow-hidden w-full max-w-[92%] sm:max-w-full"
      :class="[
        role === 'user' ? 'bg-indigo-600 text-white whitespace-pre-wrap' : 'bg-gray-800/80 border border-gray-700/50 text-gray-200',
        isError ? 'bg-rose-950/40 border border-rose-800/50 text-rose-200' : ''
      ]"
    >
      <!-- Блок мыслей ИИ -->
      <details v-if="reasoningContent" class="mb-3 bg-amber-950/20 border border-amber-800/40 rounded-xl overflow-hidden" open>
        <summary class="cursor-pointer px-3 py-1.5 text-xs font-semibold text-amber-400 hover:bg-amber-900/30 transition select-none">
          🧠 Ход размышлений ИИ
        </summary>
        <div class="p-3 text-xs font-mono text-amber-200/80 bg-amber-950/40 whitespace-pre-wrap border-t border-amber-800/30 max-h-60 overflow-y-auto" v-html="formattedThinking"></div>
      </details>

      <!-- Основное тело сообщения -->
      <div v-if="role === 'user'">{{ content }}</div>
      <div v-else class="markdown-body space-y-2" v-html="formattedContent"></div>
    </div>
  </div>
</template>
