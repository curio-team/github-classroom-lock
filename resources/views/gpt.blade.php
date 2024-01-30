<x-app-layout>
    @unless ($isChatActive)
    <div class="rounded bg-slate-500 border border-slate-600 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-slate-400"
                     fill="currentColor"
                     viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                          d="M10 2a8 8 0 100 16 8 8 0 000-16zM8 9a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1zm0 2a1 1 0 100 2h4a1 1 0 100-2H8z"
                          clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm leading-5 text-slate-200">
                    CurioGPT is currently locked. It is only active during examination times. Please ask your teacher to
                    unlock it.
                </p>
            </div>
        </div>
    </div>
    @else
    <div class="rounded bg-slate-500 border border-slate-600 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-slate-400"
                     fill="currentColor"
                     viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                          d="M10 2a8 8 0 100 16 8 8 0 000-16zM8 9a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1zm0 2a1 1 0 100 2h4a1 1 0 100-2H8z"
                          clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm leading-5 text-slate-200">
                    This chat is not saved. When you refresh the page, the chat will be gone.
                </p>
            </div>
        </div>
    </div>

    <div class="flex flex-col min-h-[500px] border border-slate-400 rounded">
        <ul class="flex flex-row border-b border-slate-400">
            <li class="flex-1 text-center rounded-tr bg-slate-200 p-4 text-black">
                <input type="radio"
                       name="model"
                       value="gpt-3.5-turbo"
                       id="model-3.5"
                       checked>
                <label for="model-3.5">GPT 3.5-turbo</label>
            </li>
            <li class="flex-1 text-center rounded-tl bg-slate-200 p-4 text-black border-l border-slate-400">
                <input type="radio"
                       name="model"
                       value="gpt-4"
                       id="model-4">
                <label for="model-4">GPT 4</label>
            </li>
        </ul>

        <div class="flex flex-col overflow-y-scroll flex-1 bg-slate-300">
            <div id="chat-history"
                 class="flex flex-col p-5"></div>
        </div>

        <form class="flex border-t border-slate-400"
              id="ai_form"
              action="#"
              method="POST">
            <fieldset class="group flex flex-row w-full">
                @csrf
                <input type="text"
                    name="prompt"
                    id="prompt"
                    placeholder="Enter your prompt here..."
                    class="flex-grow border-transparent rounded-bl p-4 bg-slate-200 text-black">

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
    </div>

    <script>
        const formEl = document.getElementById('ai_form');
        const promptEl = document.getElementById('prompt');
        const chatHistory = document.getElementById('chat-history');
        const csrfEl = document.querySelector('meta[name="csrf-token"]');
        const fieldsetEl = formEl.querySelector('fieldset');
        const history = [];

        function setFormDisabled(disabled) {
            if (disabled) {
                fieldsetEl.setAttribute('disabled', 'disabled');
            } else {
                fieldsetEl.removeAttribute('disabled');
            }
        }

        // Adds a message to the history element and history array
        // It returns the message element so further modifications can be made
        function addMessage(message, isSender)
        {
            const messageContainerEl = document.createElement('div');
            messageContainerEl.classList.add('flex', 'flex-col', 'mb-2');

            const messageEl = document.createElement('div');
            messageEl.classList.add('flex', 'flex-row', 'mb-2');

            const authorEl = document.createElement('p');
            authorEl.classList.add('inline', 'text-xs', 'text-slate-400', 'mb-1');
            authorEl.innerText = isSender === true ? 'You:' : 'CurioGPT:';
            messageContainerEl.appendChild(authorEl);

            const messageContentEl = document.createElement('div');
            messageContentEl.classList.add('flex', 'flex-col', 'rounded', 'p-4', 'text-black');

            if (isSender === true) {
                messageContentEl.classList.add('bg-emerald-200');
                messageContainerEl.classList.add('self-end', 'ml-8');
            } else {
                messageContentEl.classList.add('bg-slate-200');
                messageContainerEl.classList.add('self-start', 'mr-8');
            }

            const messageTextEl = document.createElement('p');
            messageTextEl.innerText = message;

            messageContentEl.appendChild(messageTextEl);
            messageEl.appendChild(messageContentEl);
            messageContainerEl.appendChild(messageEl);
            chatHistory.appendChild(messageContainerEl);

            const author = isSender === true ? 'user' : 'assistant';
            history.push({
                role: author,
                content: message,
            });

            return messageTextEl;
        }

        formEl.addEventListener('submit', function(event) {
            event.preventDefault();

            setFormDisabled(true);

            const prompt = promptEl.value;
            const model = document.querySelector('input[name="model"]:checked').value;
            const aiRequestRoute = '{{ route('ai-request') }}';
            const csrfToken = csrfEl.getAttribute('content');

            addMessage(prompt, true);
            const messageTextEl = addMessage('...');

            promptEl.value = '';

            fetch(aiRequestRoute, {
                method: 'POST',
                headers:{
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    model: model,
                    history: history.slice(0, -1),
                    prompt: prompt,
                }),
            })
            .then(response => response.body)
            .then(async body => {
                const reader = body.pipeThrough(new TextDecoderStream())
                    .getReader();
                let partialChunk = '';

                messageTextEl.innerText = '';

                while (true) {
                    const { done, value } = await reader.read();

                    if (done) {
                        if (partialChunk) {
                            console.log('Full chunk:', partialChunk);
                        }

                        break;
                    }

                    const text = partialChunk + value;
                    const chunks = text.split('\n');

                    for (let i = 0; i < chunks.length - 1; i++) {
                        if (chunks[i].trim().startsWith('data: ')) {
                            if(chunks[i].trim().substring(6) == '[DONE]'){
                                console.log('DONE');
                                break;
                            }

                            const dataChunk = JSON.parse(chunks[i].trim().substring(6));
                            const content = dataChunk.choices[0].delta.content;

                            if(dataChunk.choices[0].finish_reason == 'stop'){
                                break;
                            }

                            messageTextEl.innerText += content;

                            console.log(dataChunk);
                        }
                    }

                    partialChunk = chunks[chunks.length - 1];
                }

                console.log('Response received in full.');
                setFormDisabled(false);
            });
        });
    </script>
    @endif
</x-app-layout>
