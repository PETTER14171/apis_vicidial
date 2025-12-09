<?php
/**
 * update_vicidial_from_csv.php — versión adaptada a encabezados VICIdial provistos
 *
 * Uso:
 *   php update_vicidial_from_csv.php archivo.csv [--dry-run] [--verbose] [--delim=";"]
 *
 * Notas:
 * - Acepta exactamente los encabezados que compartiste (con espacios y ':' al final).
 * - Mapea a campos VICIdial (non_agent_api.php -> function=update_lead).
 * - RANK del CSV se asigna a 'rank' y también a 'priority' (0..99) para priorizar.
 * - CREATED AT DATE -> entry_date (se intenta parsear a 'YYYY-MM-DD HH:MM:SS').
 * - Corrige fgetcsv (5 args) para PHP 8.4 y autodetecta delimitador.
 */

// ======================
// 1) CONFIGURACIÓN
// ======================
const API_URL   = 'https://talkhubservices.vicihost.com/vicidial/non_agent_api.php'; // ENDPOINT correcto
const API_USER  = '6666';        // user de vicidial_users con permisos Non-Agent API
const API_PASS  = 'T7athsol';    // pass del usuario API
const API_SOURCE= 'CSV_UPD';     // etiqueta de origen (máx 20 chars)

// Comportamiento API
const DEFAULT_SEARCH_METHOD   = 'PHONE_NUMBER';
const DEFAULT_SEARCH_LOCATION = 'LIST'; // LIST | CAMPAIGN | SYSTEM
const INSERT_IF_NOT_FOUND     = 'Y';
const UPDATE_PHONE_NUMBER     = 'Y';

// Defaults de datos
const DEFAULT_STATUS          = 'NEW';
const DEFAULT_LIST_ID         = '111'; // Asegúrate de que sea una lista válida
const DEFAULT_PRIORITY        = 99;    // 0..99 (99 súper prioritario)
const DEFAULT_PHONE_CODE      = '1';  // MX=52, US=1

// Hopper
const PUSH_TO_HOPPER          = true;
const HOPPER_CAMPAIGN_ID      = '111'; // ID real de campaña (NO es list_id)

// Otros
const RATE_LIMIT_MS           = 150;   // pausa entre requests

// Campos permitidos por VICIdial (subset común)
$ALLOWLIST_FIELDS = [
  'lead_id','vendor_lead_code','source_id','list_id','phone_number','phone_code','title',
  'first_name','middle_initial','last_name','address1','address2','address3','city','state',
  'province','postal_code','country_code','date_of_birth','alt_phone','email','security_phrase',
  'comments','status','user','entry_date','gmt_offset_now','called_since_last_reset','rank',
  'owner','entry_list_id','priority','gender'
];

// ======================
// 2) ENTRADA / FLAGS
// ======================
if ($argc < 2) {
  fwrite(STDERR, "Uso: php ".$argv[0]." archivo.csv [--dry-run] [--verbose] [--delim=\";\"]\n");
  exit(1);
}
$csvPath = $argv[1];
$dryRun  = in_array('--dry-run', $argv, true);
$verbose = in_array('--verbose', $argv, true);
$forcedDelim = null;
foreach ($argv as $arg) {
  if (str_starts_with($arg, '--delim=')) {
    $forcedDelim = trim(substr($arg, 8), "\"'");
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
function normalizePhone($raw) {
  return preg_replace('/\D+/', '', (string)$raw);
}
function curlGet($url) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 45
  ]);
  $response = curl_exec($ch);
  $err      = curl_error($ch);
  $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$response, $err, $code];
}
function buildApiUrl(array $params) { return API_URL . '?' . http_build_query($params); }
function pick($row, $key, $default = null) { return array_key_exists($key, $row) && $row[$key] !== '' ? $row[$key] : $default; }
function sleepMs($ms) { usleep(max(0, (int)$ms) * 1000); }

// Autodetección de delimitador
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

// Parser flexible para CREATED AT DATE -> entry_date (YYYY-MM-DD HH:MM:SS)
function parseCreatedAtToEntryDate($s) {
  $s = trim($s);
  if ($s === '') return '';
  // Intentar varios formatos comunes: 21/11/2023, 05-08-2024, 2025-07-06, 06/07/2025 13:20
  $candidates = [
    'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
    'd-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y',
    'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d',
    'm/d/Y H:i:s', 'm/d/Y H:i', 'm/d/Y',
  ];
  foreach ($candidates as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $s);
    if ($dt instanceof DateTime) {
      return $dt->format('Y-m-d H:i:s');
    }
  }
  // último intento: strtotime
  $ts = strtotime($s);
  if ($ts !== false) return date('Y-m-d H:i:s', $ts);
  return ''; // no se pudo parsear
}

// ======================
// 4) MAPEO DE ENCABEZADOS
// ======================
// Encabezados EXACTOS esperados -> clave VICIdial
$HEADER_MAP = [
  'VENDOR LEAD CODE' => 'vendor_lead_code',
  'SOURCE ID'        => 'source_id',
  'PHONE NUMBER'     => 'phone_number',
  'TITLE'            => 'title',
  'FIRST NAME'       => 'first_name',
  'MIDDLE INITIAL'   => 'middle_initial',
  'LAST NAME'        => 'last_name',
  'ADDRESS1'         => 'address1',
  'ADDRESS2'         => 'address2',
  'ADDRESS3'         => 'address3',
  'CITY'             => 'city',
  'STATE'            => 'state',
  'PROVINCE'         => 'province',
  'POSTAL CODE'      => 'postal_code',
  'COUNTRY CODE'     => 'country_code', // ej. MX, US
  'GENDER'           => 'gender',       // no siempre usado por VICIdial; se enviará si aplica
  'DATE OF BIRTH'    => 'date_of_birth',
  'ALT PHONE'        => 'alt_phone',
  'EMAIL'            => 'email',
  'SECURITY PHRASE'  => 'security_phrase',
  'COMMENTS'         => 'comments',
  'RANK'             => 'rank',         // además lo usaremos para priority
  'OWNER'            => 'owner',
  'CREATED AT DATE'  => 'entry_date',   // parseado a formato base
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
  echo "Dry-run: ".($dryRun?'SI':'NO')."\n";
  echo "Verbose: SI\n\n";
}

