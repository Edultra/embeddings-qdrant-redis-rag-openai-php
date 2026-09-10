<?php

$documentsDirectory = __DIR__ . '/documents';
$availableDocuments = [];
if (is_dir($documentsDirectory)) {
    foreach (scandir($documentsDirectory) as $documentName) {
        $documentPath = $documentsDirectory . DIRECTORY_SEPARATOR . $documentName;
        if (is_file($documentPath) && strtolower(pathinfo($documentName, PATHINFO_EXTENSION)) === 'pdf') {
            $availableDocuments[] = $documentName;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistente RAG</title>
    <style>
        :root {
            --ink: #172026;
            --muted: #718087;
            --paper: #f6f3ed;
            --panel: #fffdf9;
            --green: #2d7d6b;
            --green-dark: #205b51;
            --bubble: #dcf3e7;
            --line: #e5e3dc;
            --shadow: 0 22px 60px rgba(40, 54, 52, .16);
        }

        * { box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            font-family: Georgia, 'Times New Roman', serif;
            background: #d8e7df;
            background-image: radial-gradient(circle at 12% 14%, rgba(255,255,255,.6) 0 2px, transparent 3px), linear-gradient(135deg, #d8e7df, #f0e7d8);
        }

        .app {
            width: min(1100px, calc(100% - 32px));
            height: min(780px, calc(100vh - 40px));
            min-height: 560px;
            margin: 20px auto;
            display: grid;
            grid-template-columns: 290px 1fr;
            overflow: hidden;
            background: var(--panel);
            border: 1px solid rgba(255,255,255,.75);
            border-radius: 18px;
            box-shadow: var(--shadow);
        }

        aside { background: #f4f0e8; border-right: 1px solid var(--line); padding: 28px 22px; }
        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 42px; }
        .brand-mark { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 13px; color: #fff; background: var(--green); font: bold 20px Georgia, serif; }
        .brand h1 { margin: 0; font-size: 21px; letter-spacing: 0; }
        .brand p { margin: 3px 0 0; color: var(--muted); font: 12px Arial, sans-serif; }
        .section-label { color: var(--muted); font: 11px Arial, sans-serif; letter-spacing: 1.5px; text-transform: uppercase; }
        .topic { margin-top: 14px; padding: 14px; border: 1px solid #ded9cf; border-radius: 10px; background: rgba(255,255,255,.55); }
        .topic strong { display: block; margin-bottom: 5px; font-size: 15px; }
        .topic span { color: var(--muted); font: 12px/1.4 Arial, sans-serif; }
        .document-label { display: block; margin-top: 24px; color: var(--muted); font: 11px Arial, sans-serif; }
        select { width: 100%; margin-top: 8px; padding: 10px 9px; border: 1px solid #d8d4ca; border-radius: 8px; color: var(--ink); background: #fffdf9; font: 12px Arial, sans-serif; }
        .index-button { width: 100%; height: 38px; margin-top: 9px; padding: 0 8px; border-radius: 8px; font-size: 12px; }
        .index-status { min-height: 30px; margin: 8px 0 0; color: var(--muted); font: 11px/1.4 Arial, sans-serif; }
        .status { display: flex; align-items: center; gap: 8px; margin-top: 30px; color: var(--muted); font: 12px Arial, sans-serif; }
        .status i { width: 8px; height: 8px; border-radius: 50%; background: #4da987; }

        main { min-width: 0; min-height: 0; display: flex; flex-direction: column; background: var(--paper); }
        .chat-header { flex: 0 0 auto; display: flex; align-items: center; justify-content: space-between; padding: 22px 30px; background: rgba(255,253,249,.86); border-bottom: 1px solid var(--line); }
        .chat-header h2 { margin: 0; font-size: 22px; }
        .chat-header p { margin: 4px 0 0; color: var(--muted); font: 12px Arial, sans-serif; }
        .header-dot { width: 11px; height: 11px; border-radius: 50%; background: var(--green); box-shadow: 0 0 0 5px rgba(45,125,107,.12); }
        .messages { flex: 1 1 auto; min-height: 0; overflow-x: hidden; overflow-y: auto; padding: 34px clamp(18px, 7vw, 82px); background-image: linear-gradient(rgba(246,243,237,.83), rgba(246,243,237,.83)), repeating-linear-gradient(45deg, transparent 0 20px, rgba(45,125,107,.035) 21px 22px); }
        .welcome { max-width: 440px; margin: 13vh auto 0; text-align: center; }
        .welcome h3 { margin: 0 0 10px; font-size: 28px; font-weight: normal; }
        .welcome p { margin: 0; color: var(--muted); font: 14px/1.6 Arial, sans-serif; }
        .message { display: flex; margin: 0 0 15px; animation: rise .24s ease-out both; }
        .message.user { justify-content: flex-end; }
        .bubble { max-width: min(75%, 590px); min-width: 0; padding: 12px 15px 9px; border-radius: 5px 15px 15px 15px; background: #fffdf9; box-shadow: 0 2px 7px rgba(57,68,62,.07); white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word; font: 15px/1.5 Arial, sans-serif; }
        .user .bubble { border-radius: 15px 5px 15px 15px; background: var(--bubble); }
        .time { display: block; margin-top: 5px; color: #84958e; text-align: right; font-size: 10px; }
        .usage { display: block; margin-top: 3px; color: #84958e; text-align: right; font-size: 10px; }
        .typing .bubble { color: var(--muted); font-style: italic; }
        .composer { flex: 0 0 auto; display: flex; gap: 12px; align-items: flex-end; padding: 18px 30px 22px; background: rgba(255,253,249,.92); border-top: 1px solid var(--line); }
        textarea { flex: 1 1 auto; min-width: 0; min-height: 48px; max-height: 130px; resize: none; padding: 14px 16px; border: 1px solid #d8d9d2; border-radius: 12px; outline: none; color: var(--ink); background: #fff; font: 14px Arial, sans-serif; }
        textarea:focus { border-color: var(--green); box-shadow: 0 0 0 3px rgba(45,125,107,.12); }
        button { height: 48px; padding: 0 19px; border: 0; border-radius: 12px; color: #fff; background: var(--green); cursor: pointer; font: bold 13px Arial, sans-serif; transition: background .2s, transform .2s; }
        button:hover { background: var(--green-dark); transform: translateY(-1px); }
        button:disabled { opacity: .55; cursor: wait; transform: none; }
        @keyframes rise { from { opacity: 0; transform: translateY(7px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 700px) {
            .app { width: 100%; height: 100vh; min-height: 0; margin: 0; border: 0; border-radius: 0; grid-template-columns: 1fr; }
            aside { display: none; }
            .chat-header { padding: 18px 20px; }
            .messages { padding: 24px 16px; }
            .composer { padding: 12px 14px 16px; }
            .bubble { max-width: 88%; }
            .welcome { margin-top: 18vh; }
        }
    </style>
</head>
<body>
    <div class="app">
        <aside>
            <div class="brand">
                <div class="brand-mark">AI</div>
                <div><h1>Assistente RAG</h1><p>Conhecimento local, respostas claras</p></div>
            </div>
            <span class="section-label">Base ativa</span>
            <div class="topic"><strong>Base de conhecimento</strong><span>Escolha um PDF para atualizar a base Qdrant.</span></div>
            <label class="document-label" for="document-select">Documento para indexar</label>
            <select id="document-select" <?php echo empty($availableDocuments) ? 'disabled' : ''; ?>>
                <option value=""><?php echo empty($availableDocuments) ? 'Nenhum PDF encontrado' : 'Selecione um PDF'; ?></option>
                <?php foreach ($availableDocuments as $documentName): ?>
                    <option value="<?php echo htmlspecialchars($documentName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($documentName, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="index-button" type="button" id="index-document" <?php echo empty($availableDocuments) ? 'disabled' : ''; ?>>Indexar documento</button>
            <p class="index-status" id="index-status" aria-live="polite"></p>
            <div class="status"><i></i> Serviços conectados</div>
        </aside>
        <main>
            <header class="chat-header">
                <div><h2>Conversa com a IA</h2><p>Assistente educacional</p></div>
                <span class="header-dot" title="Online"></span>
            </header>
            <section class="messages" id="messages" aria-live="polite">
                <div class="welcome" id="welcome"><h3>Olá, vamos conversar?</h3><p>Envie uma pergunta e eu buscarei a resposta no contexto da sua base de conhecimento.</p></div>
            </section>
            <form class="composer" id="chat-form">
                <textarea id="question" name="question" placeholder="Digite sua pergunta..." rows="1" maxlength="4000" required></textarea>
                <button type="submit" id="send">Enviar</button>
            </form>
        </main>
    </div>
    <script>
        const form = document.getElementById('chat-form');
        const input = document.getElementById('question');
        const messages = document.getElementById('messages');
        const send = document.getElementById('send');
        const welcome = document.getElementById('welcome');
        const documentSelect = document.getElementById('document-select');
        const indexButton = document.getElementById('index-document');
        const indexStatus = document.getElementById('index-status');

        const savedDocument = localStorage.getItem('rag-selected-document');
        if (savedDocument && documentSelect && Array.from(documentSelect.options).some((option) => option.value === savedDocument)) {
            documentSelect.value = savedDocument;
        }
        documentSelect?.addEventListener('change', () => {
            localStorage.setItem('rag-selected-document', documentSelect.value);
        });

        indexButton?.addEventListener('click', async () => {
            const documentName = documentSelect.value;
            if (!documentName || indexButton.disabled) return;

            indexButton.disabled = true;
            indexStatus.textContent = 'Indexando documento...';
            try {
                const response = await fetch('buscar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ action: 'index_document', document: documentName })
                });
                const data = await response.json();
                indexStatus.textContent = data.message || data.error || 'Não foi possível indexar o documento.';
                indexStatus.style.color = data.message ? 'var(--green)' : '#a0473f';
            } catch (error) {
                indexStatus.textContent = 'Falha de conexão ao indexar o documento.';
                indexStatus.style.color = '#a0473f';
            } finally {
                indexButton.disabled = false;
            }
        });

        function addMessage(text, author) {
            if (welcome) welcome.remove();
            const wrapper = document.createElement('div');
            wrapper.className = `message ${author}`;
            const bubble = document.createElement('div');
            bubble.className = 'bubble';
            bubble.append(document.createTextNode(text));
            const time = document.createElement('span');
            time.className = 'time';
            time.textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            bubble.append(time);
            wrapper.append(bubble);
            messages.append(wrapper);
            messages.scrollTop = messages.scrollHeight;
            return wrapper;
        }

        function updateMessageTime(wrapper) {
            const time = wrapper.querySelector('.time');
            if (time) {
                time.textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            }
        }

        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 130) + 'px';
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                form.requestSubmit();
            }
        });

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const question = input.value.trim();
            if (!question || send.disabled) return;
            const selectedDocument = documentSelect?.selectedOptions?.[0]?.value || '';
            if (!selectedDocument) {
                addMessage('Selecione um documento antes de fazer uma pergunta.', 'assistant error');
                return;
            }

            addMessage(question, 'user');
            input.value = '';
            input.style.height = 'auto';
            send.disabled = true;
            send.textContent = '...';
            const typing = addMessage('Consultando a base de conhecimento...', 'assistant typing');
            typing.querySelector('.time').textContent = 'Aguardando resposta';

            try {
                const response = await fetch('buscar.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' },
                    body: JSON.stringify({ question, document: selectedDocument, stream: true })
                });
                if (!response.ok || !response.body) throw new Error('Resposta de streaming indisponível.');
                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';
                let answer = '';
                let renderedAnswer = '';
                let targetAnswer = '';
                let renderTimer = null;
                let finished = false;
                const responseBubble = typing.querySelector('.bubble');
                let responseStarted = false;

                const markResponseStarted = () => {
                    if (!responseStarted) {
                        updateMessageTime(typing);
                        responseStarted = true;
                    }
                };

                const renderNextCharacters = () => {
                    if (renderedAnswer.length < targetAnswer.length) {
                        renderedAnswer = targetAnswer.slice(0, renderedAnswer.length + 3);
                        responseBubble.firstChild.nodeValue = renderedAnswer;
                        messages.scrollTop = messages.scrollHeight;
                        renderTimer = setTimeout(renderNextCharacters, 18);
                    } else {
                        renderTimer = null;
                    }
                };

                const waitForAnswer = async () => {
                    while (renderedAnswer.length < targetAnswer.length) {
                        await new Promise((resolve) => setTimeout(resolve, 20));
                    }
                };

                while (!finished) {
                    const chunk = await reader.read();
                    buffer += decoder.decode(chunk.value || new Uint8Array(), { stream: !chunk.done });
                    const events = buffer.split('\n\n');
                    buffer = events.pop();
                    for (const event of events) {
                        const line = event.split('\n').find((item) => item.startsWith('data: '));
                        if (!line) continue;
                        const data = JSON.parse(line.slice(6));
                        if (data.type === 'chunk') {
                            const chunkText = data.text || '';
                            if (chunkText !== '') markResponseStarted();
                            targetAnswer += chunkText;
                            if (!renderTimer) renderNextCharacters();
                        } else if (data.type === 'done') {
                            answer = data.answer || targetAnswer;
                            if (answer !== '') markResponseStarted();
                            targetAnswer = answer;
                            if (!renderTimer) renderNextCharacters();
                            await waitForAnswer();
                            const source = data.sources?.length ? `\n\nFonte: ${data.sources.join(', ')}` : '';
                            const usage = data.usage || {};
                            const tokenInfo = `\n\nTokens: entrada ${usage.input_tokens ?? 'n/d'} | saída ${usage.output_tokens ?? 'n/d'}`;
                            responseBubble.firstChild.nodeValue = targetAnswer + source + tokenInfo;
                            typing.classList.remove('typing');
                            finished = true;
                        } else if (data.type === 'error') {
                            responseBubble.firstChild.nodeValue = data.error || 'Não foi possível obter uma resposta.';
                            typing.classList.remove('typing');
                            typing.classList.add('error');
                            finished = true;
                        }
                    }
                    if (chunk.done) finished = true;
                }
            } catch (error) {
                typing.remove();
                addMessage('Não foi possível conectar ao backend. Confira os serviços e tente novamente.', 'assistant error');
            } finally {
                send.disabled = false;
                send.textContent = 'Enviar';
                input.focus();
            }
        });
    </script>
</body>
</html>
