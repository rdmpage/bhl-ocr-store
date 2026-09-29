<?php

// Summarise what is in the store, and estimate how big all of BHL would be from what
// has been fetched so far.

require_once __DIR__ . '/lib.php';

$db = db();

function mb($bytes)
{
	return sprintf('%.1f MB', $bytes / 1e6);
}

function gb($bytes)
{
	return sprintf('%.0f GB', $bytes / 1e9);
}

$total = $db->query('SELECT count(*) FROM item')->fetchColumn();
echo "Items: $total\n\n";

echo "Status:\n";
foreach ($db->query('SELECT coalesce(status, "not tried") AS s, count(*) AS n FROM item GROUP BY s ORDER BY n DESC') as $row)
{
	echo sprintf("  %-10s %8d\n", $row['s'], $row['n']);
}

$tried = $db->query('SELECT count(*) FROM item WHERE status IS NOT NULL')->fetchColumn();
if ($tried == 0)
{
	exit;
}

echo "\nFiles:\n";
foreach ($db->query('SELECT format, count(*) AS n, sum(size) AS size, sum(stored_size) AS stored,
	avg(size) AS avg_size, avg(stored_size) AS avg_stored FROM file GROUP BY format') as $row)
{
	echo sprintf("  %-9s %6d files  %10s -> %10s  (mean %s -> %s, %.1fx)\n",
		$row['format'], $row['n'], mb($row['size']), mb($row['stored']),
		mb($row['avg_size']), mb($row['avg_stored']), $row['size'] / max(1, $row['stored']));
}

// Scale the stored size per item tried (including those with no OCR) up to every item
$stored = $db->query('SELECT coalesce(sum(stored_size), 0) FROM file')->fetchColumn();
$raw = $db->query('SELECT coalesce(sum(size), 0) FROM file')->fetchColumn();
echo sprintf("\nEstimate for all %d items: %s compressed (%s uncompressed)\n",
	$total, gb($stored / $tried * $total), gb($raw / $tried * $total));

$free = disk_free_space(store_root());
echo "Free on store drive: " . gb($free) . "\n";

?>
