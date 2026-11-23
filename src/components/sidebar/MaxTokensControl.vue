<script setup>
import { computed } from 'vue'
import { useChatStore } from '../../stores/chatStore'

const props = defineProps({
  modelConfig: { type: Object, default: null },
  accentClass: { type: String, default: 'accent-indigo-500' }
})

const store = useChatStore()

const MIN = 256
const STEP = 256

const maxLimit = computed(() => props.modelConfig?.maxOutputTokens || 65536)

const enabled = computed({
  get: () => store.maxTokensEnabled,
  set: (v) => store.toggleMaxTokensEnabled(v)
})

const limit = computed({
  get: () => Math.min(store.maxTokensLimit, maxLimit.value),
  set: (v) => store.setMaxTokensLimit(v)
})

const displayValue = computed(() => store.effectiveMaxTokens.toLocaleString('ru-RU'))
</script>

<template>
  <div class="space-y-2 pt-2 border-t border-gray-800/80">
    <label class="flex items-center space-x-2 text-xs text-gray-300 cursor-pointer">
      <input
        type="checkbox"
        v-model="enabled"
        class="rounded bg-gray-800 border-gray-700 text-indigo-600 focus:ring-0 cursor-pointer"
      />
      <span>Задать макс. токенов ответа</span>
    </label>

    <div v-if="enabled" class="pl-2.5 border-l-2 border-indigo-500/40 space-y-1.5 pt-1">
      <div class="flex justify-between text-[11px] text-gray-400">
        <span>Лимит ответа</span>
        <span class="font-mono text-gray-200 font-semibold">{{ displayValue }} tks</span>
      </div>
      <input
        type="range"
        :min="MIN"
        :max="maxLimit"
        :step="STEP"
        v-model.number="limit"
        class="w-full cursor-pointer"
        :class="accentClass"
      />
      <div class="flex justify-between text-[9px] text-gray-500 font-mono">
        <span>{{ MIN }}</span>
        <span>{{ maxLimit.toLocaleString('ru-RU') }}</span>
      </div>
    </div>

    <div v-else class="text-[10px] text-gray-500">
      Фиксировано: {{ store.effectiveMaxTokens.toLocaleString('ru-RU') }} tks
    </div>
  </div>
</template>
