<?php
/**
 * update_vicidial_dnc_from_csv.php — carga masiva de DNC (Non-Agent API add_dnc_phone)
 *
 * Uso:
 *   php update_vicidial_dnc_from_csv.php archivo.csv [--dry-run] [--verbose] [--delim=";"] [--campaign="VENTAS"]
 *
 * CSV esperado (con encabezados; algunos ejemplos de columnas aceptadas):
 *   PHONE NUMBER, CAMPAIGN ID, COMMENTS, SOURCE ID, ...
 *   (Solo PHONE NUMBER es requerida. CAMPAIGN ID es opcional.)
 *
 * Notas:
 * - Añade a DNC del sistema si no hay campaign_id (ni flag --campaign).
 * - Si hay CAMPAIGN ID en CSV, tiene prioridad sobre --campaign por cada fila.
 * - Rate limit entre llamadas para no saturar el servidor.
 * - Resumen al final con OK/ERROR/OMITIDOS.
 */

// ======================
// 1) CONFIGURACIÓN
// ======================
const API_URL    = 'https://talkhubservices.vicihost.com/vicidial/non_agent_api.php';
const API_USER   = '6666';       // Usuario con permisos Non-Agent API
const API_PASS   = 'T7athsol';   // Contraseña
const API_SOURCE = 'CSV_DNC';    // Etiqueta de origen (<=20 chars)

// Comportamiento
const RATE_LIMIT_MS = 250;       // pausa entre requests (ms)
const TIMEOUT_SECS  = 45;        // timeout de cURL (s)

// ======================
// 2) ENTRADA / FLAGS
// ======================
if ($argc < 2) {
  fwrite(STDERR, "Uso: php ".$argv[0]." archivo.csv [--dry-run] [--verbose] [--delim=\";\"] [--campaign=\"VENTAS\"]\n");
  exit(1);
}

$csvPath   = $argv[1];
$dryRun    = in_array('--dry-run', $argv, true);
$verbose   = in_array('--verbose', $argv, true);
$forcedDelim = null;
$defaultCampaign = ''; // si viene por flag --campaign

foreach ($argv as $arg) {
  if (str_starts_with($arg, '--delim=')) {
    $forcedDelim = trim(substr($arg, 8), "\"'");
  }
  if (str_starts_with($arg, '--campaign=')) {
    $defaultCampaign = trim(substr($arg, 11), "\"'");
  }
}

if (!is_file($csvPath)) {
  fwrite(STDERR, "No se encontró el CSV: $csvPath\n");
  exit(1);
}

// ======================
// 3) UTILIDADES
// ======================
function removeBOM($s) {
  if (substr($s, 0, 3) === "\xEF\xBB\xBF") return substr($s, 3);
  return $s;
}
function normalizePhone($raw, $allowPlus = false) {
  $raw = trim((string)$raw);
  if ($raw === '') return '';
  if ($allowPlus && $raw[0] === '+') {
    return '+' . preg_replace('/\D+/', '', substr($raw,1));
  }
  return preg_replace('/\D+/', '', $raw);
}
function sleepMs($ms) { usleep(max(0, (int)$ms) * 1000); }

function detectDelimiter($filePath) {
  $candidates = [",", ";", "\t", "|"];
  $firstLine = '';
  $fh = fopen($filePath, 'r');
  if ($fh) { $firstLine = fgets($fh); fclose($fh); }
  if ($firstLine === '' || $firstLine === false) return ',';
  $firstLine = removeBOM($firstLine);
  $bestDelim = ',';
  $maxCount  = -1;
  foreach ($candidates as $d) {
    $count = substr_count($firstLine, $d);
    if ($count > $maxCount) { $maxCount = $count; $bestDelim = $d; }
  }
  return $bestDelim;
}

function curlGet($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => TIMEOUT_SECS,
    CURLOPT_USERAGENT => 'update_vicidial_dnc_from_csv/1.0'
  ]);
  $response = curl_exec($ch);
  $err      = curl_error($ch);
  $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$response, $err, $code];
}

function buildApiUrl(array $params) {
  return API_URL . '?' . http_build_query($params);
}

function pick($row, $key, $default = null) {
  return (array_key_exists($key, $row) && $row[$key] !== '') ? $row[$key] : $default;
}

// ======================
// 4) MAPEO DE ENCABEZADOS
// ======================
// Encabezados EXACTOS esperados -> clave interna
$HEADER_MAP = [
  'PHONE NUMBER'  => 'phone_number',
  'CAMPAIGN ID'   => 'campaign_id',   // opcional
  'SOURCE ID'     => 'source_id',     // opcional (solo informativo)
  'COMMENTS'      => 'comments',      // opcional (solo informativo)
  // puedes agregar más columnas si requieres guardar/registrar, pero la API DNC no las usa
];

// ======================
// 5) PROCESAR CSV
// ======================
$delimiter = $forcedDelim !== null ? $forcedDelim : detectDelimiter($csvPath);

if ($verbose) {
  echo "Endpoint: ".API_URL."\n";
  echo "Usuario API: ".API_USER."\n";
  echo "CSV: $csvPath\n";
  echo "Delimitador: ".($delimiter === "\t" ? 'TAB' : $delimiter)."\n";
  echo "Campaña por defecto (--campaign): ".($defaultCampaign !== '' ? $defaultCampaign : '(SYSTEM DNC)')."\n";
  echo "Dry-run: ".($dryRun?'SI':'NO')."\n";
  echo "Verbose: SI\n\n";
}

