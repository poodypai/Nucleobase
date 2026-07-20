<?php
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'        => $_SESSION['user_id'],
        'username'  => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
    ];
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        setFlash('error', 'Please log in to continue.');
        header('Location: login.php');
        exit;
    }
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function parseFasta(string $content): array
{
    $records = [];
    $lines = preg_split('/\r\n|\r|\n/', trim($content));

    $current = null;

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') {
            continue;
        }

        if ($line[0] === '>') {
            if ($current !== null) {
                $records[] = $current;
            }
            $header = trim(substr($line, 1));
            $parts = preg_split('/\s+/', $header, 2);
            $current = [
                'accession'   => $parts[0] !== '' ? $parts[0] : ('SEQ_' . (count($records) + 1)),
                'description' => $parts[1] ?? '',
                'sequence'    => '',
            ];
        } else {
            if ($current === null) {
                $current = [
                    'accession'   => 'SEQ_' . (count($records) + 1),
                    'description' => '',
                    'sequence'    => '',
                ];
            }
            $current['sequence'] .= preg_replace('/\s+/', '', $line);
        }
    }

    if ($current !== null) {
        $records[] = $current;
    }

    return $records;
}

function isValidNucleotideSequence(string $sequence): bool
{
    return $sequence !== '' && preg_match('/^[ACGTUNRYSWKMBDHV\-]+$/i', $sequence) === 1;
}

function detectSequenceType(string $sequence): string
{
    $hasU = stripos($sequence, 'U') !== false;
    $hasT = stripos($sequence, 'T') !== false;
    return ($hasU && !$hasT) ? 'RNA' : 'DNA';
}

function calculateGcContent(string $sequence): float
{
    $length = strlen($sequence);
    if ($length === 0) {
        return 0.0;
    }
    $gc = preg_match_all('/[GCgc]/', $sequence);
    return round(($gc / $length) * 100, 2);
}

function wrapSequence(string $sequence, int $width = 70): string
{
    return trim(chunk_split($sequence, $width, "\n"));
}

function buildFastaFile(string $accession, string $description, string $sequence): string
{
    $header = '>' . $accession . ($description !== '' ? ' ' . $description : '');
    return $header . "\n" . wrapSequence($sequence) . "\n";
}

function renderColoredSequence(string $sequence, ?int $limit = null, int $lineWidth = 60): string
{
    $seq = $limit !== null ? substr($sequence, 0, $limit) : $sequence;
    $map = ['A' => 'seq-a', 'T' => 'seq-t', 'U' => 'seq-u', 'G' => 'seq-g', 'C' => 'seq-c'];

    $out = '';
    $len = strlen($seq);
    for ($i = 0; $i < $len; $i++) {
        $base = strtoupper($seq[$i]);
        $class = $map[$base] ?? 'seq-n';
        $out .= '<span class="' . $class . '">' . h($seq[$i]) . '</span>';
        if (($i + 1) % $lineWidth === 0) {
            $out .= "\n";
        }
    }

    if ($limit !== null && $limit < strlen($sequence)) {
        $out .= '<span class="text-slate-400">&hellip;</span>';
    }

    return $out;
}

