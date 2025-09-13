<?php
declare(strict_types=1);

/* ============================================================
   EXTRACT API BATCH - Only unprocessed, filtered by pdf_files.subject, process and update pdf_keywords.sum_pt
   ============================================================ */

mb_internal_encoding('UTF-8');
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
set_time_limit(360);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$OPENAI_API_KEY = envv('OPENAI_API_KEY', '');
$OPENAI_MODEL   = envv('OPENAI_MODEL', 'gpt-4o-mini');
$PDFTOPPM_BIN   = envv('PDFTOPPM_BIN', '/usr/bin/pdftoppm');
if (!is_executable($PDFTOPPM_BIN) && is_executable('/usr/local/bin/pdftoppm')) {
    $PDFTOPPM_BIN = '/usr/local/bin/pdftoppm';
}
$PDF_RENDER_DPI = (int)envv('PDF_RENDER_DPI', '110');
$PDF_MAX_PAGES  = (int)envv('PDF_MAX_PAGES', '8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action === 'select_company' && isset($_POST['company'])) {
    $companyKey = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$_POST['company']);
    try {
        select_company($companyKey);
        unset($_SESSION['batch_amount'], $_SESSION['batch_files'], $_SESSION['batch_confirmed'], $_SESSION['batch_result']);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'extract_api_batch.php', '?'));
        exit;
    } catch (Throwable $e) {
        $companySelectError = $e->getMessage();
    }
}
if ($action === 'change_company') {
    unset($_SESSION['batch_amount'], $_SESSION['batch_files'], $_SESSION['batch_confirmed'], $_SESSION['batch_result']);
    unset($_SESSION['company'], $_SESSION['company_key'], $_SESSION['company_db'], $_SESSION['company_dir']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'extract_api_batch.php', '?'));
    exit;
}

