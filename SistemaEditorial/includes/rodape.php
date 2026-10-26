<?php
/**
 * Rodapé das telas novas (design system WareSys): contêiner de toasts (com o flash da sessão), rodapé do app,
 * fechamento de <main> e do app, Lucide local e waresys.js. Par do includes/cabecalho.php, que abre tudo isso.
 *
 * O flash ($_SESSION['flash'] = ['type' => ..., 'message' => ...]) é lido e apagado aqui, então as telas
 * migradas não precisam mais ler nem exibir o flash por conta própria:
 *   sucesso → ws-alerta--ok,      ícone check,        some em 6 s
 *   erro    → ws-alerta--cobrado, ícone circle-alert, role="alert", não some sozinho (data-ws-duracao="0")
 *   info (e qualquer outro tipo, inclusive vazio) → ws-alerta sem modificador, ícone info, some em 6 s
 * A mensagem sempre passa por htmlspecialchars e todo toast tem o botão de fechar.
 */
require_once __DIR__ . '/formatacao.php';
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/database.php';
}

$ws_base = BASE_URL;

$ws_flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$ws_toast = null;
if (is_array($ws_flash) && isset($ws_flash['message']) && is_scalar($ws_flash['message']) && trim((string)$ws_flash['message']) !== '') {
    $ws_tipo = isset($ws_flash['type']) && is_string($ws_flash['type']) ? $ws_flash['type'] : '';
    $ws_toast = match ($ws_tipo) {
        'sucesso' => ['classe' => 'ws-alerta ws-alerta--ok',      'icone' => 'check',        'papel' => 'status', 'duracao' => '6000'],
        'erro'    => ['classe' => 'ws-alerta ws-alerta--cobrado', 'icone' => 'circle-alert', 'papel' => 'alert',  'duracao' => '0'],
        default   => ['classe' => 'ws-alerta',                    'icone' => 'info',         'papel' => 'status', 'duracao' => '6000'],
    };
    $ws_toast['mensagem'] = trim((string)$ws_flash['message']);
}
?>

      <div class="ws-toasts" aria-live="polite">
<?php if ($ws_toast !== null): ?>
        <div class="ws-toast <?= escaparHtml($ws_toast['classe']) ?>" role="<?= escaparHtml($ws_toast['papel']) ?>" data-ws-toast data-ws-duracao="<?= escaparHtml($ws_toast['duracao']) ?>">
          <i class="ws-alerta__icone" data-lucide="<?= escaparHtml($ws_toast['icone']) ?>"></i>
          <div class="ws-alerta__corpo">
            <p><?= escaparHtml($ws_toast['mensagem']) ?></p>
          </div>
          <button class="ws-fechar" type="button" data-ws-toast-fechar aria-label="Fechar aviso"><i data-lucide="x" class="ws-icone--peq"></i></button>
        </div>
<?php endif; ?>
      </div>

      <footer class="ws-rodape">
        <p>WareSys · controle editorial para editoras · Projeto acadêmico FATEC</p>
      </footer>
    </main>
  </div>

  <!-- Ícones Lucide (local; traço 1,5 aplicado pelo waresys.js) + comportamentos -->
  <script src="<?= escaparHtml($ws_base) ?>/assets/js/lucide.min.js"></script>
  <script src="<?= escaparHtml($ws_base) ?>/assets/js/waresys.js" defer></script>
</body>
</html>
