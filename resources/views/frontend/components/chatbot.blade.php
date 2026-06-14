{{--
|──────────────────────────────────────────────────────────────────────────────
| Chatbot Floating Widget — Tư vấn phòng trọ AI (Gemini)
|
| Cách dùng: @include('frontend.components.chatbot')
| Nhúng vào: layouts/app.blade.php, ngay trước thẻ </body>
|──────────────────────────────────────────────────────────────────────────────
--}}

{{-- ══════════════════════════════════════════════════════════════════════════
     CSS STYLES
══════════════════════════════════════════════════════════════════════════ --}}
<style>
  /* ── Variables ─────────────────────────────────────────────────────────── */
  :root {
    --cb-primary:      #4f46e5;
    --cb-primary-dark: #3730a3;
    --cb-primary-glow: rgba(79,70,229,.35);
    --cb-surface:      #ffffff;
    --cb-bg:           #f5f5f7;
    --cb-user-bg:      #4f46e5;
    --cb-user-text:    #ffffff;
    --cb-bot-bg:       #f0f0f5;
    --cb-bot-text:     #1e1e2e;
    --cb-border:       rgba(0,0,0,.08);
    --cb-shadow:       0 20px 60px rgba(0,0,0,.18), 0 4px 16px rgba(79,70,229,.15);
    --cb-radius:       18px;
    --cb-w:            360px;
    --cb-h:            520px;
  }

  /* ── Toggle Button ─────────────────────────────────────────────────────── */
  #cb-toggle-btn {
    position: fixed;
    bottom: 28px;
    right: 28px;
    z-index: 9998;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--cb-primary), var(--cb-primary-dark));
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 24px var(--cb-primary-glow), 0 2px 8px rgba(0,0,0,.2);
    transition: transform .25s cubic-bezier(.34,1.56,.64,1),
                box-shadow .25s ease;
  }
  #cb-toggle-btn:hover {
    transform: scale(1.1) rotate(-5deg);
    box-shadow: 0 10px 32px var(--cb-primary-glow);
  }
  #cb-toggle-btn svg { transition: opacity .2s, transform .25s; }
  #cb-toggle-btn .icon-close { display: none; }

  /* Unread badge */
  #cb-badge {
    position: absolute;
    top: -3px;
    right: -3px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #ef4444;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #fff;
    opacity: 0;
    transform: scale(0);
    transition: opacity .2s, transform .3s cubic-bezier(.34,1.56,.64,1);
    pointer-events: none;
  }
  #cb-badge.show { opacity: 1; transform: scale(1); }

  /* ── Chat Window ────────────────────────────────────────────────────────── */
  #cb-window {
    position: fixed;
    bottom: 104px;
    right: 28px;
    z-index: 9997;
    width: var(--cb-w);
    height: var(--cb-h);
    border-radius: var(--cb-radius);
    background: var(--cb-surface);
    box-shadow: var(--cb-shadow);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--cb-border);

    /* Hidden state */
    opacity: 0;
    transform: translateY(24px) scale(.95);
    pointer-events: none;
    transition: opacity .3s cubic-bezier(.4,0,.2,1),
                transform .3s cubic-bezier(.34,1.56,.64,1);
  }
  #cb-window.open {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: all;
  }

  /* ── Header ─────────────────────────────────────────────────────────────── */
  #cb-header {
    background: linear-gradient(135deg, var(--cb-primary), var(--cb-primary-dark));
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
  }
  .cb-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(255,255,255,.2);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border: 2px solid rgba(255,255,255,.3);
  }
  .cb-header-info { flex: 1; min-width: 0; }
  .cb-header-name {
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .cb-header-status {
    display: flex;
    align-items: center;
    gap: 5px;
    margin-top: 2px;
  }
  .cb-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #4ade80;
    animation: pulse-dot 2s infinite;
  }
  @keyframes pulse-dot {
    0%,100% { box-shadow: 0 0 0 0 rgba(74,222,128,.5); }
    50%      { box-shadow: 0 0 0 4px rgba(74,222,128,0); }
  }
  .cb-status-text { color: rgba(255,255,255,.85); font-size: 11px; }
  #cb-close-btn {
    background: rgba(255,255,255,.15);
    border: none;
    color: #fff;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background .2s;
    flex-shrink: 0;
  }
  #cb-close-btn:hover { background: rgba(255,255,255,.3); }

  /* ── Message area ───────────────────────────────────────────────────────── */
  #cb-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 14px;
    background: var(--cb-bg);
    display: flex;
    flex-direction: column;
    gap: 10px;
    scroll-behavior: smooth;
  }
  #cb-messages::-webkit-scrollbar { width: 4px; }
  #cb-messages::-webkit-scrollbar-track { background: transparent; }
  #cb-messages::-webkit-scrollbar-thumb { background: rgba(0,0,0,.15); border-radius: 4px; }

  /* ── Bubbles ─────────────────────────────────────────────────────────────── */
  .cb-bubble-wrap {
    display: flex;
    align-items: flex-end;
    gap: 7px;
    animation: bubble-in .25s cubic-bezier(.34,1.56,.64,1) both;
  }
  @keyframes bubble-in {
    from { opacity: 0; transform: translateY(10px) scale(.95); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
  }
  .cb-bubble-wrap.user  { flex-direction: row-reverse; }
  .cb-bubble-wrap.bot   { flex-direction: row; }

  .cb-bot-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--cb-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-bottom: 2px;
  }

  .cb-bubble {
    max-width: 78%;
    padding: 10px 14px;
    border-radius: 18px;
    font-size: 13.5px;
    line-height: 1.55;
    word-break: break-word;
    white-space: pre-wrap;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
  }
  .cb-bubble.user {
    background: var(--cb-user-bg);
    color: var(--cb-user-text);
    border-bottom-right-radius: 5px;
  }
  .cb-bubble.bot {
    background: var(--cb-bot-bg);
    color: var(--cb-bot-text);
    border-bottom-left-radius: 5px;
  }

  /* Typing indicator */
  .cb-typing-dots {
    display: flex;
    gap: 4px;
    align-items: center;
    padding: 12px 16px;
  }
  .cb-typing-dots span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #9ca3af;
    animation: typing-bounce .9s infinite;
  }
  .cb-typing-dots span:nth-child(2) { animation-delay: .15s; }
  .cb-typing-dots span:nth-child(3) { animation-delay: .30s; }
  @keyframes typing-bounce {
    0%,80%,100% { transform: translateY(0); opacity: .5; }
    40%          { transform: translateY(-6px); opacity: 1; }
  }

  /* Timestamp */
  .cb-time {
    font-size: 10px;
    color: #9ca3af;
    margin-top: 3px;
    padding: 0 4px;
    align-self: flex-end;
  }
  .cb-bubble-wrap.user  .cb-time { text-align: right; }

  /* ── Input area ─────────────────────────────────────────────────────────── */
  #cb-footer {
    padding: 10px 12px;
    background: var(--cb-surface);
    border-top: 1px solid var(--cb-border);
    flex-shrink: 0;
  }
  #cb-form {
    display: flex;
    gap: 8px;
    align-items: flex-end;
  }
  #cb-input {
    flex: 1;
    border: 1.5px solid var(--cb-border);
    border-radius: 22px;
    padding: 9px 16px;
    font-size: 13.5px;
    outline: none;
    resize: none;
    max-height: 90px;
    min-height: 40px;
    line-height: 1.4;
    transition: border-color .2s, box-shadow .2s;
    font-family: inherit;
    background: var(--cb-bg);
    overflow-y: auto;
  }
  #cb-input:focus {
    border-color: var(--cb-primary);
    box-shadow: 0 0 0 3px var(--cb-primary-glow);
    background: #fff;
  }
  #cb-send-btn {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--cb-primary), var(--cb-primary-dark));
    border: none;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: transform .2s cubic-bezier(.34,1.56,.64,1), box-shadow .2s;
    box-shadow: 0 3px 10px var(--cb-primary-glow);
  }
  #cb-send-btn:hover:not(:disabled) {
    transform: scale(1.1);
    box-shadow: 0 5px 16px var(--cb-primary-glow);
  }
  #cb-send-btn:disabled { opacity: .55; cursor: not-allowed; transform: none; }

  #cb-hint {
    font-size: 10.5px;
    color: #9ca3af;
    text-align: center;
    margin-top: 6px;
    letter-spacing: .01em;
  }

  /* ── Responsive ─────────────────────────────────────────────────────────── */
  @media (max-width: 480px) {
    :root { --cb-w: calc(100vw - 24px); --cb-h: 70vh; }
    #cb-window { right: 12px; bottom: 96px; }
    #cb-toggle-btn { right: 16px; bottom: 16px; }
  }
