/**
 * A roster row's Edit drawer (resources/views/pages/events/inc/trooper.blade.php, guest.blade.php).
 *
 * The drawer slides open (x-collapse) from the Edit button or a click anywhere on the row, except
 * on the row's own links, buttons and fields, which keep doing their own thing.
 *
 * Every change on the event page swaps the whole shift, which would close the drawer after each
 * pick. The rows that are open are remembered here, by id, so the new copy of the row opens again.
 * A link with data-edit-row="<id>" (the summary's "Change") opens that row too.
 */
const open = new Set();

const INTERACTIVE = 'a, button, select, input, textarea, label, form';

export function openRow(id) {
    open.add(String(id));
    window.dispatchEvent(new CustomEvent('roster-row:open', { detail: { id: String(id) } }));
}

export default function rosterRow(id, opensItself = false) {
    const key = String(id);

    return {
        editing: opensItself || open.has(key),

        init() {
            this.$watch('editing', (value) => (value ? open.add(key) : open.delete(key)));
            window.addEventListener('roster-row:open', (event) => {
                if (event.detail.id === key) {
                    this.editing = true;
                }
            });
        },

        toggleFrom(event) {
            if (event.target.closest(INTERACTIVE)) {
                return;
            }
            //  selecting text on the row isn't a click to open it
            if (window.getSelection()?.toString()) {
                return;
            }
            this.editing = !this.editing;
        },
    };
}

document.addEventListener('click', (event) => {
    const link = event.target.closest('[data-edit-row]');
    if (link) {
        openRow(link.dataset.editRow);
    }
});
