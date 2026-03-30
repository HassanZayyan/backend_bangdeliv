<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BangDeliv - API Tester</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #f97316;
            --primary-hover: #ea580c;
            --bg-color: #0f172a;
            --glass-bg: rgba(30, 41, 59, 0.7);
            --glass-border: rgba(255, 255, 255, 0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-color);
            background-image:
                radial-gradient(at 0% 0%, hsla(253, 16%, 7%, 1) 0, transparent 50%),
                radial-gradient(at 50% 0%, hsla(28, 90%, 30%, 1) 0, transparent 50%),
                radial-gradient(at 100% 0%, hsla(339, 49%, 30%, 1) 0, transparent 50%);
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: var(--text-main);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            box-sizing: border-box;
        }

        .container {
            width: 100%;
            max-width: 650px;
            padding: 2.5rem;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .header-content {
            text-align: center;
            margin-bottom: 2rem;
        }

        h1 {
            margin-top: 0;
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #fdba74, #ea580c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }

        p {
            color: var(--text-muted);
            margin-bottom: 2rem;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.75rem;
            font-weight: 500;
            font-size: 0.9rem;
            color: #cbd5e1;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        label svg {
            width: 18px;
            height: 18px;
            color: var(--primary);
        }

        textarea {
            width: 100%;
            padding: 1.25rem;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            color: var(--text-main);
            font-family: inherit;
            font-size: 1rem;
            resize: vertical;
            min-height: 120px;
            box-sizing: border-box;
            transition: all 0.3s ease;
            line-height: 1.5;
        }

        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.15);
            background: rgba(15, 23, 42, 0.8);
        }

        textarea::placeholder {
            color: #475569;
        }

        button {
            width: 100%;
            padding: 1.1rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-hover));
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 10px 20px -10px var(--primary);
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px -10px var(--primary);
            filter: brightness(1.1);
        }

        button:active {
            transform: translateY(1px);
        }

        button:disabled {
            background: #334155;
            color: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            border: 1px solid var(--glass-border);
        }

        button svg {
            width: 20px;
            height: 20px;
        }

        .spinner {
            display: none;
            width: 22px;
            height: 22px;
            border: 3px solid rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .result-container {
            margin-top: 2.5rem;
            display: none;
            animation: fadeIn 0.4s ease-out;
            position: relative;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .result-header h3 {
            margin: 0;
            font-size: 1.1rem;
            color: #e2e8f0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .status-success {
            background: rgba(74, 222, 128, 0.1);
            color: #4ade80;
            border: 1px solid rgba(74, 222, 128, 0.2);
        }

        .status-error {
            background: rgba(248, 113, 113, 0.1);
            color: #f87171;
            border: 1px solid rgba(248, 113, 113, 0.2);
        }

        .code-wrapper {
            position: relative;
        }

        pre {
            background: rgba(0, 0, 0, 0.5);
            padding: 1.5rem;
            border-radius: 16px;
            border: 1px solid var(--glass-border);
            overflow-x: auto;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 0.9rem;
            line-height: 1.6;
            margin: 0;
            box-shadow: inset 0 2px 10px rgba(0, 0, 0, 0.2);
        }

        .json-key {
            color: #7dd3fc;
        }

        .json-string {
            color: #a7f3d0;
        }

        .json-number {
            color: #fde047;
        }

        .json-boolean {
            color: #c4b5fd;
        }

        .json-null {
            color: #fca5a5;
            font-style: italic;
        }

        @media (max-width: 640px) {
            .container {
                padding: 1.5rem;
                border-radius: 20px;
            }

            h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>

    <div class="container">
        <div class="header-content">
            <h1>BangDeliv Chatbot</h1>
            <p>Platform uji coba AI asisten BangDeliv berbasis Gemini 2.5 Flash. Sistem akan memahami pesan natural Anda
                dan mengekstrak detail pesanan secara otomatis.</p>
        </div>

        <form id="chatForm">
            <div class="form-group">
                <label for="message">
                    <svg xmlns="http://www.w3.org/polygons/2000/svg" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                    Pesan Customer
                </label>
                <textarea id="message"
                    placeholder="Ketik pesanan Anda disini...&#10;Contoh: Bang, aku mau order nasi padang 2 bungkus dan es teh manis 2 gelas dari RM Sederhana banget ya."
                    required></textarea>
            </div>
            <button type="submit" id="submitBtn">
                <span id="btnText" style="display: flex; align-items: center; gap: 0.5rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    Ekstrak Pesanan Pake AI
                </span>
                <div class="spinner" id="btnSpinner"></div>
            </button>
        </form>

        <div class="result-container" id="resultContainer">
            <div class="result-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" style="width:18px;height:18px;" fill="none"
                        viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16m-7 6h7" />
                    </svg>
                    Hasil Ekstraksi JSON
                </h3>
                <span id="statusBadge" class="status-badge status-success">Success</span>
            </div>
            <div class="code-wrapper">
                <pre id="jsonOutput"></pre>
            </div>
        </div>
    </div>

    <script>
        // Simple JSON syntax highlighter
        function syntaxHighlight(json) {
            if (typeof json != 'string') {
                json = JSON.stringify(json, undefined, 4);
            }
            json = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            return json.replace(/("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g, function (match) {
                var cls = 'json-number';
                if (/^"/.test(match)) {
                    if (/:$/.test(match)) {
                        cls = 'json-key';
                    } else {
                        cls = 'json-string';
                    }
                } else if (/true|false/.test(match)) {
                    cls = 'json-boolean';
                } else if (/null/.test(match)) {
                    cls = 'json-null';
                }
                return '<span class="' + cls + '">' + match + '</span>';
            });
        }

        document.getElementById('chatForm').addEventListener('submit', async function (e) {
            e.preventDefault();

            const message = document.getElementById('message').value;
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const btnSpinner = document.getElementById('btnSpinner');
            const resultContainer = document.getElementById('resultContainer');
            const jsonOutput = document.getElementById('jsonOutput');
            const statusBadge = document.getElementById('statusBadge');

            // Set loading state
            submitBtn.disabled = true;
            btnText.style.display = 'none';
            btnSpinner.style.display = 'block';

            if (resultContainer.style.display === 'block') {
                resultContainer.style.opacity = '0.5';
            }

            try {
                const response = await fetch('/api/chatbot/process', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ message: message })
                });

                const data = await response.json();

                resultContainer.style.display = 'block';
                resultContainer.style.opacity = '1';

                if (data.status === 'success' || response.ok) {
                    statusBadge.className = 'status-badge status-success';
                    statusBadge.textContent = 'BERHASIL: ' + response.status;
                } else {
                    statusBadge.className = 'status-badge status-error';
                    statusBadge.textContent = 'GAGAL: ' + response.status;
                }

                jsonOutput.innerHTML = syntaxHighlight(data);

            } catch (error) {
                resultContainer.style.display = 'block';
                resultContainer.style.opacity = '1';
                statusBadge.className = 'status-badge status-error';
                statusBadge.textContent = 'NETWORK ERROR';

                jsonOutput.innerHTML = syntaxHighlight({
                    error: "Tidak dapat terhubung ke server laravel.",
                    message: error.message
                });
            } finally {
                // Reset loading state
                submitBtn.disabled = false;
                btnText.style.display = 'flex';
                btnSpinner.style.display = 'none';
            }
        });
    </script>
</body>

</html>