# BHL OCR store

A local copy of the OCR, with word coordinates, for items in the Biodiversity Heritage Library, so that annotations can be mapped onto pages without fetching anything at the time (see [ocr-format](https://github.com/rdmpage/ocr-format)).

BHL's own OCR (in its [open data on AWS](https://bhl-open-data.s3.amazonaws.com/README.html)) is plain text, one file per page, with no coordinates. Most BHL items were scanned by the Internet Archive, which has OCR with word coordinates, so we get that from IA. For each item we store:

- the DjVu XML (`_djvu.xml`) if IA has it, as it is the most compact format with word coordinates, otherwise the hOCR (`_hocr.html`);
- the scandata (`_scandata.xml`), which lists every leaf scanned and whether it is in the access formats, so IA's pages can be lined up with BHL's. We take this from BHL's copy on AWS where there is one, as it is quicker and more reliable than IA.

IA sometimes lists a DjVu XML file but returns a 500 when asked for it; we then fall back to the hOCR, and if that fails too the item is marked `error` for `--retry`. Many of these 500s are transient.

## Where things go

The code lives here, the data does not. Copy `env-template.php` to `env.php` and set `BHL_OCR_STORE` to the store directory (by default `/Volumes/LaCie/bhl-ocr-store`). That directory holds:

- `manifest.sqlite`, the manifest;
- `ia/xx/<identifier>/<file>.zst`, the files, compressed with zstd, where `xx` is the first two hex digits of the MD5 of the IA identifier (spreading items over 256 directories).

To read a file: `zstd -dc /Volumes/LaCie/bhl-ocr-store/ia/.../<identifier>_djvu.xml.zst`.

## Manifest

`item` has one row per BHL item (from BHL's `item.txt`): `item_id`, `title_id`, `barcode` (the IA identifier), `year`, `institution`, and the result of fetching it, `status` (`ok`, `no-ocr`, `error`, or null if not yet tried) with a `message`.

`file` has one row per stored file: `barcode`, `name`, `format` (`djvu`, `hocr` or `scandata`), the original `size` and `md5` from IA, the `path` in the store, its `stored_size`, and when it was `fetched`.

## Use

```
php items.php                 # load (or refresh) BHL's item list
php fetch.php 32 122          # fetch items by BHL ItemID
php fetch.php --barcode mobot31753003125132
php fetch.php --sample 200    # random items not yet tried
php fetch.php --all           # everything not yet tried (resumable)
php fetch.php --retry         # items that failed
php report.php                # what is in the store, and a size estimate for all of BHL
```

`fetch.php` runs 4 workers by default; set another number with `--workers n`. Fetching is limited by IA's download speed, not by compression: with 8 workers a sample of 100 items took about 2 seconds per item, so all of BHL would take about a week.

Fetching is resumable: items with a status are skipped by `--sample` and `--all`, and a file is only recorded once it has been downloaded, checked against IA's MD5, and compressed.