$company = current_company();
if (!$company) {
    $companies = load_companies();
    echo "<!doctype html><html lang='pt'><head><meta charset='utf-8'><title>Selecionar Empresa</title>
    <style>
      body{font-family:system-ui,Arial,sans-serif;max-width:820px;margin:2rem auto;padding:0 1rem}
      form{margin-top:1rem}
      select,button{font-size:1rem;padding:.55rem .9rem}
      .box{border:1px solid #d0d7de;padding:1rem;border-radius:10px;background:#fafafa}
      .err{color:#b91c1c;margin-top:1rem}
    </style></head><body>";
    echo "<h2>Selecione a Empresa</h2>";
    if (isset($companySelectError)) echo "<div class='err'>".h($companySelectError)."</div>";
    if (!$companies) {
        echo "<p>Nenhuma empresa configurada.</p>";
    } else {
        echo "<form method='post' class='box'>
                <input type='hidden' name='action' value='select_company'>
                <label>Empresa:
                    <select name='company' required>
                        <option value=''>-- selecione --</option>";
        foreach ($companies as $code => $info) {
            echo "<option value='".h($code)."'>".h($code)."</option>";
        }
        echo "      </select>
                </label>
                <button type='submit'>Entrar</button>
              </form>";
    }
    echo "</body></html>";
    exit;
}

try {
    $db = db_company();
    $db->set_charset('utf8mb4');
} catch (Throwable $e) {
    echo "<!doctype html><meta charset='utf-8'><p><strong>Erro de conexão ao banco:</strong> ".h($e->getMessage())."</p>";
    exit;
}

$paths = company_paths();
$pdfDir = $paths['pdf_files_copies'] ?? (($company['dir'] ?? '') ? rtrim((string)$company['dir'], "/\\") . '/pdf_files_copies' : '');
$pdfDir = rtrim((string)$pdfDir, "/\\");

function fetch_pdf_blob(mysqli $db, ?string $file_name): ?string {
    if ($file_name !== null && $file_name !== '') {
        if ($st = $db->prepare("SELECT file_data FROM pdf_files WHERE file_name = ? LIMIT 1")) {
            $st->bind_param('s', $file_name);
            if ($st->execute()) {
                $st->store_result();
                $st->bind_result($blob);
                if ($st->fetch()) {
                    $data = $blob;
                    $st->close();
                    return (is_string($data) && $data !== '') ? $data : null;
                }
            }
            $st->close();
        }
    }
    return null;
}
function ensure_pdf_on_disk(mysqli $db, string $file_name, string $pdf_dir, array &$errors): string {
    $base = rtrim($pdf_dir, "/\\");
    $path = $base . DIRECTORY_SEPARATOR . $file_name;
    if ($base === '' || !is_dir($base)) {
        $errors[] = "Diretório de PDFs não configurado/encontrado: " . h($pdf_dir);
        return $path;
    }
    if (is_readable($path)) return $path;

    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $data = fetch_pdf_blob($db, $file_name);
    if (!is_string($data) || $data === '') {
        $errors[] = "Falha ao obter BLOB (file_name=" . h($file_name) . ").";
        return $path;
    }
    if (@file_put_contents($path, $data) === false) {
        $errors[] = "Falha ao gravar PDF em: " . h($path) . " (permissões?).";
        return $path;
    }
    if (!is_readable($path)) $errors[] = "Arquivo gravado mas não legível: " . h($path);
    return $path;
}
function render_pdf_to_images(string $pdf_path, string $PDFTOPPM_BIN, int $PDF_RENDER_DPI, int $PDF_MAX_PAGES, array &$errors): array {
    $images = [];
    $tmp_pdf = null;
    $tmp_dir = '';
    if (!is_readable($pdf_path)) { $errors[] = "PDF não encontrado: " . h($pdf_path); return [$images, $tmp_pdf, $tmp_dir]; }
    $pdf_bytes = @file_get_contents($pdf_path);
    if ($pdf_bytes === false || $pdf_bytes === '') { $errors[] = "Falha ao ler PDF: " . h($pdf_path); return [$images, $tmp_pdf, $tmp_dir]; }
    $tmp_pdf = tempnam(sys_get_temp_dir(), 'pdf_');
    @file_put_contents($tmp_pdf, $pdf_bytes);
    if (!is_executable($PDFTOPPM_BIN)) {
        $errors[] = "pdftoppm não encontrado em " . h($PDFTOPPM_BIN) . ". Instale poppler-utils.";
        return [$images, $tmp_pdf, $tmp_dir];
    }
    $tmp_dir = sys_get_temp_dir() . '/pdfimg_' . bin2hex(random_bytes(4));
    @mkdir($tmp_dir, 0700);
    $prefix = $tmp_dir . '/page';
    $cmd = escapeshellcmd($PDFTOPPM_BIN) . ' -jpeg -r ' . (int)$PDF_RENDER_DPI . ' ' . escapeshellarg($tmp_pdf) . ' ' . escapeshellarg($prefix);
    $desc = [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']];
    $proc = @proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        $errors[] = "Falha ao iniciar pdftoppm.";
        return [$images, $tmp_pdf, $tmp_dir];
    }
    if (isset($pipes[0])) fclose($pipes[0]);
    $stdout = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    if (isset($pipes[1])) fclose($pipes[1]);
    $stderr = isset($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[2])) fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0) $errors[] = "pdftoppm falhou (código $code): " . h($stderr !== '' ? $stderr : $stdout);
    $images = glob($prefix . '-*.jpg') ?: [];
    natsort($images);
    $images = array_values($images);
    if ($PDF_MAX_PAGES > 0 && count($images) > $PDF_MAX_PAGES) $images = array_slice($images, 0, $PDF_MAX_PAGES);
    if (!$images) $errors[] = "Nenhuma página renderizada.";
    return [$images, $tmp_pdf, $tmp_dir];
}
function call_openai_images(string $OPENAI_API_KEY, string $OPENAI_MODEL, array $images, array &$errors): string {
    if (!$OPENAI_API_KEY) { $errors[] = "OPENAI_API_KEY ausente."; return ''; }
    if (!function_exists('curl_init')) { $errors[] = "Extensão cURL ausente."; return ''; }
    if (empty($images)) { $errors[] = "Sem imagens para enviar."; return ''; }
    if (!empty($errors)) return '';
    $prompt = "Leia as imagens (páginas do PDF, em português) e retorne apenas:\n".
              "1) Nomes após 'senhor:' ou 'senhora:'\n".
              "2) n.referencia\n3) data\n4) talhão\n5) área (m²)\n6) bairro\n7) destino\n8) taxas/impostos (a., b., c., ...)\n".
              "Escreva a resposta em texto simples. Se um item não existir, use '—'.";
    $parts = [["type" => "text", "text" => $prompt]];
    foreach ($images as $img) {
        $b = @file_get_contents($img);
        if ($b === false || $b === '') continue;
        $parts[] = [
            "type" => "image_url",
            "image_url" => [
                "url" => "data:image/jpeg;base64," . base64_encode($b),
                "detail" => "high"
            ]
        ];
    }
    if (count($parts) <= 1) { $errors[] = "Falha ao preparar imagens."; return ''; }
    $body = [
        "model" => $OPENAI_MODEL,
        "messages" => [
            ["role"=>"system","content"=>"Você lê imagens de documentos e extrai somente os itens solicitados."],
            ["role"=>"user","content"=>$parts]
        ],
        "temperature" => 0.2,
        "max_tokens" => 700
    ];
    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $OPENAI_API_KEY,
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 240,
    ]);
    $resp = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) { $errors[] = "Erro cURL: " . h($curl_error); return ''; }
    $data = json_decode($resp, true);
    if ($http !== 200) {
        $msg = $data['error']['message'] ?? $resp;
        $errors[] = "HTTP $http: " . h(substr((string)$msg, 0, 1000));
        return '';
    }
    $result_text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
    if ($result_text === '') { $errors[] = "Resposta vazia da API."; return ''; }
    return $result_text;
}
function update_sum_pt(mysqli $db, string $file_name, string $result_text, array &$errors): bool {
    if ($result_text === '') return false;
    $sql = "UPDATE pdf_keywords SET sum_pt = ? WHERE file_name = ?";
    $st = $db->prepare($sql);
    if (!$st) { $errors[] = "Falha ao preparar atualização: " . h($db->error); return false; }
    $st->bind_param('ss', $result_text, $file_name);
    $ok = $st->execute();
    if (!$ok) { $errors[] = "Falha ao atualizar sum_pt: " . h($st->error); }
    $st->close();
    return $ok;
}

