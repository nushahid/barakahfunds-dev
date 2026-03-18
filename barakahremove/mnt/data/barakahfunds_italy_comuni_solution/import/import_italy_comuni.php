<?php
require_once __DIR__ . '/../includes/functions.php';
startSecureSession();
require_once __DIR__ . '/../includes/db.php';
requireRole($pdo, ['admin', 'operator']);

set_time_limit(0);
ini_set('memory_limit', '512M');

function normalizeComune(string $value): string
{
    $value = trim($value);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?: $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?: $value);
}

function detectDelimiter(string $line): string
{
    $delimiters = [',', ';', "\t", '|'];
    $best = ',';
    $bestCount = -1;
    foreach ($delimiters as $delimiter) {
        $count = substr_count($line, $delimiter);
        if ($count > $bestCount) {
            $bestCount = $count;
            $best = $delimiter;
        }
    }
    return $best;
}

function mapHeader(array $header): array
{
    $aliases = [
        'istat_code' => ['istat_code', 'codice', 'codice_istat', 'codiceistat', 'codice comune formato numerico', 'codice comune numerico con 110 province'],
        'comune_name' => ['comune_name', 'comune', 'nome', 'denominazione in italiano', 'denominazione_it', 'denominazione (ita e straniera)', 'denominazione in italiano e in tedesco', 'denominazione'],
        'province_code' => ['province_code', 'provincia_sigla', 'sigla', 'sigla automobilistica', 'sigla_provincia'],
        'province_name' => ['province_name', 'provincia', 'denominazione provincia', 'citta metropolitana/provincia', 'provincia_nome'],
        'region_code' => ['region_code', 'codice_regione', 'regione_codice'],
        'region_name' => ['region_name', 'regione', 'denominazione regione', 'regione_nome'],
        'cadastral_code' => ['cadastral_code', 'codice_catastale', 'codice catastale del comune', 'codice catastale'],
        'cap' => ['cap', 'caps', 'codice di avviamento postale'],
        'is_active' => ['is_active', 'attivo', 'active'],
    ];

    $normalized = [];
    foreach ($header as $idx => $value) {
        $key = strtolower(trim((string)$value));
        $key = preg_replace('/\s+/', ' ', $key) ?: $key;
        $normalized[$idx] = $key;
    }

    $mapping = [];
    foreach ($aliases as $target => $options) {
        foreach ($normalized as $idx => $key) {
            if (in_array($key, $options, true)) {
                $mapping[$target] = $idx;
                break;
            }
        }
    }

    if (!isset($mapping['istat_code']) || !isset($mapping['comune_name'])) {
        throw new RuntimeException('Required columns not found. CSV must contain at least istat_code and comune_name (or equivalent headers).');
    }

    return $mapping;
}

function rowValue(array $row, array $mapping, string $key): string
{
    if (!isset($mapping[$key])) {
        return '';
    }
    return trim((string)($row[$mapping[$key]] ?? ''));
}

