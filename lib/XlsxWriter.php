<?php

/**
 * Writes a small .xlsx workbook with no library: a .xlsx is a ZIP of XML files, and PHP's zip
 * extension isn't installed on the server, so the ZIP is assembled here too (zlib's gzdeflate
 * and crc32 are all it needs). Supports text and number cells with a handful of styles, column
 * widths, a frozen header row, column charts (with an optional target line and per-bar colors),
 * and sheet + workbook protection with a password.
 *
 * Protection is the ordinary Excel kind: it stops accidental or casual edits and asks for the
 * password to unprotect, but it is not encryption, so the file's contents can still be read.
 */
class XlsxWriter
{
    // Cell styles (indexes into the cellXfs in styles()).
    public const S_DEFAULT = 0;
    public const S_TITLE = 1;
    public const S_HEADER = 2;
    public const S_NUM1 = 3;     // 0.0, bordered
    public const S_BOLD = 4;
    public const S_DATE = 5;     // yyyy-mm-dd, bordered
    public const S_GOOD = 6;     // bold green text, bordered
    public const S_BAD = 7;      // bold red text, bordered
    public const S_NOTE = 8;     // italic grey
    public const S_TEXT = 9;     // bordered text
    public const S_INT = 10;     // whole number, bordered
    public const S_LABEL = 11;   // bold text, bordered

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** @var array<int,array<string,mixed>> */
    private array $sheets = [];
    /** @var array<string,int> */
    private array $stringIndex = [];
    /** @var array<int,string> */
    private array $strings = [];
    private ?string $password = null;

    /** Passwords are hashed the way Excel's own (all-versions) sheet protection does it. */
    public function protect(string $password): void
    {
        $this->password = $password;
    }