/* --- Batch Amount Selection --- */
if (!isset($_SESSION['batch_amount']) && !isset($_POST['amount'])) {
    echo "<!doctype html><html lang='pt'><head><meta charset='utf-8'><title>Batch Extraction - Quantidade</title>
    <style>
      body{font-family:system-ui,Arial,sans-serif;max-width:600px;margin:2rem auto;padding:0 1rem}
      label{display:block;margin-bottom:1rem}
      input[type=number]{font-size:1.2rem;padding:0.4rem 0.8rem;width:6em}
      button{font-size:1rem;padding:0.6rem 1.4rem}
      .box{border:1px solid #d0d7de;padding:1.2rem 1.5rem;border-radius:10px;background:#fafafa}
      .hdr{margin-bottom:1.5rem;font-size:1.1em}
    </style></head><body>";
    echo "<div class='hdr'>
            <span>Empresa: <strong>".h($company['key'] ?? $company['db'] ?? '')."</strong> | DB: <code>".h($company['db'] ?? '')."</code></span>
            <form method='post' style='display:inline'><button name='action' value='change_company'>Trocar Empresa</button></form>
          </div>";
    echo "<h2>Processamento em Lote</h2>";
    echo "<form method='post' class='box'>
            <label>Quantos arquivos devem ser processados nesta rodada?<br>
              <input type='number' name='amount' min='1' max='1000' value='10' required>
            </label>
            <button type='submit'>Selecionar arquivos</button>
          </form>";
    echo "</body></html>";
    exit;
}
if (isset($_POST['amount']) && is_numeric($_POST['amount'])) {
    $_SESSION['batch_amount'] = max(1, min((int)$_POST['amount'], 1000));
    unset($_SESSION['batch_files'], $_SESSION['batch_confirmed'], $_SESSION['batch_result']);
    header("Location: " . strtok($_SERVER['REQUEST_URI'] ?? 'extract_api_batch.php', '?'));
    exit;
}

$batch_amount = $_SESSION['batch_amount'] ?? 10;

// Select files from pdf_files where subject LIKE '%_page_1.pdf' AND corresponding pdf_keywords.sum_pt IS NULL or ''
if (!isset($_SESSION['batch_files'])) {
    $files = [];
    $sql = "SELECT f.file_name
            FROM pdf_files f
            INNER JOIN pdf_keywords k ON f.file_name = k.file_name
            WHERE f.subject LIKE ? AND (k.sum_pt IS NULL OR k.sum_pt = '')
            ORDER BY f.file_name
            LIMIT ?";
    if ($st = $db->prepare($sql)) {
        $like = "%_page_1.pdf";
        $st->bind_param('si', $like, $batch_amount);
        if ($st->execute()) {
            $st->bind_result($file_name);
            while ($st->fetch()) {
                $files[] = $file_name;
            }
        }
        $st->close();
    }
    $_SESSION['batch_files'] = $files;
    unset($_SESSION['batch_confirmed'], $_SESSION['batch_result']);
}

$files = $_SESSION['batch_files'] ?? [];
if (!$files) {
    echo "<!doctype html><html lang='pt'><head><meta charset='utf-8'><title>Batch Extraction - Nenhum arquivo</title>
    <style>body{font-family:system-ui,Arial,sans-serif;max-width:600px;margin:2rem auto;padding:0 1rem}</style></head><body>
    <h2>Nenhum arquivo encontrado para processar.</h2>
    <form method='post'><button name='action' value='change_company'>Trocar Empresa</button></form>
    </body></html>";
    unset($_SESSION['batch_files'], $_SESSION['batch_confirmed'], $_SESSION['batch_result']);
    exit;
}

if (!isset($_SESSION['batch_confirmed']) && !isset($_POST['confirm_batch'])) {
    echo "<!doctype html><html lang='pt'><head><meta charset='utf-8'><title>Batch Extraction - Confirmação</title>
    <style>
      body{font-family:system-ui,Arial,sans-serif;max-width:700px;margin:2rem auto;padding:0 1rem}
      ul{margin:1.5em 0 2em 1.5em}
      code{background:#f6f8fa;padding:2px 8px;border-radius:6px}
      .box{border:1px solid #d0d7de;padding:1.2rem 1.5rem;border-radius:10px;background:#fafafa}
      .hdr{margin-bottom:1.5rem;font-size:1.1em}
    </style></head><body>";
    echo "<div class='hdr'>
            <span>Empresa: <strong>".h($company['key'] ?? $company['db'] ?? '')."</strong> | DB: <code>".h($company['db'] ?? '')."</code></span>
            <form method='post' style='display:inline'><button name='action' value='change_company'>Trocar Empresa</button></form>
          </div>";
    echo "<h2>Arquivos selecionados</h2>";
    echo "<p>Os seguintes arquivos serão processados e atualizados:</p><ul>";
    foreach ($files as $i => $f) {
        echo "<li><strong>".($i+1).".</strong> <code>".h($f)."</code></li>";
    }
    echo "</ul>";
    echo "<form method='post' class='box'>
            <button name='confirm_batch' value='1'>Processar estes arquivos</button>
          </form>";
    echo "</body></html>";
    exit;
}
if (isset($_POST['confirm_batch'])) {
    $_SESSION['batch_confirmed'] = 1;
    unset($_SESSION['batch_result']);
    header("Location: " . strtok($_SERVER['REQUEST_URI'] ?? 'extract_api_batch.php', '?'));
    exit;
}

if (!isset($_SESSION['batch_result'])) {
    $results = [];
    foreach ($files as $file_name) {
        $file_errors = [];
        $pdf_path = ensure_pdf_on_disk($db, $file_name, $pdfDir, $file_errors);
        if (!is_readable($pdf_path)) {
            $results[] = [
                'file_name' => $file_name,
                'status' => 'erro',
                'message' => "PDF não disponível em disco.",
                'errors' => $file_errors
            ];
            continue;
        }
        [$images, $tmp_pdf, $tmp_dir] = render_pdf_to_images($pdf_path, $PDFTOPPM_BIN, $PDF_RENDER_DPI, $PDF_MAX_PAGES, $file_errors);
        $result_text = '';
        if (empty($file_errors)) {
            $result_text = call_openai_images($OPENAI_API_KEY, $OPENAI_MODEL, $images, $file_errors);
        }
        if (!empty($images)) { foreach ($images as $p) @unlink($p); }
        if (!empty($tmp_dir) && is_dir($tmp_dir)) { @rmdir($tmp_dir); }
        if (!empty($tmp_pdf)) { @unlink($tmp_pdf); }
        if ($result_text !== '') {
            $ok = update_sum_pt($db, $file_name, $result_text, $file_errors);
            $results[] = [
                'file_name' => $file_name,
                'status' => $ok ? 'ok' : 'erro',
                'message' => $ok ? "Atualizado com sucesso." : "Erro ao atualizar sum_pt.",
                'errors' => $file_errors
            ];
        } else {
            $results[] = [
                'file_name' => $file_name,
                'status' => 'erro',
                'message' => "Falha ao obter resposta do OpenAI.",
                'errors' => $file_errors
            ];
        }
    }
    $_SESSION['batch_result'] = $results;
    unset($_SESSION['batch_files'], $_SESSION['batch_confirmed']);
    header("Location: " . strtok($_SERVER['REQUEST_URI'] ?? 'extract_api_batch.php', '?'));
    exit;
}

$results = $_SESSION['batch_result'] ?? [];
echo "<!doctype html><html lang='pt'><head><meta charset='utf-8'><title>Batch Extraction - Relatório</title>
<style>
  body{font-family:system-ui,Arial,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem}
  table{border-collapse:collapse;width:100%;margin:1.5em 0}
  th,td{border:1px solid #e5e7eb;padding:.5em 1em}
  th{background:#eef6ff}
  tr.ok td{background:#eafbe7}
  tr.erro td{background:#faeaea}
  code{background:#f6f8fa;padding:2px 7px;border-radius:6px}
  .hdr{margin-bottom:1.2rem;font-size:1.1em}
  .btnrow{margin:2em 0}
</style></head><body>";
echo "<div class='hdr'>
        <span>Empresa: <strong>".h($company['key'] ?? $company['db'] ?? '')."</strong> | DB: <code>".h($company['db'] ?? '')."</code></span>
        <form method='post' style='display:inline'><button name='action' value='change_company'>Trocar Empresa</button></form>
      </div>";
echo "<h2>Relatório de Processamento em Lote</h2>";
echo "<p>Veja abaixo o resultado da atualização dos arquivos em <code>pdf_keywords.sum_pt</code>:</p>";
echo "<table><tr><th>#</th><th>Arquivo (file_name)</th><th>Status</th><th>Mensagem</th></tr>";
foreach ($results as $i => $r) {
    echo "<tr class='".h($r['status'])."'><td>".($i+1)."</td><td><code>".h($r['file_name'])."</code></td><td>".h(strtoupper($r['status']))."</td><td>".h($r['message']);
    if (!empty($r['errors'])) {
        echo "<ul style='margin:0.2em 0 0.2em 1.2em'>";
        foreach ($r['errors'] as $err) echo "<li>".h($err)."</li>";
        echo "</ul>";
    }
    echo "</td></tr>";
}
echo "</table>";
echo "<div class='btnrow'><form method='post'><button name='action' value='change_company'>Processar outra empresa</button></form>
<form method='post' style='display:inline'><button name='amount' value='".h($batch_amount)."'>Processar mais arquivos</button></form>
</div>";
echo "</body></html>";
exit;
?>