</style>

{{-- ══════════════════════════════════════════════════════════════════════════
     HTML MARKUP
══════════════════════════════════════════════════════════════════════════ --}}

{{-- Toggle Button --}}
<button id="cb-toggle-btn" aria-label="Mở chatbot tư vấn phòng trọ" title="Tư vấn phòng trọ AI">
  {{-- Chat icon --}}
  <svg class="icon-chat" width="26" height="26" fill="none" viewBox="0 0 24 24">
    <path fill="#fff" d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2Zm-2 10H6V10h12v2Zm0-3H6V7h12v2Z"/>
  </svg>
  {{-- Close icon --}}
  <svg class="icon-close" width="22" height="22" fill="none" viewBox="0 0 24 24">
    <path stroke="#fff" stroke-width="2.5" stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/>
  </svg>
  <span id="cb-badge">1</span>
</button>

{{-- Chat Window --}}
<div id="cb-window" role="dialog" aria-label="Chatbot tư vấn phòng trọ" aria-hidden="true">

  {{-- Header --}}
  <div id="cb-header">
    <div class="cb-avatar">
      <svg width="22" height="22" fill="none" viewBox="0 0 24 24">
        <circle cx="12" cy="8" r="4" fill="rgba(255,255,255,.9)"/>
        <path fill="rgba(255,255,255,.7)" d="M4 20c0-4 3.58-7 8-7s8 3 8 7H4Z"/>
      </svg>
    </div>
    <div class="cb-header-info">
      <div class="cb-header-name">🏠 Trợ lý Phòng Trọ AI</div>
      <div class="cb-header-status">
        <span class="cb-status-dot"></span>
        <span class="cb-status-text">Đang hoạt động</span>
      </div>
    </div>
    <button id="cb-close-btn" aria-label="Đóng chat" title="Đóng">
      <svg width="14" height="14" fill="none" viewBox="0 0 24 24">
        <path stroke="#fff" stroke-width="2.5" stroke-linecap="round" d="M6 6l12 12M18 6 6 18"/>
      </svg>
    </button>
  </div>

  {{-- Messages --}}
  <div id="cb-messages" role="log" aria-live="polite">
    {{-- Tin nhắn chào mừng --}}
    <div class="cb-bubble-wrap bot">
      <div class="cb-bot-icon">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24">
          <circle cx="12" cy="8" r="4" fill="#fff"/>
          <path fill="rgba(255,255,255,.8)" d="M4 20c0-4 3.58-7 8-7s8 3 8 7H4Z"/>
        </svg>
      </div>
      <div>
        <div class="cb-bubble bot">
          👋 Xin chào! Tôi là trợ lý AI của <strong>CityHouse</strong>.<br>
          Tôi có thể giúp bạn tìm phòng trọ phù hợp với nhu cầu và ngân sách. Bạn muốn tìm phòng ở khu vực nào? 🏠
        </div>
        <div class="cb-time" id="cb-welcome-time"></div>
      </div>
    </div>
  </div>

  {{-- Input Footer --}}
  <div id="cb-footer">
    <div id="cb-form">
      <textarea
        id="cb-input"
        placeholder="Nhập tin nhắn... (Enter để gửi)"
        rows="1"
        aria-label="Nhập tin nhắn"
        maxlength="500"
      ></textarea>
      <button id="cb-send-btn" aria-label="Gửi tin nhắn" title="Gửi">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24">
          <path fill="#fff" d="M2.01 21 23 12 2.01 3 2 10l15 2-15 2 .01 7Z"/>
        </svg>
      </button>
    </div>
    <p id="cb-hint">Powered by Google Gemini AI &nbsp;·&nbsp; Enter để gửi</p>
  </div>
