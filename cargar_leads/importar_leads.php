<?php
/***** CONFIG *****/
$host    = "127.0.0.1";
$port    = 3307;                                   // <-- tu puerto
$dbname  = "welcome_tch";                     // <-- cámbialo
$user    = "root";
$pass    = "admin";
$csvFile = "./Poder_salud_08-12-2025.csv";       // <-- ruta (usa absoluta si prefieres)
$batchSize = 1000;

/***** Utils *****/
function detectDelimiter(string $firstLine): string {
    $candidates = [",", ";", "\t", "|"];
    $best = ","; $maxParts = 0;
    foreach ($candidates as $d) {
        // cuenta columnas de forma simple
        $parts = str_getcsv($firstLine, $d, '"', "\\");
        if (count($parts) > $maxParts) { $maxParts = count($parts); $best = $d; }
    }
    return $best;
}

// Normaliza fechas a 'Y-m-d' (acepta: vacío, serial Excel, dd/mm/yyyy[ hh:mm[:ss] AM/PM], yyyy-mm-dd[ hh:mm:ss])
function normalizeDate(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;

    // Excel serial integer/decimal
    if (preg_match('~^\d+(\.\d+)?$~', $v)) {
        $parts = explode('.', $v, 2);
        $days = (int)$parts[0];
        if ($days >= 1 && $days <= 100000) {
            $base = new DateTime('1899-12-30');
            $base->modify("+{$days} days");
            return $base->format('Y-m-d');
        }
    }

    // limpia AM/PM español/inglés
    $vClean = preg_replace('~\s*(a\.?\s?m\.?|p\.?\s?m\.?|AM|PM)$~iu', '', $v);

    // dd/mm/yyyy [hh:mm[:ss]]
    if (preg_match('~^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$~', $vClean, $m)) {
        $d=(int)$m[1]; $M=(int)$m[2]; $Y=(int)$m[3];
        if ($Y < 100) { $Y += ($Y >= 70 ? 1900 : 2000); }
        if ($d>=1 && $d<=31 && $M>=1 && $M<=12) {
            return sprintf('%04d-%02d-%02d', $Y, $M, $d);
        }
    }

    // yyyy-mm-dd [hh:mm[:ss]]
    if (preg_match('~^(\d{4})-(\d{2})-(\d{2})(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?$~', $vClean, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }

    return null; // no reconocida
}

// limpia teléfono a dígitos; regla flexible (ajusta $min/$max si hace falta)
function sanitizePhone(?string $raw, int $min=6, int $max=32): ?string {
    if ($raw === null) return null;
    $digits = preg_replace('~\D+~', '', $raw);
    if ($digits === null) return null;
    $len = strlen($digits);
    if ($len < $min || $len > $max) return null;
    return $digits;
}

/***** MAIN *****/
try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ]);

    echo "✅ Conexión establecida correctamente\n";

    if (!is_file($csvFile)) {
        throw new RuntimeException("El archivo no existe: {$csvFile}");
    }

    // Leemos la primera línea cruda para detectar delimitador
    $raw = file_get_contents($csvFile, false, null, 0, 4096);
    if ($raw === false) throw new RuntimeException("No se pudo leer el archivo.");
    $firstLine = strtok($raw, "\r\n");
    if ($firstLine === false) throw new RuntimeException("El archivo está vacío.");

    $delim = detectDelimiter($firstLine);
    echo "🔎 Delimitador detectado: " . ($delim === "\t" ? "\\t" : $delim) . "\n";

    $fh = fopen($csvFile, 'r');
    if ($fh === false) throw new RuntimeException("No se pudo abrir el CSV.");

    // Lee encabezados con el delimitador detectado (incluye $escape para evitar Deprecated)
    $headers = fgetcsv($fh, 0, $delim, '"', '\\');
    if ($headers === false) throw new RuntimeException("No se pudo leer encabezados.");

    // Mapeo por nombre (case-insensitive, recorta espacios)
    $map = [];
    foreach ($headers as $i => $h) {
        $key = strtolower(trim($h));
        $map[$key] = $i;
    }

    // nombres esperados (intenta variantes)
    $need = [
        'clicked'  => ['clicked at date','clicked_at_date','clicked at','clicked'],
        'userid'   => ['user id','user_id','userid','id usuario'],
        'fname'    => ['first name cleaned','first name','first_name','first_name_cleaned'],
        'lname'    => ['last name cleaned','last name','last_name','last_name_cleaned'],
        'phone'    => ['phone number','phone','tel','telefono'],
        'city'     => ['city','ciudad'],
        'state'    => ['state','estado'],
        'created'  => ['created at date','created at','created_at_date','created'],
        'lang'     => ['language','lang','idioma'],
        'smg'      => ['simplified marketing group','marketing group','group','smg'],
    ];

    // resuelve índices por encabezados
    $idx = [];
    foreach ($need as $k => $aliases) {
        $found = null;
        foreach ($aliases as $alias) {
            $key = strtolower($alias);
            if (array_key_exists($key, $map)) { $found = $map[$key]; break; }
        }
        $idx[$k] = $found; // puede quedar null si no existe
    }

    // Muestra mapeo encontrado
    echo "🧭 Mapeo columnas:\n";
    foreach ($idx as $k => $v) {
        echo "  - $k -> " . ($v === null ? 'NO ENCONTRADA' : "col {$v} (‘{$headers[$v]}’)") . "\n";
    }

    // Si falta 'phone', no podemos continuar
    if ($idx['phone'] === null) {
        throw new RuntimeException("No se encontró la columna de teléfono. Revisa encabezados.");
    }

    // Preparar insert
    $sql = "INSERT INTO leads_08122025 (
                clicked_at_date, user_id, first_name_cleaned, last_name_cleaned,
                phone_number, city, state, created_at_date, language, simplified_marketing_group
            ) VALUES (
                :clicked_at_date, :user_id, :first_name, :last_name,
                :phone, :city, :state, :created_at, :lang, :smg
            )";
    $stmt = $pdo->prepare($sql);

    // Contadores de diagnóstico
    $inserted=0; $processed=0; $skipped=0;
    $skipReasons = [
        'no_phone' => 0,
        'bad_phone'=> 0,
        'pdo_error'=> 0,
    ];

    $pdo->beginTransaction();

    // Imprime 3 filas de muestra (para validar parseo)
    $samples = 0;

    while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $processed++;

        // toma valores por índice si existe, si no null
        $get = function($key) use ($idx, $row) {
            $i = $idx[$key];
            return ($i === null || !array_key_exists($i, $row)) ? null : $row[$i];
        };

        $clicked_raw = $get('clicked');
        $user_raw    = $get('userid');
        $fname       = $get('fname');
        $lname       = $get('lname');
        $phone_raw   = $get('phone');
        $city        = $get('city');
        $state       = $get('state');
        $created_raw = $get('created');
        $lang        = $get('lang');
        $smg         = $get('smg');

        // Teléfono requerido
        if ($phone_raw === null || trim($phone_raw) === '') {
            $skipped++; $skipReasons['no_phone']++; continue;
        }
        $phone = sanitizePhone($phone_raw, 6, 32);
        if ($phone === null) {
            $skipped++; $skipReasons['bad_phone']++; continue;
        }

        // Fechas (permiten null si no se reconocen)
        $clicked_at  = normalizeDate($clicked_raw);
        $created_at  = normalizeDate($created_raw);

        // user_id numérico o null
        $user_id = null;
        if ($user_raw !== null && trim($user_raw) !== '') {
            $uid = preg_replace('~\D+~', '', $user_raw);
            if ($uid !== '') $user_id = (int)$uid;
        }

        try {
            $stmt->execute([
                ':clicked_at_date' => $clicked_at,
                ':user_id'         => $user_id,
                ':first_name'      => $fname !== null ? trim($fname) : null,
                ':last_name'       => $lname !== null ? trim($lname) : null,
                ':phone'           => $phone,
                ':city'            => $city  !== null ? trim($city)  : null,
                ':state'           => $state !== null ? trim($state) : null,
                ':created_at'      => $created_at,
                ':lang'            => $lang  !== null ? trim($lang)  : null,
                ':smg'             => $smg   !== null ? trim($smg)   : null,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $skipped++; $skipReasons['pdo_error']++;
            // Descomenta si quieres log:
            // file_put_contents('errores_import.txt', "Fila ".($processed+1).": ".$e->getMessage()."\n", FILE_APPEND);
        }

        if (($inserted + $skipped) % $batchSize === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
            echo "✔️  Procesadas: ".($inserted + $skipped)." | Insertadas: $inserted | Saltadas: $skipped\n";
        }

        // Muestra 3 ejemplos parseados al inicio
        if ($samples < 3) {
            $samples++;
            echo "🧪 Ejemplo {$samples}: phone='{$phone_raw}' -> '{$phone}', clicked='{$clicked_raw}' -> '{$clicked_at}', created='{$created_raw}' -> '{$created_at}'\n";
        }
    }

    $pdo->commit();
    fclose($fh);

    echo "🎯 Terminado. Insertadas: $inserted | Saltadas: $skipped de $processed procesadas\n";
    echo "📊 Razones de filas saltadas: " . json_encode($skipReasons) . "\n";

} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
