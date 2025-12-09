<?php
/***** CONFIG *****/
$host    = "127.0.0.1";
$port    = 3307;                           // <-- tu puerto
$dbname  = "welcome_tch";                  // <-- tu BD
$user    = "root";
$pass    = "admin";
$csvFile = "./active_members_08-12-2025.csv";  // <-- ruta al CSV
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

// Teléfono -> solo dígitos (ajusta rangos si requieres)
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

    // Encabezados (con escape para evitar deprecated)
    $headers = fgetcsv($fh, 0, $delim, '"', '\\');
    if ($headers === false) throw new RuntimeException("No se pudo leer encabezados.");

    // Mapeo por nombre (insensible a mayúsculas/minúsculas)
    $map = [];
    foreach ($headers as $i => $h) {
        $key = strtolower(trim($h));
        $map[$key] = $i;
    }

    // Aliases tolerantes
    $need = [
        'created' => ['created at date','created_at_date','created','fecha alta','fecha activación','fecha activacion'],
        'fname'   => ['first name','first_name','nombre','first'],
        'lname'   => ['last name','last_name','apellido','last'],
        'phone'   => ['phone number','phone','telefono','tel','mobile','cell']
    ];

    // Resolver índices
    $idx = [];
    foreach ($need as $k => $aliases) {
        $found = null;
        foreach ($aliases as $alias) {
            $key = strtolower($alias);
            if (array_key_exists($key, $map)) { $found = $map[$key]; break; }
        }
        $idx[$k] = $found;
    }

    echo "🧭 Mapeo columnas:\n";
    foreach ($idx as $k => $v) {
        echo "  - $k -> " . ($v === null ? 'NO ENCONTRADA' : "col {$v} (‘{$headers[$v]}’)") . "\n";
    }

    // Validaciones mínimas
    if ($idx['phone'] === null)  throw new RuntimeException("No se encontró la columna de teléfono.");
    if ($idx['created'] === null) echo "⚠️ No se detectó 'Created At Date'; intentaré normalizar si llega en otra forma.\n";

    // UPSERT: si existe phone, actualiza nombres y conserva la fecha MÁS RECIENTE
    $sql = "INSERT INTO active_members (
                created_at_date, first_name, last_name, phone_number
            ) VALUES (
                :created_at_date, :first_name, :last_name, :phone_number
            )
            ON DUPLICATE KEY UPDATE
                first_name = COALESCE(VALUES(first_name), first_name),
                last_name  = COALESCE(VALUES(last_name), last_name),
                created_at_date = GREATEST(created_at_date, VALUES(created_at_date))";
    $stmt = $pdo->prepare($sql);

    // Contadores
    $inserted=0; $updated=0; $processed=0; $skipped=0;
    $skipReasons = [ 'no_phone'=>0, 'bad_phone'=>0, 'no_created'=>0, 'pdo_error'=>0 ];

    $pdo->beginTransaction();

    // Muestras
    $samples = 0;

    while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
        $processed++;

        $get = function($key) use ($idx, $row) {
            $i = $idx[$key];
            return ($i === null || !array_key_exists($i, $row)) ? null : $row[$i];
        };

        $created_raw = $get('created');
        $fname       = $get('fname');
        $lname       = $get('lname');
        $phone_raw   = $get('phone');

        // Requeridos
        if ($phone_raw === null || trim($phone_raw) === '') { $skipped++; $skipReasons['no_phone']++; continue; }
        $phone = sanitizePhone($phone_raw, 6, 32);
        if ($phone === null) { $skipped++; $skipReasons['bad_phone']++; continue; }

        $created_at = normalizeDate($created_raw);
        if ($created_at === null) { $skipped++; $skipReasons['no_created']++; continue; }

        try {
            $ok = $stmt->execute([
                ':created_at_date' => $created_at,
                ':first_name'      => $fname !== null ? trim($fname) : null,
                ':last_name'       => $lname !== null ? trim($lname) : null,
                ':phone_number'    => $phone,
            ]);
            if ($ok) {
                // Si hubo upsert, PDO no distingue; podemos estimar por rowCount():
                // 1 = insert; 2 = update por ON DUPLICATE KEY
                $rc = $stmt->rowCount();
                if ($rc === 1) $inserted++; else $updated++;
            }
        } catch (PDOException $e) {
            $skipped++; $skipReasons['pdo_error']++;
            // file_put_contents('errores_active_members.txt', "Fila ".($processed+1).": ".$e->getMessage()."\n", FILE_APPEND);
        }

        if (($inserted + $updated + $skipped) % $batchSize === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
            echo "✔️  Procesadas: ".($inserted + $updated + $skipped)." | Insertadas: $inserted | Actualizadas: $updated | Saltadas: $skipped\n";
        }

        if ($samples < 3) {
            $samples++;
            echo "🧪 Ej {$samples}: phone='{$phone_raw}' -> '{$phone}', created='{$created_raw}' -> '{$created_at}'\n";
        }
    }

    $pdo->commit();
    fclose($fh);

    echo "🎯 Terminado. Insertadas: $inserted | Actualizadas: $updated | Saltadas: $skipped de $processed procesadas\n";
    echo "📊 Razones de filas saltadas: " . json_encode($skipReasons) . "\n";

} catch (Throwable $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
