const API_URL = import.meta.env.VITE_API_URL || window.APP_API_URL || '/api/index.php'

// === Хранилище Auth-токена туннеля ===
export function getAuthToken() {
  if (typeof window !== 'undefined' && typeof localStorage !== 'undefined') {
    try {
      return localStorage.getItem('tunnel_auth_token') || ''
    } catch {
      return ''
    }
  }
  return ''
}

export function setAuthToken(token) {
  if (typeof window !== 'undefined' && typeof localStorage !== 'undefined') {
    try {
      if (token) {
        localStorage.setItem('tunnel_auth_token', token)
      } else {
        localStorage.removeItem('tunnel_auth_token')
      }
    } catch {}
  }
}

export function clearAuthToken() {
  setAuthToken(null)
}

/**
 * Хелпер для внедрения заголовка авторизации
 */
function getAuthHeaders(customHeaders = {}) {
  const headers = { ...customHeaders }
  const token = getAuthToken()
  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }
  return headers
}

// === Хранилище сессии ===
let currentSession = getStoredSession()

function getStoredSession() {
  if (typeof window !== 'undefined' && typeof localStorage !== 'undefined') {
    try {
      return localStorage.getItem('api_session')
    } catch {
      return null
    }
  }
  return null
}

function setStoredSession(session) {
  if (typeof window !== 'undefined' && typeof localStorage !== 'undefined') {
    try {
      if (session) {
        localStorage.setItem('api_session', session)
      } else {
        localStorage.removeItem('api_session')
      }
    } catch {}
  }
}

export function getSession() {
  return currentSession
}

export function setSession(session) {
  currentSession = session
  setStoredSession(session)
}

export function clearSession() {
  currentSession = null
  setStoredSession(null)
}

// === Утилиты ===

function buildSearchParams(action, params = {}) {
  const searchParams = new URLSearchParams()
  if (action) searchParams.append('action', action)
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '') {
      searchParams.append(key, String(value))
    }
  }
  return searchParams.toString()
}

async function parseErrorResponse(response) {
  try {
    const text = await response.text()
    if (text) {
      try {
        const json = JSON.parse(text)
        return json.error?.message || json.error || json.message || `Ошибка сервера (${response.status})`
      } catch {
        return `Ошибка сервера (${response.status}): ${text.slice(0, 1000).trim()}`
      }
    }
  } catch {}
  return `Ошибка HTTP (${response.status}): ${response.statusText || 'Неизвестный сбой'}`
}

function injectSession(params = {}) {
  const session = getSession()
  if (session && !params.session) {
    return { ...params, session }
  }
  return params
}

function cloneAndInjectFormData(formData) {
  const newFd = new FormData()

  if (formData instanceof FormData) {
    for (const [key, value] of formData.entries()) {
      newFd.append(key, value)
    }
  } else if (formData && typeof formData === 'object') {
    for (const [key, value] of Object.entries(formData)) {
      if (value !== null && value !== undefined) {
        newFd.append(key, value)
      }
    }
  }

  const session = getSession()
  if (session && !newFd.get('session')) {
    newFd.append('session', session)
  }
  return newFd
}

function extractChunkValue(val) {
  if (val === null || val === undefined) return ''
  if (typeof val === 'string') return val
  if (typeof val === 'number' || typeof val === 'boolean') return String(val)
  if (typeof val === 'object') {
    if (val.chunk !== undefined) return extractChunkValue(val.chunk)
    if (val.text !== undefined) return extractChunkValue(val.text)
    if (val.content !== undefined) return extractChunkValue(val.content)
    if (val.reasoning_content !== undefined) return extractChunkValue(val.reasoning_content)
    if (val.delta !== undefined) return extractChunkValue(val.delta)
    return val
  }
  return String(val)
}

// === API методы ===

