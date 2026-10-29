<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';

interface DocumentEntry {
    id: number;
    nombre_original: string;
    created_at: string;
}

interface Assignment {
    id: number;
    nombre_empresa: string;
    nombre_especialidad: string;
    fecha_inicio: string;
    fecha_fin: string;
    documentos: Record<string, DocumentEntry>;
}

const props = defineProps<{ csrfToken: string; assignmentsJson: string }>();
const assignments = ref<Assignment[]>(JSON.parse(props.assignmentsJson || '[]'));
const assignmentId = ref(assignments.value[0]?.id || 0);
const documentType = ref('MEMORIA');
const selectedFile = ref<File | null>(null);
const previewUrl = ref('');
const message = ref('');
const error = ref('');
const uploading = ref(false);
const image = ref<HTMLImageElement | null>(null);
const canvas = ref<HTMLCanvasElement | null>(null);
const crop = ref({ x: 0, y: 0, width: 0, height: 0 });
const dragging = ref(false);
const dragStart = ref({ x: 0, y: 0 });

const selectedAssignment = computed(() => assignments.value.find((item) => item.id === assignmentId.value));
const selectedDocument = computed(() => selectedAssignment.value?.documentos[documentType.value]);
const cropStyle = computed(() => {
    const current = crop.value;
    const element = canvas.value;
    if (!element || current.width === 0 || current.height === 0) return {};
    return {
        left: `${(current.x / element.width) * 100}%`,
        top: `${(current.y / element.height) * 100}%`,
        width: `${(current.width / element.width) * 100}%`,
        height: `${(current.height / element.height) * 100}%`,
    };
});

function clearSelection(): void {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = '';
    selectedFile.value = null;
    image.value = null;
    crop.value = { x: 0, y: 0, width: 0, height: 0 };
}

function onFileChange(event: Event): void {
    clearSelection();
    error.value = '';
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
        error.value = 'El archivo no puede superar los 10 MB.';
        input.value = '';
        return;
    }
    selectedFile.value = file;
    if (file.type === 'application/pdf') return;
    if (!['image/jpeg', 'image/png'].includes(file.type)) {
        error.value = 'Solo se admiten PDF, JPG o PNG.';
        clearSelection();
        input.value = '';
        return;
    }
    previewUrl.value = URL.createObjectURL(file);
}

function onImageLoad(event: Event): void {
    const element = event.target as HTMLImageElement;
    image.value = element;
    const context = canvas.value?.getContext('2d');
    if (!canvas.value || !context) return;
    canvas.value.width = element.naturalWidth;
    canvas.value.height = element.naturalHeight;
    context.drawImage(element, 0, 0);
    crop.value = { x: 0, y: 0, width: element.naturalWidth, height: element.naturalHeight };
}

function point(event: PointerEvent): { x: number; y: number } {
    const element = canvas.value as HTMLCanvasElement;
    const bounds = element.getBoundingClientRect();
    return {
        x: Math.max(0, Math.min(element.width, ((event.clientX - bounds.left) / bounds.width) * element.width)),
        y: Math.max(0, Math.min(element.height, ((event.clientY - bounds.top) / bounds.height) * element.height)),
    };
}