$fh = fopen($csvPath, 'r');
if (!$fh) { fwrite(STDERR, "No se pudo abrir el CSV.\n"); exit(1); }

// Leer encabezados (fgetcsv con 5 args)
$rawHeader = fgetcsv($fh, 0, $delimiter, '"', "\\");
if (!$rawHeader) { fwrite(STDERR, "CSV vacío o sin encabezados.\n"); exit(1); }

// Normalizar: recortar, quitar BOM, dejar tal cual para mapear exacto (pero sin espacios extra)
$normalizedHeader = array_map(function($h){
  $h = removeBOM($h);
  return trim($h);
}, $rawHeader);

if ($verbose) {
  echo "Encabezados CSV:\n";
  foreach ($normalizedHeader as $h) echo " - {$h}\n";
  echo "\n";
}

// Mapear a claves VICIdial según HEADER_MAP
$headerKeys = [];
foreach ($normalizedHeader as $h) {
  if (array_key_exists($h, $HEADER_MAP)) {
    $headerKeys[] = $HEADER_MAP[$h];
  } else {
    // columnas desconocidas → usamos una key genérica por si quieres depurar
    $headerKeys[] = null;
  }
}

$lineNum       = 1;
$okCount       = 0;
$errorCount    = 0;
$skippedCount  = 0;
$results       = [];

while (($row = fgetcsv($fh, 0, $delimiter, '"', "\\")) !== false) {
  $lineNum++;

  // Fila vacía
  if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) { continue; }

  // Alinear conteo
  if (count($row) < count($headerKeys)) {
    $row = array_pad($row, count($headerKeys), '');
  }

  // Construir arreglo asociativo usando el mapeo
  $assoc = [];
  foreach ($row as $idx => $val) {
    $key = $headerKeys[$idx];
    if ($key === null) continue; // ignorar columnas no mapeadas
    $assoc[$key] = trim($val);
  }

  // Normalizar/validar teléfono
  $phone = normalizePhone(pick($assoc, 'phone_number', ''));
  if ($phone === '') {
    $skippedCount++;
    $results[] = ["line"=>$lineNum, "status"=>"SKIP", "detail"=>"Sin PHONE NUMBER (revisa encabezados/CSV)"];
    continue;
  }

  // list_id y status por defecto (tu CSV no los trae, así que usamos defaults)
  $listId = DEFAULT_LIST_ID;
  $status = DEFAULT_STATUS;

  // RANK del CSV -> rank y priority
  $rank = pick($assoc, 'rank', '');
  $priority = DEFAULT_PRIORITY;
  if ($rank !== '' && is_numeric($rank)) {
    $rank = (int)$rank;
    // VICIdial tiene 'rank' y 'priority'; usamos ambos
    $priority = max(0, min(99, (int)$rank));
  } else {
    $rank = null; // si no hay, no lo mandamos
  }

  // entry_date desde CREATED AT DATE (si viene)
  if (!empty($assoc['entry_date'])) {
    $parsed = parseCreatedAtToEntryDate($assoc['entry_date']);
    $assoc['entry_date'] = $parsed !== '' ? $parsed : null; // si no se pudo parsear, mejor no enviarlo
  }

  // Base params obligatorios de API
  $params = [
    'source'              => API_SOURCE,
    'user'                => API_USER,
    'pass'                => API_PASS,
    'function'            => 'update_lead',
    'search_method'       => DEFAULT_SEARCH_METHOD,
    'search_location'     => DEFAULT_SEARCH_LOCATION,
    'insert_if_not_found' => INSERT_IF_NOT_FOUND,
    'update_phone_number' => UPDATE_PHONE_NUMBER,

    'phone_number'        => $phone,
    'phone_code'          => DEFAULT_PHONE_CODE, // por defecto; tu CSV no lo trae
    'status'              => $status,
    'priority'            => $priority
  ];

  // list_id es obligatorio si insertamos
  if (INSERT_IF_NOT_FOUND === 'Y' && $listId === '') {
    $skippedCount++;
    $results[] = ["line"=>$lineNum, "status"=>"SKIP", "detail"=>"Falta list_id (requerido si insert_if_not_found=Y)"];
    continue;
  }
  if ($listId !== '') { $params['list_id'] = $listId; }

  // Pasar campos permitidos del CSV
  foreach ($assoc as $k => $v) {
    if ($v === '' || $v === null) continue;
    if (in_array($k, $GLOBALS['ALLOWLIST_FIELDS'], true)) {
      // ya seteados arriba
      if ($k === 'phone_number' || $k === 'priority') continue;
      // Si es 'rank' y lo parseamos vacío, sáltalo
      if ($k === 'rank' && !is_numeric($v)) continue;
      $params[$k] = $v;
    }
  }

  // Hopper
  if (PUSH_TO_HOPPER) {
    $params['add_to_hopper'] = 'Y';
    $params['campaign_id']   = HOPPER_CAMPAIGN_ID; // ID real de campaña
    if (!empty($listId)) { $params['entry_list_id'] = $listId; }
  }

  // URL
  $url = buildApiUrl($params);

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

  // Evaluación básica
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
