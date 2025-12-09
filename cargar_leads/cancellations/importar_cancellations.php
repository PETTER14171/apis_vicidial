<?php
/***** CONFIG *****/
$host    = "127.0.0.1";
$port    = 3307;                           // <-- tu puerto
$dbname  = "welcome_tch";                  // <-- tu BD
$user    = "root";
$pass    = "admin";
$csvFile = "./cancellations_08-12-2025.csv";  // <-- ruta a tu CSV
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

// Normaliza fechas a 'Y-m-d' (acepta: vacío, serial Excel, dd/mm/yyyy[ hh:mm[:ss] AM/PM], yyyy-mm-dd[ hh:mm[:ss]])
function normalizeDate(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    if ($v === '') return null;

    // Excel serial
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

// Teléfono a solo dígitos; rango flexible
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

    // Lee encabezados (con $escape para evitar deprecated)
    $headers = fgetcsv($fh, 0, $delim, '"', '\\');
    if ($headers === false) throw new RuntimeException("No se pudo leer encabezados.");

    // Mapeo por nombre (case-insensitive)
    $map = [];
    foreach ($headers as $i => $h) {
        $key = strtolower(trim($h));
        $map[$key] = $i;
    }

    // Aliases esperados
    $need = [
        'cancel_date' => ['cancellation date','cancel date','fecha cancelación','fecha cancelacion'],
        'user_id'     => ['user id','user_id','userid','id usuario'],
        'first_name'  => ['first name','first_name','nombre','first'],
        'last_name'   => ['last name','last_name','apellido','last'],
        'phone'       => ['phone number','phone','telefono','tel','mobile','cell'],
        'email'       => ['email address','email','correo','mail'],
        'length_days' => ['subscription length (days)','subscription length days','length days','dias suscripcion','dias suscripción'],
        'first_act'   => ['first activation date','activation date','first activation','fecha activacion','fecha activación'],
        'status'      => ['status','estado','motivo']
    ];

    // Resolver índices por encabezado
    $idx = [];
    foreach ($need as $k => $aliases) {
        $found = null;
        foreach ($aliases as $alias) {
            $key = strtolower($alias);
            if (array_key_exists($key, $map)) { $found = $map[$key]; break; }
        }
        $idx[$k] = $found;
    }

    // Mostrar mapeo
    echo "🧭 Mapeo columnas:\n";
    foreach ($idx as $k => $v) {
        echo "  - $k -> " . ($v === null ? 'NO ENCONTRADA' : "col {$v} (‘{$headers[$v]}’)") . "\n";
    }

    // Validaciones mínimas: teléfono y fecha de cancelación son requeridos en la tabla
    if ($idx['phone'] === null)  throw new RuntimeException("No se encontró la columna de teléfono.");
    if ($idx['cancel_date'] === null) echo "⚠️ No se detectó 'Cancellation Date' en encabezados; intentaré normalizar si existe en otra forma.\n";

    // Preparar INSERT
    $sql = "INSERT INTO cancellations (
                cancellation_date, user_id, first_name, last_name, phone_number,
                email_address, subscription_length_days, first_activation_date, status
            ) VALUES (
                :cancellation_date, :user_id, :first_name, :last_name, :phone_number,
                :email_address, :subscription_length_days, :first_activation_date, :status
            )";
    $stmt = $pdo->prepare($sql);

    // Contadores
    $inserted=0; $processed=0; $skipped=0;
    $skipReasons = [
        'no_phone'   => 0,
        'bad_phone'  => 0,
        'no_cancel'  => 0,
        'pdo_error'  => 0,
    ];

    $pdo->beginTransaction();

    // Muestras
    $samples = 0;

    while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $processed++;

        $get = function($key) use ($idx, $row) {
            $i = $idx[$key];
            return ($i === null || !array_key_exists($i, $row)) ? null : $row[$i];
        };

        $cancel_raw = $get('cancel_date');
        $uid_raw    = $get('user_id');
        $fname      = $get('first_name');
        $lname      = $get('last_name');
        $phone_raw  = $get('phone');
        $email      = $get('email');
        $len_raw    = $get('length_days');
        $first_act  = $get('first_act');
        $status     = $get('status');

        // Requeridos: phone y cancellation_date
        if ($phone_raw === null || trim($phone_raw) === '') { $skipped++; $skipReasons['no_phone']++; continue; }
        $phone = sanitizePhone($phone_raw, 6, 32);
        if ($phone === null) { $skipped++; $skipReasons['bad_phone']++; continue; }

        $cancel_date = normalizeDate($cancel_raw);
        if ($cancel_date === null) { $skipped++; $skipReasons['no_cancel']++; continue; }

        // first_activation_date (opcional)
        $first_activation_date = normalizeDate($first_act);

        // user_id numérico o null
        $user_id = null;
        if ($uid_raw !== null && trim($uid_raw) !== '') {
            $uid = preg_replace('~\D+~', '', $uid_raw);
            if ($uid !== '') $user_id = (int)$uid;
        }

        // subscription_length_days entero
        $subscription_length_days = null;
        if ($len_raw !== null && trim($len_raw) !== '') {
            $n = preg_replace('~[^\d-]+~', '', $len_raw);
            if ($n !== '' && is_numeric($n)) $subscription_length_days = (int)$n;
        }

        try {
            $stmt->execute([
                ':cancellation_date'       => $cancel_date,
                ':user_id'                 => $user_id,
                ':first_name'              => $fname !== null ? trim($fname) : null,
                ':last_name'               => $lname !== null ? trim($lname) : null,
                ':phone_number'            => $phone,
                ':email_address'           => $email !== null ? trim($email) : null,
                ':subscription_length_days'=> $subscription_length_days,
                ':first_activation_date'   => $first_activation_date,
                ':status'                  => $status !== null ? trim($status) : null,
            ]);
            $inserted++;
        } catch (PDOException $e) {
            $skipped++; $skipReasons['pdo_error']++;
            // file_put_contents('errores_cancellations.txt', "Fila ".($processed+1).": ".$e->getMessage()."\n", FILE_APPEND);
        }

        if (($inserted + $skipped) % $batchSize === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
            echo "✔️  Procesadas: ".($inserted + $skipped)." | Insertadas: $inserted | Saltadas: $skipped\n";
        }

        if ($samples < 3) {
            $samples++;
            echo "🧪 Ejemplo {$samples}: phone='{$phone_raw}' -> '{$phone}', cancel='{$cancel_raw}' -> '{$cancel_date}', first_act='{$first_act}' -> '{$first_activation_date}'\n";
        }
    }

    $pdo->commit();
    fclose($fh);

    echo "🎯 Terminado. Insertadas: $inserted | Saltadas: $skipped de $processed procesadas\n";
    echo "📊 Razones de filas saltadas: " . json_encode($skipReasons) . "\n";

} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
