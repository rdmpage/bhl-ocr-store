<?php

// Fetch OCR with word coordinates for BHL items from the Internet Archive, compress it
// with zstd and record it in the manifest.
//
// For each item we take the DjVu XML if there is one, otherwise the hOCR, plus the
// scandata (needed to line IA's pages up with BHL's), preferably from BHL's copy on AWS.
//
//   php fetch.php 32 122                  items by BHL ItemID
//   php fetch.php --barcode mobot31753003125132
//   php fetch.php --sample 200            random items not yet tried
//   php fetch.php --all [--limit n]       every item not yet tried
//   php fetch.php --retry                 items that failed last time
//
// Add --workers n to fetch n items at a time (default 4).

require_once __DIR__ . '/lib.php';

// Formats in order of preference; we store the first one IA has
$ocr_suffixes = [
	'djvu' => '_djvu.xml',
	'hocr' => '_hocr.html',
];

$zstd_level = getenv('ZSTD_LEVEL') ? getenv('ZSTD_LEVEL') : 15;

//----------------------------------------------------------------------------------------
// Download one IA file into the store, check its md5, compress it, and record it.
// $mirror is another URL for the same file, tried first; its copy may not be identical
// to IA's, so we record its own md5 rather than check IA's.
function fetch_file($barcode, $file, $format, $mirror = null)
{
	global $zstd_level;

	$root = store_root();
	$path = stored_path($barcode, $file->name);
	$full = $root . '/' . $path;

	// Already have this version of the file (e.g. when retrying an item)
	if (isset($file->md5) && file_exists($full))
	{
		$stmt = db()->prepare('SELECT 1 FROM file WHERE barcode = ? AND name = ? AND md5 = ?');
		$stmt->execute([$barcode, $file->name, $file->md5]);
		if ($stmt->fetchColumn())
		{
			return;
		}
	}

	@mkdir(dirname($full), 0777, true);
	$tmp = $full . '.part';

	$md5 = isset($file->md5) ? $file->md5 : null;

	if ($mirror && http_get($mirror, $tmp, 1))
	{
		$md5 = md5_file($tmp);
	}
	else
	{
		$url = 'https://archive.org/download/' . rawurlencode($barcode) . '/' . rawurlencode($file->name);
		if (!http_get($url, $tmp))
		{
			@unlink($tmp);
			throw new Exception("could not download {$file->name}");
		}

		if ($md5 && md5_file($tmp) != $md5)
		{
			@unlink($tmp);
			throw new Exception("md5 mismatch for {$file->name}");
		}
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
	$stmt->execute([$barcode, $file->name, $format, $size, $md5, $path, filesize($full)]);
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

		// Take the first format IA has, falling back to the next if a download fails
		// (IA sometimes lists a DjVu XML file but gives a 500 when asked for it)
		$errors = [];
		foreach ($ocr_suffixes as $format => $suffix)
		{
			if (isset($files[$barcode . $suffix]))
			{
				try
				{
					fetch_file($barcode, $files[$barcode . $suffix], $format);
					$message = $format;
					break;
				}
				catch (Exception $e)
				{
					$errors[] = $e->getMessage();
				}
			}
		}
		if (!$message)
		{
			if (count($errors) == 0)
			{
				$status = 'no-ocr';
				throw new Exception('no DjVu XML or hOCR');
			}
			throw new Exception(implode('; ', $errors));
		}

		$scandata = $barcode . '_scandata.xml';
		if (isset($files[$scandata]))
		{
			// BHL has its own copy of the scandata on AWS, which is quicker to get
			fetch_file($barcode, $files[$scandata], 'scandata',
				'https://bhl-open-data.s3.amazonaws.com/scandata/' . rawurlencode($scandata));
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
$workers = 4;

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++)
{
	switch ($args[$i])
	{
		case '--sample':
			$sql = 'SELECT item_id, barcode FROM item WHERE status IS NULL ORDER BY random() LIMIT ' . (int)$args[++$i];
			break;

		case '--all':
			$sql = 'SELECT item_id, barcode FROM item WHERE status IS NULL ORDER BY item_id';
			break;

		case '--retry':
			$sql = 'SELECT item_id, barcode FROM item WHERE status = "error" ORDER BY item_id';
			break;

		case '--limit':
			$limit = (int)$args[++$i];
			break;

		case '--workers':
			$workers = max(1, (int)$args[++$i]);
			break;

		case '--barcode':
			$sql = 'SELECT item_id, barcode FROM item WHERE barcode = ?';
			$params[] = $args[++$i];
			break;

		default:
			$sql = 'SELECT item_id, barcode FROM item WHERE item_id IN ('
				. implode(',', array_map('intval', array_slice($args, $i))) . ')';
			$i = count($args);
			break;
	}
}

if (!$sql)
{
	fwrite(STDERR, "Usage: php fetch.php (ItemID ... | --barcode id | --sample n | --all [--limit n] | --retry) [--workers n]\n");
	exit(1);
}
if ($limit && strpos($sql, 'LIMIT') === false)
{
	$sql .= ' LIMIT ' . $limit;
}

// Keep just the ids and barcodes in two plain arrays: a row array per item for all of
// BHL is more than PHP's default memory limit
$ids = [];
$barcodes = [];
$stmt = db()->prepare($sql);
$stmt->execute($params);
while ($row = $stmt->fetch(PDO::FETCH_NUM))
{
	$ids[] = (int)$row[0];
	$barcodes[] = (string)$row[1];
}
$stmt = null;
$total = count($ids);

if ($total == 0)
{
	fwrite(STDERR, "No matching items (have you run items.php?)\n");
}

// Fetching is limited by IA's download speed, not by us, so split the items between
// several worker processes, each taking every nth item
$pids = [];
for ($worker = 0; $worker < $workers; $worker++)
{
	$pid = $workers > 1 ? pcntl_fork() : 0;
	if ($pid > 0)
	{
		$pids[] = $pid;
		continue;
	}

	db(true);
	for ($n = $worker; $n < $total; $n += $workers)
	{
		$item = ['item_id' => $ids[$n], 'barcode' => $barcodes[$n]];
		list($status, $message) = fetch_item($item);
		echo '[' . ($n + 1) . "/$total] {$item['item_id']} {$item['barcode']} $status" . ($message ? " ($message)" : '') . "\n";
	}
	if ($workers > 1)
	{
		exit(0);
	}
}

foreach ($pids as $pid)
{
	pcntl_waitpid($pid, $status);
}

?>
