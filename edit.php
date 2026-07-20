<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user = currentUser();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$stmt = $db->prepare('SELECT * FROM nucleotide_records WHERE id = ?');
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record) {
    setFlash('error', 'That record does not exist.');
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accession   = trim($_POST['accession_number'] ?? '');
    $organism    = trim($_POST['organism'] ?? '');
    $geneName    = trim($_POST['gene_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sequence    = strtoupper(preg_replace('/\s+/', '', $_POST['sequence'] ?? ''));

    if ($accession === '') {
        $errors[] = 'Accession number is required.';
    }
    if (!isValidNucleotideSequence($sequence)) {
        $errors[] = 'Sequence must only contain valid IUPAC nucleotide codes (A, C, G, T, U, N, etc).';
    }

    if (!$errors) {
        $dupCheck = $db->prepare('SELECT id FROM nucleotide_records WHERE accession_number = ? AND id != ?');
        $dupCheck->execute([$accession, $id]);
        if ($dupCheck->fetch()) {
            $errors[] = 'Another record already uses that accession number.';
        }
    }

    if (!$errors) {
        $sequenceType = detectSequenceType($sequence);
        $gcContent = calculateGcContent($sequence);

        $update = $db->prepare(
            'UPDATE nucleotide_records
             SET accession_number = ?, organism = ?, gene_name = ?, sequence_type = ?,
                 description = ?, sequence = ?, sequence_length = ?, gc_content = ?
             WHERE id = ?'
        );
        $update->execute([
            $accession, $organism, $geneName, $sequenceType,
            $description, $sequence, strlen($sequence), $gcContent, $id,
        ]);

        logActivity($user['id'], $id, 'UPDATE', $accession);

        $annotations = computeAnnotations($sequence, $sequenceType);
        saveComputedAnnotations($id, $user['id'], $annotations);

        setFlash('success', 'Record "' . $accession . '" updated.');
        header('Location: view.php?id=' . $id);
        exit;
    }

    $record = array_merge($record, [
        'accession_number' => $accession,
        'organism'         => $organism,
        'gene_name'        => $geneName,
        'description'      => $description,
        'sequence'         => $sequence,
    ]);
}

$pageTitle = 'Edit ' . $record['accession_number'];
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-2xl">
  <a href="view.php?id=<?= (int) $record['id'] ?>" class="text-sm text-slate-500 hover:text-teal-600">&larr; Back to record</a>
  <h1 class="font-display font-bold text-2xl text-slate-900 mt-2 mb-6">Edit record</h1>

  <?php if ($errors): ?>
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
      <ul class="list-disc list-inside space-y-1">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="bg-white border border-slate-200 rounded-xl p-6 space-y-4 shadow-sm">
    <input type="hidden" name="id" value="<?= (int) $record['id'] ?>">

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Accession number</label>
        <input type="text" name="accession_number" value="<?= h($record['accession_number']) ?>" required
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500">
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Gene name</label>
        <input type="text" name="gene_name" value="<?= h($record['gene_name']) ?>"
               class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
      </div>
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Organism</label>
      <input type="text" name="organism" value="<?= h($record['organism']) ?>"
             class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500">
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
      <textarea name="description" rows="2"
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-500"><?= h($record['description']) ?></textarea>
    </div>

    <div>
      <label class="block text-sm font-medium text-slate-700 mb-1">Sequence</label>
      <textarea name="sequence" rows="8" required
                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-teal-500"><?= h($record['sequence']) ?></textarea>
      <p class="text-xs text-slate-400 mt-1">Sequence type and GC content are recalculated automatically on save.</p>
    </div>

    <div class="flex gap-3 pt-2">
      <button type="submit" class="bg-teal-600 hover:bg-teal-500 text-white font-medium rounded-lg py-2.5 px-6 transition">
        Save changes
      </button>
      <a href="view.php?id=<?= (int) $record['id'] ?>" class="py-2.5 px-6 text-slate-600 hover:text-slate-900">Cancel</a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