function startCrop(event: PointerEvent): void {
    if (!canvas.value || !image.value) return;
    dragging.value = true;
    dragStart.value = point(event);
    crop.value = { x: dragStart.value.x, y: dragStart.value.y, width: 0, height: 0 };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function moveCrop(event: PointerEvent): void {
    if (!dragging.value) return;
    const end = point(event);
    crop.value = {
        x: Math.min(dragStart.value.x, end.x),
        y: Math.min(dragStart.value.y, end.y),
        width: Math.abs(end.x - dragStart.value.x),
        height: Math.abs(end.y - dragStart.value.y),
    };
}

function endCrop(): void {
    dragging.value = false;
    if (crop.value.width < 8 || crop.value.height < 8) {
        if (image.value) crop.value = { x: 0, y: 0, width: image.value.naturalWidth, height: image.value.naturalHeight };
    }
}

async function fileToUpload(): Promise<File | null> {
    if (!selectedFile.value || !image.value || !canvas.value || crop.value.width < 8 || crop.value.height < 8) return selectedFile.value;
    const output = document.createElement('canvas');
    output.width = Math.round(crop.value.width);
    output.height = Math.round(crop.value.height);
    const context = output.getContext('2d');
    if (!context) return selectedFile.value;
    context.drawImage(image.value, crop.value.x, crop.value.y, crop.value.width, crop.value.height, 0, 0, output.width, output.height);
    const blob = await new Promise<Blob | null>((resolve) => output.toBlob(resolve, selectedFile.value?.type || 'image/jpeg', .92));
    return blob ? new File([blob], selectedFile.value.name, { type: selectedFile.value.type }) : selectedFile.value;
}

async function upload(): Promise<void> {
    error.value = '';
    message.value = '';
    if (!assignmentId.value || !selectedFile.value) {
        error.value = 'Selecciona una práctica y un documento.';
        return;
    }
    uploading.value = true;
    try {
        const form = new FormData();
        form.append('_csrf', props.csrfToken);
        form.append('asignacion_id', String(assignmentId.value));
        form.append('tipo_documento', documentType.value);
        const file = await fileToUpload();
        if (!file) throw new Error('No se pudo preparar el documento.');
        form.append('documento', file);
        const response = await fetch('index.php?action=subir-documentacion-cierre', { method: 'POST', headers: { Accept: 'application/json' }, body: form });
        const result = await response.json() as { success?: boolean; error?: string };
        if (!response.ok || !result.success) throw new Error(result.error || 'No se pudo entregar el documento.');
        message.value = 'Documento enviado y almacenado correctamente.';
        clearSelection();
        window.location.reload();
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'No se pudo entregar el documento.';
    } finally {
        uploading.value = false;
    }
}

onBeforeUnmount(clearSelection);
</script>

<template>
    <section class="document-scanner" aria-label="Entrega de documentación">
        <p v-if="assignments.length === 0" class="document-scanner__empty">Todavía no tienes prácticas cerradas disponibles para entregar documentación.</p>
        <template v-else>
            <div class="document-scanner__selection">
                <label>Práctica finalizada
                    <select v-model="assignmentId">
                        <option v-for="assignment in assignments" :key="assignment.id" :value="assignment.id">{{ assignment.nombre_empresa }} · {{ assignment.nombre_especialidad }}</option>
                    </select>
                </label>
                <label>Tipo de documentación
                    <select v-model="documentType">
                        <option value="MEMORIA">Memoria de prácticas</option>
                        <option value="HOJA_FIRMAS">Hoja de firmas</option>
                    </select>
                </label>
            </div>
            <p v-if="selectedDocument" class="document-scanner__existing">Ya entregado: <strong>{{ selectedDocument.nombre_original }}</strong>. Puedes sustituirlo.</p>
            <label class="document-scanner__file">Selecciona o captura un documento
                <input type="file" accept="application/pdf,image/jpeg,image/png" @change="onFileChange">
            </label>
            <div v-if="previewUrl" class="document-scanner__preview" @pointerdown="startCrop" @pointermove="moveCrop" @pointerup="endCrop" @pointercancel="endCrop">
                <img :src="previewUrl" alt="Previsualización del documento" @load="onImageLoad">
                <canvas ref="canvas" aria-hidden="true"></canvas>
                <span class="document-scanner__crop" :style="cropStyle"></span>
                <small>Arrastra sobre la imagen para recortar el área legible.</small>
            </div>
            <p v-else-if="selectedFile" class="document-scanner__pdf">PDF seleccionado: <strong>{{ selectedFile.name }}</strong></p>
            <p v-if="error" class="message message-error" role="alert">{{ error }}</p>
            <p v-if="message" class="message message-success" role="status">{{ message }}</p>
            <button type="button" :disabled="uploading || !selectedFile" @click="upload">{{ uploading ? 'Analizando y enviando…' : 'Analizar y entregar documento' }}</button>
        </template>
    </section>
</template>
