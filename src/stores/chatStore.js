import { defineStore } from 'pinia'
import { ref, computed, watch } from 'vue'
import { 
  apiRequest, 
  apiStream, 
  setSession, 
  getSession,
  getAuthToken,
  setAuthToken,
  clearAuthToken
} from '../api/client'
import { AI_MODELS } from '../config/models'

const MAX_TOKENS_FIXED = 16384
const MAX_TOKENS_MIN = 256
const MAX_TOKENS_MAX = 128000
const LS_MAX_TOKENS_ENABLED = 'maxTokensEnabled'
const LS_MAX_TOKENS_LIMIT = 'maxTokensLimit'
const LS_EXTRA_PARAMS = 'modelExtraParams'

function clampMaxTokens(value) {
  const n = Number(value)
  if (!Number.isFinite(n)) return 16384
  return Math.min(MAX_TOKENS_MAX, Math.max(MAX_TOKENS_MIN, Math.round(n)))
}

function setSessionModelCookie(preset, session, modelId) {
  if (!preset || !session || !modelId) return
  const name = `session_model_${preset}_${session}`
  const date = new Date()
  date.setTime(date.getTime() + (365 * 24 * 60 * 60 * 1000))
  document.cookie = `${encodeURIComponent(name)}=${encodeURIComponent(modelId)}; expires=${date.toUTCString()}; path=/; SameSite=Lax`
}

function getSessionModelCookie(preset, session) {
  if (!preset || !session) return null
  const nameEQ = `session_model_${preset}_${session}=`
  const ca = document.cookie.split(';')
  for (let i = 0; i < ca.length; i++) {
    let c = ca[i].trim()
    if (c.indexOf(nameEQ) === 0) return decodeURIComponent(c.substring(nameEQ.length))
  }
  return null
}

function loadSavedExtraParams() {
  try {
    return JSON.parse(localStorage.getItem(LS_EXTRA_PARAMS) || '{}')
  } catch {
    return {}
  }
}

