@props(['chatMode' => 'inactive'])

<div class="flex flex-col gap-2">
    @if ($chatMode === 'active')
        <x-notice type="warning">
            CurioGPT wordt geleverd zonder garanties. Het is mogelijk dat het niet altijd beschikbaar is. Gebruik van andere taalmodellen (zoals ChatGPT, Gemini, enz.) is niet toegestaan.
        </x-notice>

        <x-notice icon="💾">
            <strong>Wat je invoert in CurioGPT wordt opgeslagen en kan door beoordelaars worden bekeken.</strong>
            Je kunt gesprekken in CurioGPT zelf niet terughalen. Wanneer je de pagina ververst, is het gesprek weg.
        </x-notice>

        <x-notice icon="🤖">
            Het &quot;advanced&quot; model is duurder, vandaar dat er een beperkt aantal tokens per dag beschikbaar is per gebruiker.
            Gebruik voornamelijk het mini model en schakel alleen over naar het geavanceerde wanneer dat nodig blijkt.
        </x-notice>

        <x-notice type="warning" icon="🧹">
            <strong>Ververs de pagina wanneer je klaar bent met een chatsessie!</strong>
            Als je steeds de hele chatgeschiedenis meestuurt, kan het zijn dat je chatlimiet sneller bereikt is.
            Daarbij raakt de AI er soms van in de war als er teveel informatie wordt meegestuurd.
        </x-notice>

        <div x-data="{ maximized: false }"
            x-bind:class="{ 'fixed inset-0 bg-black': maximized }">

            <x-content.section tight
                            id="chat-section"
                            x-bind:class="{ 'h-full': maximized, 'h-[600px]': !maximized }"
                            class="flex flex-col border border-slate-400 rounded">
                <div class="flex flex-row border-b border-slate-400">
                    @php
                    $defaultMaxChats = \App\Settings\ChatSettings::getDefaultMaxUserChatTokensPerModelPerDay()
                    @endphp

                    @foreach (user()->getChatLimits() as $model => $limit)
                    @if(!isset($defaultMaxChats[$model]))
                        @continue
                    @endif
                    @php $modelMaxChats = $defaultMaxChats[$model] @endphp
                    <label for="model-{{ $model }}"
                        class="flex-1 flex flex-col items-center bg-slate-200 p-4 border-l @if ($loop->first) rounded-tl @endif border-slate-400 @if ($modelMaxChats === -1 || $limit > 0) hover:bg-slate-300 cursor-pointer @else opacity-50 cursor-not-allowed @endif">
                        <div class="flex-1 flex flex-row items-center gap-2">
                            <input type="radio"
                                name="model"
                                @if ($modelMaxChats !== -1 && $limit <= 0) disabled @endif
                                value="{{ $model }}"
                                id="model-{{ $model }}"
                                {{ $loop->first ? 'checked' : '' }}>
                            {{ $model }}
                            <small>
                                ({{ App\Http\Controllers\ApiController::getModelId($model) }})
                            </small>
                        </div>
                        @if ($modelMaxChats === -1)
                            <span class="text-xs">Onbeperkt aantal tokens, zolang de voorraad strekt</span>
                        @else
                            @if($limit > 0)
                                <x-progress-bar :value="$limit"
                                                :max="$modelMaxChats"
                                                hideMaxLabel
                                                id="chat-limit-{{ $model }}"
                                                class="flex-1 mt-2">
                                                tokens over vandaag
                                </x-progress-bar>
                            @else
                                <span class="text-xs">Geen tokens meer over vandaag</span>
                            @endif
                        @endif
                    </label>
                    @endforeach
                    <button class="flex-shrink text-center rounded-tr bg-slate-200 p-4 text-black border-l border-slate-400 hover:bg-slate-300 cursor-pointer"
                            type="button"
                            title="Maximize chat"
                            aria-label="Maximize chat"
                            @click="maximized = !maximized">
                        <x-icons.maximize x-show="!maximized" />
                        <x-icons.minimize x-show="maximized" />
                    </button>
                </div>

                <div class="flex flex-col overflow-y-scroll gap-2 flex-1 bg-slate-300 p-5">
                    <div id="chat-history"
                        class="flex flex-col"></div>
                </div>

                <form class="flex border-t border-slate-400"
                    id="ai-form"
                    method="POST">
                    <fieldset class="group flex flex-row w-full">
                        @csrf
                        <textarea name="prompt"
                                id="prompt"
                                role="presentation"
                                autocomplete="off"
                                placeholder="Voer hier je prompt in en druk op Enter om te verzenden (gebruik Shift+Enter om een nieuwe regel toe te voegen)"
                                rows="1"
                                cols="1"
                                class="flex-grow border-transparent rounded-bl p-4 bg-slate-200 text-black"></textarea>

                        <button type="submit"
                                x-data="{ disabled: false }"
                                class="flex-shrink-0 bg-emerald-200 border-emerald-600 text-black p-4 rounded-br disabled:opacity-50">
                            <svg xmlns="http://www.w3.org/2000/svg"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke-width="2"
                                stroke="currentColor"
                                :class="{ 'hidden': disabled }"
                                class="w-4 h-4 text-gray-600">
                                <path stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                            </svg>

                            <svg xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke-width="1.5"
                                    stroke="currentColor"
                                    :class="{ 'hidden': !disabled }"
                                    class="w-4 h-4 text-gray-600">
                                <path stroke-linecap="round"
                                        stroke-linejoin="round"
                                        d="M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z" />
                            </svg>
                        </button>
                    </fieldset>
                </form>
            </x-content.section>

            <div id="message-template"
                class="message-container group flex flex-col mb-2 hidden">
                <p class="author text-xs text-slate-400 mb-1"></p>
                <div class="message flex flex-row mb-2">
                    <div class="relative message-content flex flex-col rounded p-4 text-black"
                        x-data="{ isEditting: false }">
                        <div class="absolute opacity-50 top-0 right-0 -mt-2 -mr-2 hidden group-hover:flex gap-1 flex-row">
                            <x-buttons.secondary tight
                                                title="Kopieer bericht"
                                                aria-label="Kopieer bericht"
                                                @click="$tooltip('Bericht gekopieerd naar klembord!')"
                                                x-clipboard="atou($refs.chatMessageText.dataset.originalText)">
                                <x-slot name="icon">
                                    <x-icons.copy />
                                </x-slot>
                            </x-buttons.secondary>
                            <x-buttons.secondary tight
                                                title="Bewerk bericht"
                                                aria-label="Bewerk bericht"
                                                x-show="!isEditting"
                                                @click="isEditting = !isEditting">
                                <x-slot name="icon">
                                    <x-icons.edit />
                                </x-slot>
                            </x-buttons.secondary>
                            <x-buttons.secondary tight
                                                title="Annuleer bewerken"
                                                aria-label="Annuleer bewerken"
                                                x-show="isEditting"
                                                @click="isEditting = !isEditting">
                                <x-slot name="icon">
                                    <x-icons.close />
                                </x-slot>
                            </x-buttons.secondary>
                        </div>
                        <p class="chat-message-text"
                            x-bind:contenteditable="isEditting"
                            x-effect="isEditting ? $refs.chatMessageText.focus() : null"
                            x-ref="chatMessageText"></p>
                            <div class="flex justify-between gap-2 mt-2"
                                x-show="isEditting">
                                <x-buttons.danger tight
                                    title="Verwijder bericht"
                                    aria-label="Verwijder bericht"
                                    x-show="isEditting"
                                    @click="isEditting = !isEditting; removeChatMessageFromElement($root.closest('.message-container').id)">
                                    Verwijderen
                                </x-buttons.danger>
                                <x-buttons.primary tight
                                    title="Verstuur bewerking"
                                    aria-label="Verstuur bewerking"
                                    x-show="isEditting"
                                    @click="isEditting = !isEditting; updateChatMessageFromElement($root.closest('.message-container').id)">
                                    Opslaan
                                </x-buttons.primary>
                            </div>
                    </div>
                </div>
            </div>

            <script>
                const MESSAGE_ID_PREFIX = 'history-chat-message-';
                const formEl = document.getElementById('ai-form');
                const promptEl = document.getElementById('prompt');
                const chatHistory = document.getElementById('chat-history');
                const csrfEl = document.querySelector('meta[name="csrf-token"]');
                const submitButtonEl = formEl.querySelector('fieldset button');
                const template = document.getElementById('message-template');

                let isChatCancelled = false;
                let isChatDisabled = false;
                let lastScrollByUser = 0;
                const history = [];

                function utoa(data) {
                    return btoa(unescape(encodeURIComponent(data)));
                }
                function atou(b64) {
                    return decodeURIComponent(escape(atob(b64)));
                }

                function setFormDisabled(disabled) {
                    submitButtonEl._x_dataStack[0].disabled = disabled;
                    isChatDisabled = disabled;

                    if (!disabled) {
                        isChatCancelled = false;
                        promptEl.focus();
                    }
                }

                function sizePromptEl() {
                    const rows = Math.max(1, Math.min(promptEl.value.split('\n').length - 1, 10));
                    promptEl.rows = rows;
                }

                // Adds a message to the history element and history array
                // It returns the message element so further modifications can be made
                function addMessage(message, role) {
                    const messageContainerEl = template.cloneNode(true);
                    messageContainerEl.classList.remove('hidden');

                    const authorEl = messageContainerEl.querySelector('.author');

                    if (role === 'assistant') {
                        authorEl.innerText = 'CurioGPT:';
                    } else if (role === 'user') {
                        authorEl.innerText = 'Jij:';
                    } else {
                        authorEl.innerText = 'Samenvatting van eerdere gesprek:';
                    }

                    const messageContentEl = messageContainerEl.querySelector('.message-content');
                    if (role === 'user') {
                        messageContentEl.classList.add('bg-emerald-200');
                        messageContainerEl.classList.add('self-end', 'ml-8');
                    } else {
                        messageContentEl.classList.add('bg-slate-200');
                        messageContainerEl.classList.add('self-start', 'mr-8');
                    }

                    const messageTextEl = messageContainerEl.querySelector('.chat-message-text');
                    messageTextEl.innerText = message;
                    messageTextEl.dataset.originalText = utoa(message);

                    chatHistory.appendChild(messageContainerEl);
                    chatScrollToBottom();

                    const historyLength = history.push({
                        role,
                        content: message,
                    });

                    messageContainerEl.id = MESSAGE_ID_PREFIX + historyLength;

                    return { messageTextEl, historyLength };
                }

                function getElementWithHistoryIndexFromId(id) {
                    const messageContainerEl = document.getElementById(id);
                    const historyIndex = id.replace(MESSAGE_ID_PREFIX, '') - 1;

                    return { messageContainerEl, historyIndex };
                }

                function updateChatMessageFromElement(id) {
                    const { messageContainerEl, historyIndex } = getElementWithHistoryIndexFromId(id);
                    const messageTextEl = messageContainerEl.querySelector('.chat-message-text');
                    history[historyIndex].content = messageTextEl.innerText;

                    const resend = confirm('Wil je dit bericht opnieuw versturen?\nAlle berichten die erop volgde worden verwijderd.');

                    if (resend) {
                        promptEl.value = messageTextEl.innerText;

                        history.splice(historyIndex, history.length - historyIndex);

                        for (let i = chatHistory.children.length - 1; i >= historyIndex; i--) {
                            chatHistory.children[i].remove();
                        }

                        submitPrompt();
                    }
                }

                function removeChatMessageFromElement(id) {
                    const { messageContainerEl, historyIndex } = getElementWithHistoryIndexFromId(id);
                    // Leave null so the indexes stay the same
                    history[historyIndex] = null;
                    messageContainerEl.remove();
                }

                function chatScrollToBottom() {
                    if (lastScrollByUser + 1000 > Date.now()) {
                        return;
                    }

                    chatHistory.parentElement.scrollTop = chatHistory.parentElement.scrollHeight;
                }

                chatHistory.parentElement.addEventListener('scroll', function() {
                    if (chatHistory.parentElement.scrollTop + chatHistory.parentElement.clientHeight < chatHistory.parentElement.scrollHeight) {
                        lastScrollByUser = Date.now();
                    }
                });

                function maximizeChat() {
                    const chatSection = document.getElementById('chat-section');
                    chatSection.classList.toggle('fixed');

                    if (chatSection.classList.contains('fixed')) {
                        chatSection.style.height = '100vh';
                    } else {
                        chatSection.style.height = '';
                    }
                }

                function decreaseTokenBar(model, tokenCount) {
                    tokenCount = tokenCount || 1;

                    const progressBar = document.getElementById('chat-limit-' + model);

                    if (progressBar) {
                        const progressBarBar = progressBar.querySelector('progress');
                        const progressBarLabel = progressBar.querySelector('.progress-value');
                        const progressBarApproximationSign = progressBar.querySelector('.progress-value-approximation');
                        const currentTokens = progressBarBar.value;
                        progressBarBar.value = currentTokens - tokenCount;
                        progressBarLabel.innerText = new Intl.NumberFormat().format(progressBarBar.value);
                        progressBarApproximationSign.classList.remove('hidden');
                    }
                }

                function performPrompt(onReceivedChunk, onReceivedFull, summarizeMode) {
                    summarizeMode = summarizeMode ? true : false;

                    const model = document.querySelector('input[name="model"]:checked').value;
                    const aiRequestRoute = '{{ route('ai-request') }}';
                    const csrfToken = csrfEl.getAttribute('content');
                    let filteredHistory = history.slice(0, -1); // Remove the last message, which is the '...' loading message;
                    filteredHistory = filteredHistory.filter(message => message !== null);

                    fetch(aiRequestRoute, {
                        method: 'POST',
                        headers:{
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({
                            model: model,
                            history: filteredHistory,
                            should_summarize_history: summarizeMode,
                        }),
                    })
                    .then(response => response.body)
                    .then(async body => {
                        const reader = body.pipeThrough(new TextDecoderStream()).getReader();

                        let currentMessage = '';
                        let canBeSummarized = false;

                        while (true) {
                            const { done, value } = await reader.read();

                            if (done) {
                                break;
                            }

                            if (isChatCancelled) {
                                setFormDisabled(false);
                                return;
                            }

                            // Parse the JSON output from PHP, splitting it by the separator
                            const chunks = value.split('\n\n');
                            chunks.forEach(chunk => {
                                if (chunk.length === 0) {
                                    return;
                                }

                                const parsed = JSON.parse(chunk);
                                currentMessage += parsed.content;
                                canBeSummarized = parsed.can_be_summarized;

                                if (typeof parsed.error !== 'undefined') {
                                    console.error(parsed.error);
                                    setFormDisabled(false);
                                    return;
                                }

                                if (onReceivedChunk) {
                                    decreaseTokenBar(model);
                                    onReceivedChunk(parsed.content, currentMessage);
                                }
                            });
                        }

                        if (onReceivedFull) {
                            onReceivedFull(currentMessage, canBeSummarized);
                        }

                        window.dispatchEvent(new CustomEvent('app-chat-received', {
                            bubbles: true,
                            detail: {},
                        }));
                    }).catch(error => {
                        console.error(error);
                        onReceivedFull('Er is een fout opgetreden bij het ophalen van het bericht.', false);
                        setFormDisabled(false);
                        window.dispatchEvent(new CustomEvent('app-chat-received', {
                            bubbles: true,
                            detail: {},
                        }));
                    });
                }

                function submitPrompt() {
                    if (isChatDisabled) {
                        isChatCancelled = true;
                        return;
                    }

                    let prompt = promptEl.value;

                    if (prompt.trim() === '') {
                        return;
                    }

                    setFormDisabled(true);

                    addMessage(prompt, 'user');
                    const { messageTextEl, historyLength } = addMessage('...', 'assistant');

                    promptEl.value = '';
                    sizePromptEl();

                    messageTextEl.innerText = '';

                    performPrompt(function(content, currentMessage) {
                        // If the content ends with one or more newlines, call marked.parse to convert all text until now to HTML
                        if (content.endsWith('\n')) {
                            const html = marked.parse(currentMessage);
                            messageTextEl.innerHTML = html;
                            messageTextEl.dataset.originalText = utoa(currentMessage);
                            return;
                        }

                        const span = document.createElement('span');
                        span.innerText = content;
                        span.classList.add('opacity-0', 'transition-opacity')

                        messageTextEl.appendChild(span);

                        // Fade in the span
                        setTimeout(() => {
                            span.classList.remove('opacity-0');
                        }, 1);
                        chatScrollToBottom();
                    }, function(currentMessage, canBeSummarized) {
                        messageTextEl.innerHTML = marked.parse(currentMessage);
                        history[historyLength - 1].content = currentMessage;

                        if (canBeSummarized) {
                            const summarizeButton = document.createElement('button');
                            summarizeButton.classList.add('text-xs', 'text-slate-400', 'hover:text-slate-600', 'cursor-pointer');
                            summarizeButton.innerText = '» Vervang gehele chatgeschiedenis met samenvatting';
                            summarizeButton.addEventListener('click', function() {
                                setFormDisabled(true);
                                chatHistory.innerHTML = '';
                                addMessage('Bezig met samenvatten...', 'system');
                                performPrompt(undefined, function(currentMessage) {
                                    // Set the history to the summarized message
                                    history.length = 0;
                                    chatHistory.innerHTML = '';
                                    addMessage(currentMessage, 'system');
                                    chatScrollToBottom();
                                    setFormDisabled(false);
                                }, true);
                            });

                            messageTextEl.appendChild(summarizeButton);
                        }

                        setFormDisabled(false);
                        chatScrollToBottom();
                    });
                }

                promptEl.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();

                        if (!event.shiftKey) {
                            submitPrompt();
                            return;
                        }

                        promptEl.value += '\n';

                        sizePromptEl();
                        return;
                    }

                    if (event.key === 'Backspace') {
                        // Cheat to quickly enter test data into prompt
                        if (event.altKey && event.ctrlKey) {
                            promptEl.value = 'Give a short one sentence story that is witty and humorous';
                            submitPrompt();
                            return;
                        }

                        sizePromptEl();
                        return;
                    }

                    // When someone pastes also resize
                    if (event.key === 'v' && (event.metaKey || event.ctrlKey)) {
                        setTimeout(sizePromptEl, 1);
                    }

                    if (event.ctrlKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
                        event.preventDefault();
                        const historyLength = history.length;

                        if (historyLength === 0) {
                            return;
                        }

                        const currentPrompt = promptEl.value;
                        const currentIndex = history.findIndex(message => message.content === currentPrompt && message.role === 'user');
                        let newIndex = currentIndex;

                        if (event.key === 'ArrowDown') {
                            newIndex = currentIndex === -1 ? historyLength - 2 : currentIndex - 2;
                        } else if (event.key === 'ArrowUp') {
                            newIndex = currentIndex === -1 ? 0 : currentIndex + 2;
                        }

                        if (newIndex < 0 || newIndex >= historyLength) {
                            promptEl.value = '';
                            sizePromptEl();
                            return;
                        }

                        promptEl.value = history[newIndex].content;
                        sizePromptEl();
                    }
                });

                formEl.addEventListener('submit', function(event) {
                    event.preventDefault();
                    submitPrompt();
                });
            </script>
        </div>
    @else
        <x-notice>
            CurioGPT is momenteel vergrendeld. Het is alleen actief tijdens examens. Vraag je leraar om het te
            ontgrendelen.
        </x-notice>
    @endif
</div>
