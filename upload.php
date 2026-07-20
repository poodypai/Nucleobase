<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = currentUser();
$errors = [];
$imported = [];

$old = ['organism' => '', 'gene_name' => '', 'description' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['organism']    = trim($_POST['organism'] ?? '');
    $old['gene_name']   = trim($_POST['gene_name'] ?? '');
    $old['description'] = trim($_POST['description'] ?? '');
    $pastedFasta        = trim($_POST['fasta_text'] ?? '');

    $content = null;

    if (isset($_FILES['fasta_file']) && $_FILES['fasta_file']['error'] === UPLOAD_ERR_OK) {
        $tmpName = $_FILES['fasta_file']['tmp_name'];
        $origName = $_FILES['fasta_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ['fasta', 'fa', 'fna', 'txt'], true)) {
            $errors[] = 'Please upload a .fasta, .fa, .fna, or .txt file.';
        } elseif ($_FILES['fasta_file']['size'] > 10 * 1024 * 1024) {
            $errors[] = 'File is too large (10MB max).';
        } else {
            $content = file_get_contents($tmpName);
        }
    } elseif ($pastedFasta !== '') {
        $content = $pastedFasta;
    } else {
        $errors[] = 'Upload a FASTA file or paste FASTA text below.';
    }

    if (!$errors && $content !== null) {
        $records = parseFasta($content);

        if (!$records) {
            $errors[] = 'No valid FASTA records were found in that input.';
        } else {
            $db = getDB();
            $insertStmt = $db->prepare(
                'INSERT INTO nucleotide_records
                    (accession_number, organism, gene_name, sequence_type, description,
                     sequence, sequence_length, gc_content, original_filename, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $checkStmt = $db->prepare('SELECT id FROM nucleotide_records WHERE accession_number = ?');

            foreach ($records as $rec) {
                $sequence = strtoupper($rec['sequence']);

                if (!isValidNucleotideSequence($sequence)) {
                    $errors[] = 'Skipped "' . $rec['accession'] . '": contains characters outside the IUPAC nucleotide alphabet.';
                    continue;
                }

                $checkStmt->execute([$rec['accession']]);
                if ($checkStmt->fetch()) {
                    $errors[] = 'Skipped "' . $rec['accession'] . '": accession number already exists in the database.';
                    continue;
                }

                $description = $old['description'] !== '' ? $old['description'] : $rec['description'];

                $insertStmt->execute([
                    $rec['accession'],
                    $old['organism'],
                    $old['gene_name'],
                    detectSequenceType($sequence),
                    $description,
                    $sequence,
                    strlen($sequence),
                    calculateGcContent($sequence),
                    $_FILES['fasta_file']['name'] ?? null,
                    $user['id'],
                ]);

                $newId = (int) $db->lastInsertId();
                logActivity($user['id'], $newId, 'CREATE', $rec['accession']);

                $annotations = computeAnnotations($sequence, detectSequenceType($sequence));
                saveComputedAnnotations($newId, $user['id'], $annotations);

                $imported[] = $rec['accession'];
            }
        }
    }

    if ($imported && !$errors) {
        setFlash('success', count($imported) . ' record(s) imported: ' . implode(', ', $imported));
        header('Location: dashboard.php');
        exit;
    } elseif ($imported) {
        setFlash('success', count($imported) . ' record(s) imported successfully.');
    }
}

$pageTitle = 'Upload FASTA';
$activeNav = 'upload';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-2xl">
  <h1 class="font-display font-bold text-2xl text-slate-900 mb-1">Upload nucleotide data</h1>
  <p class="text-slate-500 text-sm mb-6">Upload a FASTA file (single or multi-sequence) or paste FASTA text directly. Fields below apply to all sequences in this batch.</p>

  <?php if ($errors): ?>
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      <ul class="list-disc list-inside space-y-1">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="bg-white border border-slate-200 rounded-xl p-6 space-y-5 shadow-sm">
    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Organism</label>
        <input type="text" name="organism" value="<?= h($old['organism']) ?>" placeholder="e.g. Homo sapiens"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Gene name</label>
        <input type="text" name="gene_name" value="<?= h($old['gene_name']) ?>" placeholder="e.g. TP53"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
      </div>
    </div>
    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Description <span class="text-slate-400 font-normal">(optional — overrides FASTA header text if set)</span></label>
      <input type="text" name="description" value="<?= h($old['description']) ?>"
             class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">FASTA file</label>
      <input type="file" name="fasta_file" accept=".fasta,.fa,.fna,.txt"
             class="w-full text-sm border border-slate-300 rounded-lg px-3 py-2 file:mr-3 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:bg-teal-50 file:text-teal-700 file:font-medium hover:file:bg-teal-100">
    </div>

    <div class="relative flex items-center py-1">
      <div class="flex-grow border-t border-slate-200"></div>
      <span class="mx-3 text-xs text-slate-400 uppercase">or paste text</span>
      <div class="flex-grow border-t border-slate-200"></div>
    </div>

    <div>
      <textarea name="fasta_text" rows="6" placeholder="&gt;accession description&#10;ACGTACGTACGT..."
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500"><?= h($_POST['fasta_text'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="w-full bg-teal-600 hover:bg-teal-500 text-white font-medium rounded-lg py-2.5 transition">
      Import sequence(s)
    </button>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