export const useChatStore = defineStore('chat', () => {
  // === Состояние сессий и контекста ===
  const sessionConfig = ref({})
  const hasDictionary = computed(() => sessionConfig.value.has_dictionary ?? false)
  let sessionLoadSeq = 0

  const selectedModel = ref(AI_MODELS[0]?.id || '')
  const activeModelMeta = computed(() => AI_MODELS.find(m => m.id === selectedModel.value) || AI_MODELS[0])

  // === Токены и лимиты ===
  const maxTokensEnabled = ref(localStorage.getItem(LS_MAX_TOKENS_ENABLED) === '1')
  const maxTokensLimit = ref(clampMaxTokens(localStorage.getItem(LS_MAX_TOKENS_LIMIT) ?? 8192))

  watch(maxTokensEnabled, (v) => {
    localStorage.setItem(LS_MAX_TOKENS_ENABLED, v ? '1' : '0')
  })

  watch(maxTokensLimit, (v) => {
    localStorage.setItem(LS_MAX_TOKENS_LIMIT, String(clampMaxTokens(v)))
  })

  // Лимит никогда не превышает физический максимум модели
  const effectiveMaxTokens = computed(() => {
    const base = maxTokensEnabled.value ? maxTokensLimit.value : MAX_TOKENS_FIXED
    return Math.min(base, activeModelMeta.value?.maxOutputTokens || MAX_TOKENS_MAX)
  })

  function setMaxTokensLimit(value) {
    maxTokensLimit.value = clampMaxTokens(value)
  }

  function toggleMaxTokensEnabled(enabled) {
    maxTokensEnabled.value = !!enabled
  }

  // === Состояние авторизации туннеля ===
  const isAuthorized = ref(!!getAuthToken())
  const isAuthModalOpen = ref(!getAuthToken())

  if (typeof window !== 'undefined') {
    window.addEventListener('auth:required', () => {
      isAuthorized.value = false
      isAuthModalOpen.value = true
    })
  }

  // === Основное состояние ===
  const globalStructure = ref([])
  const currentPreset = ref(localStorage.getItem('currentPreset') || '')
  const currentSession = ref(getSession() || localStorage.getItem('currentSession') || '')
  
  const isNotesOpen = ref(false)
  const notes = ref([])
  const isNotesLoading = ref(false)

  // === Параметры модели со стойким сохранением ===
  const modelExtraParams = ref({
    temperature: 0.7,
    enable_thinking: true,
    thinking_level: 'high',
    thinking_budget: 8192,
    use_web_search: false,
    code_execution: false,
    enable_code_execution: false,
    enable_url_context: false,
    telegram_notify: false,
    time_in_chat: false,
    set_period: false,
    system_context: '',
    ...loadSavedExtraParams()
  })

  watch(
    modelExtraParams,
    (val) => {
      localStorage.setItem(LS_EXTRA_PARAMS, JSON.stringify(val))
    },
    { deep: true }
  )

  const history = ref([])
  const files = ref([])
  const dictionary = ref({ master: [], active_buffer: [] })
  
  const draftText = ref('')
  const draftThinking = ref('')
  const isGenerating = ref(false)
  const lastError = ref(null)
  
  const usageStats = ref({ promptTokens: 0, completionTokens: 0, cost: 0 })
  const abortController = ref(null)

  // === Вычисляемые свойства ===
  const activePresetObj = computed(() => globalStructure.value.find(p => p.id === currentPreset.value))
  const activeSessionObj = computed(() => activePresetObj.value?.sessions?.find(s => s.id === currentSession.value))

  // === Авторизация ===
  async function handleAuthSuccess(token) {
    setAuthToken(token)
    isAuthorized.value = true
    isAuthModalOpen.value = false
    await fetchStructure()
    await fetchNotes()
  }

  function logout() {
    clearAuthToken()
    isAuthorized.value = false
    isAuthModalOpen.value = true
  }

  // === Загрузка структуры и данных ===
  async function fetchStructure() {
    lastError.value = null
    const res = await apiRequest('get_structure')
    if (res && !res.error) {
      globalStructure.value = res.presets || []
      if (!globalStructure.value.length) return

      let targetPreset = globalStructure.value.find(p => p.id === currentPreset.value) || globalStructure.value[0]
      let targetSession = targetPreset.sessions?.find(s => s.id === currentSession.value) || targetPreset.sessions?.[0]

      if (targetPreset && targetSession) {
        await selectSession(targetPreset.id, targetSession.id)
      }
    } else {
      lastError.value = res?.message || 'Не удалось загрузить структуру'
    }
  }

  async function fetchDictionary(presetId = currentPreset.value, sessionId = currentSession.value) {
    if (!presetId || !sessionId) return null

    const res = await apiRequest('get_dictionary', {
      params: { preset: presetId, session: sessionId }
    })

    if (res && !res.error) {
      dictionary.value = res.dictionary || res.data || res || { master: [], active_buffer: [] }
    }
    return res
  }

  async function selectSession(presetId, sessionId) {
    if (!presetId || !sessionId) return
    const seq = ++sessionLoadSeq

    abortStream({ keepPartial: false })
    currentPreset.value = presetId
    currentSession.value = sessionId

    setSession(sessionId)
    localStorage.setItem('currentPreset', presetId)
    localStorage.setItem('currentSession', sessionId)

    clearDraft()
    usageStats.value = { promptTokens: 0, completionTokens: 0, cost: 0 }

    const savedCookieModel = getSessionModelCookie(presetId, sessionId)
    if (savedCookieModel && AI_MODELS.some(m => m.id === savedCookieModel)) {
      selectedModel.value = savedCookieModel
    }

    const res = await apiRequest('get_session_data', { params: { preset: presetId, session: sessionId } })
    if (seq !== sessionLoadSeq) return // Защита от рассинхрона при быстром переключении

    if (res && !res.error) {
      history.value = res.history || []
      files.value = res.files || []
      sessionConfig.value = res.config || {}

      const meta = res.meta || res.usage || {}
      const lastUsage = meta.last_usage || meta.usage || {}

      usageStats.value = {
        promptTokens: lastUsage.prompt_tokens ?? lastUsage.promptTokens ?? meta.prompt_tokens ?? 0,
        completionTokens: lastUsage.completion_tokens ?? lastUsage.completionTokens ?? meta.completion_tokens ?? 0,
        cost: lastUsage.cost ?? meta.last_cost ?? meta.cost ?? 0
      }

      const hasDict = sessionConfig.value.has_dictionary ?? res.has_dictionary ?? false

      if (hasDict) {
        dictionary.value = res.dictionary || { master: [], active_buffer: [] }
        if (!res.dictionary?.active_buffer?.length && !res.dictionary?.master?.length) {
          await fetchDictionary(presetId, sessionId)
        }
      } else {
        dictionary.value = { master: [], active_buffer: [] }
      }

      if (!savedCookieModel && (res.modelId || res.meta?.model || res.config?.model)) {
        const backendModel = res.modelId || res.meta?.model || res.config?.model
        if (AI_MODELS.some(m => m.id === backendModel)) {
          selectedModel.value = backendModel
        }
      }
    } else {
      lastError.value = res?.message || 'Ошибка загрузки сессии'
    }
  }

  async function setModel(modelId) {
    selectedModel.value = modelId
    if (currentPreset.value && currentSession.value) {
      setSessionModelCookie(currentPreset.value, currentSession.value, modelId)
      const res = await apiRequest('set_model', {
        method: 'GET',
        params: {
          preset: currentPreset.value,
          session: currentSession.value,
          model: modelId
        }
      })
      if (res?.error) {
        console.error('[chatStore] Не удалось сохранить модель:', res.message)
      }
    }
  }

  function updateModelExtraParams(params = {}) {
    modelExtraParams.value = { ...modelExtraParams.value, ...params }
  }

  // === Отправка сообщений ===
  async function sendMessage(text, extraParams = {}) {
    if (isGenerating.value) return
    if (!text.trim() || !currentPreset.value || !currentSession.value) return

    setSession(currentSession.value)
    lastError.value = null

    history.value.push({ role: 'user', content: text })
    isGenerating.value = true
    clearDraft()

    const meta = activeModelMeta.value
    const params = { ...modelExtraParams.value, ...extraParams }

    // Очистка несовместимых флагов для предотвращения ошибок API
    if (!params.enable_thinking) {
      params.thinking_level = 'off'
      delete params.thinking_budget
    }
    if (meta.provider === 'claude' && params.enable_thinking) {
      delete params.temperature
    }
    if (meta.provider !== 'gemini') {
      params.code_execution = false
    }
    if (!meta.supportsWebSearch) {
      params.use_web_search = false
    }
    params.enable_code_execution = params.code_execution

    const fd = new FormData()
    fd.append('preset', currentPreset.value)
    fd.append('session', currentSession.value)
    fd.append('message', text)
    fd.append('model', selectedModel.value)
    fd.append('max_tokens', String(effectiveMaxTokens.value))

    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null && v !== '') {
        fd.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v))
      }
    }

    if (params.enable_thinking) {
      fd.append('enable_extended_thinking', '1')
    }

    const ctrl = new AbortController()
    abortController.value = ctrl
    const sentSession = currentSession.value
    const isStale = () => ctrl.signal.aborted || currentSession.value !== sentSession

    await apiStream('send_message', fd, {
      onChunk: (type, chunk) => {
        if (isStale()) return
        if (type === 'thinking') {
          draftThinking.value += (typeof chunk === 'string' ? chunk : JSON.stringify(chunk))
        } else if (type === 'text') {
          draftText.value += (typeof chunk === 'string' ? chunk : JSON.stringify(chunk))
        } else if (type === 'tool_calls' || type === 'tool_calls_delta') {
          const toolStr = typeof chunk === 'string' ? chunk : JSON.stringify(chunk)
          draftText.value += `\n\`\`\`tool_call\n${toolStr}\n\`\`\`\n`
        } else if (type === 'usage' && typeof chunk === 'object') {
          usageStats.value = {
            promptTokens: chunk.prompt_tokens ?? chunk.prompt_token_count ?? usageStats.value.promptTokens,
            completionTokens: chunk.completion_tokens ?? chunk.candidates_token_count ?? usageStats.value.completionTokens,
            cost: chunk.cost ?? usageStats.value.cost
          }
        }
      },
      onDone: (data) => {
        if (isStale()) return
        if (data?.error || data?.status === 'error') {
          const errorMsg = data?.message || data?.error || 'Неизвестная ошибка сервера'
          lastError.value = errorMsg
          history.value.push({
            role: 'assistant',
            content: (draftText.value ? `${draftText.value}\n\n` : '') + `❌ **[Ошибка API]**: ${errorMsg}`
          })
        } else {
          if (data?.session) {
            currentSession.value = data.session
            setSession(data.session)
          }
          if (data?.history) {
            history.value = data.history
          }
          if (data?.usage) {
            usageStats.value = {
              promptTokens: data.usage.prompt_tokens ?? data.usage.prompt_token_count ?? usageStats.value.promptTokens,
              completionTokens: data.usage.completion_tokens ?? data.usage.candidates_token_count ?? usageStats.value.completionTokens,
              cost: data.cost ?? usageStats.value.cost
            }
          }
        }
        finalizeGeneration()
      },
      onError: (err) => {
        if (isStale()) return
        const errorMsg = typeof err === 'string' ? err : (err?.message || JSON.stringify(err, null, 2))
        lastError.value = errorMsg

        const finalContent = draftText.value 
          ? `${draftText.value}\n\n❌ **[Ошибка API]**: ${errorMsg}`
          : `❌ **[Ошибка API]**: ${errorMsg}`

        history.value.push({
          role: 'assistant',
          content: finalContent
        })

        finalizeGeneration()
      }
    }, ctrl.signal)
  }

  function abortStream({ keepPartial = true } = {}) {
    if (abortController.value) {
      abortController.value.abort()
      abortController.value = null
      if (keepPartial && draftText.value.trim()) {
        history.value.push({
          role: 'assistant',
          content: `${draftText.value}\n\n⏹ *Генерация остановлена*`
        })
      }
    }
    finalizeGeneration()
  }

  function clearDraft() {
    draftText.value = ''
    draftThinking.value = ''
  }

  function finalizeGeneration() {
    isGenerating.value = false
    abortController.value = null
    clearDraft()
  }

  // === Обновление списка файлов без полной перезагрузки сессии ===
  async function refreshFiles() {
    const preset = currentPreset.value
    const session = currentSession.value
    const res = await apiRequest('get_session_data', { params: { preset, session } })
    if (res && !res.error && preset === currentPreset.value && session === currentSession.value) {
      files.value = res.files || []
    }
  }

  async function uploadFiles(fileList) {
    if (!currentPreset.value || !currentSession.value) return
    setSession(currentSession.value)

    const fd = new FormData()
    fd.append('preset', currentPreset.value)
    fd.append('session', currentSession.value)
    for (const f of fileList) fd.append('files[]', f)

    const res = await apiRequest('upload_file', { method: 'POST', body: fd })
    if (!res?.error) {
      await refreshFiles()
    }
    return res
  }

  async function deleteFile(fileName) {
    if (!currentPreset.value || !currentSession.value) return
    setSession(currentSession.value)

    const fd = new FormData()
    fd.append('preset', currentPreset.value)
    fd.append('session', currentSession.value)
    fd.append('file', fileName)

    const res = await apiRequest('delete_file', { method: 'POST', body: fd })
    if (!res?.error) {
      await refreshFiles()
    }
    return res
  }

  // === Удаление сессий и пресетов ===
  async function deleteSession(presetId, sessionId) {
    if (!presetId || !sessionId) return
    lastError.value = null

    const res = await apiRequest('delete_session', {
      params: { preset: presetId, session: sessionId }
    })

    if (res && !res.error) {
      if (currentPreset.value === presetId && currentSession.value === sessionId) {
        currentSession.value = ''
        localStorage.removeItem('currentSession')
      }
      await fetchStructure()
    } else {
      lastError.value = res?.message || 'Не удалось удалить сессию'
    }
  }

  async function deletePreset(presetId) {
    if (!presetId) return
    lastError.value = null

    const res = await apiRequest('delete_preset', {
      params: { preset: presetId }
    })

    if (res && !res.error) {
      if (currentPreset.value === presetId) {
        currentPreset.value = ''
        currentSession.value = ''
        localStorage.removeItem('currentPreset')
        localStorage.removeItem('currentSession')
      }
      await fetchStructure()
    } else {
      lastError.value = res?.message || 'Не удалось удалить пресет'
    }
  }

  // === Словарь ===
  async function addWord(word, category = 'B1_B2') {
    if (!word || !word.trim()) return false

    const fd = new FormData()
    fd.append('preset', currentPreset.value)
    fd.append('session', currentSession.value)
    fd.append('word', word.trim())
    fd.append('category', category)

    const res = await apiRequest('add_word', {
      method: 'POST',
      body: fd
    })

    if (res && !res.error) {
      if (res.dictionary) {
        dictionary.value = res.dictionary
      } else {
        await fetchDictionary()
      }
      return true
    } else {
      lastError.value = res?.message || 'Не удалось добавить слово'
      return false
    }
  }

  // === Заметки ===
  async function fetchNotes() {
    isNotesLoading.value = true
    try {
      const res = await apiRequest('get_notes')
      if (res && !res.error) {
        notes.value = Array.isArray(res.notes) ? res.notes : []
      }
    } finally {
      isNotesLoading.value = false
    }
  }

  async function saveNotes(newNotes) {
    notes.value = newNotes
    const fd = new FormData()
    fd.append('notes', JSON.stringify(newNotes))
    
    await apiRequest('save_notes', {
      method: 'POST',
      body: fd
    })
  }

  async function addNote({ title, content, type = 'note' }) {
    const newNote = {
      id: 'note_' + Date.now(),
      title: title.trim(),
      content: content.trim(),
      type,
      done: false,
      created_at: new Date().toISOString()
    }
    const updated = [newNote, ...notes.value]
    await saveNotes(updated)
  }

  async function deleteNote(id) {
    const updated = notes.value.filter(n => n.id !== id)
    await saveNotes(updated)
  }

  async function toggleNoteStatus(id) {
    const updated = notes.value.map(n => n.id === id ? { ...n, done: !n.done } : n)
    await saveNotes(updated)
  }
