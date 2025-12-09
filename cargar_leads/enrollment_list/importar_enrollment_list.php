<?php
/***** CONFIG *****/
$host    = "127.0.0.1";
$port    = 3307;                           // <-- tu puerto
$dbname  = "welcome_tch";                  // <-- tu BD
$user    = "root";
$pass    = "admin";
$csvFile = "./enrollment_08-12-2025.csv";  // <-- ruta al CSV (absoluta o relativa)
$batchSize = 1000;

/***** Utils *****/
function detectDelimiter(string $firstLine): string {
    $candidates = [",", ";", "\t", "|"];
    $best = ","; $maxParts = 0;
    foreach ($candidates as $d) {
        $parts = str_getcsv($firstLine, $d, '"', "\\");
        if (count($parts) > $maxParts) { $maxParts = count($parts); $best = $d; }
    }
    return $best;
}

// Normaliza fechas a 'Y-m-d' (vacío, serial Excel, dd/mm/yyyy[ hh:mm[:ss]], yyyy-mm-dd[ hh:mm[:ss]])
function normalizeDate(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;

    // Excel serial (días desde 1899-12-30)
    if (preg_match('~^\d+(\.\d+)?$~', $v)) {
        $parts = explode('.', $v, 2);
        $days = (int)$parts[0];
        if ($days >= 1 && $days <= 100000) {
            $base = new DateTime('1899-12-30');
            $base->modify("+{$days} days");
            return $base->format('Y-m-d');
        }
    }

    // quita AM/PM
    $vClean = preg_replace('~\s*(a\.?\s?m\.?|p\.?\s?m\.?|AM|PM)$~iu', '', $v);

    // dd/mm/yyyy [hh:mm[:ss]]
    if (preg_match('~^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})(?:\s+\d{1,2}:\d{2}(?::\d{2})?)?$~', $vClean, $m)) {
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

    return null;
}

// Normaliza hora a 'H:i:s' (acepta serial Excel en fracción de día o textos 'hh:mm[:ss]')
function normalizeTime(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;

    // Excel serial fraccional (tiempo = fracción de día)
    if (is_numeric($v)) {
        $f = floatval($v);
        if ($f >= 0 && $f < 1) {
            $secs = (int)round($f * 86400); // segundos en el día
            $h = intdiv($secs, 3600);
            $m = intdiv($secs % 3600, 60);
            $s = $secs % 60;
            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        }
    }

    // hh:mm[:ss] con posibles AM/PM
    if (preg_match('~^\s*(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM|am|pm|a\.?m\.?|p\.?m\.?)?\s*$~', $v, $m)) {
        $h = (int)$m[1]; $i = (int)$m[2]; $s = isset($m[3]) ? (int)$m[3] : 0;
        $ampm = isset($m[4]) ? strtolower($m[4]) : null;
        if ($ampm) {
            $isPM = in_array($ampm, ['pm','p.m.','p.m','p m','p']);
            $isAM = in_array($ampm, ['am','a.m.','a.m','a m','a']);
            if ($isPM && $h < 12) $h += 12;
            if ($isAM && $h == 12) $h = 0;
        }
        if ($h>=0 && $h<24 && $i>=0 && $i<60 && $s>=0 && $s<60) {
            return sprintf('%02d:%02d:%02d', $h, $i, $s);
        }
    }

    return null;
}

// Teléfono -> solo dígitos (ajusta rangos si hace falta)
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

    // Detectar delimitador
    $raw = file_get_contents($csvFile, false, null, 0, 4096);
    if ($raw === false) throw new RuntimeException("No se pudo leer el archivo.");
    $firstLine = strtok($raw, "\r\n");
    if ($firstLine === false) throw new RuntimeException("El archivo está vacío.");

    $delim = detectDelimiter($firstLine);
    echo "🔎 Delimitador detectado: " . ($delim === "\t" ? "\\t" : $delim) . "\n";

    $fh = fopen($csvFile, 'r');
    if ($fh === false) throw new RuntimeException("No se pudo abrir el CSV.");

    // Encabezados (con $escape para evitar Deprecated)
    $headers = fgetcsv($fh, 0, $delim, '"', '\\');
    if ($headers === false) throw new RuntimeException("No se pudo leer encabezados.");

    // Mapeo por nombre (insensible a mayúsculas/minúsculas, recorta espacios)
    $map = [];
    foreach ($headers as $i => $h) {
        $key = strtolower(trim($h));
        $map[$key] = $i;
    }

    // Aliases de columnas
    $need = [
        'plan_type'   => ['plan type','plan','tipo plan','tipo de plan'],
        'enr_date'    => ['enrollment date','date','fecha','fecha inscripcion','fecha inscripción'],
        'phone'       => ['phone number','phone','telefono','tel','mobile','cell'],
        'enr_time'    => ['enrollment time','time','hora'],
        'coupon'      => ['coupon code','coupon','cupon','cupón','codigo','código'],
        'state'       => ['state','estado'],
        'user_id'     => ['user id','user_id','userid','id usuario'],
        'bundle'      => ['subscription bundle name','bundle','paquete','plan bundle'],
        'activity'    => ['activity type','activity','tipo actividad','tipo de actividad']
    ];

    // Resolver índices
    $idx = [];
    foreach ($need as $k => $aliases) {
        $found = null;
        foreach ($aliases as $alias) {
            $key = strtolower($alias);
            if (array_key_exists($key, $map)) { $found = $map[$key]; break; }
        }
        $idx[$k] = $found; // puede quedar null si falta en el CSV
    }

    // Muestra mapeo
    echo "🧭 Mapeo columnas:\n";
    foreach ($idx as $k => $v) {
        echo "  - $k -> " . ($v === null ? 'NO ENCONTRADA' : "col {$v} (‘{$headers[$v]}’)") . "\n";
    }

    // Requeridos mínimos: phone + enrollment_date
    if ($idx['phone'] === null)  throw new RuntimeException("No se encontró la columna de teléfono.");
    if ($idx['enr_date'] === null) echo "⚠️ No se detectó 'Enrollment Date'; intentaré normalizar si viene con otro alias.\n";

    // Preparar INSERT
    $sql = "INSERT INTO enrollment_list (
                plan_type, enrollment_date, phone_number, enrollment_time,
                coupon_code, state, user_id, subscription_bundle_name, activity_type
            ) VALUES (
                :plan_type, :enrollment_date, :phone_number, :enrollment_time,
                :coupon_code, :state, :user_id, :subscription_bundle_name, :activity_type
            )";
    $stmt = $pdo->prepare($sql);

    // Contadores
    $inserted=0; $processed=0; $skipped=0;
    $skipReasons = [ 'no_phone'=>0, 'bad_phone'=>0, 'no_date'=>0, 'pdo_error'=>0 ];

    $pdo->beginTransaction();

    // Muestras
    $samples = 0;

    while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $processed++;

        $get = function($key) use ($idx, $row) {
            $i = $idx[$key];
            return ($i === null || !array_key_exists($i, $row)) ? null : $row[$i];
        };

        $plan_type = $get('plan_type');
        $date_raw  = $get('enr_date');
        $phone_raw = $get('phone');
        $time_raw  = $get('enr_time');
        $coupon    = $get('coupon');
        $state     = $get('state');
        $user_raw  = $get('user_id');
        $bundle    = $get('bundle');
        $activity  = $get('activity');

        // Requeridos: phone + date
        if ($phone_raw === null || trim($phone_raw) === '') { $skipped++; $skipReasons['no_phone']++; continue; }
        $phone = sanitizePhone($phone_raw, 6, 32);
        if ($phone === null) { $skipped++; $skipReasons['bad_phone']++; continue; }

        $enrollment_date = normalizeDate($date_raw);
        if ($enrollment_date === null) { $skipped++; $skipReasons['no_date']++; continue; }

        // Hora (opcional)
        $enrollment_time = normalizeTime($time_raw);

        // user_id entero (opcional)
        $user_id = null;
        if ($user_raw !== null && trim($user_raw) !== '') {
            $uid = preg_replace('~\D+~', '', $user_raw);
            if ($uid !== '') $user_id = (int)$uid;
        }

        try {
            $stmt->execute([
                ':plan_type'               => $plan_type !== null ? trim($plan_type) : null,
                ':enrollment_date'         => $enrollment_date,
                ':phone_number'            => $phone,
                ':enrollment_time'         => $enrollment_time,
                ':coupon_code'             => $coupon !== null ? trim($coupon) : null,
                ':state'                   => $state !== null ? trim($state) : null,
                ':user_id'                 => $user_id,
                ':subscription_bundle_name'=> $bundle !== null ? trim($bundle) : null,
                ':activity_type'           => $activity !== null ? trim($activity) : null,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $skipped++; $skipReasons['pdo_error']++;
            // file_put_contents('errores_enrollment.txt', "Fila ".($processed+1).": ".$e->getMessage()."\n", FILE_APPEND);
        }

        if (($inserted + $skipped) % $batchSize === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
            echo "✔️  Procesadas: ".($inserted + $skipped)." | Insertadas: $inserted | Saltadas: $skipped\n";
        }

        // Muestra 3 ejemplo(s) parseados
        if ($samples < 3) {
            $samples++;
            echo "🧪 Ej {$samples}: phone='{$phone_raw}' -> '{$phone}', date='{$date_raw}' -> '{$enrollment_date}', time='{$time_raw}' -> '".($enrollment_time ?? 'NULL')."'\n";
        }
    }

    $pdo->commit();
    fclose($fh);

    echo "🎯 Terminado. Insertadas: $inserted | Saltadas: $skipped de $processed procesadas\n";
    echo "📊 Razones de filas saltadas: " . json_encode($skipReasons) . "\n";



} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}