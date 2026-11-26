<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import Placeholder from '@tiptap/extension-placeholder';

export interface EmailPlaceholder {
  token: string;
  label?: string;
  description?: string;
}

const props = defineProps<{
  modelValue: string;
  placeholders?: EmailPlaceholder[];
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void;
}>();

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit.configure({
      heading: { levels: [2, 3] },
    }),
    Underline,
    Link.configure({
      openOnClick: false,
      HTMLAttributes: {
        rel: 'noopener noreferrer',
        target: '_blank',
      },
    }),
    Placeholder.configure({
      placeholder: 'Write your webinar email message here…',
    }),
  ],
  editorProps: {
    attributes: {
      class: 'tiptap-editor-content focus:outline-none',
    },
  },
  onUpdate: ({ editor: ed }) => {
    emit('update:modelValue', ed.getHTML());
  },
});

watch(
  () => props.modelValue,
  (val) => {
    if (!editor.value) return;
    if (editor.value.getHTML() !== val) {
      editor.value.commands.setContent(val || '', { emitUpdate: false });
    }
  },
);

onBeforeUnmount(() => {
  editor.value?.destroy();
});

function setLink() {
  if (!editor.value) return;
  const previous = editor.value.getAttributes('link').href || '';
  const url = window.prompt('Link URL (https://… or {{join_url}})', previous);
  if (url === null) return;
  if (url.trim() === '') {
    editor.value.chain().focus().extendMarkRange('link').unsetLink().run();
    return;
  }
  editor.value.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
}

function insertToken(token: string) {
  editor.value?.chain().focus().insertContent(token).run();
}
</script>

<template>
  <div class="overflow-hidden rounded-2xl border border-[#E5E0D5] bg-white shadow-xs">
    <div
      v-if="editor"
      class="flex flex-wrap items-center gap-1 border-b border-[#E5E0D5] bg-[#FAF8F2] px-3 py-2"
    >
      <button
        type="button"
        class="tb-btn"
        :class="{ active: editor.isActive('bold') }"
        title="Bold"
        @click="editor.chain().focus().toggleBold().run()"
      >
        <strong>B</strong>
      </button>
      <button
        type="button"
        class="tb-btn"
        :class="{ active: editor.isActive('italic') }"
        title="Italic"
        @click="editor.chain().focus().toggleItalic().run()"
      >
        <em>I</em>
      </button>
      <button
        type="button"
        class="tb-btn"
        :class="{ active: editor.isActive('underline') }"
        title="Underline"
        @click="editor.chain().focus().toggleUnderline().run()"
      >
        <span class="underline">U</span>
      </button>

      <span class="mx-1 h-4 w-px bg-[#E5E0D5]" />

      <button
        type="button"
        class="tb-btn"
        :class="{ active: editor.isActive('heading', { level: 2 }) }"
        title="Heading 2"
        @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
      >
        H2
      </button>
      <button
        type="button"
        class="tb-btn"
        :class="{ active: editor.isActive('heading', { level: 3 }) }"
        title="Heading 3"
        @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"
      >
        H3
      </button>
      <button
        type="button"
        class="tb-btn"
        :class="{ active: editor.isActive('bulletList') }"
        title="Bullet list"
        @click="editor.chain().focus().toggleBulletList().run()"
      >
        • List
      </button>
      <button
        type="button"
        class="tb-btn"
        :class="{ active: editor.isActive('orderedList') }"
        title="Numbered list"
        @click="editor.chain().focus().toggleOrderedList().run()"
      >
        1. List
      </button>
      <button
        type="button"
        class="tb-btn"
        :class="{ active: editor.isActive('blockquote') }"
        title="Quote"
        @click="editor.chain().focus().toggleBlockquote().run()"
      >
        “Quote”
      </button>
      <button
        type="button"
        class="tb-btn"
        :class="{ active: editor.isActive('link') }"
        title="Insert link"
        @click="setLink"
      >
        Link
      </button>

      <span class="mx-1 h-4 w-px bg-[#E5E0D5]" />

      <button
        type="button"
        class="tb-btn"
        title="Undo"
        @click="editor.chain().focus().undo().run()"
      >
        Undo
      </button>
      <button
        type="button"
        class="tb-btn"
        title="Redo"
        @click="editor.chain().focus().redo().run()"
      >
        Redo
      </button>
    </div>

    <div
      v-if="placeholders && placeholders.length"
      class="flex flex-wrap items-center gap-1.5 border-b border-[#E5E0D5] bg-[#F2EFE6] px-3 py-2"
    >
      <span class="mr-1 text-[11px] font-bold uppercase tracking-wide text-[#5C6260]">
        Variables:
      </span>
      <button
        v-for="item in placeholders"
        :key="item.token"
        type="button"
        class="rounded-full border border-[#006849]/25 bg-white px-2.5 py-0.5 font-mono text-[11px] font-semibold text-[#006849] transition hover:border-[#006849] hover:bg-[#C9F284]/45"
        :title="item.description || item.label || item.token"
        @click="insertToken(item.token)"
      >
        {{ item.token }}
      </button>
    </div>

    <EditorContent :editor="editor" class="min-h-[220px] max-h-[360px] overflow-y-auto px-4 py-3 text-sm text-[#202423]" />
  </div>
</template>

<style scoped>
.tb-btn {
  border-radius: 0.5rem;
  padding: 0.25rem 0.55rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #5C6260;
  border: 1px solid transparent;
  transition: all 0.15s ease;
}
.tb-btn:hover {
  background: #F2EFE6;
  color: #202423;
}
.tb-btn.active {
  background: #006849;
  color: #ffffff;
  border-color: #006849;
}
:deep(.tiptap-editor-content) {
  min-height: 190px;
  line-height: 1.65;
  color: #202423;
}
:deep(.tiptap-editor-content h2) {
  font-size: 1.2rem;
  font-weight: 700;
  color: #202423;
  margin: 0.65rem 0 0.35rem;
}
:deep(.tiptap-editor-content h3) {
  font-size: 1.05rem;
  font-weight: 600;
  color: #202423;
  margin: 0.55rem 0 0.3rem;
}
:deep(.tiptap-editor-content p) {
  margin: 0.45rem 0;
}
:deep(.tiptap-editor-content ul),
:deep(.tiptap-editor-content ol) {
  padding-left: 1.3rem;
  margin: 0.45rem 0;
}
:deep(.tiptap-editor-content ul) {
  list-style-type: disc;
}
:deep(.tiptap-editor-content ol) {
  list-style-type: decimal;
}
:deep(.tiptap-editor-content blockquote) {
  border-left: 3px solid #006849;
  padding-left: 0.8rem;
  color: #5C6260;
  margin: 0.6rem 0;
}
:deep(.tiptap-editor-content a) {
  color: #006849;
  text-decoration: underline;
}
:deep(.tiptap-editor-content p.is-editor-empty:first-child::before) {
  color: #8A918E;
  content: attr(data-placeholder);
  float: left;
  height: 0;
  pointer-events: none;
}
</style>