$fh = fopen($csvPath, 'r');
if (!$fh) { fwrite(STDERR, "No se pudo abrir el CSV.\n"); exit(1); }

// Leer encabezados
$rawHeader = fgetcsv($fh, 0, $delimiter, '"', "\\");
if (!$rawHeader) { fwrite(STDERR, "CSV vacío o sin encabezados.\n"); exit(1); }

// Normalizar encabezados (sin BOM, sin espacios extremos)
$normalizedHeader = array_map(function($h){
  $h = removeBOM($h);
  return trim($h);
}, $rawHeader);

if ($verbose) {
  echo "Encabezados CSV:\n";
  foreach ($normalizedHeader as $h) echo " - {$h}\n";
  echo "\n";
}

// Mapear encabezados a claves internas
$headerKeys = [];
foreach ($normalizedHeader as $h) {
  if (array_key_exists($h, $HEADER_MAP)) {
    $headerKeys[] = $HEADER_MAP[$h];
  } else {
    $headerKeys[] = null; // ignorar columnas no mapeadas
  }
}

// Validar que exista PHONE NUMBER en encabezados
if (!in_array('phone_number', $headerKeys, true)) {
  fwrite(STDERR, "No se encontró la columna obligatoria 'PHONE NUMBER' en los encabezados.\n");
  exit(1);
}

$lineNum       = 1;
$okCount       = 0;
$errorCount    = 0;
$skippedCount  = 0;
$results       = [];

while (($row = fgetcsv($fh, 0, $delimiter, '"', "\\")) !== false) {
  $lineNum++;

  if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) { continue; }

  if (count($row) < count($headerKeys)) {
    $row = array_pad($row, count($headerKeys), '');
  }

  // Asociar columnas a claves internas
  $assoc = [];
  foreach ($row as $idx => $val) {
    $key = $headerKeys[$idx];
    if ($key === null) continue;
    $assoc[$key] = trim($val);
  }

  // Teléfono (obligatorio)
  $phone = normalizePhone(pick($assoc, 'phone_number', ''), false);
  if ($phone === '') {
    $skippedCount++;
    $results[] = ["line"=>$lineNum, "status"=>"SKIP", "detail"=>"Sin PHONE NUMBER (revisa encabezados/CSV)"];
    continue;
  }

  // campaign_id: el del CSV (si viene) tiene prioridad; si no, usa --campaign; si tampoco, DNC de sistema
  $campaignId = trim((string)pick($assoc, 'campaign_id', ''));
  if ($campaignId === '') $campaignId = $defaultCampaign;

  // Construir parámetros para add_dnc_phone
  $params = [
    'function'     => 'add_dnc_phone',
    'user'         => API_USER,
    'pass'         => API_PASS,
    'source'       => API_SOURCE,
    'phone_number' => $phone
  ];
  if ($campaignId !== '') {
    $params['campaign_id'] = $campaignId; // DNC por campaña
  }

  $url = buildApiUrl($params);

  // DRY RUN
  if ($dryRun) {
    $okCount++;
    $results[] = ["line"=>$lineNum, "status"=>"DRY-RUN", "detail"=>$url];
    if ($verbose) {
      echo "---- línea $lineNum (DRY-RUN) ----\n$url\n\n";
    }
    continue;
  }

  // Llamada real
  if ($verbose) {
    echo "---- línea $lineNum (REAL) ----\n$url\n";
  }

  [$response, $err, $code] = curlGet($url);

  if ($verbose) {
    echo "HTTP: $code\n";
    if ($err) echo "cURL error: $err\n";
    echo "Respuesta: " . trim((string)$response) . "\n\n";
  }

  if ($err) {
    $errorCount++;
    $results[] = ["line"=>$lineNum, "status"=>"ERROR", "detail"=>"cURL: $err"];
  } elseif ($code >= 400) {
    $errorCount++;
    $results[] = ["line"=>$lineNum, "status"=>"ERROR", "detail"=>"HTTP $code: $response"];
  } elseif (stripos((string)$response, 'ERROR:') !== false) {
    $errorCount++;
    $results[] = ["line"=>$lineNum, "status"=>"ERROR", "detail"=>trim((string)$response)];
  } else {
    // La Non-Agent API suele responder con "SUCCESS:" en texto plano
    $okCount++;
    $results[] = ["line"=>$lineNum, "status"=>"OK", "detail"=>trim((string)$response)];
  }

  sleepMs(RATE_LIMIT_MS);
}
fclose($fh);

// ======================
// 6) RESUMEN
// ======================
$summary = [
  'archivo'   => $csvPath,
  'dry_run'   => $dryRun ? 'SI' : 'NO',
  'ok'        => $okCount,
  'errores'   => $errorCount,
  'omitidos'  => $skippedCount,
];
echo "==== RESUMEN ====\n";
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$maxShow = 40;
foreach (array_slice($results, 0, $maxShow) as $r) {
  echo "[{$r['status']}] línea {$r['line']} => {$r['detail']}\n";
}
if (count($results) > $maxShow) {
  echo "... (".(count($results) - $maxShow)." más)\n";
}
