<script setup lang="ts">
import { useId } from 'vue'
defineOptions({ inheritAttrs: false })
withDefaults(defineProps<{ label: string; type?: string; error?: string; hint?: string }>(), {
  type: 'text',
})
const model = defineModel<string>({ required: true })
const id = useId()
</script>
<template>
  <div class="field account-field">
    <label :for="id">{{ label }}</label>
    <input
      :id="id"
      v-model="model"
      :type="type"
      v-bind="$attrs"
      :aria-invalid="!!error"
      :aria-describedby="error ? `${id}-error` : hint ? `${id}-hint` : undefined"
    />
    <p v-if="error" :id="`${id}-error`" class="field-error">{{ error }}</p>
    <p v-else-if="hint" :id="`${id}-hint`" class="field-hint">{{ hint }}</p>
  </div>
</template>
