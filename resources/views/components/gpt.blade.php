@props(['isChatActive' => false])

<x-notice>
    CurioGPT is provided as is and with no guarantees. It may not always be available. As with all AI, it may produce incorrect or inappropriate responses. Please use it responsibly.
</x-notice>

@unless ($isChatActive)
<x-notice>
    CurioGPT is currently locked. It is only active during examination times. Please ask your teacher to unlock it.
</x-notice>
@else
<x-notice>
    This chat is not saved. When you refresh the page, the chat will be gone.
</x-notice>
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
            const reader = body.pipeThrough(new TextDecoderStream()).getReader();
            messageTextEl.innerText = '';

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

                    const span = document.createElement('span');
                    span.innerText = parsed.content;
                    span.classList.add('opacity-0', 'transition-opacity')

                    messageTextEl.appendChild(span);

                    // Fade in the span
                    setTimeout(() => {
                        span.classList.remove('opacity-0');
                    }, 1);
                });
            }

            // Simplify the message to only text (removing the span elements)
            messageTextEl.innerText = messageTextEl.innerText.trim();
            setFormDisabled(false);

            window.dispatchEvent(new CustomEvent('app-chat-received', {
                bubbles: true,
                detail: {},
            }));
        });
    });
</script>
@endif
