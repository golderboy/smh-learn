import type { Directive } from 'vue';
function labelCells(table: HTMLTableElement) {
    const labels = [...(table.tHead?.rows[0]?.cells ?? [])].map(
        (cell) => cell.textContent?.trim() ?? '',
    );
    for (const row of table.tBodies[0]?.rows ?? []) {
        if (row.cells.length !== labels.length || [...row.cells].some((cell) => cell.colSpan !== 1))
            continue;
        [...row.cells].forEach((cell, index) => {
            cell.dataset.label = labels[index] ?? '';
        });
    }
    table.classList.add('responsive-records');
}
export const responsiveTable: Directive<HTMLTableElement> = {
    mounted: labelCells,
    updated: labelCells,
};
