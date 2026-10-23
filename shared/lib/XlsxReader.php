<?php

/**
 * Reads the first worksheet of an .xlsx file into rows of plain strings, with
 * no extension beyond zlib (a .xlsx is a ZIP of XML files, and PHP's zip
 * extension isn't installed on the server). Used by api/roster-upload.php so a
 * roster can be saved straight from Excel without converting it to CSV first.
 *
 * Only cell values are read — formulas use the value Excel last calculated,
 * numbers come back as text, and formatting is ignored. Every size is capped so
 * a malicious or broken file can't use up the server's memory.
 */
class XlsxReader
{
    private const MAX_ENTRIES = 2000;
    private const MAX_XML_BYTES = 8 * 1024 * 1024; // per uncompressed part
    private const MAX_ROWS = 20000;
    private const MAX_COLUMNS = 50;

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var array<string,array{method:int,size:int,usize:int,start:int}> */
    private array $entries = [];
    private string $bin;

    /**
     * @return array<int,array<int,string>> rows (1-based file row n is index n-1), each a list of cell strings by column
     * @throws RuntimeException when the file isn't a readable workbook
     */
    public static function readRows(string $path): array
    {
        $bin = @file_get_contents($path);
        if ($bin === false || $bin === '') {
            throw new RuntimeException('Could not read the uploaded file.');
        }
        $reader = new self($bin);
        return $reader->firstSheetRows();
    }

    private function __construct(string $bin)
    {
        $this->bin = $bin;
        $this->indexZip();
    }

    private function indexZip(): void
    {
        $eocd = strrpos($this->bin, "PK\x05\x06");
        if ($eocd === false || strlen($this->bin) < $eocd + 22) {
            throw new RuntimeException('This is not a valid .xlsx file.');
        }
        $end = unpack('vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset', substr($this->bin, $eocd + 4, 16));
        if ($end['entries'] > self::MAX_ENTRIES) {
            throw new RuntimeException('This .xlsx file has too many parts.');
        }
        $pos = $end['cdOffset'];
        for ($i = 0; $i < $end['entries']; $i++) {
            if (substr($this->bin, $pos, 4) !== "PK\x01\x02") {
                throw new RuntimeException('This is not a valid .xlsx file.');
            }
            // method, (skip time + date), crc, compressed size, uncompressed size, name/extra/comment lengths
            $c = unpack('vmethod/x4skip/Vcrc/Vsize/Vusize/vnameLen/vextraLen/vcommentLen', substr($this->bin, $pos + 10, 24));
            $local = unpack('Voffset', substr($this->bin, $pos + 42, 4))['offset'];
            $name = substr($this->bin, $pos + 46, $c['nameLen']);
            $pos += 46 + $c['nameLen'] + $c['extraLen'] + $c['commentLen'];

            if (substr($this->bin, $local, 4) !== "PK\x03\x04") {
                throw new RuntimeException('This is not a valid .xlsx file.');
            }
            $l = unpack('vnameLen/vextraLen', substr($this->bin, $local + 26, 4));
            $this->entries[$name] = [
                'method' => $c['method'],
                'size' => $c['size'],
                'usize' => $c['usize'],
                'start' => $local + 30 + $l['nameLen'] + $l['extraLen'],
            ];
        }
    }

    private function part(string $name): ?string
    {
        $e = $this->entries[$name] ?? null;
        if ($e === null) {
            return null;
        }
        if ($e['usize'] > self::MAX_XML_BYTES) {
            throw new RuntimeException('This .xlsx file is too large to read.');
        }
        $raw = substr($this->bin, $e['start'], $e['size']);
        if ($e['method'] === 0) {
            return $raw;
        }
        if ($e['method'] === 8) {
            $out = @gzinflate($raw, self::MAX_XML_BYTES);
            if ($out === false) {
                throw new RuntimeException('This .xlsx file is damaged.');
            }
            return $out;
        }
        throw new RuntimeException('This .xlsx file uses an unsupported compression.');
    }