function computeAnnotations(string $sequence, string $sequenceType): array
{
    $annotations = [];
    $seq = strtoupper($sequence);
    $len = strlen($seq);

    if ($len < 3) {
        return $annotations;
    }

    $dnaSeq = ($sequenceType === 'RNA') ? str_replace('U', 'T', $seq) : $seq;

    $minOrfLen = 90;
    $stopCodons = ['TAA', 'TAG', 'TGA'];

    for ($frame = 0; $frame < 3; $frame++) {
        $orfStart = null;
        for ($i = $frame; $i + 2 < $len; $i += 3) {
            $codon = substr($dnaSeq, $i, 3);
            if ($codon === 'ATG' && $orfStart === null) {
                $orfStart = $i;
            } elseif (in_array($codon, $stopCodons, true) && $orfStart !== null) {
                $orfEnd    = $i + 2;
                $orfLength = $orfEnd - $orfStart + 1;
                if ($orfLength >= $minOrfLen) {
                    $codons = (int) ($orfLength / 3);
                    $annotations[] = [
                        'type'   => 'exon',
                        'start'  => $orfStart + 1,
                        'end'    => $orfEnd + 1,
                        'strand' => '+',
                        'label'  => 'ORF frame ' . ($frame + 1),
                        'notes'  => "Open reading frame: $codons codons ($orfLength bp) in reading frame " . ($frame + 1) . '.',
                    ];
                }
                $orfStart = null;
            }
        }
    }

    $pos = 0;
    while (($pos = strpos($dnaSeq, 'TATAAA', $pos)) !== false) {
        $annotations[] = [
            'type'   => 'promoter',
            'start'  => $pos + 1,
            'end'    => $pos + 6,
            'strand' => '+',
            'label'  => 'TATA box',
            'notes'  => 'Putative TATA box motif (TATAAA) detected.',
        ];
        $pos++;
    }

    $enzymes = [
        'EcoRI'   => 'GAATTC',
        'BamHI'   => 'GGATCC',
        'HindIII' => 'AAGCTT',
        'NotI'    => 'GCGGCCGC',
        'XhoI'    => 'CTCGAG',
    ];
    foreach ($enzymes as $name => $site) {
        $pos = 0;
        while (($pos = strpos($dnaSeq, $site, $pos)) !== false) {
            $annotations[] = [
                'type'   => 'binding_site',
                'start'  => $pos + 1,
                'end'    => $pos + strlen($site),
                'strand' => '+',
                'label'  => "$name site",
                'notes'  => "$name restriction enzyme recognition site ($site).",
            ];
            $pos++;
        }
    }

    $windowSize = 50;
    $stepSize   = 25;
    if ($len >= $windowSize) {
        $gcRegions = [];
        for ($i = 0; $i <= $len - $windowSize; $i += $stepSize) {
            $window = substr($seq, $i, $windowSize);
            $gc     = preg_match_all('/[GC]/', $window);
            $gcPct  = ($gc / $windowSize) * 100;
            if ($gcPct > 65) {
                $start = $i;
                $end   = $i + $windowSize - 1;
                if ($gcRegions && $start <= end($gcRegions)['end'] + 1) {
                    $gcRegions[count($gcRegions) - 1]['end'] = max(end($gcRegions)['end'], $end);
                } else {
                    $gcRegions[] = ['start' => $start, 'end' => $end];
                }
            }
        }
        foreach ($gcRegions as $region) {
            $regionLen = $region['end'] - $region['start'] + 1;
            $annotations[] = [
                'type'   => 'other',
                'start'  => $region['start'] + 1,
                'end'    => $region['end'] + 1,
                'strand' => '+',
                'label'  => 'GC-rich region',
                'notes'  => "Region with GC content >65% spanning $regionLen bp.",
            ];
        }
    }

    usort($annotations, fn($a, $b) => $a['start'] - $b['start']);

    return $annotations;
}

function saveComputedAnnotations(int $recordId, int $userId, array $annotations): void
{
    $db = getDB();
    $db->prepare('DELETE FROM sequence_annotations WHERE record_id = ?')
       ->execute([$recordId]);

    if (empty($annotations)) {
        return;
    }

    $stmt = $db->prepare(
        'INSERT INTO sequence_annotations
            (record_id, user_id, annotation_type, start_position, end_position, strand, label, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($annotations as $a) {
        $stmt->execute([
            $recordId, $userId,
            $a['type'], $a['start'], $a['end'], $a['strand'],
            $a['label'], $a['notes'],
        ]);
    }
}

function logActivity(?int $userId, ?int $recordId, string $action, string $details = ''): void
{
    $stmt = getDB()->prepare(
        'INSERT INTO activity_log (user_id, record_id, action, details) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $recordId, $action, $details]);
}

function currentPage(): int
{
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    return $page > 0 ? $page : 1;
}
