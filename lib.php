<?php

// Shared set up: where the store lives, the manifest database, and HTTP.

if (file_exists(__DIR__ . '/env.php'))
{
	require_once __DIR__ . '/env.php';
}

//----------------------------------------------------------------------------------------
// Root of the store, which holds the manifest and the downloaded files. It lives outside
// the repository, e.g. on an external drive.
function store_root()
{
	$root = getenv('BHL_OCR_STORE');
	if (!$root)
	{
		fwrite(STDERR, "BHL_OCR_STORE is not set (see env-template.php)\n");
		exit(1);
	}
	if (!is_dir($root))
	{
		fwrite(STDERR, "Store directory $root does not exist (is the drive mounted?)\n");
		exit(1);
	}
	return rtrim($root, '/');
}

//----------------------------------------------------------------------------------------
function db()
{
	static $db = null;

	if (!$db)
	{
		$db = new PDO('sqlite:' . store_root() . '/manifest.sqlite');
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		$db->exec('PRAGMA journal_mode = WAL');
		$db->exec('PRAGMA busy_timeout = 10000');

		// One row per BHL item, from BHL's item table. status is null until we have
		// tried to fetch it, then 'ok', 'no-ocr' (IA has no OCR with coordinates for
		// it) or 'error'.
		$db->exec('CREATE TABLE IF NOT EXISTS item (
			item_id INTEGER PRIMARY KEY,
			title_id INTEGER,
			barcode TEXT,
			year TEXT,
			institution TEXT,
			status TEXT,
			message TEXT,
			checked TEXT
		)');
		$db->exec('CREATE INDEX IF NOT EXISTS item_barcode ON item(barcode)');
		$db->exec('CREATE INDEX IF NOT EXISTS item_status ON item(status)');

		// One row per stored file. size and md5 are those of the original file on IA,
		// path and stored_size those of the compressed copy in the store.
		$db->exec('CREATE TABLE IF NOT EXISTS file (
			barcode TEXT,
			name TEXT,
			format TEXT,
			size INTEGER,
			md5 TEXT,
			path TEXT,
			stored_size INTEGER,
			fetched TEXT,
			PRIMARY KEY (barcode, name)
		)');
	}
	return $db;
}

//----------------------------------------------------------------------------------------
// GET a URL, retrying on network errors, 429 and 5xx. If $filename is given the body is
// written there, otherwise it is returned. Returns false on a 404 or when retries run out.
function http_get($url, $filename = null, $tries = 5)
{
	for ($attempt = 1; $attempt <= $tries; $attempt++)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'bhl-ocr-store (rdmpage@gmail.com)');
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 600);

		$fp = null;
		if ($filename)
		{
			$fp = fopen($filename, 'w');
			curl_setopt($ch, CURLOPT_FILE, $fp);
		}
		else
		{
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		}

		$body = curl_exec($ch);
		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		curl_close($ch);
		if ($fp)
		{
			fclose($fp);
		}

		if ($body !== false && $code == 200)
		{
			return $filename ? true : $body;
		}
		if ($code == 404 || $code == 403)
		{
			return false;
		}

		fwrite(STDERR, "  $url: " . ($error ? $error : "HTTP $code") . ", attempt $attempt\n");
		sleep(min(60, 5 * $attempt * $attempt));
	}
	return false;
}

//----------------------------------------------------------------------------------------
// Where a file for an IA identifier goes, relative to the store root. Items are spread
// over 256 directories by a hash of the identifier so no directory gets too big.
function stored_path($barcode, $name)
{
	return 'ia/' . substr(md5($barcode), 0, 2) . '/' . $barcode . '/' . $name . '.zst';
}

?>
