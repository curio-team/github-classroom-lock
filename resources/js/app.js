import './bootstrap';

import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import Clipboard from '@ryangjchandler/alpine-clipboard';
import Tooltip from "@ryangjchandler/alpine-tooltip";
Alpine.plugin(Clipboard)
Alpine.plugin(Tooltip);
window.Alpine = Alpine;
Livewire.start();

import { Notyf } from 'notyf';
import 'notyf/notyf.min.css'; // for React, Vue and Svelte

window.Notyf = new Notyf({
    duration: 0,
    dismissible: true,
    position: {
        x: 'right',
        y: 'top',
    },
    types: [
        {
            type: 'warning',
            background: 'orange',
            icon: {
                className: 'notyf__icon--error',
                tagName: 'i',
            },
        },
    ],
});
