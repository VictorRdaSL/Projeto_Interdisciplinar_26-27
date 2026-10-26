/* ==========================================================================
   WareSys · Design system · waresys.js
   --------------------------------------------------------------------------
   JavaScript vanilla, sem dependências, só para o que o CSS não resolve:
     1. Ícones Lucide (se o script do Lucide estiver na página)
     2. Menu lateral no celular (melhora o :target que já funciona sem JS)
     3. Modal com foco preso, Esc e devolução do foco
     4. Confirmação de formulário por modal (substitui o confirm() nativo)
     5. Toasts que somem sozinhos
     6. Contador de rodadas: gera os blocos se vierem vazios e mostra o
        número real quando passa de R4+
   Inclua no fim do <body> ou com defer:
     <script src="design-system/js/lucide.min.js"></script>
     <script src="design-system/js/waresys.js" defer></script>
   Tudo funciona com HTML gerado pelo PHP: sem SPA, sem fetch.
   ========================================================================== */
(function () {
  'use strict';

  /* ------------------------------------------------------------------------
     Utilitários
     ------------------------------------------------------------------------ */
  var SELETOR_FOCAVEL = [
    'a[href]', 'area[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]:not([tabindex="-1"])'
  ].join(',');

  function focaveis(raiz) {
    return Array.prototype.filter.call(raiz.querySelectorAll(SELETOR_FOCAVEL), function (el) {
      return el.offsetParent !== null || el === document.activeElement;
    });
  }

  function cada(seletor, fn, raiz) {
    Array.prototype.forEach.call((raiz || document).querySelectorAll(seletor), fn);
  }

  /* ------------------------------------------------------------------------
     1. Ícones Lucide — traço 1,5 (manual pág. 11)
     ------------------------------------------------------------------------ */
  function iniciarIcones() {
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
      window.lucide.createIcons({ attrs: { 'stroke-width': 1.5, 'aria-hidden': 'true' } });
    }
  }

  /* ------------------------------------------------------------------------
     2. Menu lateral no celular
     HTML: botão/link com data-ws-menu-abrir (href="#ws-sidebar"),
           botão com data-ws-menu-fechar dentro da sidebar,
           .ws-app__veu com data-ws-menu-fechar.
     ------------------------------------------------------------------------ */
  function iniciarMenu() {
    var app = document.querySelector('.ws-app');
    var sidebar = document.getElementById('ws-sidebar');
    if (!app || !sidebar) return;

    var abridores = document.querySelectorAll('[data-ws-menu-abrir]');
    var ultimoAbridor = null;

    function abrir(evento) {
      if (evento) evento.preventDefault();
      ultimoAbridor = evento ? evento.currentTarget : null;
      app.classList.add('is-menu-aberto');
      cada('[data-ws-menu-abrir]', function (b) { b.setAttribute('aria-expanded', 'true'); });
      var primeiro = sidebar.querySelector('.ws-nav__item') || focaveis(sidebar)[0];
      if (primeiro) primeiro.focus();
    }

    function fechar(evento) {
      if (evento) evento.preventDefault();
      if (!app.classList.contains('is-menu-aberto') && location.hash !== '#ws-sidebar') return;
      app.classList.remove('is-menu-aberto');
      cada('[data-ws-menu-abrir]', function (b) { b.setAttribute('aria-expanded', 'false'); });
      // Se a gaveta foi aberta pelo :target (antes do JS), limpa o hash
      if (location.hash === '#ws-sidebar' && history.replaceState) {
        history.replaceState(null, '', location.pathname + location.search);
      }
      if (ultimoAbridor) ultimoAbridor.focus();
    }

    Array.prototype.forEach.call(abridores, function (b) {
      b.setAttribute('aria-controls', 'ws-sidebar');
      b.setAttribute('aria-expanded', 'false');
      b.addEventListener('click', abrir);
    });
    cada('[data-ws-menu-fechar]', function (b) { b.addEventListener('click', fechar); });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && app.classList.contains('is-menu-aberto')) fechar();
    });

    // Ao voltar para o desktop, garante a gaveta fechada
    var mq = window.matchMedia('(min-width: 1024px)');
    var aoMudar = function () { if (mq.matches) app.classList.remove('is-menu-aberto'); };
    if (mq.addEventListener) mq.addEventListener('change', aoMudar);
  }

  /* ------------------------------------------------------------------------
     3. Modal (<dialog class="ws-modal">)
     Abrir:  <button data-ws-modal-abrir="id-do-dialog">
     Fechar: <button data-ws-modal-fechar> dentro do dialog, Esc, ou clique no véu
     ------------------------------------------------------------------------ */
  var origemDoFoco = new WeakMap();

  function abrirModal(modal, origem) {
    if (typeof modal === 'string') modal = document.getElementById(modal);
    if (!modal) return;
    origemDoFoco.set(modal, origem || document.activeElement);

    if (typeof modal.showModal === 'function') {
      if (!modal.open) modal.showModal();
    } else {
      // Navegador sem <dialog>: mostra e marca para o CSS
      modal.setAttribute('open', '');
    }

    // Foco no primeiro campo; se não houver, no botão de confirmar; se não, no 1º focável
    var alvo = modal.querySelector('[autofocus]') ||
               modal.querySelector('input, select, textarea') ||
               modal.querySelector('[data-ws-confirmar-ok]') ||
               focaveis(modal)[0];
    if (alvo) alvo.focus();
  }

  function fecharModal(modal, valor) {
    if (typeof modal === 'string') modal = document.getElementById(modal);
    if (!modal) return;
    if (typeof modal.close === 'function' && modal.open) {
      modal.close(valor || '');
    } else {
      modal.removeAttribute('open');
      aoFecharModal(modal);
    }
  }

  function aoFecharModal(modal) {
    var origem = origemDoFoco.get(modal);
    if (origem && typeof origem.focus === 'function') origem.focus();
    origemDoFoco.delete(modal);
  }

  function iniciarModais() {
    cada('[data-ws-modal-abrir]', function (botao) {
      botao.addEventListener('click', function (e) {
        e.preventDefault();
        abrirModal(botao.getAttribute('data-ws-modal-abrir'), botao);
      });
    });

    cada('dialog.ws-modal', function (modal) {
      modal.addEventListener('close', function () { aoFecharModal(modal); });

      cada('[data-ws-modal-fechar]', function (b) {
        b.addEventListener('click', function (e) { e.preventDefault(); fecharModal(modal, 'cancelar'); });
      }, modal);

      // Clique no véu (fora da caixa) fecha
      modal.addEventListener('click', function (e) {
        if (e.target === modal) fecharModal(modal, 'cancelar');
      });

      // Foco preso: Tab e Shift+Tab circulam só dentro do modal
      modal.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab') return;
        var lista = focaveis(modal);
        if (!lista.length) return;
        var primeiro = lista[0];
        var ultimo = lista[lista.length - 1];
        if (e.shiftKey && document.activeElement === primeiro) {
          e.preventDefault();
          ultimo.focus();
        } else if (!e.shiftKey && document.activeElement === ultimo) {
          e.preventDefault();
          primeiro.focus();
        }
      });

      // Navegadores sem <dialog>: Esc manual
      if (typeof modal.showModal !== 'function') {
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && modal.hasAttribute('open')) fecharModal(modal, 'cancelar');
        });
      }
    });
  }

  /* ------------------------------------------------------------------------
     4. Confirmação de formulário
     <form method="post" data-ws-confirmar="modal-rodada">…</form>
     No modal: <button type="button" data-ws-confirmar-ok>Registrar rodada</button>
     Sem JS o formulário é enviado direto — a validação continua no PHP.
     ------------------------------------------------------------------------ */
  function iniciarConfirmacoes() {
    cada('form[data-ws-confirmar]', function (form) {
      form.addEventListener('submit', function (e) {
        if (form.dataset.wsConfirmado === '1') return; // já confirmado: segue
        var modal = document.getElementById(form.getAttribute('data-ws-confirmar'));
        if (!modal) return;
        e.preventDefault();
        modal._wsFormulario = form;
        modal._wsBotaoEnvio = e.submitter || null;
        abrirModal(modal, e.submitter || form.querySelector('[type="submit"]'));
      });
    });

    cada('dialog.ws-modal [data-ws-confirmar-ok]', function (ok) {
      ok.addEventListener('click', function () {
        var modal = ok.closest('dialog');
        var form = modal && modal._wsFormulario;
        if (!form) { fecharModal(modal, 'ok'); return; }
        form.dataset.wsConfirmado = '1';
        origemDoFoco.delete(modal); // a página vai recarregar; não devolve foco
        fecharModal(modal, 'ok');
        ok.disabled = true;
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit(modal._wsBotaoEnvio || undefined);
        } else {
          form.submit();
        }
      });
    });
  }

  /* ------------------------------------------------------------------------
     5. Toasts
     Toasts escritos pelo PHP: [data-ws-toast] (opcional data-ws-duracao="6000",
     ou data-ws-duracao="0" para não sumir). Criados por JS: WareSys.toast().
     ------------------------------------------------------------------------ */
  var ICONES_TOAST = { ok: 'check', limite: 'triangle-alert', cobrado: 'circle-alert', info: 'info' };

  function container() {
    var c = document.querySelector('.ws-toasts');
    if (!c) {
      c = document.createElement('div');
      c.className = 'ws-toasts';
      c.setAttribute('aria-live', 'polite');
      document.body.appendChild(c);
    }
    return c;
  }

  function removerToast(toast) {
    if (!toast || toast._wsSaindo) return;
    toast._wsSaindo = true;
    toast.classList.add('is-saindo');
    setTimeout(function () { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 220);
  }

  function programarToast(toast) {
    var duracao = parseInt(toast.getAttribute('data-ws-duracao') || '6000', 10);
    var fechar = toast.querySelector('[data-ws-toast-fechar]');
    if (fechar) fechar.addEventListener('click', function () { removerToast(toast); });
    if (!duracao) return;

    var restante = duracao;
    var inicio;
    var timer;
    function iniciar() { inicio = Date.now(); timer = setTimeout(function () { removerToast(toast); }, restante); }
    function pausar() { clearTimeout(timer); restante -= Date.now() - inicio; }

    // Pausa enquanto a pessoa lê (mouse em cima ou foco dentro) — WCAG 2.2.1
    toast.addEventListener('mouseenter', pausar);
    toast.addEventListener('mouseleave', iniciar);
    toast.addEventListener('focusin', pausar);
    toast.addEventListener('focusout', iniciar);
    iniciar();
  }

  function toast(mensagem, tipo, opcoes) {
    tipo = tipo || 'info';
    opcoes = opcoes || {};
    var el = document.createElement('div');
    el.className = 'ws-toast ws-alerta ws-alerta--' + tipo;
    el.setAttribute('role', tipo === 'cobrado' ? 'alert' : 'status');
    el.setAttribute('data-ws-duracao', String(opcoes.duracao != null ? opcoes.duracao : 6000));

    var icone = document.createElement('i');
    icone.className = 'ws-alerta__icone';
    icone.setAttribute('data-lucide', ICONES_TOAST[tipo] || 'info');

    var corpo = document.createElement('div');
    corpo.className = 'ws-alerta__corpo';
    if (opcoes.titulo) {
      var t = document.createElement('p');
      t.className = 'ws-alerta__titulo';
      t.textContent = opcoes.titulo;
      corpo.appendChild(t);
    }
    var p = document.createElement('p');
    p.textContent = mensagem; // textContent: nunca interpreta HTML
    corpo.appendChild(p);

    var fechar = document.createElement('button');
    fechar.type = 'button';
    fechar.className = 'ws-fechar';
    fechar.setAttribute('aria-label', 'Fechar aviso');
    fechar.setAttribute('data-ws-toast-fechar', '');
    fechar.innerHTML = '<i data-lucide="x" class="ws-icone--peq"></i>';

    el.appendChild(icone);
    el.appendChild(corpo);
    el.appendChild(fechar);
    container().appendChild(el);
    iniciarIcones();
    programarToast(el);
    return el;
  }

  function iniciarToasts() {
    cada('[data-ws-toast]', programarToast);
  }

  /* ------------------------------------------------------------------------
     6. Contador de rodadas
     ------------------------------------------------------------------------ */
  function iniciarRodadas() {
    cada('.ws-rodadas[data-limite]', function (contador) {
      var limite = parseInt(contador.getAttribute('data-limite'), 10);
      var atualTxt = contador.getAttribute('data-atual');
      var atual = parseInt(atualTxt, 10);
      if (!limite || limite < 1) return;

      var lista = contador.querySelector('.ws-rodadas__blocos');
      if (!lista) {
        lista = document.createElement('ol');
        lista.className = 'ws-rodadas__blocos';
        contador.appendChild(lista);
      }

      // Gera R1..R(limite) e R(limite+1)+ se o PHP mandou a lista vazia
      if (!lista.children.length) {
        for (var n = 1; n <= limite + 1; n++) {
          var li = document.createElement('li');
          li.className = 'ws-rodada';
          li.textContent = 'R' + n + (n > limite ? '+' : '');
          lista.appendChild(li);
        }
      }

      // Rodada além do último bloco: mostra o número real (R5, R6…)
      var ultimo = lista.lastElementChild;
      if (!isNaN(atual) && ultimo && atual > lista.children.length) {
        ultimo.textContent = 'R' + atual;
      }

      // Texto para leitor de tela, se o PHP não escreveu um
      if (!contador.hasAttribute('aria-label') && !isNaN(atual)) {
        var etapa = contador.querySelector('.ws-rodadas__etapa');
        var nome = etapa ? etapa.textContent.trim() + ': ' : '';
        var situacao = atual > limite ? 'acima do limite, cobrada' : (atual === limite ? 'no limite' : 'no prazo');
        contador.setAttribute('role', 'group');
        contador.setAttribute('aria-label', nome + 'rodada ' + atual + ' de ' + limite + ' incluídas, ' + situacao);
      }
    });
  }

  /* ------------------------------------------------------------------------
     Início
     ------------------------------------------------------------------------ */
  function iniciar() {
    iniciarRodadas();
    iniciarMenu();
    iniciarModais();
    iniciarConfirmacoes();
    iniciarToasts();
    iniciarIcones();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }

  // API pública mínima, para usar em scripts das telas
  window.WareSys = {
    toast: toast,
    abrirModal: abrirModal,
    fecharModal: fecharModal,
    iniciarIcones: iniciarIcones
  };
})();
