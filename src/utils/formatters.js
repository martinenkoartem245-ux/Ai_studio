import { marked } from 'marked'
import hljs from 'highlight.js'

export function escapeHTML(str) {
  if (!str) return ''
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

export function renderMarkdown(content) {
  if (!content) return ''
  return marked.parse(content)
}

export function highlightCode(el) {
  if (!el) return
  el.querySelectorAll('pre code').forEach((block) => {
    hljs.highlightElement(block)
  })
}