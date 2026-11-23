<script setup>
import { ref, computed, watch } from 'vue'
import { useChatStore } from '../../stores/chatStore'
import MaxTokensControl from './MaxTokensControl.vue'

const props = defineProps({
  modelConfig: { type: Object, required: true }
})

const store = useChatStore()
const showExactBudget = ref(false)

// Гарантированный резерв токенов для финального ответа ИИ
const ANSWER_RESERVE = 1024

const budgetStep = computed(() => (props.modelConfig.provider === 'kimi' ? 2048 : 512))

// Минимальный бюджет размышления с выравниванием по шагу
const budgetMin = computed(() => {
  const providerMin = props.modelConfig.provider === 'claude' ? 1024 : 512
  return Math.ceil(providerMin / budgetStep.value) * budgetStep.value
})

const snap = (v) => Math.floor(v / budgetStep.value) * budgetStep.value

const modelThinkingMax = computed(() => props.modelConfig.maxThinking || 32768)

// Потолок мыслей отталкивается от эффективного лимита ответа
const thinkingCap = computed(() => {
  const available = store.effectiveMaxTokens - ANSWER_RESERVE
  return Math.max(0, snap(Math.min(modelThinkingMax.value, available)))
})

const canThink = computed(() =>
  !!props.modelConfig.supportsThinking && thinkingCap.value >= budgetMin.value
)

const cappedByOutput = computed(() => thinkingCap.value < snap(modelThinkingMax.value))

const clampBudget = (v) =>
  Math.min(thinkingCap.value, Math.max(budgetMin.value, snap(Number(v) || 0)))

// Пресеты мыслей (динамические, гарантированно монотонные)
const thinkingPresets = computed(() => {
  const cap = thinkingCap.value
  const list = [
    { id: 'off', label: 'Выкл', budget: 0 },
    { id: 'low', label: 'Низкий', budget: clampBudget(Math.min(2048, cap * 0.15)) },
    { id: 'medium', label: 'Средний', budget: clampBudget(Math.min(8192, cap * 0.4)) },
    { id: 'high', label: 'Высокий', budget: clampBudget(Math.min(16384, cap * 0.75)) },
    { id: 'max', label: 'Макс', budget: cap }
  ]

  return list.map((p, i) => ({
    ...p,
    disabled: p.id !== 'off' && (!canThink.value || (i > 1 && p.budget === list[i - 1].budget))
  }))
})

// Нахождение наиболее близкого уровня
function levelForBudget(budget) {
  if (!budget || budget <= 0) return 'off'
  let best = 'low'
  let bestDiff = Infinity
  for (const p of thinkingPresets.value) {
    if (p.id === 'off' || p.disabled) continue
    const diff = Math.abs(p.budget - budget)
    if (diff < bestDiff) {
      bestDiff = diff
      best = p.id
    }
  }
  return best
}

function selectThinkingLevel(preset) {
  if (preset.disabled) return
  const p = store.modelExtraParams

  if (preset.id === 'off') {
    p.enable_thinking = false
    p.thinking_level = 'off'
    p.thinking_budget = 0
    showExactBudget.value = false
    return
  }
  p.enable_thinking = true
  p.thinking_level = preset.id
  p.thinking_budget = preset.budget
}

function onBudgetInput(e) {
  const p = store.modelExtraParams
  p.thinking_budget = clampBudget(e.target.value)
  p.thinking_level = levelForBudget(p.thinking_budget)
}

function onToggleExactBudget(e) {
  showExactBudget.value = e.target.checked
  if (e.target.checked && !store.modelExtraParams.enable_thinking) {
    const low = thinkingPresets.value.find((p) => p.id === 'low')
    if (low && !low.disabled) selectThinkingLevel(low)
  }
}

function syncParamsWithModel() {
  const cfg = props.modelConfig
  const p = store.modelExtraParams

  if (!canThink.value) {
    p.enable_thinking = false
    p.thinking_level = 'off'
    p.thinking_budget = 0
    showExactBudget.value = false
  } else if (p.enable_thinking) {
    if (showExactBudget.value) {
      p.thinking_budget = clampBudget(p.thinking_budget)
    } else {
      const preset = thinkingPresets.value.find((x) => x.id === p.thinking_level && !x.disabled)
      p.thinking_budget = preset ? preset.budget : clampBudget(p.thinking_budget)
    }
    p.thinking_level = levelForBudget(p.thinking_budget)
  }

  if (cfg.provider !== 'gemini') p.code_execution = false
  if (!cfg.supportsWebSearch) p.use_web_search = false
}

