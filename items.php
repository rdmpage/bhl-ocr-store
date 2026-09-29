<?php

// Load BHL's item table from its open data on AWS into the manifest. Safe to rerun: new
// items are added and the metadata of existing ones updated, without losing fetch status.

require_once __DIR__ . '/lib.php';

$url = 'https://bhl-open-data.s3.amazonaws.com/data/item.txt.gz';

$tmp = store_root() . '/item.txt.gz';
echo "Downloading $url\n";
if (!http_get($url, $tmp))
{
	fwrite(STDERR, "Could not download $url\n");
	exit(1);
}

$db = db();
$stmt = $db->prepare('INSERT INTO item (item_id, title_id, barcode, year, institution)
	VALUES (?, ?, ?, ?, ?)
	ON CONFLICT(item_id) DO UPDATE SET
		title_id = excluded.title_id,
		barcode = excluded.barcode,
		year = excluded.year,
		institution = excluded.institution');

$fp = gzopen($tmp, 'r');
$header = null;
$count = 0;

$db->beginTransaction();
while (($line = fgets($fp)) !== false)
{
	$row = explode("\t", rtrim($line, "\r\n"));
	if (!$header)
	{
		// The file starts with a byte order mark
		$row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
		$header = array_flip($row);
		continue;
	}

	$stmt->execute([
		$row[$header['ItemID']],
		$row[$header['TitleID']],
		trim($row[$header['BarCode']]),
		$row[$header['Year']],
		$row[$header['InstitutionName']],
	]);
	$count++;
}
$db->commit();
gzclose($fp);

echo "$count items\n";

?>
