@props(['isChatActive' => false])

<div x-data="{ maximized: false }"
     x-bind:class="{ 'fixed inset-0 bg-black': maximized }">
    <x-content.section tight
                       id="chat-section"
                       x-bind:class="{ 'h-full': maximized, 'h-[600px]': !maximized }"
                       class="flex flex-col border border-slate-400 rounded">
        <div class="flex flex-row border-b border-slate-400">
            <label for="model-3.5"
                   class="flex-1 text-center rounded-tl bg-slate-200 p-4 hover:bg-slate-300 cursor-pointer">
                <input type="radio"
                       name="model"
                       value="gpt-3.5-turbo"
                       id="model-3.5"
                       checked>
                GPT 3.5-turbo
            </label>
            <label
                   class="flex-1 text-center bg-slate-200 p-4 border-l border-slate-400 hover:bg-slate-300 cursor-pointer">
                <input type="radio"
                       name="model"
                       value="gpt-4"
                       id="model-4">
                GPT 4
            </label>
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
            <x-notice>
                CurioGPT wordt geleverd zoals het is en zonder garanties. Het is mogelijk dat het niet altijd
                beschikbaar is.
            </x-notice>

            @unless ($isChatActive)
            <x-notice>
                CurioGPT is momenteel vergrendeld. Het is alleen actief tijdens examens. Vraag je leraar om het te
                ontgrendelen.
            </x-notice>
            @else
            <x-notice>
                Dit gesprek wordt niet opgeslagen. Wanneer je de pagina ververst, is het gesprek weg.
            </x-notice>

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
                          placeholder="Voer je prompt hier in..."
                          rows="1"
                          cols="1"
                          class="flex-grow border-transparent rounded-bl p-4 bg-slate-200 text-black"></textarea>

                <button type="submit"
                        class="flex-shrink-0 bg-emerald-200 border-emerald-600 text-black p-4 rounded-br disabled:opacity-50 disabled:cursor-not-allowed">
                    <svg xmlns="http://www.w3.org/2000/svg"
                         fill="none"
                         viewBox="0 0 24 24"
                         stroke-width="2"
                         stroke="currentColor"
                         class="w-4 h-4 text-gray-600 group-disabled:hidden">
                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                    </svg>
                    <svg xmlns="http://www.w3.org/2000/svg"
                         fill="none"
                         viewBox="0 0 24 24"
                         stroke-width="1.5"
                         stroke="currentColor"
                         class="w-4 h-4 hidden group-disabled:block animate-spin">
                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                </button>
            </fieldset>
        </form>
    </x-content.section>

    <div id="message-template"
         class="message-container group flex flex-col mb-2 hidden">
        <p class="author text-xs text-slate-400 mb-1"></p>
        <div class="message flex flex-row mb-2">
            <div class="relative message-content flex flex-col rounded p-4 text-black">
                <x-buttons.secondary class="absolute opacity-50 top-0 right-0 -mt-2 -mr-2 hidden group-hover:block"
                                     tight
                                     title="Copy message"
                                     aria-label="Copy message"
                                     @click="$tooltip('Copied to clipboard!')"
                                     x-clipboard="$el.parentElement.querySelector('.chat-message-text').innerText">
                    <x-slot name="icon">
                        <x-icons.copy />
                    </x-slot>
                </x-buttons.secondary>
                <p class="chat-message-text"></p>
            </div>
        </div>
    </div>

    <script>
        const formEl = document.getElementById('ai-form');
        const promptEl = document.getElementById('prompt');
        const chatHistory = document.getElementById('chat-history');
        const csrfEl = document.querySelector('meta[name="csrf-token"]');
        const submitButtonEl = formEl.querySelector('fieldset button');
        const template = document.getElementById('message-template');
        let lastScrollByUser = 0;
        const history = [];

        function setFormDisabled(disabled) {
            if (disabled) {
                submitButtonEl.setAttribute('disabled', 'disabled');
            } else {
                submitButtonEl.removeAttribute('disabled');
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
            messageContainerEl.id = ''; // Remove the id to avoid duplicates
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

            chatHistory.appendChild(messageContainerEl);
            chatScrollToBottom();

            const historyLength = history.push({
                role,
                content: message,
            });

            return { messageTextEl, historyLength };
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

        function performPrompt(onReceivedChunk, onReceivedFull, summarizeMode) {
            summarizeMode = summarizeMode ? true : false;

            const model = document.querySelector('input[name="model"]:checked').value;
            const aiRequestRoute = '{{ route('ai-request') }}';
            const csrfToken = csrfEl.getAttribute('content');

            fetch(aiRequestRoute, {
                method: 'POST',
                headers:{
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    model: model,
                    history: history.slice(0, -1), // Remove the last message, which is the '...' loading message
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
                    if (done) break;

                    // Parse the JSON output from PHP, splitting it by the separator
                    const chunks = value.split('\n\n');
                    chunks.forEach(chunk => {
                        if (chunk.length === 0) {
                            return;
                        }

                        const parsed = JSON.parse(chunk);
                        currentMessage += parsed.content;
                        canBeSummarized = parsed.can_be_summarized;

                        if (onReceivedChunk) {
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
            });
        }

        function submitPrompt() {
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
                sizePromptEl();
                return;
            }

            // When someone pastes also resize
            if (event.key === 'v' && (event.metaKey || event.ctrlKey)) {
                setTimeout(sizePromptEl, 1);
            }

            if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
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
    @endif
</div>