    private function xml(string $text): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: never fetch anything over the network. Entities are not expanded by default.
        $x = simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($x === false) {
            throw new RuntimeException('This .xlsx file is damaged.');
        }
        return $x;
    }

    /** @return array<int,array<int,string>> */
    private function firstSheetRows(): array
    {
        // Which part holds the first sheet: workbook.xml names it by relationship id.
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $workbook = $this->part('xl/workbook.xml');
        $rels = $this->part('xl/_rels/workbook.xml.rels');
        if ($workbook !== null && $rels !== null) {
            $wb = $this->xml($workbook);
            $first = $wb->xpath("//*[local-name()='sheets']/*[local-name()='sheet']");
            if ($first) {
                $rid = (string) ($first[0]->attributes(self::NS_REL)['id'] ?? '');
                $relXml = $this->xml($rels);
                        foreach ($relXml->xpath("//*[local-name()='Relationship']") ?: [] as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $target = (string) $rel['Target'];
                        $sheetPath = ltrim($target[0] === '/' ? $target : 'xl/' . $target, '/');
                        break;
                    }
                }
            }
        }
        $sheetXml = $this->part($sheetPath);
        if ($sheetXml === null) {
            throw new RuntimeException('Could not find a worksheet in this .xlsx file.');
        }

        $shared = [];
        $sharedXml = $this->part('xl/sharedStrings.xml');
        if ($sharedXml !== null) {
            foreach ($this->xml($sharedXml)->xpath("//*[local-name()='si']") ?: [] as $si) {
                $text = '';
                foreach ($si->xpath(".//*[local-name()='t']") ?: [] as $t) { // plain <t> or rich-text <r><t> runs
                    $text .= (string) $t;
                }
                $shared[] = $text;
            }
        }

        $rows = [];
        foreach ($this->xml($sheetXml)->xpath("//*[local-name()='sheetData']/*[local-name()='row']") ?: [] as $row) {
            $rowNum = (int) ($row['r'] ?? 0);
            if ($rowNum <= 0) {
                $rowNum = count($rows) + 1;
            }
            if ($rowNum > self::MAX_ROWS) {
                throw new RuntimeException('This worksheet has too many rows.');
            }
            $cells = [];
            $next = 0;
            foreach ($row->xpath("*[local-name()='c']") ?: [] as $c) {
                $col = isset($c['r']) ? self::columnIndex((string) $c['r']) : $next;
                if ($col >= self::MAX_COLUMNS) {
                    continue;
                }
                $next = $col + 1;
                $cells[$col] = $this->cellValue($c, $shared);
            }
            if ($cells) {
                $line = array_fill(0, max(array_keys($cells)) + 1, '');
                foreach ($cells as $i => $v) {
                    $line[$i] = $v;
                }
                $rows[$rowNum - 1] = $line;
            }
        }
        if (!$rows) {
            return [];
        }
        // Fill skipped (completely empty) rows so row numbers match the sheet.
        $out = [];
        for ($i = 0, $n = max(array_keys($rows)) + 1; $i < $n; $i++) {
            $out[$i] = $rows[$i] ?? [];
        }
        return $out;
    }

    /** @param array<int,string> $shared */
    private function cellValue(SimpleXMLElement $c, array $shared): string
    {
        $type = (string) ($c['t'] ?? '');
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($c->xpath(".//*[local-name()='t']") ?: [] as $t) {
                $text .= (string) $t;
            }
            return trim($text);
        }
        $v = isset($c->v) ? (string) $c->v : '';
        // Cells in a namespaced sheet: fall back to the namespace-aware lookup.
        if ($v === '') {
            $vv = $c->xpath("*[local-name()='v']");
            $v = $vv ? (string) $vv[0] : '';
        }
        if ($type === 's') {
            return trim($shared[(int) $v] ?? '');
        }
        if ($type === 'b') {
            return $v === '1' ? 'TRUE' : 'FALSE';
        }
        if ($type === '' || $type === 'n') {
            // A long number Excel kept in scientific form: write it back out as whole digits.
            if (preg_match('/^-?\d+(\.\d+)?[eE][+-]?\d+$/', $v) && abs((float) $v) < 1e15) {
                return sprintf('%.0f', (float) $v);
            }
        }
        return trim($v);
    }

    /** "C12" -> 2 */
    private static function columnIndex(string $ref): int
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $ref));
        $n = 0;
        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }
        return max(0, $n - 1);
    }
}
