<?php
$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'site-config.json';
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads';

$defaultConfig = [
  'whatsappNumber' => '5511930756726',
  'carousel' => [
    ['src' => './assets/hero-servicos-principal-web.jpg', 'alt' => 'Ambiente de trabalho TiT Resolve'],
    ['src' => './assets/hero-casal-fisionomia-web.jpg', 'alt' => 'Casal TiT Resolve em desenho'],
    ['src' => './assets/casal-trabalhando-desenho-web.jpg', 'alt' => 'Servicos residenciais em desenho'],
    ['src' => './assets/casal-trabalhando-web.jpg', 'alt' => 'Casal trabalhando'],
    ['src' => './assets/hero-personagens-oficiais-web.jpg', 'alt' => 'Personagens TiT Resolve'],
    ['src' => './assets/tit-resolve-casal-web.jpg', 'alt' => 'TiT Resolve casal de aluguel'],
    ['src' => './assets/boneca-obra-web.jpg', 'alt' => 'Profissional de obra'],
    ['src' => './assets/boneca-andando-web.jpg', 'alt' => 'Profissional em movimento'],
    ['src' => './assets/hero-casal-final-web.jpg', 'alt' => 'Casal TiT Resolve'],
    ['src' => './assets/hero-servicos-principal-original.png', 'alt' => 'Divulgacao dos servicos TiT Resolve'],
  ],
  'promo' => [
    'enabled' => true,
    'eyebrow' => 'Promocao especial',
    'title' => 'Pintura completa em apartamento COHAB',
    'price' => 'R$ 799,00',
    'text' => 'Transforme seu apartamento com uma pintura completa, organizada e feita com cuidado em cada detalhe. Ideal para renovar o ambiente com acabamento limpo, paredes bem preparadas e atendimento direto da TiT Resolve.',
    'note' => 'Na cor branca. Oferta sujeita a avaliacao do estado das paredes, metragem do imovel e necessidade de reparos extras.',
    'buttonText' => 'Quero essa promocao',
    'whatsappText' => 'Ola! Quero saber sobre a promocao de pintura em apartamento COHAB por R$ 799,00.',
  ],
];