watch(
  () => [props.modelConfig.id, thinkingCap.value],
  syncParamsWithModel,
  { immediate: true }
)

const providerTheme = computed(() => {
  switch (props.modelConfig.provider) {
    case 'claude':
      return {
        title: 'Параметры Claude',
        textAccent: 'text-amber-400',
        borderAccent: 'border-amber-500/40',
        bgActive: 'bg-amber-600 text-white border-amber-500',
        accentSlider: 'accent-amber-500',
        checkboxColor: 'text-amber-500'
      }
    case 'kimi':
      return {
        title: 'Параметры Kimi',
        textAccent: 'text-emerald-400',
        borderAccent: 'border-emerald-500/40',
        bgActive: 'bg-emerald-600 text-white border-emerald-500',
        accentSlider: 'accent-emerald-500',
        checkboxColor: 'text-emerald-500'
      }
    case 'gemini':
    default:
      return {
        title: 'Параметры Gemini',
        textAccent: 'text-indigo-400',
        borderAccent: 'border-indigo-500/40',
        bgActive: 'bg-indigo-600 text-white border-indigo-500',
        accentSlider: 'accent-indigo-500',
        checkboxColor: 'text-indigo-600'
      }
  }
})
</script>

<template>
  <div class="space-y-4 pt-2 border-t border-gray-800/80">
    <div class="flex items-center justify-between">
      <span class="text-[10px] uppercase font-semibold tracking-wider" :class="providerTheme.textAccent">
        {{ providerTheme.title }}
      </span>
      <span v-if="modelConfig.supportsThinking && store.modelExtraParams.enable_thinking" class="text-[10px] font-mono text-gray-400">
        Max Think: {{ thinkingCap.toLocaleString('ru-RU') }} tks
      </span>
    </div>

    <!-- МОДУЛЬ ВЫБОРА УРОВНЯ РАЗМЫШЛЕНИЯ -->
    <div v-if="modelConfig.supportsThinking" class="space-y-2">
      <label class="block text-[11px] font-medium text-gray-300">
        Уровень размышления (Reasoning Effort)
      </label>

      <div class="grid grid-cols-5 gap-1">
        <button
          v-for="preset in thinkingPresets"
          :key="preset.id"
          type="button"
          :disabled="preset.disabled"
          :title="preset.budget ? `${preset.budget.toLocaleString('ru-RU')} tks` : 'Без размышлений'"
          @click="selectThinkingLevel(preset)"
          :class="[
            'py-1.5 px-0.5 text-[10px] font-medium rounded-lg border transition text-center truncate',
            'disabled:opacity-35 disabled:cursor-not-allowed cursor-pointer',
            store.modelExtraParams.thinking_level === preset.id
              ? providerTheme.bgActive
              : 'bg-gray-800/80 text-gray-400 border-gray-700/60 hover:bg-gray-700 hover:text-gray-200'
          ]"
        >
          {{ preset.label }}
        </button>
      </div>

      <p v-if="!canThink" class="text-[10px] text-amber-400/90 pt-0.5">
        Лимит ответа слишком мал для размышлений — увеличьте его ниже.
      </p>
      <p v-else-if="cappedByOutput" class="text-[10px] text-gray-500 pt-0.5">
        Потолок ограничен лимитом ответа. Модель поддерживает до {{ modelThinkingMax.toLocaleString('ru-RU') }} tks.
      </p>

      <!-- Чекбокс показа точного слайдера -->
      <div class="pt-1">
        <label class="flex items-center space-x-2 text-xs text-gray-300" :class="canThink ? 'cursor-pointer' : 'opacity-50 cursor-not-allowed'">
          <input
            type="checkbox"
            :checked="showExactBudget"
            :disabled="!canThink"
            @change="onToggleExactBudget"
            class="rounded bg-gray-800 border-gray-700 focus:ring-0 cursor-pointer"
            :class="providerTheme.checkboxColor"
          />
          <span>Задать точный бюджет размышлений</span>
        </label>
      </div>
  
      <!-- ПОЛЗУНОК ТОЧНОГО БЮДЖЕТА -->
      <div 
        v-if="showExactBudget && store.modelExtraParams.enable_thinking" 
        class="pl-2.5 border-l-2 space-y-1.5 pt-2 mt-2 transition-all" 
        :class="providerTheme.borderAccent"
      >
        <div class="flex justify-between text-[11px] text-gray-400">
          <span>Бюджет размышлений</span>
          <span class="font-mono text-gray-200 font-semibold">
            {{ (store.modelExtraParams.thinking_budget || 0).toLocaleString('ru-RU') }} tks
          </span>
        </div>

        <input 
          type="range" 
          :min="budgetMin" 
          :max="thinkingCap" 
          :step="budgetStep" 
          :value="store.modelExtraParams.thinking_budget"
          @input="onBudgetInput"
          class="w-full cursor-pointer"
          :class="providerTheme.accentSlider"
        />
      </div>
    </div>

    <!-- Управление макс. токенами ответа -->
    <MaxTokensControl :modelConfig="modelConfig" :accentClass="providerTheme.accentSlider" />

    <!-- СПЕЦИФИЧНЫЕ ФЛАГИ -->
    <div class="space-y-2 pt-1 border-t border-gray-800/60">
      <template v-if="modelConfig.provider === 'gemini'">
        <label class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer">
          <input 
            type="checkbox" 
            v-model="store.modelExtraParams.use_web_search" 
            class="rounded bg-gray-800 border-gray-700 text-indigo-600 focus:ring-0 cursor-pointer" 
          />
          <span>Интернет поиск</span>
        </label>
        <label class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer">
          <input 
            type="checkbox" 
            v-model="store.modelExtraParams.code_execution" 
            class="rounded bg-gray-800 border-gray-700 text-indigo-600 focus:ring-0 cursor-pointer" 
          />
          <span>Python Code Execution</span>
        </label>
      </template>

      <label v-if="modelConfig.provider === 'claude'" class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer">
        <input 
          type="checkbox" 
          v-model="store.modelExtraParams.use_web_search" 
          class="rounded bg-gray-800 border-gray-700 text-amber-500 focus:ring-0 cursor-pointer" 
        />
        <span>Интернет поиск</span>
      </label>

      <label v-if="modelConfig.provider === 'kimi' && modelConfig.supportsWebSearch" class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer">
        <input 
          type="checkbox" 
          v-model="store.modelExtraParams.use_web_search" 
          class="rounded bg-gray-800 border-gray-700 text-emerald-500 focus:ring-0 cursor-pointer" 
        />
        <span>Интернет поиск</span>
      </label>

      <label class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer">
        <input 
          type="checkbox" 
          v-model="store.modelExtraParams.telegram_notify" 
          class="rounded bg-gray-800 border-gray-700 focus:ring-0 cursor-pointer" 
          :class="providerTheme.checkboxColor"
        />
        <span>Сообщение в телеграмм</span>
      </label>

      <label class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer">
        <input 
          type="checkbox" 
          v-model="store.modelExtraParams.time_in_chat" 
          class="rounded bg-gray-800 border-gray-700 focus:ring-0 cursor-pointer" 
          :class="providerTheme.checkboxColor"
        />
        <span>Время в беседе</span>
      </label>

      <label class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer">
        <input 
          type="checkbox" 
          v-model="store.modelExtraParams.set_period" 
          class="rounded bg-gray-800 border-gray-700 focus:ring-0 cursor-pointer" 
          :class="providerTheme.checkboxColor"
        />
        <span>Установка периода</span>
      </label>
    </div>

    <!-- ТЕМПЕРАТУРА (Скрывается при Claude Thinking) -->
    <div v-if="!(modelConfig.provider === 'claude' && store.modelExtraParams.enable_thinking)" class="pt-2 border-t border-gray-800/60">
      <div class="flex justify-between text-[11px] text-gray-400 mb-1">
        <span>Температура</span>
        <span class="font-mono text-gray-200">{{ store.modelExtraParams.temperature ?? 0.7 }}</span>
      </div>
      <input 
        type="range" min="0" max="1" step="0.05" 
        v-model.number="store.modelExtraParams.temperature" 
        class="w-full cursor-pointer"
        :class="providerTheme.accentSlider"
      />
    </div>
  </div>
</template>
