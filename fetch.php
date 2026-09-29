<?php

// Fetch OCR with word coordinates for BHL items from the Internet Archive, compress it
// with zstd and record it in the manifest.
//
// For each item we take the DjVu XML if there is one, otherwise the hOCR, plus the
// scandata (needed to line IA's pages up with BHL's).
//
//   php fetch.php 32 122                  items by BHL ItemID
//   php fetch.php --barcode mobot31753003125132
//   php fetch.php --sample 200            random items not yet tried
//   php fetch.php --all [--limit n]       every item not yet tried
//   php fetch.php --retry                 items that failed last time

require_once __DIR__ . '/lib.php';

// Formats in order of preference; we store the first one IA has
$ocr_suffixes = [
	'djvu' => '_djvu.xml',
	'hocr' => '_hocr.html',
];

$zstd_level = getenv('ZSTD_LEVEL') ? getenv('ZSTD_LEVEL') : 15;

//----------------------------------------------------------------------------------------
// Download one IA file into the store, check its md5, compress it, and record it.
function fetch_file($barcode, $file, $format)
{
	global $zstd_level;

	$root = store_root();
	$path = stored_path($barcode, $file->name);
	$full = $root . '/' . $path;

	@mkdir(dirname($full), 0777, true);
	$tmp = $full . '.part';

	$url = 'https://archive.org/download/' . rawurlencode($barcode) . '/' . rawurlencode($file->name);
	if (!http_get($url, $tmp))
	{
		@unlink($tmp);
		throw new Exception("could not download {$file->name}");
	}

	if (isset($file->md5) && md5_file($tmp) != $file->md5)
	{
		@unlink($tmp);
		throw new Exception("md5 mismatch for {$file->name}");
	}
	$size = filesize($tmp);

	$cmd = 'zstd -q -f --rm -' . (int)$zstd_level . ' ' . escapeshellarg($tmp) . ' -o ' . escapeshellarg($full);
	exec($cmd, $output, $status);
	if ($status != 0)
	{
		@unlink($tmp);
		throw new Exception("zstd failed for {$file->name}");
	}

	$stmt = db()->prepare('INSERT OR REPLACE INTO file
		(barcode, name, format, size, md5, path, stored_size, fetched)
		VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now"))');
	$stmt->execute([$barcode, $file->name, $format, $size,
		isset($file->md5) ? $file->md5 : null, $path, filesize($full)]);
}

//----------------------------------------------------------------------------------------
function fetch_item($item)
{
	global $ocr_suffixes;

	$barcode = $item['barcode'];
	$status = 'ok';
	$message = null;

	try
	{
		if ($barcode == '')
		{
			throw new Exception('no barcode');
		}

		$json = http_get('https://archive.org/metadata/' . rawurlencode($barcode));
		$metadata = $json ? json_decode($json) : null;
		if (!$metadata || !isset($metadata->files))
		{
			// Not every BHL item came from IA
			$status = 'no-ocr';
			throw new Exception('not on IA');
		}

		$files = [];
		foreach ($metadata->files as $file)
		{
			$files[$file->name] = $file;
		}

		$ocr = null;
		foreach ($ocr_suffixes as $format => $suffix)
		{
			if (isset($files[$barcode . $suffix]))
			{
				$ocr = [$files[$barcode . $suffix], $format];
				break;
			}
		}
		if (!$ocr)
		{
			$status = 'no-ocr';
			throw new Exception('no DjVu XML or hOCR');
		}

		fetch_file($barcode, $ocr[0], $ocr[1]);
		$message = $ocr[1];

		$scandata = $barcode . '_scandata.xml';
		if (isset($files[$scandata]))
		{
			fetch_file($barcode, $files[$scandata], 'scandata');
		}
	}
	catch (Exception $e)
	{
		if ($status == 'ok')
		{
			$status = 'error';
		}
		$message = $e->getMessage();
	}

	$stmt = db()->prepare('UPDATE item SET status = ?, message = ?, checked = datetime("now") WHERE item_id = ?');
	$stmt->execute([$status, $message, $item['item_id']]);

	return [$status, $message];
}

//----------------------------------------------------------------------------------------

$sql = null;
$params = [];
$limit = null;

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++)
{
	switch ($args[$i])
	{
		case '--sample':
			$sql = 'SELECT * FROM item WHERE status IS NULL ORDER BY random() LIMIT ' . (int)$args[++$i];
			break;

		case '--all':
			$sql = 'SELECT * FROM item WHERE status IS NULL ORDER BY item_id';
			break;

		case '--retry':
			$sql = 'SELECT * FROM item WHERE status = "error" ORDER BY item_id';
			break;

		case '--limit':
			$limit = (int)$args[++$i];
			break;

		case '--barcode':
			$sql = 'SELECT * FROM item WHERE barcode = ?';
			$params[] = $args[++$i];
			break;

		default:
			$sql = 'SELECT * FROM item WHERE item_id IN ('
				. implode(',', array_map('intval', array_slice($args, $i))) . ')';
			$i = count($args);
			break;
	}
}

if (!$sql)
{
	fwrite(STDERR, "Usage: php fetch.php (ItemID ... | --barcode id | --sample n | --all [--limit n] | --retry)\n");
	exit(1);
}
if ($limit && strpos($sql, 'LIMIT') === false)
{
	$sql .= ' LIMIT ' . $limit;
}

$stmt = db()->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

if (count($items) == 0)
{
	fwrite(STDERR, "No matching items (have you run items.php?)\n");
}

$n = 0;
foreach ($items as $item)
{
	$n++;
	list($status, $message) = fetch_item($item);
	echo "[$n/" . count($items) . "] {$item['item_id']} {$item['barcode']} $status" . ($message ? " ($message)" : '') . "\n";
}

?>