function h($value) {
  return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function loadConfig($path, $fallback) {
  if (!is_file($path)) return $fallback;
  $decoded = json_decode(file_get_contents($path), true);
  if (!is_array($decoded)) return $fallback;
  return array_replace_recursive($fallback, $decoded);
}

$config = loadConfig($configPath, $defaultConfig);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
  }

  $config['whatsappNumber'] = preg_replace('/\D+/', '', $_POST['whatsappNumber'] ?? $config['whatsappNumber']);
  $config['promo'] = [
    'enabled' => isset($_POST['promoEnabled']),
    'eyebrow' => trim($_POST['promoEyebrow'] ?? ''),
    'title' => trim($_POST['promoTitle'] ?? ''),
    'price' => trim($_POST['promoPrice'] ?? ''),
    'text' => trim($_POST['promoText'] ?? ''),
    'note' => trim($_POST['promoNote'] ?? ''),
    'buttonText' => trim($_POST['promoButtonText'] ?? ''),
    'whatsappText' => trim($_POST['promoWhatsappText'] ?? ''),
  ];

  for ($i = 0; $i < 10; $i++) {
    $src = trim($_POST['carousel'][$i]['src'] ?? ($config['carousel'][$i]['src'] ?? ''));
    $alt = trim($_POST['carousel'][$i]['alt'] ?? ($config['carousel'][$i]['alt'] ?? 'Servico TiT Resolve'));

    if (isset($_FILES['carouselFile']['tmp_name'][$i]) && is_uploaded_file($_FILES['carouselFile']['tmp_name'][$i])) {
      $originalName = $_FILES['carouselFile']['name'][$i];
      $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
      $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
      if (in_array($extension, $allowed, true)) {
        $fileName = 'carousel-' . ($i + 1) . '-' . time() . '.' . $extension;
        $target = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        if (move_uploaded_file($_FILES['carouselFile']['tmp_name'][$i], $target)) {
          $src = './assets/uploads/' . $fileName;
        }
      }
    }

    $config['carousel'][$i] = ['src' => $src, 'alt' => $alt];
  }

  file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  $message = 'Alteracoes salvas com sucesso.';
}
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin | TiT Resolve</title>
    <style>
      :root { --ink:#082437; --teal:#249c9a; --paper:#fbf7ef; --line:rgba(8,36,55,.14); --radius:8px; }
      * { box-sizing: border-box; }
      body { margin:0; padding:28px; color:var(--ink); background:var(--paper); font-family:Arial,sans-serif; }
      header, form { width:min(1100px,100%); margin:0 auto; }
      header { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-bottom:24px; }
      a { color:var(--teal); font-weight:800; text-decoration:none; }
      h1, h2, h3 { margin:0 0 12px; }
      section { margin-bottom:22px; padding:22px; background:white; border:1px solid var(--line); border-radius:var(--radius); }
      label { display:grid; gap:7px; margin-bottom:14px; font-weight:800; }
      input, textarea { width:100%; min-height:42px; padding:10px 12px; border:1px solid var(--line); border-radius:var(--radius); font:inherit; }
      textarea { min-height:110px; resize:vertical; }
      .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:16px; }
      .photo-card { padding:16px; border:1px solid var(--line); border-radius:var(--radius); background:#fbfbfb; }
      .photo-card img { width:100%; height:170px; object-fit:cover; border-radius:var(--radius); background:#ddd; }
      .actions { position:sticky; bottom:0; display:flex; justify-content:flex-end; gap:12px; padding:16px 0; background:linear-gradient(180deg,rgba(251,247,239,0),var(--paper) 32%); }
      button { min-height:46px; padding:0 22px; color:white; background:var(--ink); border:0; border-radius:var(--radius); cursor:pointer; font-weight:900; }
      .message { width:min(1100px,100%); margin:0 auto 18px; padding:14px 16px; color:white; background:var(--teal); border-radius:var(--radius); font-weight:800; }
      @media (max-width:720px) { body { padding:16px; } header, .grid { display:block; } }
    </style>
  </head>
  <body>
    <header>
      <div>
        <h1>Central de administra&ccedil;&atilde;o</h1>
        <p>Troque ou envie as imagens do carrossel de trabalhos e edite o banner promocional.</p>
      </div>
      <a href="./">Voltar ao site</a>
    </header>

    <?php if ($message): ?>
      <div class="message"><?= h($message) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
      <section>
        <h2>Contato</h2>
        <label>
          N&uacute;mero do WhatsApp com DDI e DDD
          <input name="whatsappNumber" value="<?= h($config['whatsappNumber']) ?>" />
        </label>
      </section>

      <section>
        <h2>Banner promocional de entrada</h2>
        <label>
          <span><input type="checkbox" name="promoEnabled" <?= $config['promo']['enabled'] ? 'checked' : '' ?> /> Mostrar banner quando entrar no site</span>
        </label>
        <div class="grid">
          <label>Chamada pequena <input name="promoEyebrow" value="<?= h($config['promo']['eyebrow']) ?>" /></label>
          <label>T&iacute;tulo <input name="promoTitle" value="<?= h($config['promo']['title']) ?>" /></label>
          <label>Pre&ccedil;o <input name="promoPrice" value="<?= h($config['promo']['price']) ?>" /></label>
          <label>Texto do bot&atilde;o <input name="promoButtonText" value="<?= h($config['promo']['buttonText']) ?>" /></label>
        </div>
        <label>Texto principal <textarea name="promoText"><?= h($config['promo']['text']) ?></textarea></label>
        <label>Texto pequeno <textarea name="promoNote"><?= h($config['promo']['note']) ?></textarea></label>
        <label>Mensagem enviada ao WhatsApp <textarea name="promoWhatsappText"><?= h($config['promo']['whatsappText']) ?></textarea></label>
      </section>

      <section>
        <h2>Fotos do carrossel de trabalhos</h2>
        <div class="grid">
          <?php for ($i = 0; $i < 10; $i++): $item = $config['carousel'][$i]; ?>
            <div class="photo-card">
              <h3>Imagem <?= $i + 1 ?></h3>
              <img src="<?= h($item['src']) ?>" alt="" />
              <label>Arquivo novo <input type="file" name="carouselFile[<?= $i ?>]" accept="image/*" /></label>
              <label>Caminho atual <input name="carousel[<?= $i ?>][src]" value="<?= h($item['src']) ?>" /></label>
              <label>Descri&ccedil;&atilde;o <input name="carousel[<?= $i ?>][alt]" value="<?= h($item['alt']) ?>" /></label>
            </div>
          <?php endfor; ?>
        </div>
      </section>

      <div class="actions">
        <button type="submit">Salvar altera&ccedil;&otilde;es</button>
      </div>
    </form>
  </body>
</html>
