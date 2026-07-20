<?php
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
$records = $db->query('SELECT id, sequence, sequence_type, uploaded_by, accession_number FROM nucleotide_records')->fetchAll();

$totalAnnotations = 0;

foreach ($records as $rec) {
    $annotations = computeAnnotations($rec['sequence'], $rec['sequence_type']);
    saveComputedAnnotations((int) $rec['id'], (int) $rec['uploaded_by'], $annotations);
    $count = count($annotations);
    $totalAnnotations += $count;
    echo "  {$rec['accession_number']}: $count annotation(s) computed\n";
}

echo "\nDone. $totalAnnotations total annotation(s) across " . count($records) . " record(s).\n";