    /**
     * @param array<int,array<int,mixed>> $rows each cell is a scalar, null (empty) or ['v' => scalar, 's' => style]
     * @param array<int,float> $columnWidths by column index (0 = A), in characters
     * @param array<int,array{0:int,1:int,2:int,3:int}> $merges [firstRow, firstCol, lastRow, lastCol], 0-based
     */
    public function addSheet(string $name, array $rows, array $columnWidths = [], bool $freezeHeader = false, array $merges = []): int
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name));
        $name = mb_substr($name === '' ? 'Sheet' : $name, 0, 31);
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'cols' => $columnWidths, 'freeze' => $freezeHeader, 'merges' => $merges, 'charts' => []];
        return count($this->sheets) - 1;
    }

    /**
     * A column chart: one bar per category, optional per-bar colors, optional flat "target" line.
     *
     * @param array{title:string,sheet:int,anchor:array{0:int,1:int,2:int,3:int},catRef:string,cats:array<int,string>,valRef:string,vals:array<int,float|int>,
     *              seriesName:string,colors?:array<int,string>,targetRef?:string,targetVals?:array<int,float|int>,targetName?:string,
     *              max?:float|int,axisTitle?:string} $chart
     */
    public function addChart(array $chart): void
    {
        $this->sheets[$chart['sheet']]['charts'][] = $chart;
    }

    // ------------------------------------------------------------------ output

    public function build(): string
    {
        $files = [];
        $n = count($this->sheets);

        // Sheets first: they fill the shared-string table the workbook needs.
        $sheetXml = [];
        foreach ($this->sheets as $i => $s) {
            $sheetXml[$i] = $this->sheetXml($s);
        }

        $chartNo = 0;
        $drawingNo = 0;
        $contentTypes = [
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            '<Default Extension="xml" ContentType="application/xml"/>',
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>',
            '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>',
        ];
        $wbRels = [];
        $sheetEntries = [];
        foreach ($this->sheets as $i => $s) {
            $idx = $i + 1;
            $contentTypes[] = '<Override PartName="/xl/worksheets/sheet' . $idx . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbRels[] = '<Relationship Id="rId' . $idx . '" Type="' . self::NS_REL . '/worksheet" Target="worksheets/sheet' . $idx . '.xml"/>';
            $sheetEntries[] = '<sheet name="' . $this->esc($s['name']) . '" sheetId="' . $idx . '" r:id="rId' . $idx . '"/>';

            $drawingRel = '';
            if ($s['charts']) {
                $drawingNo++;
                $drawingRels = [];
                $anchors = [];
                foreach ($s['charts'] as $k => $chart) {
                    $chartNo++;
                    $files["xl/charts/chart$chartNo.xml"] = $this->chartXml($chart);
                    $contentTypes[] = '<Override PartName="/xl/charts/chart' . $chartNo . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
                    $drawingRels[] = '<Relationship Id="rId' . ($k + 1) . '" Type="' . self::NS_REL . '/chart" Target="../charts/chart' . $chartNo . '.xml"/>';
                    $anchors[] = $this->anchorXml($chart['anchor'], $k + 1);
                }
                $files["xl/drawings/drawing$drawingNo.xml"] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                    . implode('', $anchors) . '</xdr:wsDr>';
                $files["xl/drawings/_rels/drawing$drawingNo.xml.rels"] = $this->rels($drawingRels);
                $contentTypes[] = '<Override PartName="/xl/drawings/drawing' . $drawingNo . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
                $files["xl/worksheets/_rels/sheet$idx.xml.rels"] = $this->rels(['<Relationship Id="rId1" Type="' . self::NS_REL . '/drawing" Target="../drawings/drawing' . $drawingNo . '.xml"/>']);
                $sheetXml[$i] = str_replace('<!--DRAWING-->', '<drawing r:id="rId1"/>', $sheetXml[$i]);
            } else {
                $sheetXml[$i] = str_replace('<!--DRAWING-->', '', $sheetXml[$i]);
            }
            $files["xl/worksheets/sheet$idx.xml"] = $sheetXml[$i];
        }
        $wbRels[] = '<Relationship Id="rId' . ($n + 1) . '" Type="' . self::NS_REL . '/styles" Target="styles.xml"/>';
        $wbRels[] = '<Relationship Id="rId' . ($n + 2) . '" Type="' . self::NS_REL . '/sharedStrings" Target="sharedStrings.xml"/>';

        $protection = $this->password !== null
            ? '<workbookProtection workbookPassword="' . self::passwordHash($this->password) . '" lockStructure="1"/>'
            : '';
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">' . $protection
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
            . '<sheets>' . implode('', $sheetEntries) . '</sheets></workbook>';
        $files['xl/_rels/workbook.xml.rels'] = $this->rels($wbRels);
        $files['xl/styles.xml'] = $this->styles();
        $files['xl/sharedStrings.xml'] = $this->sharedStrings();
        $files['_rels/.rels'] = $this->rels(['<Relationship Id="rId1" Type="' . self::NS_REL . '/officeDocument" Target="xl/workbook.xml"/>']);
        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . implode('', $contentTypes) . '</Types>';

        // [Content_Types].xml conventionally comes first in the archive.
        $ordered = ['[Content_Types].xml' => $files['[Content_Types].xml']];
        foreach ($files as $path => $content) {
            if ($path !== '[Content_Types].xml') {
                $ordered[$path] = $content;
            }
        }
        return self::zip($ordered);
    }

    /** Excel's classic (all versions) sheet/workbook password hash, as the 4-digit-or-so hex string it stores. */
    public static function passwordHash(string $password): string
    {
        $hash = 0;
        $length = strlen($password);
        for ($i = 0; $i < $length; $i++) {
            $value = ord($password[$i]) << ($i + 1);
            $rotated = $value >> 15;
            $value &= 0x7FFF;
            $hash ^= ($value | $rotated);
        }
        $hash ^= $length;
        $hash ^= 0xCE4B;
        return strtoupper(dechex($hash));
    }

    // ------------------------------------------------------------------ parts

    private function esc(string $s): string
    {
        // Control characters other than tab / newline are not allowed in XML at all.
        $s = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @param array<int,string> $relationships */
    private function rels(array $relationships): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="' . self::NS_PKG_REL . '">' . implode('', $relationships) . '</Relationships>';
    }

    private static function colName(int $i): string
    {
        $name = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $name = chr(65 + ($i - 1) % 26) . $name;
        }
        return $name;
    }

    private function stringId(string $s): int
    {
        if (!isset($this->stringIndex[$s])) {
            $this->stringIndex[$s] = count($this->strings);
            $this->strings[] = $s;
        }
        return $this->stringIndex[$s];
    }

    /** @param array<string,mixed> $s */
    private function sheetXml(array $s): string
    {
        $rowsXml = '';
        $maxCol = 0;
        foreach ($s['rows'] as $r => $cells) {
            $cellsXml = '';
            foreach ($cells as $c => $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }
                $style = 0;
                if (is_array($cell)) {
                    $style = (int) ($cell['s'] ?? 0);
                    $cell = $cell['v'] ?? null;
                    if ($cell === null || $cell === '') {
                        // An empty but styled cell (e.g. a bordered blank).
                        $cellsXml .= '<c r="' . self::colName($c) . ($r + 1) . '" s="' . $style . '"/>';
                        $maxCol = max($maxCol, $c);
                        continue;
                    }
                }
                $ref = self::colName($c) . ($r + 1);
                $maxCol = max($maxCol, $c);
                if (is_int($cell) || is_float($cell)) {
                    $cellsXml .= '<c r="' . $ref . '" s="' . $style . '"><v>' . (is_float($cell) ? rtrim(rtrim(sprintf('%.10F', $cell), '0'), '.') : $cell) . '</v></c>';
                } else {
                    $cellsXml .= '<c r="' . $ref . '" s="' . $style . '" t="s"><v>' . $this->stringId((string) $cell) . '</v></c>';
                }
            }
            if ($cellsXml !== '') {
                $rowsXml .= '<row r="' . ($r + 1) . '">' . $cellsXml . '</row>';
            }
        }

        $cols = '';
        foreach ($s['cols'] as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $pane = $s['freeze'] ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '';
        $merges = '';
        if ($s['merges']) {
            $merges = '<mergeCells count="' . count($s['merges']) . '">';
            foreach ($s['merges'] as $m) {
                $merges .= '<mergeCell ref="' . self::colName($m[1]) . ($m[0] + 1) . ':' . self::colName($m[3]) . ($m[2] + 1) . '"/>';
            }
            $merges .= '</mergeCells>';
        }
        $protect = $this->password !== null
            ? '<sheetProtection password="' . self::passwordHash($this->password) . '" sheet="1" objects="1" scenarios="1"/>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
            . '<sheetViews><sheetView workbookViewId="0">' . $pane . '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
            . '<sheetData>' . $rowsXml . '</sheetData>'
            . $protect . $merges
            . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . '<!--DRAWING--></worksheet>';
    }

    private function sharedStrings(): string
    {
        $items = '';
        foreach ($this->strings as $s) {
            $items .= '<si><t xml:space="preserve">' . $this->esc($s) . '</t></si>';
        }
        $count = count($this->strings);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="' . self::NS_MAIN . '" count="' . $count . '" uniqueCount="' . $count . '">' . $items . '</sst>';
    }

    private function styles(): string
    {
        $border = '<border><left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom><diagonal/></border>';
        $xf = fn(int $numFmt, int $font, int $fill, int $border, string $align = '') =>
            '<xf numFmtId="' . $numFmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"'
            . ($numFmt ? ' applyNumberFormat="1"' : '') . ($font ? ' applyFont="1"' : '') . ($fill ? ' applyFill="1"' : '') . ($border ? ' applyBorder="1"' : '')
            . ($align !== '' ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="' . self::NS_MAIN . '">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="0.0"/><numFmt numFmtId="165" formatCode="yyyy\-mm\-dd"/></numFmts>'
            . '<fonts count="7">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="16"/><color rgb="FF0F2A6B"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF059669"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFDC2626"/><name val="Calibri"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0F2A6B"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $border . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="12">'
            . $xf(0, 0, 0, 0)                                                        // 0 default
            . $xf(0, 1, 0, 0)                                                        // 1 title
            . $xf(0, 2, 2, 1, '<alignment horizontal="center" vertical="center" wrapText="1"/>') // 2 header
            . $xf(164, 0, 0, 1)                                                      // 3 0.0
            . $xf(0, 3, 0, 0)                                                        // 4 bold
            . $xf(165, 0, 0, 1)                                                      // 5 date
            . $xf(0, 4, 0, 1)                                                        // 6 good
            . $xf(0, 5, 0, 1)                                                        // 7 bad
            . $xf(0, 6, 0, 0)                                                        // 8 note
            . $xf(0, 0, 0, 1)                                                        // 9 text
            . $xf(1, 0, 0, 1)                                                        // 10 integer
            . $xf(0, 3, 0, 1)                                                        // 11 label
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** @param array{0:int,1:int,2:int,3:int} $a [fromCol, fromRow, toCol, toRow], 0-based */
    private function anchorXml(array $a, int $rid): string
    {
        return '<xdr:twoCellAnchor>'
            . '<xdr:from><xdr:col>' . $a[0] . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . $a[1] . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
            . '<xdr:to><xdr:col>' . $a[2] . '</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>' . $a[3] . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
            . '<xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="' . ($rid + 1) . '" name="Chart ' . $rid . '"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr>'
            . '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
            . '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="' . self::NS_REL . '" r:id="rId' . $rid . '"/>'
            . '</a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor>';
    }

    /** @param array<int,string> $values */
    private function strRef(string $ref, array $values): string
    {
        $pts = '';
        foreach (array_values($values) as $i => $v) {
            $pts .= '<c:pt idx="' . $i . '"><c:v>' . $this->esc($v) . '</c:v></c:pt>';
        }
        return '<c:strRef><c:f>' . $this->esc($ref) . '</c:f><c:strCache><c:ptCount val="' . count($values) . '"/>' . $pts . '</c:strCache></c:strRef>';
    }

    /** @param array<int,float|int> $values */
    private function numRef(string $ref, array $values): string
    {
        $pts = '';
        foreach (array_values($values) as $i => $v) {
            $pts .= '<c:pt idx="' . $i . '"><c:v>' . $v . '</c:v></c:pt>';
        }
        return '<c:numRef><c:f>' . $this->esc($ref) . '</c:f><c:numCache><c:formatCode>General</c:formatCode><c:ptCount val="' . count($values) . '"/>' . $pts . '</c:numCache></c:numRef>';
    }

    /** @param array<string,mixed> $c */
    private function chartXml(array $c): string
    {
        $fill = fn(string $hex) => '<a:solidFill><a:srgbClr val="' . $hex . '"/></a:solidFill>';
        $title = fn(string $text, int $size) => '<c:title><c:tx><c:rich><a:bodyPr/><a:p><a:pPr><a:defRPr sz="' . $size . '" b="1"/></a:pPr><a:r><a:rPr lang="en-US" sz="' . $size . '" b="1"/><a:t>' . $this->esc($text) . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title>';

        $dPts = '';
        foreach (($c['colors'] ?? []) as $i => $hex) {
            $dPts .= '<c:dPt><c:idx val="' . $i . '"/><c:invertIfNegative val="0"/><c:bubble3D val="0"/><c:spPr>' . $fill($hex) . '</c:spPr></c:dPt>';
        }
        $labels = '<c:dLbls><c:numFmt formatCode="0.0" sourceLinked="0"/><c:spPr><a:noFill/><a:ln><a:noFill/></a:ln></c:spPr><c:showLegendKey val="0"/><c:showVal val="1"/><c:showCatName val="0"/><c:showSerName val="0"/><c:showPercent val="0"/><c:showBubbleSize val="0"/></c:dLbls>';
        $bar = '<c:barChart><c:barDir val="col"/><c:grouping val="clustered"/><c:varyColors val="0"/>'
            . '<c:ser><c:idx val="0"/><c:order val="0"/><c:tx><c:v>' . $this->esc($c['seriesName']) . '</c:v></c:tx>'
            . '<c:spPr>' . $fill('059669') . '</c:spPr><c:invertIfNegative val="0"/>' . $dPts . $labels
            . '<c:cat>' . $this->strRef($c['catRef'], $c['cats']) . '</c:cat><c:val>' . $this->numRef($c['valRef'], $c['vals']) . '</c:val></c:ser>'
            . '<c:gapWidth val="80"/><c:axId val="50010"/><c:axId val="50020"/></c:barChart>';

        $line = '';
        if (!empty($c['targetRef'])) {
            $line = '<c:lineChart><c:grouping val="standard"/><c:varyColors val="0"/>'
                . '<c:ser><c:idx val="1"/><c:order val="1"/><c:tx><c:v>' . $this->esc($c['targetName'] ?? 'Target') . '</c:v></c:tx>'
                . '<c:spPr><a:ln w="28575"><a:solidFill><a:srgbClr val="0F2A6B"/></a:solidFill><a:prstDash val="dash"/></a:ln></c:spPr>'
                . '<c:marker><c:symbol val="none"/></c:marker>'
                . '<c:cat>' . $this->strRef($c['catRef'], $c['cats']) . '</c:cat><c:val>' . $this->numRef($c['targetRef'], $c['targetVals']) . '</c:val><c:smooth val="0"/></c:ser>'
                . '<c:marker val="1"/><c:axId val="50010"/><c:axId val="50020"/></c:lineChart>';
        }

        $max = isset($c['max']) ? '<c:max val="' . $c['max'] . '"/>' : '';
        $axisTitle = !empty($c['axisTitle']) ? $title($c['axisTitle'], 1000) : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="' . self::NS_REL . '">'
            . '<c:roundedCorners val="0"/>'
            . '<c:chart>' . $title($c['title'], 1400) . '<c:autoTitleDeleted val="0"/>'
            . '<c:plotArea><c:layout/>' . $bar . $line
            . '<c:catAx><c:axId val="50010"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="b"/><c:majorTickMark val="out"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:crossAx val="50020"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/><c:noMultiLvlLbl val="0"/></c:catAx>'
            . '<c:valAx><c:axId val="50020"/><c:scaling><c:orientation val="minMax"/>' . $max . '<c:min val="0"/></c:scaling><c:delete val="0"/><c:axPos val="l"/><c:majorGridlines><c:spPr><a:ln><a:solidFill><a:srgbClr val="E5E7EB"/></a:solidFill></a:ln></c:spPr></c:majorGridlines>' . $axisTitle . '<c:numFmt formatCode="0" sourceLinked="0"/><c:majorTickMark val="out"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:crossAx val="50010"/><c:crosses val="autoZero"/><c:crossBetween val="between"/></c:valAx>'
            . '</c:plotArea>'
            . (!empty($c['targetRef']) ? '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>' : '')
            . '<c:plotVisOnly val="1"/><c:dispBlanksAs val="gap"/></c:chart></c:chartSpace>';
    }

    // ------------------------------------------------------------------ ZIP

    /** @param array<string,string> $files path => content */
    private static function zip(array $files): string
    {
        $out = '';
        $central = '';
        $count = 0;
        $time = 0;         // 00:00:00
        $date = (46 << 9) | (1 << 5) | 1; // fixed date, so the same content gives the same bytes
        foreach ($files as $path => $content) {
            $crc = crc32($content);
            $deflated = gzdeflate($content, 6);
            $method = 8;
            if ($deflated === false || strlen($deflated) >= strlen($content)) {
                $deflated = $content;
                $method = 0;
            }
            $offset = strlen($out);
            $header = pack('vvvvvVVVvv', 20, 0x0800, $method, $time, $date, $crc, strlen($deflated), strlen($content), strlen($path), 0);
            $out .= "PK\x03\x04" . $header . $path . $deflated;
            $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, $method, $time, $date, $crc, strlen($deflated), strlen($content), strlen($path), 0, 0, 0, 0, 0, $offset) . $path;
            $count++;
        }
        $end = "PK\x05\x06" . pack('vvvvVVv', 0, 0, $count, $count, strlen($central), strlen($out), 0);
        return $out . $central . $end;
    }
}
