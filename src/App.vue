<script setup>
import { ref, onMounted } from 'vue'
import { useChatStore } from './stores/chatStore'
import Header from './components/Header.vue'
import SidebarLeft from './components/sidebar/SidebarLeft.vue'
import SidebarRight from './components/sidebar/SidebarRight.vue'
import ChatContainer from './components/chat/ChatContainer.vue'
import ChatInput from './components/chat/ChatInput.vue'
import DictionaryModal from './components/models/DictionaryModal.vue'
import NewSessionModal from './components/models/NewSessionModal.vue'
import NewPresetModal from './components/models/NewPresetModal.vue'
import NotesModal from './components/models/NotesModal.vue'
import AuthModal from './components/models/AuthModal.vue'
import PresetEditorModal from './components/models/PresetEditorModal.vue'
const store = useChatStore()

const isDictOpen = ref(false)
const isNewSessionOpen = ref(false)
const isNewPresetOpen = ref(false)

// Состояния мобильных шторок
const isLeftOpen = ref(false)
const isRightOpen = ref(false)

function closeSidebars() {
  isLeftOpen.value = false
  isRightOpen.value = false
}

onMounted(() => {
  // Загружаем данные только если уже есть сохраненный токен
  if (store.isAuthorized) {
    store.fetchStructure()
    store.fetchNotes()
  } else {
    store.isAuthModalOpen = true
  }
})
</script>

<template>
  <div class="h-dvh flex flex-col overflow-hidden bg-gray-950 font-sans relative">
    
    <!-- Шапка -->
    <Header 
      @open-dict="isDictOpen = true" 
      @open-new-session="isNewSessionOpen = true"
      @open-notes="store.isNotesOpen = true"
      @toggle-left-sidebar="isLeftOpen = !isLeftOpen; isRightOpen = false"
      @toggle-right-sidebar="isRightOpen = !isRightOpen; isLeftOpen = false"
    />

    <!-- Основной контент -->
    <div class="flex-1 flex overflow-hidden relative">
      
      <!-- Затемняющий фон (backdrop) для мобильных устройств при открытой шторке -->
      <div 
        v-if="isLeftOpen || isRightOpen" 
        @click="closeSidebars"
        class="md:hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-30 transition-opacity"
      ></div>

      <!-- Левое меню (пресеты) -->
      <SidebarLeft 
        :is-open="isLeftOpen"
        @close="isLeftOpen = false"
        @open-new-preset="isNewPresetOpen = true"
      />
      
      <!-- Чат-зона -->
      <main class="flex-1 flex flex-col min-w-0 min-h-0 bg-gray-950/40 relative select-text">
        <ChatContainer />
        <ChatInput />
      </main>

      <!-- Правая панель (файлы и параметры) -->
      <SidebarRight 
        :is-open="isRightOpen"
        @close="isRightOpen = false"
      />
    </div>

    <!-- Модальные окна -->
    <DictionaryModal v-if="isDictOpen" @close="isDictOpen = false" />
    <NewSessionModal v-if="isNewSessionOpen" @close="isNewSessionOpen = false" />
    <NewPresetModal v-if="isNewPresetOpen" @close="isNewPresetOpen = false" />
    
    <!-- Модальное окно заметок (баги, фичи, задачи) -->
    <NotesModal />
    <PresetEditorModal/>
    <!-- Модальное окно авторизации/регистрации туннеля -->
    <AuthModal :is-open="!store.isAuthorized || store.isAuthModalOpen" />

  </div>
</template>