export async function apiRequest(action, options = {}) {
  const { method = 'GET', params = {}, body = null, signal = null, headers = {} } = options

  const paramsWithSession = injectSession(params)
  const queryString = buildSearchParams(action, paramsWithSession)
  const url = `${API_URL}?${queryString}`

  // Автоматически добавляем заголовок авторизации
  const requestHeaders = getAuthHeaders(headers)

  try {
    const response = await fetch(url, { method, headers: requestHeaders, body, signal })
    
    // Если 401 — диспатчим событие, чтобы UI показал окно авторизации
    if (response.status === 401) {
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('auth:required'))
      }
    }

    const text = await response.text()
    let data = null

    if (text && text.trim()) {
      try {
        data = JSON.parse(text)
      } catch {
        if (!response.ok) throw new Error(`Ошибка сервера (${response.status})`)
        return { error: true, message: 'Ответ сервера содержит ошибки (невалидный JSON)' }
      }
    }

    if (!response.ok) {
      return { 
        error: true, 
        status: response.status,
        message: data?.message || data?.error || `HTTP ${response.status}` 
      }
    }

    if (data?.session) {
      setSession(data.session)
    }

    return data || { status: 'success' }
  } catch (err) {
    if (err.name === 'AbortError') {
      return { error: true, message: 'Запрос отменен пользователем', isAborted: true }
    }
    return { error: true, message: err.message || 'Ошибка сети' }
  }
}

export async function apiStream(action, formData, callbacks = {}, signal = null) {
  const { onChunk = () => {}, onDone = () => {}, onError = () => {} } = callbacks

  const formDataWithSession = cloneAndInjectFormData(formData)
  const preset = formDataWithSession.get('preset') || ''
  const session = formDataWithSession.get('session') || getSession() || ''

  const urlParams = { preset, session }
  const url = `${API_URL}?${buildSearchParams(action, urlParams)}`
  let streamCompletedNormally = false

  try {
    // В потоковый запрос также добавляем Authorization
    const headers = getAuthHeaders()

    const response = await fetch(url, {
      method: 'POST',
      headers,
      body: formDataWithSession,
      signal
    })

    if (response.status === 401) {
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('auth:required'))
      }
    }

    if (!response.ok) {
      const errorText = await parseErrorResponse(response)
      throw new Error(errorText)
    }

    if (!response.body) {
      throw new Error('Сервер не передал поток данных')
    }

    const reader = response.body.getReader()
    const decoder = new TextDecoder('utf-8')
    let buffer = ''

    const parseBlock = async (block) => {
      if (!block.trim()) return
      const lines = block.split('\n')
      let eventType = 'text'
      const dataLines = []

      for (const line of lines) {
        const clean = line.replace(/\r$/, '')
        if (clean.startsWith('event:')) {
          eventType = clean.slice(6).trim()
        } else if (clean.startsWith('data:')) {
          let dataContent = clean.slice(5)
          if (dataContent.startsWith(' ')) dataContent = dataContent.slice(1)
          dataLines.push(dataContent)
        }
      }

      if (!dataLines.length) return
      const rawData = dataLines.join('\n')

      try {
        const parsed = JSON.parse(rawData)

        if (['thinking', 'text', 'tool_calls', 'tool_calls_delta', 'usage'].includes(eventType)) {
          const payload = (eventType === 'usage' || eventType === 'tool_calls' || eventType === 'tool_calls_delta')
            ? (parsed.chunk !== undefined ? parsed.chunk : parsed)
            : extractChunkValue(parsed)
            
          onChunk(eventType, payload)
        } else if (eventType === 'done') {
          streamCompletedNormally = true
          if (parsed?.session) setSession(parsed.session)
          await onDone(parsed)
        } else if (eventType === 'error') {
          streamCompletedNormally = true
          let errorMsg = 'Ошибка генерации'
          if (parsed && typeof parsed === 'object') {
            errorMsg = parsed.message || parsed.error || JSON.stringify(parsed, null, 2)
          } else if (parsed) {
            errorMsg = String(parsed)
          }
          await onError(errorMsg)
        }
      } catch {
        if (['thinking', 'text', 'tool_calls', 'tool_calls_delta'].includes(eventType)) {
          onChunk(eventType, rawData)
        } else if (eventType === 'done') {
          streamCompletedNormally = true
          await onDone(null)
        } else if (eventType === 'error') {
          streamCompletedNormally = true
          await onError(rawData || 'Ошибка генерации')
        }
      }
    }

    while (true) {
      const { done, value } = await reader.read()
      if (done) break

      buffer += decoder.decode(value, { stream: true })
      const parts = buffer.split(/\r?\n\r?\n/)
      buffer = parts.pop() || ''

      for (const block of parts) {
        await parseBlock(block)
      }
    }

    buffer += decoder.decode()
    if (buffer.trim()) {
      await parseBlock(buffer)
    }

    if (!streamCompletedNormally) {
      await onError('Соединение с сервером было закрыто до завершения генерации.')
    }
  } catch (err) {
    if (err.name === 'AbortError') return
    await onError(err.message || 'Сбой соединения')
  }
}
