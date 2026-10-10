import { onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
const forms = new Set<() => boolean>();
let removeListener: (() => void) | undefined;
export const hasUnsavedForms = () => [...forms].some((check) => check());
const dirty = hasUnsavedForms;
const beforeUnload = (event: BeforeUnloadEvent) => {
    if (dirty()) event.preventDefault();
};
export function confirmLeaveForms(): boolean {
    return (
        !dirty() ||
        window.confirm(
            'มีข้อมูลที่ยังไม่ได้บันทึก ต้องการออกจากหน้านี้และทิ้งข้อมูลที่แก้ไขหรือไม่?',
        )
    );
}
export function useUnsavedForms(...sources: { isDirty: boolean; processing: boolean }[]) {
    const check = () => sources.some((form) => form.isDirty && !form.processing);
    forms.add(check);
    if (!removeListener) {
        removeListener = router.on('before', (event) => {
            if (event.detail.visit.method === 'get' && !confirmLeaveForms()) return false;
        });
        window.addEventListener('beforeunload', beforeUnload);
    }
    onBeforeUnmount(() => {
        forms.delete(check);
        if (!forms.size) {
            removeListener?.();
            removeListener = undefined;
            window.removeEventListener('beforeunload', beforeUnload);
        }
    });
}