// === Редактор пресета ===
  const isPresetEditorOpen = ref(false)
  const editingPresetId = ref('')

  function openPresetEditor(presetId) {
    if (!presetId) return
    editingPresetId.value = presetId
    isPresetEditorOpen.value = true
  }

  function closePresetEditor() {
    isPresetEditorOpen.value = false
    editingPresetId.value = ''
  }

  async function fetchPreset(presetId) {
    if (!presetId) return null
    lastError.value = null

    const res = await apiRequest('get_preset', {
      method: 'GET',
      params: { preset: presetId }
    })

    if (res && !res.error) {
      return res.preset || res.data || res
    }

    lastError.value = res?.message || 'Не удалось загрузить данные пресета'
    return null
  }

async function updatePreset(presetId, payload = {}) {
  if (!presetId) return { error: true, message: 'Не указан ID пресета' }
  lastError.value = null

  const fd = new FormData()
  fd.append('preset', presetId)

  for (const [key, value] of Object.entries(payload)) {
    if (value !== undefined && value !== null) {
      fd.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
    }
  }

  const res = await apiRequest('update_preset', {
    method: 'POST',
    body: fd
  })

  if (res && !res.error) {
    await fetchStructure()
    return res
  }

  lastError.value = res?.message || 'Не удалось обновить пресет'
  return res || { error: true, message: 'Не удалось обновить пресет' }
}

  return {
    isAuthorized,
    isAuthModalOpen,
    handleAuthSuccess,
    logout,

    sessionConfig,
    globalStructure,
    currentPreset,
    currentSession,
    selectedModel,
    modelExtraParams,
    history,
    files,
    dictionary,
    draftText,
    draftThinking,
    isGenerating,
    usageStats,
    lastError,
    activePresetObj,
    activeSessionObj,
    activeModelMeta,

    fetchStructure,
    selectSession,
    setModel,
    updateModelExtraParams,
    sendMessage,
    abortStream,

    uploadFiles,
    deleteFile,
    deleteSession,
    deletePreset,

    fetchDictionary,
    addWord,
    hasDictionary,

    maxTokensEnabled,
    maxTokensLimit,
    effectiveMaxTokens,
    setMaxTokensLimit,
    toggleMaxTokensEnabled,

    isNotesOpen,
    notes,
    isNotesLoading,
    fetchNotes,
    saveNotes,
    addNote,
    deleteNote,
    toggleNoteStatus,

    // Редактор пресетов
    isPresetEditorOpen,
    editingPresetId,
    openPresetEditor,
    closePresetEditor,
    fetchPreset,
    updatePreset,

  }
})