$message = '';
$stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfOrFail();

    if (empty($_FILES['dataset']['tmp_name']) || !is_uploaded_file($_FILES['dataset']['tmp_name'])) {
        $message = 'Choose a CSV file first.';
    } else {
        $tmp = $_FILES['dataset']['tmp_name'];
        $fh = fopen($tmp, 'rb');
        if (!$fh) {
            $message = 'Unable to open uploaded file.';
        } else {
            $firstLine = fgets($fh);
            if ($firstLine === false) {
                fclose($fh);
                $message = 'The uploaded file is empty.';
            } else {
                $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine) ?? $firstLine;
                $delimiter = detectDelimiter($firstLine);
                rewind($fh);

                $header = fgetcsv($fh, 0, $delimiter);
                if (!$header) {
                    fclose($fh);
                    $message = 'Unable to read CSV header.';
                } else {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]) ?? (string)$header[0];

                    try {
                        $mapping = mapHeader($header);

                        $pdo->beginTransaction();
                        $sql = 'INSERT INTO italy_comuni
                            (istat_code, comune_name, comune_name_normalized, province_code, province_name, region_code, region_name, cadastral_code, cap, is_active, source_name, source_updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())
                            ON DUPLICATE KEY UPDATE
                            comune_name = VALUES(comune_name),
                            comune_name_normalized = VALUES(comune_name_normalized),
                            province_code = VALUES(province_code),
                            province_name = VALUES(province_name),
                            region_code = VALUES(region_code),
                            region_name = VALUES(region_name),
                            cadastral_code = VALUES(cadastral_code),
                            cap = VALUES(cap),
                            is_active = VALUES(is_active),
                            source_name = VALUES(source_name),
                            source_updated_at = VALUES(source_updated_at)';
                        $stmt = $pdo->prepare($sql);

                        while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                            $istatCode = rowValue($row, $mapping, 'istat_code');
                            $comuneName = rowValue($row, $mapping, 'comune_name');
                            if ($istatCode === '' || $comuneName === '') {
                                $stats['skipped']++;
                                continue;
                            }

                            $provinceCode = rowValue($row, $mapping, 'province_code');
                            $provinceName = rowValue($row, $mapping, 'province_name');
                            $regionCode = rowValue($row, $mapping, 'region_code');
                            $regionName = rowValue($row, $mapping, 'region_name');
                            $cadastralCode = rowValue($row, $mapping, 'cadastral_code');
                            $cap = rowValue($row, $mapping, 'cap');
                            $isActiveRaw = rowValue($row, $mapping, 'is_active');
                            $isActive = $isActiveRaw === '' ? 1 : (int)(in_array(strtolower($isActiveRaw), ['1', 'true', 'yes', 'y', 'active'], true) ? 1 : (is_numeric($isActiveRaw) ? (int)$isActiveRaw : 0));
                            $sourceName = trim((string)($_POST['source_name'] ?? 'manual import'));

                            $stmt->execute([
                                $istatCode,
                                $comuneName,
                                normalizeComune($comuneName),
                                $provinceCode !== '' ? $provinceCode : null,
                                $provinceName !== '' ? $provinceName : null,
                                $regionCode !== '' ? $regionCode : null,
                                $regionName !== '' ? $regionName : null,
                                $cadastralCode !== '' ? $cadastralCode : null,
                                $cap !== '' ? $cap : null,
                                $isActive ? 1 : 0,
                                $sourceName !== '' ? $sourceName : null,
                            ]);

                            if ($stmt->rowCount() === 1) {
                                $stats['inserted']++;
                            } else {
                                $stats['updated']++;
                            }
                        }

                        $pdo->commit();
                        $message = 'Import completed.';
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $message = 'Import failed: ' . $e->getMessage();
                    }

                    fclose($fh);
                }
            }
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<h1 class="title">Import Italy Comuni</h1>
<div class="card stack compact">
    <?php if ($message !== ''): ?>
        <div class="alert <?= str_starts_with($message, 'Import completed') ? 'success' : 'error' ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="stack compact">
        <?= csrfField() ?>
        <div>
            <label>Source Name</label>
            <input type="text" name="source_name" value="ISTAT / ANPR import">
        </div>
        <div>
            <label>CSV File</label>
            <input type="file" name="dataset" accept=".csv,text/csv" required>
            <div class="muted">Accepted headers can be either your own normalized fields or common ISTAT-style labels.</div>
        </div>
        <div class="toolbar">
            <a class="btn" href="../index.php">Back</a>
            <button class="btn btn-primary" type="submit">Import</button>
        </div>
    </form>

    <div class="card stack compact">
        <strong>Last run stats</strong>
        <div>Inserted: <?= (int)$stats['inserted'] ?></div>
        <div>Updated: <?= (int)$stats['updated'] ?></div>
        <div>Skipped: <?= (int)$stats['skipped'] ?></div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
