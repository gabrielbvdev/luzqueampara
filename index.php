<?php
declare(strict_types=1);

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function db(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'luz_que_ampara';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '123mudar';
    if ($name === '') {
        throw new RuntimeException('DB_NAME is not configured');
    }
    if ($user === '') {
        throw new RuntimeException('DB_USER is not configured');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function normalizedText(string $value): string
{
    $value = preg_replace('/^\s+|\s+$/u', '', $value) ?? trim($value);
    return $value;
}

function textLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    return preg_match_all('/./us', $value, $matches) ?: 0;
}

function foldedText(string $value): string
{
    if (class_exists(Normalizer::class)) {
        $value = Normalizer::normalize($value, Normalizer::FORM_D) ?: $value;
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
    } elseif (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    } else {
        $value = strtr($value, [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','ç'=>'c','Ç'=>'C',
        ]);
    }
    return strtolower($value);
}

function tributeInitials(string $name): string
{
    $skip = ['da', 'de', 'do', 'das', 'dos', 'e'];
    $parts = preg_split('/\s+/u', normalizedText($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = [];
    foreach ($parts as $part) {
        $folded = foldedText($part);
        if (in_array($folded, $skip, true)) {
            continue;
        }
        $first = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        $initials[] = (function_exists('mb_strtoupper') ? mb_strtoupper($first, 'UTF-8') : strtoupper($first)) . '.';
    }
    return implode(' ', $initials);
}

function containsBlockedWord(string $value): bool
{
    $words = [
        'porra', 'caralho', 'merda', 'puta', 'puto', 'foda', 'foder',
        'fodase', 'cacete', 'buceta', 'viado', 'arrombado', 'babaca',
        'otario', 'vagabundo', 'desgraca', 'cu',
    ];
    $folded = foldedText($value);
    foreach ($words as $word) {
        if (preg_match('/(^|[^a-z])' . preg_quote($word, '/') . '([^a-z]|$)/', $folded)) {
            return true;
        }
    }
    return false;
}

function secureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function createTribute(): never
{
    secureSession();
    header('Cache-Control: no-store, private');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, ['error' => 'Método não permitido.']);
    }
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $expected = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://')
            . ($_SERVER['HTTP_HOST'] ?? '');
        if (!hash_equals($expected, $origin)) {
            jsonResponse(403, ['error' => 'Não foi possível validar a origem do envio.']);
        }
    }
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!isset($_SESSION['csrf']) || !is_string($csrf) || !hash_equals($_SESSION['csrf'], $csrf)) {
        jsonResponse(403, ['error' => 'A sessão do formulário expirou. Atualize a página e tente novamente.']);
    }

    $body = file_get_contents('php://input');
    if ($body === false || strlen($body) > 16384) {
        jsonResponse(413, ['error' => 'O envio excede o tamanho permitido.']);
    }
    $input = json_decode($body, true);
    if (!is_array($input)) {
        jsonResponse(400, ['error' => 'Não foi possível ler os dados do formulário.']);
    }
    if (!empty($input['website'])) {
        jsonResponse(400, ['error' => 'Não foi possível validar o envio.']);
    }

    $id = is_string($input['id'] ?? null) ? $input['id'] : '';
    $name = normalizedText(is_string($input['name'] ?? null) ? $input['name'] : '');
    $author = normalizedText(is_string($input['authorName'] ?? null) ? $input['authorName'] : '');
    $email = normalizedText(is_string($input['email'] ?? null) ? $input['email'] : '');
    $phone = normalizedText(is_string($input['phone'] ?? null) ? $input['phone'] : '');
    $message = normalizedText(is_string($input['message'] ?? null) ? $input['message'] : '');
    $consent = ($input['consent'] ?? false) === true;
    $mode = $input['displayMode'] ?? 'hidden';

    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
        jsonResponse(422, ['error' => 'Identificador da homenagem inválido. Atualize a página e tente novamente.']);
    }
    if ($name === '' || textLength($name) > 80 || $author === '' || textLength($author) > 60
        || $email === '' || textLength($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || !preg_match('/^\(\d{2}\) (?:\d{4}|\d{5})-\d{4}$/D', $phone)
        || textLength($message) > 140) {
        jsonResponse(422, ['error' => 'Confira os campos: nome de quem você lembra e seu nome são obrigatórios (até 80 e 60 caracteres); informe um e-mail válido e um telefone brasileiro com DDD; mensagem até 140 caracteres.']);
    }
    if (!in_array($mode, ['hidden', 'full', 'initials'], true)
        || ($consent && $mode === 'hidden')
        || (!$consent && $mode !== 'hidden')) {
        jsonResponse(422, ['error' => 'A opção de exibição não é válida.']);
    }
    if (containsBlockedWord($name) || containsBlockedWord($author) || containsBlockedWord($message)) {
        jsonResponse(422, ['error' => 'Revise os nomes e a mensagem antes de enviar.']);
    }

    $now = time();
    $attempts = array_values(array_filter(
        $_SESSION['tribute_attempts'] ?? [],
        static fn ($timestamp) => is_int($timestamp) && $timestamp > $now - 60
    ));
    try {
        $pdo = db();
        $existing = $pdo->prepare('SELECT uuid, data_criacao FROM homenagens_velario WHERE uuid = :uuid LIMIT 1');
        $existing->execute(['uuid' => $id]);
        $row = $existing->fetch();
        if ($row) {
            jsonResponse(200, [
                'id' => $row['uuid'],
                'createdAt' => (new DateTimeImmutable($row['data_criacao'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            ]);
        }
        if (count($attempts) >= 5) {
            $_SESSION['tribute_attempts'] = $attempts;
            jsonResponse(429, ['error' => 'Muitas tentativas em pouco tempo. Aguarde um minuto e tente novamente.']);
        }

        $publicName = $consent
            ? ($mode === 'initials' ? tributeInitials($name) : $name)
            : null;
        $publicAuthor = $consent && $author !== ''
            ? ($mode === 'initials' ? tributeInitials($author) : $author)
            : null;
        $insert = $pdo->prepare(
            'INSERT INTO homenagens_velario (uuid, nome_homenageado, nome_usuario, email, telefone, nome_homenageado_publico, nome_usuario_publico, mensagem, forma_exibicao, consentimento, versao_consentimento, data_consentimento)
             VALUES (:uuid, :nome_homenageado, :nome_usuario, :email, :telefone, :nome_homenageado_publico, :nome_usuario_publico, :mensagem, :forma_exibicao, :consentimento, :versao_consentimento, :data_consentimento)'
        );
        $insert->execute([
            'uuid' => $id,
            'nome_homenageado' => $name,
            'nome_usuario' => $author !== '' ? $author : null,
            'email' => $email,
            'telefone' => $phone,
            'nome_homenageado_publico' => $publicName,
            'nome_usuario_publico' => $publicAuthor,
            'mensagem' => $message,
            'forma_exibicao' => ['hidden' => 'oculto', 'full' => 'completo', 'initials' => 'iniciais'][$mode],
            'consentimento' => $consent ? 1 : 0,
            'versao_consentimento' => $consent ? '2026-privacy-v1' : null,
            'data_consentimento' => $consent ? gmdate('Y-m-d H:i:s') : null,
        ]);
        $_SESSION['tribute_attempts'] = [...$attempts, $now];

        $created = $pdo->prepare('SELECT data_criacao FROM homenagens_velario WHERE uuid = :uuid LIMIT 1');
        $created->execute(['uuid' => $id]);
        $createdAt = $created->fetchColumn();
        if (!is_string($createdAt)) {
            throw new RuntimeException('Inserted tribute could not be read');
        }
        jsonResponse(201, [
            'id' => $id,
            'createdAt' => (new DateTimeImmutable($createdAt, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        ]);
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? null) === 1062) {
            try {
                $existing = db()->prepare('SELECT uuid, data_criacao FROM homenagens_velario WHERE uuid = :uuid LIMIT 1');
                $existing->execute(['uuid' => $id]);
                $row = $existing->fetch();
                if ($row) {
                    jsonResponse(200, [
                        'id' => $row['uuid'],
                        'createdAt' => (new DateTimeImmutable($row['data_criacao'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
                    ]);
                }
            } catch (Throwable $retryError) {
                error_log('[Luz que Ampara] Could not resolve an idempotent tribute retry.');
            }
        }
        $sqlState = $error->errorInfo[0] ?? $error->getCode();
        $driverCode = $error->errorInfo[1] ?? 'unknown';
        error_log("[Luz que Ampara] Tribute database write failed (SQLSTATE {$sqlState}; driver code {$driverCode}).");
        jsonResponse(503, ['error' => 'Não foi possível registrar sua luz agora. Tente novamente em instantes.']);
    } catch (Throwable $error) {
        if ($error->getMessage() === 'DB_USER is not configured'
            || $error->getMessage() === 'DB_NAME is not configured') {
            error_log('[Luz que Ampara] Database configuration is incomplete: ' . $error->getMessage() . '.');
        } else {
            error_log('[Luz que Ampara] Tribute request failed (' . get_class($error) . ').');
        }
        jsonResponse(503, ['error' => 'Não foi possível registrar sua luz agora. Tente novamente em instantes.']);
    }
}

function publicMural(): never
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(405, ['error' => 'Método não permitido.']);
    }

    $query = $_GET['q'] ?? '';
    if (!is_string($query)) {
        jsonResponse(400, ['error' => 'Busca inválida.']);
    }
    $query = normalizedText($query);
    $query = function_exists('mb_substr')
        ? mb_substr($query, 0, 100, 'UTF-8')
        : substr($query, 0, 100);

    $limit = filter_var($_GET['limit'] ?? '60', FILTER_VALIDATE_INT);
    if ($limit === false || $limit < 1 || $limit > 100) {
        jsonResponse(400, ['error' => 'Quantidade por página inválida.']);
    }

    try {
        $pdo = db();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM homenagens_velario')->fetchColumn();
        $where = "forma_exibicao <> 'oculto' AND consentimento = 1 AND nome_homenageado_publico IS NOT NULL";
        $params = [];
        if ($query !== '') {
            $where .= " AND (nome_homenageado_publico LIKE :name ESCAPE '!' OR nome_usuario_publico LIKE :author ESCAPE '!')";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
            $params['name'] = $like;
            $params['author'] = $like;
        }

        $count = $pdo->prepare("SELECT COUNT(*) FROM homenagens_velario WHERE {$where}");
        $count->execute($params);
        $filteredTotal = (int)$count->fetchColumn();

        $cursorDate = $_GET['before'] ?? null;
        $cursorId = $_GET['id'] ?? null;
        if (($cursorDate === null) !== ($cursorId === null)) {
            jsonResponse(400, ['error' => 'Cursor de paginação inválido.']);
        }
        if ($cursorDate !== null) {
            if (!is_string($cursorDate) || !is_string($cursorId)
                || !preg_match('/^[0-9a-f-]{36}$/i', $cursorId)) {
                jsonResponse(400, ['error' => 'Cursor de paginação inválido.']);
            }
            try {
                $cursor = new DateTimeImmutable($cursorDate);
            } catch (Throwable $error) {
                jsonResponse(400, ['error' => 'Cursor de paginação inválido.']);
            }
            $dateValue = $cursor->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
            $where .= ' AND (data_criacao < :cursor_date_a OR (data_criacao = :cursor_date_b AND uuid < :cursor_uuid))';
            $params['cursor_date_a'] = $dateValue;
            $params['cursor_date_b'] = $dateValue;
            $params['cursor_uuid'] = $cursorId;
        }

        $statement = $pdo->prepare(
            "SELECT uuid, nome_homenageado_publico, nome_usuario_publico, mensagem, forma_exibicao, data_criacao
             FROM homenagens_velario
             WHERE {$where}
             ORDER BY data_criacao DESC, uuid DESC
             LIMIT " . ($limit + 1)
        );
        $statement->execute($params);
        $rows = $statement->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(static function (array $row): array {
            $created = new DateTimeImmutable($row['data_criacao'], new DateTimeZone('UTC'));
            return [
                'id' => $row['uuid'],
                'name' => $row['nome_homenageado_publico'],
                'authorName' => $row['nome_usuario_publico'] !== '' ? $row['nome_usuario_publico'] : null,
                'message' => $row['mensagem'],
                'displayMode' => ['oculto' => 'hidden', 'completo' => 'full', 'iniciais' => 'initials'][$row['forma_exibicao']],
                'createdAt' => $created->format('Y-m-d\TH:i:s.v\Z'),
            ];
        }, $rows);
        $last = $rows ? $rows[count($rows) - 1] : null;
        jsonResponse(200, [
            'total' => $total,
            'filteredTotal' => $filteredTotal,
            'items' => $items,
            'hasMore' => $hasMore,
            'nextCursor' => $hasMore && $last ? [
                'before' => (new DateTimeImmutable($last['data_criacao'], new DateTimeZone('UTC')))
                    ->format('Y-m-d\TH:i:s.v\Z'),
                'id' => $last['id'],
            ] : null,
        ]);
    } catch (Throwable $error) {
        error_log('[Luz que Ampara] Public mural request failed.');
        jsonResponse(503, ['error' => 'Não foi possível atualizar o mural agora. Tente novamente em instantes.']);
    }
}

secureSession();
if (($_GET['action'] ?? '') === 'create') {
    createTribute();
}
if (($_GET['action'] ?? '') === 'list') {
    publicMural();
}
header('Cache-Control: no-store, private');
if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = htmlspecialchars($_SESSION['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#E4E6E1">
  <meta name="csrf-token" content="<?= $csrfToken ?>">
  <title>Acenda uma luz · Luz que Ampara</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Alegreya+Sans:wght@300;400;500;700;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{--cream:#E4E6E1;--green:#182D26;--deep:#072723;--gold:#AA965A;--muted:rgba(24,45,38,.74);--pad:clamp(20px,5vw,72px)}
    *,*::before,*::after{box-sizing:border-box}
    html{scroll-behavior:smooth;background:var(--cream);-webkit-text-size-adjust:100%}
    body{margin:0;color:var(--green);background:var(--cream);font:17px/1.6 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
    a{color:inherit}button,input,textarea{font:inherit}button,a{-webkit-tap-highlight-color:transparent}
    :focus-visible{outline:3px solid currentColor;outline-offset:3px;border-radius:5px}
    .wrap{width:min(100%,1160px);margin:auto;padding-inline:var(--pad)}
    .top{padding:calc(20px + env(safe-area-inset-top,0px)) 0 0;color:var(--muted);font-size:.95rem}
    .hero{padding:clamp(35px,7vw,86px) 0 clamp(56px,9vw,112px)}
    .hero-grid{display:grid;grid-template-columns:1fr;align-items:center;gap:36px}
    @media(min-width:880px){.hero-grid{grid-template-columns:1.15fr .85fr;gap:64px}}
    h1,h2,h3,p{margin:0}h1,h2,.counter-number,.brand{font-family:"Alegreya Sans","Trebuchet MS",sans-serif}
    h1{font-size:clamp(2.7rem,8.2vw,5.4rem);line-height:1.02;letter-spacing:-.02em}
    h1 span{display:block}h1 .light{font-weight:300}h1 .bold{font-weight:800}
    .lead{max-width:52ch;margin-top:20px;color:var(--muted);font-size:clamp(1.05rem,1.6vw,1.2rem)}
    .hero-actions,.actions{display:flex;flex-wrap:wrap;align-items:center;gap:16px 24px;margin-top:30px}
    .counter{display:flex;align-items:baseline;gap:10px}.counter-number{font-size:2.8rem;font-weight:800;line-height:1;font-variant-numeric:tabular-nums}
    .btn{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:0 24px;border:1px solid transparent;border-radius:12px;background:var(--green);color:var(--cream);font-weight:600;text-decoration:none;cursor:pointer;transition:transform .2s,background .2s}
    .btn:hover{background:#0e211a}.btn:active{transform:translateY(1px)}.btn:disabled{opacity:.58;cursor:wait}
    .on-dark .btn-main{background:var(--cream);color:var(--green)}
    .on-dark .btn-main:hover{background:#fff}
    .on-dark .btn-line{border:1.5px solid rgba(228,230,225,.55);background:transparent;color:var(--cream)}
    .on-dark .btn-line:hover{border-color:var(--cream);background:rgba(228,230,225,.08)}
    .symbol{display:block;width:min(360px,70vw);margin:auto;overflow:visible}
    .symbol .house{fill:none;stroke:var(--green);stroke-width:13.5;stroke-linecap:round;stroke-linejoin:round}
    .symbol .flame{fill:none;stroke:var(--gold);stroke-width:13.5;stroke-linecap:round;stroke-linejoin:round}
    .symbol path{stroke-dasharray:1;stroke-dashoffset:0}
    body:not(.reduce) .symbol .house{animation:draw 2.4s cubic-bezier(.5,0,.2,1) both}
    body:not(.reduce) .symbol .flame{animation:draw 1.4s 1.9s cubic-bezier(.5,0,.2,1) both}
    @keyframes draw{from{stroke-dashoffset:1}to{stroke-dashoffset:0}}
    .form-section{padding:clamp(55px,8vw,100px) 0;border-top:1px solid rgba(24,45,38,.16)}
    .form-grid{display:grid;grid-template-columns:1fr;gap:34px}
    @media(min-width:880px){.form-grid{grid-template-columns:.8fr 1.2fr;gap:72px}}
    h2{font-weight:300;font-size:clamp(2rem,4.6vw,3.3rem);line-height:1.08}h2 strong{font-weight:800}
    form{display:grid;gap:20px}.field label{display:block;margin-bottom:7px;font-weight:600}
    input[type=text],input[type=email],input[type=tel],textarea{width:100%;min-height:52px;padding:13px 15px;border:1.5px solid rgba(24,45,38,.5);border-radius:10px;background:#EEF0EB;color:var(--green)}
    textarea{min-height:110px;resize:vertical}input:focus,textarea:focus{outline:3px solid var(--green);outline-offset:2px}
    .hint,.privacy{color:var(--muted);font-size:.92rem}.counter-row{display:flex;justify-content:space-between;gap:12px}
    .check{display:flex;gap:12px;align-items:flex-start;cursor:pointer}.check input{width:22px;height:22px;margin:3px 0 0;accent-color:var(--green);flex:none}
    .radios{display:flex;flex-wrap:wrap;gap:10px 22px;margin:12px 0 0 34px}.radios label{display:flex;align-items:center;gap:8px;cursor:pointer}.radios input{accent-color:var(--green)}
    .radios[hidden], [hidden]{display:none!important}
    .summary{padding:13px 15px;border:1px solid #8A2B1F;border-radius:10px;background:#F2E6E2;color:#6E2017}
    .summary:empty{display:none}.honeypot{position:absolute!important;left:-10000px!important;width:1px!important;height:1px!important;overflow:hidden!important}
    .privacy{margin:0 0 0 34px}.result{padding:64px 0;background:var(--deep);color:var(--cream)}
    .result-inner{display:grid;gap:28px;grid-template-columns:1fr;align-items:center}
    @media(min-width:850px){.result-inner{grid-template-columns:1fr minmax(260px,420px);gap:64px}}
    .result h2{font-weight:800}.result .lead{color:rgba(228,230,225,.76)}
    .result .btn{background:var(--cream);color:var(--green)}.result .btn-outline{background:transparent;color:var(--cream);border-color:rgba(228,230,225,.6)}
    .certificate{width:100%;height:auto;border:1px solid rgba(228,230,225,.25);border-radius:4px;box-shadow:0 16px 50px #0003;background:var(--cream)}
    .footer{padding:26px 0;background:var(--deep);color:rgba(228,230,225,.75);font-size:.9rem}
    .footer a{color:var(--cream)}.reduce-toggle{padding:6px 10px;border:1px solid rgba(228,230,225,.55);border-radius:7px;background:transparent;color:var(--cream);cursor:pointer}.reduce *, .reduce *::before,.reduce *::after{scroll-behavior:auto!important;animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}
    .top p{font-size:.95rem;color:var(--muted)}
    .hero{padding:clamp(28px,6vw,72px) 0 clamp(56px,9vw,120px)}
    .hero .wrap{grid-template-columns:1fr}
    @media(min-width:880px){.hero .wrap{grid-template-columns:1.15fr .85fr;gap:64px}}
    h1{font-family:"Alegreya Sans","Trebuchet MS","Segoe UI",sans-serif;letter-spacing:-.012em}
    h1 .light{font-weight:300}h1 .bold{font-weight:800}
    .hero .lead{margin-top:22px}
    .symbol{width:min(420px,72vw)}
    .form-sec{padding:clamp(56px,8vw,104px) 0;border-top:1px solid rgba(24,45,38,.16)}
    .form-grid .lead{margin-top:18px}
    .field label{font-weight:500;font-size:1rem;margin-bottom:8px}
    .field input[type=text],.field input[type=email],.field input[type=tel],.field textarea{font:400 1.05rem/1.4 Inter,system-ui,sans-serif;min-height:54px}
    .field textarea{min-height:112px}
    .field .hint{margin-top:6px}
    .moment{min-height:380px;display:flex;flex-direction:column;justify-content:flex-end;padding:70px 0 64px;background:var(--green);color:var(--cream)}
    .moment .wait{font-size:1.05rem;color:rgba(228,230,225,.76);max-width:46ch}
    .moment-content[hidden]{display:none}
    .moment h2{font-weight:800}
    .moment p{margin-top:12px;max-width:46ch}
    .moment .actions{margin-top:26px}
    .on-dark{color:var(--cream)}
    .velario{padding:clamp(40px,6vw,80px) 0 clamp(64px,9vw,120px);background:var(--green)}
    .v-head{display:flex;flex-wrap:wrap;gap:20px 48px;align-items:flex-end;justify-content:space-between}
    .v-head .lead{margin-top:14px;color:rgba(228,230,225,.76)}
    .v-head .counter-number{font-size:3.4rem}
    .casa-wrap{margin:clamp(28px,5vw,56px) auto 0;max-width:980px}
    .casa{width:100%;height:auto;display:block}
    .casa .ground{stroke:rgba(228,230,225,.22);stroke-width:2}
    .casa .roof{fill:none;stroke:rgba(228,230,225,.85);stroke-width:5;stroke-linejoin:round;stroke-linecap:round}
    .casa .win{fill:rgba(228,230,225,.05);stroke:rgba(228,230,225,.3);stroke-width:1.5;transition:fill .6s,stroke .6s}
    .casa .win.lit{fill:var(--gold);stroke:var(--gold);filter:drop-shadow(0 0 7px rgba(170,150,90,.85))}
    .casa .win{transform-box:fill-box;transform-origin:center}
    body:not(.reduce) .casa .win.fresh{animation:ignite 1.6s ease-out both}
    @keyframes ignite{0%{fill:rgba(228,230,225,.05);filter:none}35%{fill:#E8D9A2;stroke:#E8D9A2;filter:drop-shadow(0 0 16px rgba(232,217,162,.95));transform:scale(1.14)}100%{fill:var(--gold);transform:scale(1)}}
    .casa text{font-family:"Alegreya Sans","Trebuchet MS",sans-serif;font-size:22px;font-weight:500;fill:rgba(228,230,225,.8);text-anchor:middle}
    .casa-note{min-height:1.5em;margin-top:10px;font-size:.95rem;text-align:center;color:rgba(228,230,225,.76)}
    .varal-head{margin-top:clamp(48px,7vw,88px);display:flex;flex-wrap:wrap;gap:16px 32px;align-items:flex-end;justify-content:space-between}
    .varal-head h3{font-family:"Alegreya Sans","Trebuchet MS",sans-serif;font-weight:700;font-size:1.7rem}
    .search{display:flex;flex-direction:column;gap:6px;min-width:min(100%,300px)}
    .search label{font-size:.95rem;color:rgba(228,230,225,.76)}
    .search input{font:400 1rem Inter,system-ui,sans-serif;color:var(--cream);background:rgba(228,230,225,.08);border:1.5px solid rgba(228,230,225,.5);border-radius:10px;padding:0 14px;min-height:50px}
    .search input::placeholder{color:rgba(228,230,225,.6)}
    .search input:focus{border-color:var(--cream);outline:3px solid var(--cream);outline-offset:2px}
    .varal{position:relative;margin-top:26px;overflow-x:auto;padding:0 0 18px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}
    .track{position:relative;display:flex;gap:18px;min-width:100%;width:max-content;padding:34px 4px 6px}
    .track::before{content:"";position:absolute;left:0;right:0;top:20px;height:2px;background:rgba(228,230,225,.38)}
    .tag{position:relative;flex:none;width:228px;scroll-snap-align:start;background:#223A31;border:1px solid rgba(228,230,225,.2);border-radius:6px 6px 18px 18px;padding:26px 18px 18px;display:flex;flex-direction:column;gap:10px;min-height:196px}
    .tag:nth-child(odd){transform:rotate(-1.1deg)}.tag:nth-child(even){transform:rotate(1.1deg);margin-top:6px}
    .tag::before{content:"";position:absolute;top:-17px;left:50%;width:2px;height:20px;background:rgba(228,230,225,.55);transform:translateX(-50%)}
    .tag::after{content:"";position:absolute;top:2px;left:50%;width:10px;height:10px;border-radius:50%;background:#102520;border:1.5px solid rgba(228,230,225,.6);transform:translateX(-50%)}
    .tag.mine{border-color:var(--gold);box-shadow:0 0 0 1px var(--gold)}
    .tag svg{width:20px;height:28px;flex:none}
    .tag .nm{font-family:"Alegreya Sans","Trebuchet MS",sans-serif;font-weight:700;font-size:1.4rem;line-height:1.15;word-break:break-word}
    .tag .tx{font-size:.95rem;line-height:1.5;color:rgba(228,230,225,.76);word-break:break-word}
    .tag .by{margin-top:auto;font-size:.88rem;color:rgba(228,230,225,.68)}
    .varal-empty{padding:30px 0 10px;color:rgba(228,230,225,.76);max-width:46ch}
    .varal-empty a{color:var(--cream)}
    .load-more{display:block;margin:8px auto 0;min-height:46px;padding:0 18px;border:1.5px solid rgba(228,230,225,.5);border-radius:10px;background:transparent;color:var(--cream);cursor:pointer}
    .load-more[hidden]{display:none}.load-more:disabled{opacity:.6;cursor:wait}
    .cartao{padding:clamp(64px,9vw,120px) 0;background:#102520;color:var(--cream)}
    .cartao-grid{display:grid;gap:44px;grid-template-columns:1fr;align-items:center}
    @media(min-width:880px){.cartao-grid{grid-template-columns:1fr 1fr;gap:80px}}
    .cartao .lead{margin-top:18px;color:rgba(228,230,225,.76)}
    .cartao .actions{display:flex;flex-wrap:wrap;gap:14px;margin-top:28px}
    .cartao .soft{margin-top:14px;font-size:.95rem;color:rgba(228,230,225,.76)}
    .cartao .btn-main{background:var(--cream);color:var(--green)}
    .cartao .btn-line{border-color:rgba(228,230,225,.55);background:transparent;color:var(--cream)}
    .card-view{display:flex;justify-content:center}
    #certificate{width:min(100%,380px);height:auto;aspect-ratio:4/5;border-radius:6px;box-shadow:0 20px 50px rgba(0,0,0,.35);background:var(--cream)}
    .doe{padding:clamp(64px,9vw,120px) 0;background:#0B2622;color:var(--cream)}
    .doe-grid{display:grid;gap:40px;grid-template-columns:1fr}
    @media(min-width:880px){.doe-grid{grid-template-columns:1fr 1fr;gap:80px}}
    .doe .lead{margin-top:20px;color:rgba(228,230,225,.76)}
    .doe .soft{margin-top:14px;font-size:.95rem;color:rgba(228,230,225,.76)}
    .facts{list-style:none;margin:0;padding:0;display:grid;gap:0}
    .facts li{padding:20px 0;border-top:1px solid rgba(228,230,225,.22);font-size:1.05rem}
    .facts li:last-child{border-bottom:1px solid rgba(228,230,225,.22)}
    .facts strong{font-family:"Alegreya Sans","Trebuchet MS",sans-serif;font-weight:700;font-size:1.3rem;display:block;margin-bottom:2px}
    .hoje{padding:clamp(56px,8vw,104px) 0;background:var(--deep);border-top:1px solid rgba(228,230,225,.12);color:var(--cream)}
    .hoje dl{margin:36px 0 0;display:grid;gap:0;grid-template-columns:1fr}
    @media(min-width:760px){.hoje dl{grid-template-columns:1fr 1fr;column-gap:64px}}
    .hoje .it{padding:18px 0;border-top:1px solid rgba(228,230,225,.2)}
    .hoje dt{font-family:"Alegreya Sans","Trebuchet MS",sans-serif;font-weight:700;font-size:1.3rem}
    .hoje dd{margin:2px 0 0;color:rgba(228,230,225,.76)}
    .foot{padding:48px 0 calc(40px + env(safe-area-inset-bottom,0px));background:var(--deep);border-top:1px solid rgba(228,230,225,.12);color:var(--cream)}
    .foot .wrap{display:grid;gap:18px}.foot p{color:rgba(228,230,225,.76);font-size:.98rem}
    .foot .tagline{font-family:"Alegreya Sans","Trebuchet MS",sans-serif;font-weight:700;font-size:1.4rem;color:var(--cream)}
    .foot details{color:rgba(228,230,225,.76);font-size:.95rem;max-width:62ch}
    .foot summary{cursor:pointer;color:var(--cream);font-weight:500}.foot details p{margin-top:10px}
    .foot .tools{display:flex;flex-wrap:wrap;gap:12px}
    .mini{font:500 .95rem Inter,system-ui,sans-serif;color:var(--cream);background:transparent;border:1.5px solid rgba(228,230,225,.5);border-radius:10px;min-height:44px;padding:0 16px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
    .mini[aria-pressed=true]{background:rgba(228,230,225,.14);border-color:var(--cream)}
    #telao{position:fixed;inset:0;z-index:100;background:var(--deep);color:var(--cream);display:grid;grid-template-rows:auto 1fr auto;padding:clamp(20px,3.5vw,56px);overflow:hidden}
    #telao[hidden]{display:none}
    .t-top{display:flex;justify-content:space-between;align-items:flex-start;gap:24px}
    .t-top .counter-number{font-size:clamp(4rem,11vw,9rem)}
    .t-top .counter span{font-size:clamp(1.2rem,2.4vw,2rem)}
    .t-brand{text-align:right;font-family:"Alegreya Sans","Trebuchet MS",sans-serif;font-size:clamp(1.1rem,2vw,1.7rem);color:rgba(228,230,225,.76)}
    .t-brand strong{display:block;color:var(--cream);font-size:1.25em}
    .t-mid{display:flex;align-items:center;justify-content:center;min-height:0}
    .t-mid .casa{max-height:100%;width:min(100%,1200px)}
    .t-bot{min-height:clamp(120px,20vh,210px);display:flex;align-items:flex-end;justify-content:space-between;gap:30px}
    .t-name{max-width:min(100%,1100px);transition:opacity .6s}
    .t-name.out{opacity:0}.t-name .em{font-size:clamp(1rem,1.8vw,1.5rem);color:rgba(228,230,225,.76)}
    .t-name .nm{font-family:"Alegreya Sans","Trebuchet MS",sans-serif;font-weight:800;font-size:clamp(2.2rem,5.6vw,4.8rem);line-height:1.05}
    .t-name .tx{font-size:clamp(1.1rem,2vw,1.7rem);color:rgba(228,230,225,.76);margin-top:6px}
    .t-name .by{font-size:clamp(.95rem,1.5vw,1.2rem);color:rgba(228,230,225,.68);margin-top:6px}
    @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}*,*::before,*::after{animation:none!important;transition:none!important}}
    @media(max-width:520px){body{font-size:16px}.tag{width:206px}.hero-actions>.btn{width:auto}.cartao .actions .btn{width:100%}}
  </style>
</head>
<body>
  <header class="top on-light"><div class="wrap"><p>Campo Santo Familiar &amp; Santa Casa da Bahia</p></div></header>
  <main>
    <section class="hero on-light">
      <div class="wrap hero-grid">
        <div>
          <h1><span class="light">Acenda uma luz</span><span class="bold">por quem você ama.</span></h1>
          <p class="lead">Cada luz acesa neste Finados ampara alguém.</p>
          <div class="hero-actions">
            <a class="btn" href="#acender">Acender minha luz</a>
            <p class="counter" role="status" aria-live="polite"><strong class="counter-number" id="light-count">—</strong><span id="count-label">luzes acesas</span></p>
          </div>
        </div>
        <svg class="symbol" viewBox="0 0 333 325" aria-hidden="true">
          <path class="house" pathLength="1" d="M124.289 297.057L59.0378 248.118C51.8869 242.755 48.3115 235.604 48.3115 226.666V84.7659C48.3115 74.3376 52.8553 66.5722 61.9428 61.4698L152.111 10.8552C161.868 5.38031 171.626 5.38031 181.384 10.8552L271.552 61.4698C280.64 66.5722 285.183 74.3376 285.183 84.7659V226.666C285.183 235.604 281.608 242.755 274.457 248.118L209.206 297.057"/>
          <path class="house" pathLength="1" d="M117.585 311.582C122.054 311.582 124.289 309.348 124.289 304.878V254.04C124.289 249.571 126.523 247.336 130.993 247.336H202.501C206.97 247.336 209.205 249.571 209.205 254.04V304.878C209.205 309.348 211.44 311.582 215.909 311.582H308.088C317.026 311.582 321.496 307.113 321.496 298.174V164.655C321.496 153.481 316.617 145.157 306.859 139.683L183.339 70.5763C172.278 64.3938 161.216 64.3938 150.155 70.5763L26.6349 139.683C16.877 145.157 11.998 153.481 11.998 164.655V298.174C11.998 307.113 16.4673 311.582 25.4059 311.582H117.585Z"/>
          <path class="flame" pathLength="1" d="M166.748 119.961C150.546 142.307 129.876 161.86 129.876 184.207C129.876 206.553 150.546 226.106 166.748 235.604C182.949 226.106 203.619 206.553 203.619 184.207C203.619 161.86 182.949 142.307 166.748 119.961Z"/>
          <path class="flame" pathLength="1" d="M166.746 235.603V311.58"/>
        </svg>
      </div>
    </section>

    <section class="form-sec on-light" id="acender">
      <div class="wrap form-grid">
        <div>
          <h2>Quem você quer <strong>lembrar?</strong></h2>
          <p class="lead">Leva menos de um minuto. Você escolhe como sua homenagem aparece.</p>
        </div>
        <form id="tribute-form" novalidate>
          <div class="summary" id="form-message" role="alert" aria-live="assertive"></div>
          <div class="field">
            <label for="remembered-name">Nome de quem você lembra</label>
            <input id="remembered-name" name="name" type="text" maxlength="80" autocomplete="off" required>
            <p class="hint field-error" id="name-error" hidden></p>
          </div>
          <div class="field">
            <label for="author-name">Seu nome</label>
            <input id="author-name" name="authorName" type="text" maxlength="60" autocomplete="given-name" required>
          </div>
          <div class="field">
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" maxlength="254" autocomplete="email" required>
          </div>
          <div class="field">
            <label for="phone">Telefone</label>
            <input id="phone" name="phone" type="tel" maxlength="15" autocomplete="tel" value="719" required>
          </div>
          <div class="field">
            <label for="message">Mensagem (opcional)</label>
            <textarea id="message" name="message" maxlength="140"></textarea>
            <div class="counter-row hint"><span>Até 140 caracteres.</span><span id="message-count">0/140</span></div>
          </div>
          <div class="field">
            <label class="check" for="consent"><input id="consent" name="consent" type="checkbox"><span>Autorizo exibir os nomes e a mensagem no mural.</span></label>
            <div class="radios" id="display-options" role="radiogroup" aria-label="Como exibir os nomes" hidden>
              <label><input type="radio" name="displayMode" value="full" checked>Nome completo</label>
              <label><input type="radio" name="displayMode" value="initials">Só as iniciais</label>
            </div>
            <p class="hint" style="margin:10px 0 0 34px">Sem autorização, sua luz acende e entra na contagem, mas os nomes e a mensagem não aparecem na tela.</p>
          </div>
          <div class="honeypot" aria-hidden="true"><label for="website">Deixe este campo vazio</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
          <button class="btn" id="submit-button" type="submit">Acender</button>
          <p class="privacy">Seu e-mail e telefone não serão exibidos no mural. Não inclua dados de menores de idade ou informações de saúde na homenagem.</p>
        </form>
      </div>
    </section>

    <section class="velario on-dark" id="velario" aria-labelledby="h-vel">
      <div class="wrap">
        <div class="v-head">
          <div>
            <h2 id="h-vel">O velário</h2>
            <p class="lead">Cada janela que acende é alguém lembrado. A casa cresce até virar um bairro, as três casas de amparo.</p>
          </div>
          <p class="counter" role="status" aria-live="polite"><strong class="counter-number" id="mural-count">—</strong><span id="mural-count-label">luzes acesas</span></p>
        </div>
        <div class="casa-wrap">
          <svg class="casa" id="casa" viewBox="0 0 900 430" role="img" aria-label="Casa aguardando atualização do mural"></svg>
          <p class="casa-note" id="casaNote" aria-live="polite"></p>
        </div>
        <div class="varal-head">
          <h3>Nomes lembrados</h3>
          <div class="search">
            <label for="busca">Buscar um nome</label>
            <input type="search" id="busca" placeholder="Digite um nome" autocomplete="off" maxlength="100">
          </div>
        </div>
        <p class="hint" id="mural-status" role="status" aria-live="polite"></p>
        <div class="varal" id="varal" tabindex="0" role="region" aria-label="Varal de luz; role para o lado para ver mais nomes"><div class="track" id="track"></div></div>
        <button class="load-more" id="load-more" type="button" hidden>Carregar mais homenagens</button>
      </div>
    </section>

    <section class="cartao on-dark" id="cartao" aria-labelledby="h-cartao">
      <div class="wrap cartao-grid">
        <div>
          <h2 id="h-cartao">Seu cartão de <strong>amparo</strong></h2>
          <p class="lead">Um cartão para guardar e compartilhar, com o nome de quem você lembrou. Ele registra a homenagem, não comprova uma doação.</p>
          <div class="actions">
            <button class="btn btn-main" id="share-button" type="button" disabled>Compartilhar</button>
            <button class="btn btn-line" id="download-button" type="button" disabled>Baixar imagem</button>
          </div>
          <p class="soft" id="card-note">Seu cartão aparece aqui depois que você acender uma luz.</p>
        </div>
        <div class="card-view"><canvas id="certificate" width="1080" height="1350" role="img" aria-label="Prévia do Certificado de Amparo digital"></canvas></div>
      </div>
    </section>
  </main>
  <footer class="foot on-dark">
    <div class="wrap">
      <p class="tagline">Luz que Ampara · O cuidado que permanece</p>
      <p>Campo Santo Familiar · Santa Casa da Bahia · Casa Solange Fraga</p>
      <p>#LuzQueAmpara</p>
      <details>
        <summary>Privacidade</summary>
        <p>Os nomes e a mensagem aparecem no mural apenas com sua autorização, por inteiro ou só com as iniciais. Sem autorização, a luz é contada sem exibir nomes ou mensagem. Não inclua dados de menores de idade, informações de saúde ou contatos na homenagem.</p>
      </details>
      <div class="tools"><button class="mini" id="reduce-toggle" type="button" aria-pressed="false" style="display: none;">Reduzir animações</button><a class="mini" id="openTelao" href="#telao" style="display: none;">Ver telão</a></div>
    </div>
  </footer>
  <div id="telao" hidden role="dialog" aria-modal="true" aria-label="Telão do evento">
    <div class="t-top"><p class="counter"><strong class="counter-number" id="screen-count">—</strong><span id="screen-count-label">luzes acesas</span></p><div class="t-brand"><strong>Luz que Ampara</strong>Toda luz ampara alguém.</div></div>
    <div class="t-mid"><svg class="casa" id="casa-telao" viewBox="0 0 900 430" role="img" aria-label="Casa de luzes"></svg></div>
    <div class="t-bot"><div class="t-name" id="screen-name" aria-live="polite"></div><button class="mini" id="closeTelao" type="button">Sair do telão</button></div>
  </div>

  <script>
    (() => {
      "use strict";
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const form = document.getElementById("tribute-form");
      const formMessage = document.getElementById("form-message");
      const consent = document.getElementById("consent");
      const options = document.getElementById("display-options");
      const message = document.getElementById("message");
      const phoneInput = document.getElementById("phone");
      const canvas = document.getElementById("certificate");
      const context = canvas.getContext("2d");
      const savedKey = "lqa-last-tribute";
      const blocked = ["porra","caralho","merda","puta","puto","foda","foder","fodase","cacete","buceta","viado","arrombado","babaca","otario","vagabundo","desgraca","cu"];
      let savedTribute = null;
      let muralTimer = 0;
      const reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)");
      const reduceToggle = document.getElementById("reduce-toggle");
      let reduceActive = reducedMotion.matches;
      function setReducedMotion(value) {
        reduceActive = value;
        document.body.classList.toggle("reduce", value);
        reduceToggle.setAttribute("aria-pressed", String(value));
        reduceToggle.textContent = value ? "Ativar animações" : "Reduzir animações";
      }
      setReducedMotion(reduceActive);
      reduceToggle.addEventListener("click", () => setReducedMotion(!reduceActive));
      reducedMotion.addEventListener?.("change", event => setReducedMotion(event.matches));

      function fold(value) {
        return value.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase("pt-BR");
      }
      function hasProfanity(value) {
        const normalized = fold(value);
        return blocked.some(word => new RegExp("(^|[^a-z])" + word + "([^a-z]|$)", "u").test(normalized));
      }
      function formatBrazilianPhone(value) {
        const digits = value.replace(/\D/g, "").slice(0, 11);
        if (!digits) return "";
        if (digits.length <= 2) return "(" + digits;
        const areaCode = digits.slice(0, 2);
        const subscriber = digits.slice(2);
        const subscriberPrefixLength = digits[2] === "9" || digits.length > 10 ? 5 : 4;
        let formatted = "(" + areaCode + ") " + subscriber.slice(0, subscriberPrefixLength);
        if (subscriber.length > subscriberPrefixLength) {
          formatted += "-" + subscriber.slice(subscriberPrefixLength, 9);
        }
        return formatted;
      }
      function phoneCaretPosition(value, digitCount) {
        if (digitCount === 0) return 0;
        let seen = 0;
        for (let index = 0; index < value.length; index++) {
          if (/\d/.test(value[index]) && ++seen === digitCount) return index + 1;
        }
        return value.length;
      }
      function maskPhone() {
        const cursor = phoneInput.selectionStart ?? phoneInput.value.length;
        const digitsBeforeCursor = phoneInput.value.slice(0, cursor).replace(/\D/g, "").length;
        phoneInput.value = formatBrazilianPhone(phoneInput.value);
        const nextCursor = phoneCaretPosition(phoneInput.value, digitsBeforeCursor);
        phoneInput.setSelectionRange(nextCursor, nextCursor);
      }
      phoneInput.value = formatBrazilianPhone(phoneInput.value);
      function setCount(value) {
        const formatted = Number.isFinite(value) ? value.toLocaleString("pt-BR") : "—";
        const label = value === 1 ? "luz acesa" : "luzes acesas";
        ["light-count", "mural-count", "screen-count"].forEach(id => {
          document.getElementById(id).textContent = formatted;
        });
        ["count-label", "mural-count-label", "screen-count-label"].forEach(id => {
          document.getElementById(id).textContent = label;
        });
      }
      const svgNS = "http://www.w3.org/2000/svg";
      const houseSpecs = [
        {label:"Santa Casa da Bahia",x:40,w:260,peak:100,wall:210,base:370,cols:4,rows:3,ww:38,wh:32,gap:9,top:228},
        {label:"Casa Solange Fraga",x:320,w:260,peak:100,wall:210,base:370,cols:4,rows:3,ww:38,wh:32,gap:9,top:228},
        {label:"Campo Santo Familiar",x:600,w:260,peak:100,wall:210,base:370,cols:4,rows:3,ww:38,wh:32,gap:9,top:228}
      ];
      function svgElement(name, attributes) {
        const element = document.createElementNS(svgNS, name);
        Object.entries(attributes).forEach(([key,value]) => element.setAttribute(key, String(value)));
        return element;
      }
      function buildHouses(svg) {
        const windows = [];
        svg.appendChild(svgElement("line",{x1:20,y1:370,x2:880,y2:370,class:"ground"}));
        houseSpecs.forEach(spec => {
          const roof = `M${spec.x},${spec.base} L${spec.x},${spec.wall} L${spec.x+spec.w/2},${spec.peak} L${spec.x+spec.w},${spec.wall} L${spec.x+spec.w},${spec.base} Z`;
          svg.appendChild(svgElement("path",{d:roof,class:"roof"}));
          const inner = spec.w - 56, gapX = (inner - spec.cols * spec.ww) / (spec.cols - 1);
          for (let row=0; row<spec.rows; row++) {
            for (let col=0; col<spec.cols; col++) {
              const window = svgElement("rect",{
                x:(spec.x+28+col*(spec.ww+gapX)).toFixed(1),
                y:spec.top+row*(spec.wh+spec.gap),
                width:spec.ww,height:spec.wh,rx:6,class:"win"
              });
              windows.push(window);
              svg.appendChild(window);
            }
          }
          const label = svgElement("text",{x:spec.x+spec.w/2,y:408});
          label.textContent = spec.label;
          svg.appendChild(label);
        });
        return windows;
      }
      const mainWindows = buildHouses(document.getElementById("casa"));
      const screenWindows = buildHouses(document.getElementById("casa-telao"));
      let previousLit = 0;
      function paintWindows(windows, lit, animate, previous) {
        windows.forEach((window,index) => {
          const active = index < lit;
          window.classList.toggle("lit", active);
          window.classList.remove("fresh");
          if (active && animate && index >= previous && !reduceActive) {
            window.style.animationDelay = ((index - previous) * 90) + "ms";
            requestAnimationFrame(() => window.classList.add("fresh"));
          }
        });
      }
      function updateHouses(total, animate) {
        const lit = Math.min(Math.max(0, total || 0), mainWindows.length);
        paintWindows(mainWindows, lit, animate, previousLit);
        paintWindows(screenWindows, lit, animate, previousLit);
        previousLit = lit;
        const description = `Casa com ${lit} de ${mainWindows.length} janelas acesas. Total de luzes: ${total}.`;
        document.getElementById("casa").setAttribute("aria-label", description);
        document.getElementById("casa-telao").setAttribute("aria-label", description);
        document.getElementById("casaNote").textContent = total > mainWindows.length
          ? `Todas as ${mainWindows.length} janelas estão acesas. O contador mostra o total de luzes.`
          : "";
      }
      const search = document.getElementById("busca");
      const track = document.getElementById("track");
      const status = document.getElementById("mural-status");
      const loadMoreButton = document.getElementById("load-more");
      let query = "";
      let lights = [];
      let totalLights = null;
      let nextCursor = null;
      let hasMore = false;
      let muralBusy = false;
      let muralSequence = 0;
      let renderedMural = "";
      const telao = document.getElementById("telao");
      const telaoName = document.getElementById("screen-name");
      let telaoTimer = 0;
      let telaoIndex = 0;
      let telaoCurrentId = null;
      function muralUrl(cursor = null) {
        const params = new URLSearchParams({action:"list",limit:"60"});
        if (query) params.set("q", query);
        if (cursor) {
          params.set("before", cursor.before);
          params.set("id", cursor.id);
        }
        return "index.php?" + params.toString();
      }
      function flameIcon() {
        const icon = svgElement("svg",{viewBox:"0 0 24 34","aria-hidden":"true",focusable:"false"});
        icon.appendChild(svgElement("path",{d:"M12 3C7 9.5 3.5 14 3.5 19.5a8.5 8.5 0 0 0 17 0C20.5 14 17 9.5 12 3Z",fill:"none",stroke:"#AA965A","stroke-width":2.2,"stroke-linejoin":"round"}));
        icon.appendChild(svgElement("path",{d:"M12 28V33",fill:"none",stroke:"#AA965A","stroke-width":2.2,"stroke-linecap":"round"}));
        return icon;
      }
      function renderMural() {
        const snapshot = JSON.stringify([query,lights]);
        if (snapshot !== renderedMural) {
          renderedMural = snapshot;
          track.replaceChildren();
          if (!lights.length) {
            const empty = document.createElement("p");
            empty.className = "varal-empty";
            empty.textContent = query
              ? `Nenhum nome encontrado para “${query}”. Confira a grafia ou apague a busca.`
              : totalLights === null
                ? "Aguardando as luzes do mural compartilhado."
                : totalLights === 0
                  ? "Ainda não há luzes acesas. A primeira pode ser a sua."
                  : "Nenhum nome está exibido no mural por enquanto.";
            if (!query && totalLights === 0) {
              const link = document.createElement("a");
              link.href = "#acender";
              link.textContent = " Acender minha luz";
              empty.appendChild(link);
              empty.appendChild(document.createTextNode("."));
            }
            track.appendChild(empty);
          } else {
            lights.forEach(light => {
              const card = document.createElement("article");
              card.className = "tag" + (savedTribute?.id === light.id ? " mine" : "");
              card.appendChild(flameIcon());
              const name = document.createElement("div");
              name.className = "nm";
              name.textContent = light.name;
              card.appendChild(name);
              if (light.message) {
                const text = document.createElement("div");
                text.className = "tx";
                text.textContent = light.message;
                card.appendChild(text);
              }
              if (light.authorName) {
                const author = document.createElement("div");
                author.className = "by";
                author.textContent = "Acesa por " + light.authorName;
                card.appendChild(author);
              }
              track.appendChild(card);
            });
          }
        }
        loadMoreButton.hidden = !hasMore;
        loadMoreButton.disabled = muralBusy;
        loadMoreButton.textContent = muralBusy ? "Carregando…" : "Carregar mais homenagens";
      }
      function updateMuralStatus(data) {
        status.textContent = query
          ? `${data.filteredTotal.toLocaleString("pt-BR")} ${data.filteredTotal === 1 ? "nome encontrado" : "nomes encontrados"}`
          : `${data.total.toLocaleString("pt-BR")} ${data.total === 1 ? "luz acesa" : "luzes acesas"} · ${data.filteredTotal.toLocaleString("pt-BR")} exibidas no mural`;
      }
      function renderTelaoTribute(light) {
        telaoCurrentId = light ? light.id : null;
        telaoName.replaceChildren();
        const addLine = (className,text) => {
          const line = document.createElement("div");
          line.className = className;
          line.textContent = text;
          telaoName.appendChild(line);
        };
        if (!light) {
          addLine("em","Acenda a sua luz");
          addLine("nm","@camposantofamiliar");
          return;
        }
        addLine("em","Em memória de");
        addLine("nm",light.name);
        if (light.message) addLine("tx",light.message);
        if (light.authorName) addLine("by","Acesa por " + light.authorName);
      }
      function updateTelao() {
        if (telao.hidden) return;
        const current = lights.find(light => light.id === telaoCurrentId);
        if (!current) nextTelaoTribute();
        else renderTelaoTribute(current);
      }
      function nextTelaoTribute() {
        if (!lights.length) {
          renderTelaoTribute(null);
          return;
        }
        const light = lights[telaoIndex % lights.length];
        telaoIndex++;
        if (reduceActive) {
          renderTelaoTribute(light);
          return;
        }
        telaoName.classList.add("out");
        window.setTimeout(() => {
          if (!telao.hidden) renderTelaoTribute(light);
          telaoName.classList.remove("out");
        }, 600);
      }
      function openTelao(event) {
        event.preventDefault();
        telao.hidden = false;
        document.body.style.overflow = "hidden";
        updateHouses(Number(document.getElementById("light-count").textContent.replace(/\D/g,"")) || 0, false);
        nextTelaoTribute();
        window.clearInterval(telaoTimer);
        telaoTimer = window.setInterval(nextTelaoTribute, 7000);
        document.getElementById("closeTelao").focus();
        document.documentElement.requestFullscreen?.().catch(() => {});
      }
      function closeTelao() {
        telao.hidden = true;
        document.body.style.overflow = "";
        window.clearInterval(telaoTimer);
        if (document.fullscreenElement) document.exitFullscreen?.().catch(() => {});
        document.getElementById("openTelao").focus();
      }
      document.getElementById("openTelao").addEventListener("click", openTelao);
      document.getElementById("closeTelao").addEventListener("click", closeTelao);
      document.addEventListener("keydown", event => {
        if (event.key === "Escape" && !telao.hidden) closeTelao();
      });
      async function fetchMuralPage(cursor) {
        const response = await fetch(muralUrl(cursor), {cache:"no-store",headers:{Accept:"application/json"}});
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || "Não foi possível atualizar o mural.");
        return data;
      }
      async function refreshMural({append=false, merge=false}={}) {
        if (muralBusy || document.hidden) return;
        muralBusy = true;
        const version = ++muralSequence;
        renderMural();
        try {
          const data = await fetchMuralPage(append ? nextCursor : null);
          if (version !== muralSequence) return;
          if (append) {
            const known = new Set(lights.map(light => light.id));
            lights = [...lights, ...data.items.filter(light => !known.has(light.id))];
            nextCursor = data.nextCursor;
            hasMore = data.hasMore;
          } else if (merge) {
            const oldLights = lights;
            const firstPageIds = new Set(data.items.map(light => light.id));
            lights = [...data.items, ...oldLights.filter(light => !firstPageIds.has(light.id))];
            nextCursor = lights.length
              ? {before:lights[lights.length-1].createdAt,id:lights[lights.length-1].id}
              : data.nextCursor;
            hasMore = data.hasMore || oldLights.length > data.items.length;
          } else {
            lights = data.items;
            nextCursor = data.nextCursor;
            hasMore = data.hasMore;
          }
          setCount(data.total);
          totalLights = data.total;
          updateHouses(data.total, merge);
          updateMuralStatus(data);
          renderMural();
          updateTelao();
        } catch (error) {
          if (version === muralSequence) {
            status.textContent = error instanceof Error ? error.message : "Não foi possível atualizar o mural.";
            document.getElementById("casaNote").textContent = "Não foi possível atualizar o mural. Vamos tentar novamente em instantes.";
          }
        } finally {
          if (version === muralSequence) {
            muralBusy = false;
            renderMural();
          }
        }
      }
      let searchTimer = 0;
      search.addEventListener("input", () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
          const next = search.value.trim();
          if (next === query) return;
          query = next;
          muralSequence++;
          muralBusy = false;
          lights = [];
          nextCursor = null;
          hasMore = false;
          renderedMural = "";
          track.replaceChildren();
          document.getElementById("varal").scrollLeft = 0;
          status.textContent = "Buscando homenagens…";
          refreshMural();
        }, 300);
      });
      loadMoreButton.addEventListener("click", () => refreshMural({append:true}));
      document.addEventListener("visibilitychange", () => { if (!document.hidden) refreshMural({merge:true}); });
      window.addEventListener("focus", () => refreshMural({merge:true}));
      window.addEventListener("online", () => refreshMural({merge:true}));
      refreshMural();
      muralTimer = window.setInterval(() => { if (!document.hidden) refreshMural({merge:true}); }, 3000);
      window.addEventListener("pagehide", () => {
        window.clearInterval(muralTimer);
        window.clearTimeout(searchTimer);
      }, {once:true});

      consent.addEventListener("change", () => { options.hidden = !consent.checked; });
      phoneInput.addEventListener("input", maskPhone);
      message.addEventListener("input", () => {
        document.getElementById("message-count").textContent = message.value.length + "/140";
      });

      function wrapCanvasText(text, maxWidth) {
        const words = String(text).split(/\s+/);
        const lines = [];
        let line = "";
        for (const word of words) {
          if (context.measureText(word).width > maxWidth) {
            if (line) { lines.push(line); line = ""; }
            for (const character of Array.from(word)) {
              if (line && context.measureText(line + character).width > maxWidth) {
                lines.push(line);
                line = character;
              } else {
                line += character;
              }
            }
            continue;
          }
          const candidate = line ? line + " " + word : word;
          if (context.measureText(candidate).width <= maxWidth || !line) {
            line = candidate;
          } else {
            lines.push(line);
            line = word;
          }
        }
        if (line) lines.push(line);
        return lines;
      }
      function drawCertificate(tribute) {
        const W = canvas.width, H = canvas.height, green = "#182D26", gold = "#AA965A";
        context.clearRect(0, 0, W, H);
        context.fillStyle = "#E4E6E1"; context.fillRect(0, 0, W, H);
        context.strokeStyle = "rgba(24,45,38,.35)"; context.lineWidth = 3; context.strokeRect(48, 48, W - 96, H - 96);
        context.save(); context.translate(390, 100); context.scale(.9, .9);
        context.lineWidth = 13.5; context.lineCap = "round"; context.lineJoin = "round";
        context.strokeStyle = green;
        context.stroke(new Path2D("M124.289 297.057L59.0378 248.118C51.8869 242.755 48.3115 235.604 48.3115 226.666V84.7659C48.3115 74.3376 52.8553 66.5722 61.9428 61.4698L152.111 10.8552C161.868 5.38031 171.626 5.38031 181.384 10.8552L271.552 61.4698C280.64 66.5722 285.183 74.3376 285.183 84.7659V226.666C285.183 235.604 281.608 242.755 274.457 248.118L209.206 297.057"));
        context.stroke(new Path2D("M117.585 311.582C122.054 311.582 124.289 309.348 124.289 304.878V254.04C124.289 249.571 126.523 247.336 130.993 247.336H202.501C206.97 247.336 209.205 249.571 209.205 254.04V304.878C209.205 309.348 211.44 311.582 215.909 311.582H308.088C317.026 311.582 321.496 307.113 321.496 298.174V164.655C321.496 153.481 316.617 145.157 306.859 139.683L183.339 70.5763C172.278 64.3938 161.216 64.3938 150.155 70.5763L26.6349 139.683C16.877 145.157 11.998 153.481 11.998 164.655V298.174C11.998 307.113 16.4673 311.582 25.4059 311.582H117.585Z"));
        context.strokeStyle = gold;
        context.stroke(new Path2D("M166.748 119.961C150.546 142.307 129.876 161.86 129.876 184.207C129.876 206.553 150.546 226.106 166.748 235.604C182.949 226.106 203.619 206.553 203.619 184.207C203.619 161.86 182.949 142.307 166.748 119.961Z"));
        context.stroke(new Path2D("M166.746 235.603V311.58"));
        context.restore();
        context.textAlign = "center";
        context.fillStyle = green; context.font = '800 70px "Alegreya Sans","Trebuchet MS",sans-serif'; context.fillText("Luz que Ampara", 540, 520);
        context.fillStyle = "rgba(24,45,38,.72)"; context.font = "300 28px system-ui,sans-serif"; context.fillText("O cuidado que permanece", 540, 566);
        context.fillStyle = green; context.font = "400 36px system-ui,sans-serif"; context.fillText("acendeu uma luz em memória de", 540, 700);
        let size = 120, lines;
        do { context.font = `800 ${size}px "Alegreya Sans","Trebuchet MS",sans-serif`; lines = wrapCanvasText(tribute.name, 860); size -= 4; } while (lines.length > 2 && size >= 36);
        size += 4; context.font = `800 ${size}px "Alegreya Sans","Trebuchet MS",sans-serif`;
        const lineHeight = size * 1.08, firstY = 840 - ((lines.length - 1) * lineHeight) / 2 + size * .3;
        context.fillStyle = green;
        lines.forEach((line, index) => context.fillText(line, 540, firstY + index * lineHeight));
        context.font = 'italic 300 54px "Alegreya Sans","Trebuchet MS",sans-serif'; context.fillText("Toda luz ampara alguém.", 540, 1060);
        context.font = "600 34px system-ui,sans-serif"; context.fillText("#LuzQueAmpara", 540, 1160);
        context.font = "400 24px system-ui,sans-serif"; context.fillText("Campo Santo Familiar · Santa Casa da Bahia · Casa Solange Fraga", 540, 1215);
        context.fillText("Salvador, " + new Date(tribute.createdAt).toLocaleDateString("pt-BR"), 540, 1262);
      }
      function showSaved(tribute, moveToResult = true) {
        savedTribute = tribute;
        drawCertificate(tribute);
        document.getElementById("share-button").disabled = false;
        document.getElementById("download-button").disabled = false;
        document.getElementById("card-note").textContent = "Pronto. Compartilhe ou guarde a imagem.";
        if (moveToResult) {
          document.getElementById("wait").hidden = true;
          document.getElementById("done").hidden = false;
          document.getElementById("result-title").focus({preventScroll:true});
          document.getElementById("momento").scrollIntoView({behavior: reduceActive ? "auto" : "smooth", block:"center"});
        }
      }
      try {
        const cached = JSON.parse(localStorage.getItem(savedKey) || "null");
        if (cached && typeof cached.name === "string" && typeof cached.createdAt === "string") {
          showSaved(cached, false);
          document.getElementById("wait").hidden = true;
          document.getElementById("done").hidden = false;
        }
      } catch { /* Armazenamento local pode estar indisponível. */ }
      if (!savedTribute) drawCertificate({name:"Nome de quem você lembra",createdAt:new Date().toISOString()});
      if (document.fonts?.ready) document.fonts.ready.then(() => { if (savedTribute) drawCertificate(savedTribute); });

      form.addEventListener("submit", async event => {
        event.preventDefault();
        formMessage.textContent = "";
        const name = form.elements.name.value.trim();
        const authorName = form.elements.authorName.value.trim();
        const email = form.elements.email.value.trim();
        const phone = form.elements.phone.value.trim();
        const phoneDigits = phone.replace(/\D/g, "");
        const text = form.elements.message.value.trim();
        const nameError = document.getElementById("name-error");
        nameError.hidden = true;
        const violations = [];
        if (!name) { violations.push("Informe o nome de quem você lembra."); nameError.textContent = "Escreva o nome de quem você lembra."; nameError.hidden = false; }
        if (!authorName) violations.push("Informe seu nome.");
        if (!email || !form.elements.email.validity.valid) violations.push("Informe um e-mail válido.");
        if (!/^\(\d{2}\) (?:\d{4}|\d{5})-\d{4}$/.test(phone) || ![10, 11].includes(phoneDigits.length)) violations.push("Informe um telefone brasileiro válido com DDD.");
        if (name && hasProfanity(name)) violations.push("Revise o nome de quem você lembra.");
        if (authorName && hasProfanity(authorName)) violations.push("Revise seu nome.");
        if (text && hasProfanity(text)) violations.push("Revise a mensagem.");
        if (violations.length) { formMessage.textContent = violations.join(" "); return; }
        const button = document.getElementById("submit-button");
        button.disabled = true; button.textContent = "Acendendo…";
        try {
          const tribute = {
            id: crypto.randomUUID(),
            name,
            authorName,
            email,
            phone,
            message: text,
            consent: consent.checked,
            displayMode: consent.checked ? form.elements.displayMode.value : "hidden",
            website: form.elements.website.value
          };
          const response = await fetch("index.php?action=create", {
            method:"POST",
            credentials:"same-origin",
            cache:"no-store",
            headers:{"Content-Type":"application/json","Accept":"application/json","X-CSRF-Token":csrf},
            body:JSON.stringify(tribute)
          });
          const data = await response.json();
          if (!response.ok) throw new Error(data.error || "Não foi possível registrar sua luz.");
          const localTribute = {id:data.id,name,createdAt:data.createdAt};
          try { localStorage.setItem(savedKey, JSON.stringify(localTribute)); } catch { /* O cartão continua disponível nesta sessão. */ }
          showSaved(localTribute);
          form.reset(); phoneInput.value = formatBrazilianPhone(phoneInput.value); options.hidden = true; document.getElementById("message-count").textContent = "0/140";
          refreshMural({merge:true});
        } catch (error) {
          formMessage.textContent = error instanceof Error ? error.message : "Não foi possível registrar sua luz. Tente novamente.";
        } finally {
          button.disabled = false; button.textContent = "Acender";
        }
      });
      function blobFromCanvas() {
        return new Promise((resolve, reject) => canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error("Não foi possível gerar a imagem.")), "image/png"));
      }
      document.getElementById("download-button").addEventListener("click", async () => {
        try {
          const blob = await blobFromCanvas(), url = URL.createObjectURL(blob), anchor = document.createElement("a");
          anchor.href = url; anchor.download = "luz-que-ampara.png"; anchor.click();
          setTimeout(() => URL.revokeObjectURL(url), 30000);
        } catch (error) { formMessage.textContent = error.message; }
      });
      document.getElementById("share-button").addEventListener("click", async () => {
        try {
          const blob = await blobFromCanvas();
          const file = new File([blob], "luz-que-ampara.png", {type:"image/png"});
          const payload = {title:"Luz que Ampara",text:"Acendi uma luz por quem eu amo. #LuzQueAmpara",files:[file]};
          if (navigator.canShare?.(payload)) await navigator.share(payload);
          else if (navigator.share) await navigator.share({title:payload.title,text:payload.text});
          else document.getElementById("download-button").click();
        } catch (error) {
          if (error?.name !== "AbortError") formMessage.textContent = error.message || "Não foi possível compartilhar o cartão.";
        }
      });
    })();
  </script>
</body>
</html>
