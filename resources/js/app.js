import { initializeTerminalComponent } from './terminal.js';

const registerTerminalComponent = () => {
    initializeTerminalComponent();
};

// Register the Alpine provider before Livewire initializes any navigated markup.
// The immediate call also covers Vite modules that load after Alpine has started.
document.addEventListener('alpine:init', registerTerminalComponent, { once: true });
registerTerminalComponent();

// Livewire 3.5.19+ re-applies `x-cloak` to morphed elements during wire:navigate
// (via replaceHtmlAttributes). With `[x-cloak]{display:none}` on the app wrapper,
// this blanks the whole page on every navigation until Alpine re-processes it.
// Strip leftover x-cloak after each navigation; the initial-load FOUC guard stays.
document.addEventListener('livewire:navigated', () => {
    document.querySelectorAll('[x-cloak]').forEach((el) => el.removeAttribute('x-cloak'));
});
