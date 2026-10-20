// @mention suggestions in the composer.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('mentionPicker', ($wire) => ({
        open: false,
        items: [],
        active: 0,
        start: null,

        async lookup() {
            const el = this.$refs.input;
            const before = el.value.slice(0, el.selectionStart);
            const match = before.match(/(^|[^\w.@])@(\w{0,30})$/);

            if (!match) {
                this.open = false;
                return;
            }

            this.start = el.selectionStart - match[2].length;
            this.items = await $wire.mentionSuggestions(match[2]);
            this.active = 0;
            this.open = true;
        },

        keys(event) {
            if (!this.open || !this.items.length) return;

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.active = (this.active + step + this.items.length) % this.items.length;
            } else if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                this.pick(this.items[this.active]);
            } else if (event.key === 'Escape') {
                this.open = false;
            }
        },

        pick(item) {
            const el = this.$refs.input;
            const end = el.selectionStart;
            el.value = el.value.slice(0, this.start) + item.username + ' ' + el.value.slice(end);
            const caret = this.start + item.username.length + 1;
            el.setSelectionRange(caret, caret);
            el.dispatchEvent(new Event('input'));
            this.open = false;
            el.focus();
        },
    }));
});
