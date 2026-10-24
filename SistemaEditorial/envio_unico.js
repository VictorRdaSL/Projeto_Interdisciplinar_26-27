// Impede o duplo envio de formulários POST: enquanto o primeiro envio está em andamento, os
// seguintes são cancelados. O botão não é desabilitado de verdade (um botão desabilitado não
// manda o seu name/value), só ganha a classe .enviando (aparência de "aguarde").
(function () {
    var TEMPO_MAXIMO_MS = 10000; // se a navegação travar/for interrompida, libera o formulário

    function liberar(form) {
        delete form.dataset.enviando;
        form.querySelectorAll('.enviando').forEach(function (b) { b.classList.remove('enviando'); });
    }

    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!(form instanceof HTMLFormElement) || (form.method || '').toLowerCase() !== 'post') return;
        // Um onsubmit="return confirm(...)" cancelado já chamou preventDefault: não é um envio.
        if (ev.defaultPrevented) return;
        if (form.dataset.enviando === '1') {
            ev.preventDefault();
            return;
        }
        form.dataset.enviando = '1';
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (b) { b.classList.add('enviando'); });
        setTimeout(function () { liberar(form); }, TEMPO_MAXIMO_MS);
    });

    // Voltar pelo botão do navegador pode restaurar a página já com o formulário "travado".
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('form[data-enviando]').forEach(liberar);
    });
})();