</div>

{{-- ══════════════════════════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════════════════════════ --}}
<script>
(function () {
  'use strict';

  // ── DOM references ────────────────────────────────────────────────────────
  const toggleBtn  = document.getElementById('cb-toggle-btn');
  const closeBtn   = document.getElementById('cb-close-btn');
  const chatWindow = document.getElementById('cb-window');
  const messages   = document.getElementById('cb-messages');
  const input      = document.getElementById('cb-input');
  const sendBtn    = document.getElementById('cb-send-btn');
  const badge      = document.getElementById('cb-badge');
  const iconChat   = toggleBtn.querySelector('.icon-chat');
  const iconClose  = toggleBtn.querySelector('.icon-close');

  // ── State ─────────────────────────────────────────────────────────────────
  let isOpen      = false;
  let isWaiting   = false;   // đang chờ phản hồi API
  let unreadCount = 0;

  // ── Helpers ───────────────────────────────────────────────────────────────
  const getTime = () =>
    new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });

  const scrollToBottom = () =>
    messages.scrollTo({ top: messages.scrollHeight, behavior: 'smooth' });

  /** Lấy CSRF token từ thẻ meta trong <head> */
  const getCsrfToken = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  };

  // ── Render welcome time ───────────────────────────────────────────────────
  const welcomeTime = document.getElementById('cb-welcome-time');
  if (welcomeTime) welcomeTime.textContent = getTime();

  // ── Toggle window ─────────────────────────────────────────────────────────
  function openChat() {
    isOpen = true;
    chatWindow.classList.add('open');
    chatWindow.setAttribute('aria-hidden', 'false');
    iconChat.style.display  = 'none';
    iconClose.style.display = 'block';
    // Reset unread badge
    unreadCount = 0;
    badge.classList.remove('show');
    // Focus input sau khi animation xong
    setTimeout(() => input.focus(), 300);
  }

  function closeChat() {
    isOpen = false;
    chatWindow.classList.remove('open');
    chatWindow.setAttribute('aria-hidden', 'true');
    iconChat.style.display  = 'block';
    iconClose.style.display = 'none';
  }

  toggleBtn.addEventListener('click', () => isOpen ? closeChat() : openChat());
  closeBtn.addEventListener('click', closeChat);

  // Đóng khi click ngoài vùng chat
  document.addEventListener('click', (e) => {
    if (isOpen &&
        !chatWindow.contains(e.target) &&
        !toggleBtn.contains(e.target)) {
      closeChat();
    }
  });

  // ── Auto-resize textarea ──────────────────────────────────────────────────
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 90) + 'px';
  });

  // ── Append bubble ─────────────────────────────────────────────────────────
  function appendBubble(text, role) {
    const wrap = document.createElement('div');
    wrap.className = `cb-bubble-wrap ${role}`;

    let botIconHtml = '';
    if (role === 'bot') {
      botIconHtml = `
        <div class="cb-bot-icon">
          <svg width="14" height="14" fill="none" viewBox="0 0 24 24">
            <circle cx="12" cy="8" r="4" fill="#fff"/>
            <path fill="rgba(255,255,255,.8)" d="M4 20c0-4 3.58-7 8-7s8 3 8 7H4Z"/>
          </svg>
        </div>`;
    }

    // Escape HTML để tránh XSS, sau đó chuyển \n thành <br>
    const safe = text
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\n/g, '<br>');

    wrap.innerHTML = `
      ${botIconHtml}
      <div>
        <div class="cb-bubble ${role}">${safe}</div>
        <div class="cb-time">${getTime()}</div>
      </div>`;

    messages.appendChild(wrap);
    scrollToBottom();
    return wrap;
  }

  // ── Typing indicator ──────────────────────────────────────────────────────
  function appendTypingIndicator() {
    const wrap = document.createElement('div');
    wrap.className = 'cb-bubble-wrap bot';
    wrap.id = 'cb-typing';
    wrap.innerHTML = `
      <div class="cb-bot-icon">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24">
          <circle cx="12" cy="8" r="4" fill="#fff"/>
          <path fill="rgba(255,255,255,.8)" d="M4 20c0-4 3.58-7 8-7s8 3 8 7H4Z"/>
        </svg>
      </div>
      <div class="cb-bubble bot" style="padding:0">
        <div class="cb-typing-dots">
          <span></span><span></span><span></span>
        </div>
      </div>`;
    messages.appendChild(wrap);
    scrollToBottom();
    return wrap;
  }

  function removeTypingIndicator() {
    const el = document.getElementById('cb-typing');
    if (el) el.remove();
  }

  // ── Set loading state ─────────────────────────────────────────────────────
  function setWaiting(state) {
    isWaiting = state;
    sendBtn.disabled = state;
    input.disabled   = state;
  }

  // ── Send message ──────────────────────────────────────────────────────────
  async function sendMessage() {
    const text = input.value.trim();
    if (!text || isWaiting) return;

    // 1. Append user bubble
    appendBubble(text, 'user');

    // 2. Clear input
    input.value = '';
    input.style.height = 'auto';

    // 3. Typing indicator
    setWaiting(true);
    appendTypingIndicator();

    // 4. Fetch API
    try {
      const response = await fetch('/api/chatbot/send-message', {
        method: 'POST',
        headers: {
          'Content-Type':  'application/json',
          'Accept':        'application/json',
          'X-CSRF-TOKEN':  getCsrfToken(),
        },
        body: JSON.stringify({ message: text }),
      });

      const data = await response.json();

      removeTypingIndicator();

      if (data.status === 'success' && data.reply) {
        appendBubble(data.reply, 'bot');

        // Nếu cửa sổ đang đóng → tăng badge
        if (!isOpen) {
          unreadCount++;
          badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
          badge.classList.add('show');
        }
      } else {
        const errMsg = data.message || 'Có lỗi xảy ra. Vui lòng thử lại!';
        appendBubble('⚠️ ' + errMsg, 'bot');
      }
    } catch (err) {
      removeTypingIndicator();
      appendBubble('⚠️ Không thể kết nối đến máy chủ. Vui lòng kiểm tra mạng và thử lại.', 'bot');
      console.error('[Chatbot] Fetch error:', err);
    } finally {
      setWaiting(false);
      input.focus();
    }
  }

  // ── Event listeners ───────────────────────────────────────────────────────
  sendBtn.addEventListener('click', sendMessage);

  input.addEventListener('keydown', (e) => {
    // Enter gửi, Shift+Enter xuống dòng
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  });

  // ── Show badge sau 3s nếu chat chưa mở (first-visit nudge) ───────────────
  setTimeout(() => {
    if (!isOpen) {
      badge.textContent = '1';
      badge.classList.add('show');
      unreadCount = 1;
    }
  }, 3000);

})();
</script>
