<?php
/**
 * update_vicidial_from_csv.php — versión segura con DNC, hopper condicional y refuerzos
 *
 * Uso:
 *   php update_vicidial_from_csv.php archivo.csv [--dry-run] [--verbose] [--delim=";"]
 *
 * CSV encabezados (SIN “:”):
 * VENDOR LEAD CODE, SOURCE ID, PHONE NUMBER, PHONE CODE, TITLE, FIRST NAME, MIDDLE INITIAL, LAST NAME,
 * ADDRESS1, ADDRESS2, ADDRESS3, CITY, STATE, PROVINCE, POSTAL CODE, COUNTRY CODE, GENDER, DATE OF BIRTH,
 * ALT PHONE, EMAIL, SECURITY PHRASE, COMMENTS, RANK, OWNER, CREATED AT DATE, HOPPER PRIORITY,
 * ADD TO HOPPER, DESIRED STATUS, LIST ID, HOPPER CAMPAIGN ID
 */

// ======================
// 1) CONFIGURACIÓN
// ======================
const API_URL   = 'https://talkhubservices.vicihost.com/vicidial/non_agent_api.php';
const API_USER  = '6666';
const API_PASS  = 'T7athsol';
const API_SOURCE= 'CSV_UPD';

// Comportamiento API
const DEFAULT_SEARCH_METHOD   = 'PHONE_NUMBER_VENDOR_LEAD_CODE'; // PHONE_NUMBER | VENDOR_LEAD_CODE | PHONE_NUMBER_VENDOR_LEAD_CODE
const DEFAULT_SEARCH_LOCATION = 'SYSTEM'; // LIST | CAMPAIGN | SYSTEM
const INSERT_IF_NOT_FOUND     = 'Y';
const UPDATE_PHONE_NUMBER     = 'Y';

// Defaults de datos
const DEFAULT_LIST_ID         = '121';  // Debe existir y pertenecer a la campaña deseada
const DEFAULT_STATUS          = 'NEW';
const DEFAULT_PRIORITY        = 99;     // 0..99
const DEFAULT_PHONE_CODE      = '1';    // US/CAN=1, MX=52
const DEFAULT_HOPPER_PRIORITY = 99;     // 0..99

// Hopper (por defecto NO empujar)
const PUSH_TO_HOPPER          = true;  // el empuje se controla por fila con "ADD TO HOPPER"=Y
const HOPPER_CAMPAIGN_ID      = 'OneCamp';  // Usada si la fila no trae HOPPER CAMPAIGN ID

// Reintentos básicos opcionales (estable)
const RATE_LIMIT_MS           = 300;    // pausa entre filas

// Estatus que NO deben entrar al hopper
$DNC_STATUSES = ['DNC','DNCL','XDNC','DNCX'];

// ¿Hacer un segundo intento por lead_id para anclar status si aplica?
const FORCE_STATUS_BY_LEAD_ID_IF_AVAILABLE = true;

// Campos permitidos por VICIdial (subset común)
$ALLOWLIST_FIELDS = [
  'lead_id','vendor_lead_code','source_id','list_id','phone_number','phone_code','title',
  'first_name','middle_initial','last_name','address1','address2','address3','city','state',
  'province','postal_code','country_code','date_of_birth','alt_phone','email','security_phrase',
  'comments','status','user','entry_date','gmt_offset_now','called_since_last_reset','rank',
  'owner','entry_list_id','priority','gender','hopper_priority'
];

// ======================
// 2) ENTRADA / FLAGS
// ======================
if ($argc < 2) {
  fwrite(STDERR, "Uso: php ".$argv[0]." archivo.csv [--dry-run] [--verbose] [--delim=\";\"]\n");
  exit(1);
}
$csvPath   = $argv[1];
$dryRun    = in_array('--dry-run', $argv, true);
$verbose   = in_array('--verbose', $argv, true);
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

// Parser CREATED AT DATE -> entry_date (YYYY-MM-DD HH:MM:SS)
function parseCreatedAtToEntryDate($s) {
  $s = trim($s);
  if ($s === '') return '';
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
  $ts = strtotime($s);
  if ($ts !== false) return date('Y-m-d H:i:s', $ts);
  return '';
}

// phone_code derivado por country_code si no viene
function derivePhoneCode($csvPhoneCode, $countryCode) {
  $csvPhoneCode = trim((string)$csvPhoneCode);
  if ($csvPhoneCode !== '' && preg_match('/^\d+$/', $csvPhoneCode)) return $csvPhoneCode;
  $cc = strtoupper(trim((string)$countryCode));
  if ($cc === 'US' || $cc === 'USA' || $cc === 'CA' || $cc === 'CAN') return '1';
  if ($cc === 'MX' || $cc === 'MEX') return '52';
  return DEFAULT_PHONE_CODE;
}

// Limitar 0..99
function clamp0_99($n, $default) {
  if ($n === '' || !is_numeric($n)) return $default;
  $n = (int)$n;
  return max(0, min(99, $n));
}

// ======================
// 4) MAPEO DE ENCABEZADOS
// ======================
$HEADER_MAP = [
  'VENDOR LEAD CODE'  => 'vendor_lead_code',
  'SOURCE ID'         => 'source_id',
  'PHONE NUMBER'      => 'phone_number',
  'PHONE CODE'        => 'phone_code',
  'TITLE'             => 'title',
  'FIRST NAME'        => 'first_name',
  'MIDDLE INITIAL'    => 'middle_initial',
  'LAST NAME'         => 'last_name',
  'ADDRESS1'          => 'address1',
  'ADDRESS2'          => 'address2',
  'ADDRESS3'          => 'address3',
  'CITY'              => 'city',
  'STATE'             => 'state',
  'PROVINCE'          => 'province',
  'POSTAL CODE'       => 'postal_code',
  'COUNTRY CODE'      => 'country_code',
  'GENDER'            => 'gender',
  'DATE OF BIRTH'     => 'date_of_birth',
  'ALT PHONE'         => 'alt_phone',
  'EMAIL'             => 'email',
  'SECURITY PHRASE'   => 'security_phrase',
  'COMMENTS'          => 'comments',
  'RANK'              => 'rank',
  'OWNER'             => 'owner',
  'CREATED AT DATE'   => 'entry_date',
  'HOPPER PRIORITY'   => 'hopper_priority',
  // NUEVOS:
  'ADD TO HOPPER'     => 'add_to_hopper',      // Y/N por fila
  'DESIRED STATUS'    => 'desired_status',     // p.ej. NEW, SALE, DNC...
  'LIST ID'           => 'list_id',            // opcional por fila
  'HOPPER CAMPAIGN ID'=> 'hopper_campaign_id', // opcional por fila
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

// Normalizar encabezados
$normalizedHeader = array_map(function($h){
  $h = removeBOM($h);
  return trim($h);
}, $rawHeader);

if ($verbose) {
  echo "Encabezados CSV:\n";
  foreach ($normalizedHeader as $h) echo " - {$h}\n";
  echo "\n";
}

// Mapear encabezados
$headerKeys = [];
foreach ($normalizedHeader as $h) {
  $headerKeys[] = array_key_exists($h, $HEADER_MAP) ? $HEADER_MAP[$h] : null;
}

$lineNum       = 1;
$okCount       = 0;
$errorCount    = 0;
$skippedCount  = 0;
$results       = [];

while (($row = fgetcsv($fh, 0, $delimiter, '"', "\\")) !== false) {
  $lineNum++;

  if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) { continue; }
  if (count($row) < count($headerKeys)) $row = array_pad($row, count($headerKeys), '');

  // Construir arreglo asociativo usando el mapeo
  $assoc = [];
  foreach ($row as $idx => $val) {
    $key = $headerKeys[$idx];
    if ($key === null) continue;
    $assoc[$key] = trim($val);
  }

  // Teléfono
  $phone = normalizePhone(pick($assoc, 'phone_number', ''));
  if ($phone === '') {
    $skippedCount++;
    $results[] = ["line"=>$lineNum, "status"=>"SKIP", "detail"=>"Sin PHONE NUMBER (revisa encabezados/CSV)"];
    continue;
  }

  // list_id (por fila o default)
  $listId = pick($assoc, 'list_id', DEFAULT_LIST_ID);

  // rank -> priority
  $rank = pick($assoc, 'rank', '');
  $priority = DEFAULT_PRIORITY;
  if ($rank !== '' && is_numeric($rank)) {
    $priority = clamp0_99($rank, DEFAULT_PRIORITY);
  } else {
    $rank = null;
  }

  // entry_date
  if (!empty($assoc['entry_date'])) {
    $parsed = parseCreatedAtToEntryDate($assoc['entry_date']);
    $assoc['entry_date'] = $parsed !== '' ? $parsed : null;
  }

  // phone_code (CSV -> país -> default)
  $phoneCode = derivePhoneCode(pick($assoc, 'phone_code', ''), pick($assoc, 'country_code', ''));

  // hopper_priority (CSV -> rank/priority -> default)
  $hopperPriorityCsv = pick($assoc, 'hopper_priority', '');
  if ($hopperPriorityCsv === '' || !is_numeric($hopperPriorityCsv)) {
    $hopperPriority = clamp0_99($priority, DEFAULT_HOPPER_PRIORITY);
  } else {
    $hopperPriority = clamp0_99($hopperPriorityCsv, DEFAULT_HOPPER_PRIORITY);
  }

  // status deseado por fila (o default)
  $desiredStatusCsv = strtoupper(trim((string)pick($assoc, 'desired_status', '')));
  $status = $desiredStatusCsv !== '' ? $desiredStatusCsv : DEFAULT_STATUS;

  // Empuje condicional por fila
  $wantPush = false;
  $pushCsv = strtoupper(trim((string)pick($assoc, 'add_to_hopper', '')));
  if ($pushCsv === 'Y') $wantPush = true;

  // Nunca empujar si status es DNC-like
  global $DNC_STATUSES;
  if (in_array($status, $DNC_STATUSES, true)) $wantPush = false;

  // search_method en mayúsculas
  $searchMethod = strtoupper(DEFAULT_SEARCH_METHOD);

  // Parámetros base
  $params = [
    'source'              => API_SOURCE,
    'user'                => API_USER,
    'pass'                => API_PASS,
    'function'            => 'update_lead',
    'search_method'       => $searchMethod,
    'search_location'     => DEFAULT_SEARCH_LOCATION,
    'insert_if_not_found' => INSERT_IF_NOT_FOUND,
    'update_phone_number' => UPDATE_PHONE_NUMBER,

    'phone_number'        => $phone,
    'phone_code'          => $phoneCode,
    'status'              => $status,
    'priority'            => $priority,
    'list_id'             => $listId,
  ];

  // Pasar campos permitidos del CSV (evitar duplicados ya seteados)
  foreach ($assoc as $k => $v) {
    if ($v === '' || $v === null) continue;
    if (in_array($k, $GLOBALS['ALLOWLIST_FIELDS'], true)) {
      if ($k === 'phone_number' || $k === 'priority' || $k === 'list_id') continue;
      if ($k === 'rank' && !is_numeric($v)) continue;
      if ($k === 'entry_date' && $assoc['entry_date'] === null) continue; // si no parseó, no lo mandes
      $params[$k] = $v;
    }
  }

  // Hopper condicional por fila + bloqueo DNC
  if (PUSH_TO_HOPPER || $wantPush) {
    // Campaña por fila o default
    $campaignId = pick($assoc, 'hopper_campaign_id', HOPPER_CAMPAIGN_ID);
    // Si el status es DNC, forzar NO empujar
    if (!in_array($status, $DNC_STATUSES, true)) {
      $params['add_to_hopper']   = 'Y';
      $params['campaign_id']     = $campaignId;
      if (!empty($listId)) $params['entry_list_id'] = $listId;
      $params['hopper_priority'] = $hopperPriority;
    }
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

  // Segundo intento por LEAD_ID para anclar status (si aplica)
  if (
    !$err &&
    stripos((string)$response, 'SUCCESS') !== false &&
    FORCE_STATUS_BY_LEAD_ID_IF_AVAILABLE
  ) {
    if (preg_match('/SUCCESS:\s*update_lead\s+(\d+)/i', (string)$response, $m)) {
      $leadId = $m[1];

      // re-disparo por lead_id para fijar status sin empujar si es DNC
      $params2 = [
        'source'              => API_SOURCE,
        'user'                => API_USER,
        'pass'                => API_PASS,
        'function'            => 'update_lead',
        'insert_if_not_found' => 'N',
        'update_phone_number' => 'N',
        'lead_id'             => $leadId,
        'status'              => $status,
        'list_id'             => $listId,
      ];

      // mantener empuje si correspondía y no es DNC
      if ((PUSH_TO_HOPPER || $wantPush) && !in_array($status, $DNC_STATUSES, true)) {
        $campaignId = pick($assoc, 'hopper_campaign_id', HOPPER_CAMPAIGN_ID);
        $params2['add_to_hopper']   = 'Y';
        $params2['campaign_id']     = $campaignId;
        $params2['entry_list_id']   = $listId;
        $params2['hopper_priority'] = $hopperPriority;
      }

      $url2 = buildApiUrl($params2);
      if ($verbose) {
        echo "---- línea $lineNum (2nd PASS by LEAD_ID) ----\n$url2\n";
      }
      [$response2, $err2, $code2] = curlGet($url2);
      if ($verbose) {
        echo "HTTP: $code2\n";
        if ($err2) echo "cURL error: $err2\n";
        echo "Respuesta: " . trim((string)$response2) . "\n\n";
      }
    }
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
