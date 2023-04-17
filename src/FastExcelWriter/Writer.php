<?php

namespace avadim\FastExcelWriter;

use avadim\FastExcelWriter\Exception\Exception;
use avadim\FastExcelWriter\Exception\ExceptionFile;

/**
 * Class Writer
 *
 * @package avadim\FastExcelWriter
 */
class Writer
{
    /** @var Excel */
    protected $excel;

    /** @var array */
    protected array $tempFiles = [];

    /** @var string */
    protected $tempDir = '';

    // Experimental option but it does not work now
    private bool $linksEnabled = false;

    /**
     * Writer constructor
     *
     * @param array|null $options ;
     */
    public function __construct(?array $options = [])
    {
        date_default_timezone_get() || date_default_timezone_set('UTC');//php.ini missing tz, avoid warning
        if (isset($options['excel'])) {
            $this->excel = $options['excel'];
        }
        if (isset($options['temp_dir'])) {
            $this->tempDir = $options['temp_dir'];
        }
        if (!is_writable($this->tempFilename())) {
            throw new Exception('Warning: tempdir ' . sys_get_temp_dir() . ' is not writeable, use ->setTempDir()');
        }
        if (!class_exists('\ZipArchive')) {
            throw new Exception('Error: ZipArchive class does not exist');
        }
    }

    /**
     * Writer destructor
     */
    public function __destruct()
    {
        if (!empty($this->tempFiles)) {
            foreach ($this->tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * @param string $fileName
     *
     * @return WriterBuffer
     */
    public static function makeWriteBuffer(string $fileName)
    {
        return new WriterBuffer($fileName);
    }

    /**
     * @param Excel $excel
     */
    public function setExcel(Excel $excel)
    {
        $this->excel = $excel;
    }

    /**
     * @param string|null $tempDir
     */
    public function setTempDir(?string $tempDir = '')
    {
        $this->tempDir = $tempDir;
    }

    /**
     * @return bool|string
     */
    public function tempFilename()
    {
        $tempPrefix = 'xlsx_writer_';
        if (!$this->tempDir) {
            $tempDir = sys_get_temp_dir();
            $filename = tempnam($tempDir, $tempPrefix);
            if (!$filename) {
                $filename = tempnam(getcwd(), $tempPrefix);
            }
        } else {
            $filename = tempnam($this->tempDir, $tempPrefix);
        }
        if ($filename) {
            $this->tempFiles[] = $filename;
        }

        return $filename;
    }

    /**
     * @param string $fileName
     * @param bool|null $overWrite
     * @param array|null $metadata
     *
     * @return bool
     */
    public function saveToFile(string $fileName, ?bool $overWrite = true, ?array $metadata = [], $custom_workbook_print_area_xml=''): bool
    {
        $sheets = $this->excel->getSheets();
        foreach ($sheets as $sheet) {
            if (!$sheet->open) {
                $this->writeSheetDataBegin($sheet);
            }
            $this->writeSheetDataEnd($sheet);//making sure all footers have been written
        }

        if (!is_dir(dirname($fileName))) {
            ExceptionFile::throwNew('Directory "%s" for output file is not exist', dirname($fileName));
        }
        if (file_exists($fileName)) {
            if ($overWrite && is_writable($fileName)) {
                @unlink($fileName); //if the zip already exists, remove it
            }
            else {
                ExceptionFile::throwNew('File "%s" is not writeable', $fileName);
            }
        }
        $zip = new \ZipArchive();
        if (empty($sheets)) {
            ExceptionFile::throwNew('No worksheets defined');
        }
        if (!$zip->open($fileName, \ZIPARCHIVE::CREATE)) {
            ExceptionFile::throwNew('Unable to create zip "%s"', $fileName);
        }

        $zip->addEmptyDir('docProps/');
        $zip->addFromString('docProps/app.xml', $this->_buildAppXML($metadata));
        $zip->addFromString('docProps/core.xml', $this->_buildCoreXML($metadata));

        $zip->addEmptyDir('_rels/');
        $zip->addFromString('_rels/.rels', $this->_buildRelationshipsXML());

        $zip->addEmptyDir('xl/worksheets/');
        $xmlRels = [];
        foreach ($sheets as $sheet) {
            $xml = $sheet->getXmlRels();
            if ($xml && $this->linksEnabled) {
                $xmlRels['xl/worksheets/_rels/' . $sheet->xmlName . '.rels'] = $xml;
            }
            $zip->addFile($sheet->fileName, 'xl/worksheets/' . $sheet->xmlName);
        }

        if ($xmlRels) {
            $zip->addEmptyDir('xl/worksheets/_rels/');
            foreach ($xmlRels as $file => $content) {
                $zip->addFromString($file, $content);
            }
        }

        $xmlSharedStrings = $this->_getXmlSharedStrings();

        $zip->addFromString('xl/workbook.xml', $this->_buildWorkbookXML($sheets, $custom_workbook_print_area_xml));
        $zip->addFile($this->_writeStylesXML(), 'xl/styles.xml');  //$zip->addFromString("xl/styles.xml"           , self::buildStylesXML() );
        $zip->addFromString('[Content_Types].xml', $this->_buildContentTypesXML($sheets, $xmlSharedStrings));

        $zip->addEmptyDir('xl/_rels/');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->_buildWorkbookRelsXML($sheets, $xmlSharedStrings));

        if ($xmlSharedStrings) {
            $zip->addFromString('xl/worksheets/sharedStrings.xml', $xmlSharedStrings);
        }

        $zip->close();

        return true;
    }

    /**
     * @return string|null
     */
    protected function _getXmlSharedStrings(): ?string
    {
        $sharedStrings = $this->excel->getSharedStrings();
        if ($sharedStrings) {
            $uniqueCount = count($sharedStrings);
            $count = 0;
            $result = '';
            foreach ($sharedStrings as $string => $info) {
                $count += $info['count'];
                $result .= '<si><t>' . $string . '</t></si>';
            }
            return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $uniqueCount . '">'
                . $result
                . '</sst>';
        }

        return null;
    }

    /**
     * @param Sheet $sheet
     *
     * @return WriterBuffer
     */
    protected function _writeSheetHead($sheet)
    {
        $fileWriter = self::makeWriteBuffer($this->tempFilename());

        $fileWriter->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
        $xmlnsLinks = [
            'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"',
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"',
            'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x14ac xr xr2 xr3"',
            'xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac"',
            'xmlns:xr="http://schemas.microsoft.com/office/spreadsheetml/2014/revision"',
            'xmlns:xr2="http://schemas.microsoft.com/office/spreadsheetml/2015/revision2"',
            'xmlns:xr3="http://schemas.microsoft.com/office/spreadsheetml/2016/revision3"',
            'xr:uid="{00000000-0001-0000-0000-000000000000}"',
        ];
        $xmlns = implode(' ', $xmlnsLinks);
        $fileWriter->write('<worksheet ' . $xmlns . '>');

        $fileWriter->write('<sheetPr>');
        if ($sheet->getPageFit()) {
            $fileWriter->write('<pageSetUpPr fitToPage="1"/>');
        } else {
            $fileWriter->write('<pageSetUpPr fitToPage="false"/>');
        }
        $fileWriter->write('</sheetPr>');

        if ($sheet->rowCount + $sheet->colCount === 0) {
            $fileWriter->write('<dimension ref="A1"/>');
        } else {
            $maxCell = $sheet->maxCell();
            $fileWriter->write('<dimension ref="A1:' . $maxCell . '"/>');
        }

        //custom sheet protection here

        if($sheet->getPasswordForReadOnlyProtection()!="" && $sheet->getPasswordForReadOnlyProtection()!=NULL){
            $fileWriter->write('<sheetProtection sheet="true" password="'.$sheet->getPasswordForReadOnlyProtection().'" objects="true" scenarios="true" />');
        }

        $rightToLeftValue = $sheet->isRightToLeft() ? 'true' : 'false';

        $fileWriter->write('<sheetViews>');
        $fileWriter->write('<sheetView tabSelected="1" workbookViewId="0"/>');

        $tabSelected = ($sheet->active ? 'tabSelected="true"' : '');
        $fileWriter->write('<sheetView colorId="64" defaultGridColor="true" rightToLeft="' . $rightToLeftValue . '" showFormulas="false" showGridLines="true" showOutlineSymbols="true" showRowColHeaders="true" showZeros="true" ' . $tabSelected . ' topLeftCell="A1" view="normal" windowProtection="false" workbookViewId="0" zoomScale="100" zoomScaleNormal="100" zoomScalePageLayoutView="100">');

        $paneRow = ($sheet->freezeRows ? $sheet->freezeRows + 1 : 0);
        $paneCol = ($sheet->freezeColumns ? $sheet->freezeColumns + 1 : 0);
        if ($sheet->freezeRows && $sheet->freezeColumns) {
            // frozen rows and cols
            $fileWriter->write('<pane ySplit="' . $sheet->freezeRows . '" xSplit="' . $sheet->freezeColumns . '" topLeftCell="' . Excel::cellAddress($paneRow, $paneCol) . '" activePane="bottomRight" state="frozen"/>');
            $fileWriter->write('<selection activeCell="' . Excel::cellAddress($paneRow, 1) . '" activeCellId="0" pane="topRight" sqref="' . Excel::cellAddress($paneRow, 1) . '"/>');
            $fileWriter->write('<selection activeCell="' . Excel::cellAddress(1, $paneCol) . '" activeCellId="0" pane="bottomLeft" sqref="' . Excel::cellAddress(1, $paneCol) . '"/>');
            $fileWriter->write('<selection activeCell="' . Excel::cellAddress($paneRow, $paneCol) . '" activeCellId="0" pane="bottomRight" sqref="' . Excel::cellAddress($paneRow, $paneCol) . '"/>');
        }
        elseif ($sheet->freezeRows) {
            // frozen rows only
            $fileWriter->write('<pane ySplit="' . $sheet->freezeRows . '" topLeftCell="' . Excel::cellAddress($paneRow, 1) . '" activePane="bottomLeft" state="frozen"/>');
            $fileWriter->write('<selection activeCell="' . Excel::cellAddress($paneRow, 1) . '" activeCellId="0" pane="bottomLeft" sqref="' . Excel::cellAddress($paneRow, 1) . '"/>');
        }
        elseif ($sheet->freezeColumns) {
            // frozen cols only
            $fileWriter->write('<pane xSplit="' . $sheet->freezeColumns . '" topLeftCell="' . Excel::cellAddress(1, $paneCol) . '" activePane="topRight" state="frozen"/>');
            $fileWriter->write('<selection activeCell="' . Excel::cellAddress(1, $paneCol) . '" activeCellId="0" pane="topRight" sqref="' . Excel::cellAddress(1, $paneCol) . '"/>');
        }
        else {
            // not frozen
            $fileWriter->write('<selection activeCell="A1" activeCellId="0" pane="topLeft" sqref="A1"/>');
        }
        $fileWriter->write('</sheetView>');

        $fileWriter->write('</sheetViews>');

        if (!empty($sheet->colWidths)) {
            $fileWriter->write('<cols>');
            foreach ($sheet->colWidths as $colNum => $columnWidth) {
                $fileWriter->write('<col min="' . ($colNum + 1) . '" max="' . ($colNum + 1) . '" width="' . $columnWidth . '" customWidth="1"/>');
            }
            $fileWriter->write('</cols>');
        }
        //$fileWriter->write('<col collapsed="false" hidden="false" max="1024" min="' . ($i + 1) . '" style="0" customWidth="false" width="11.5"/>');

        return $fileWriter;
    }

    /**
     * @param Sheet $sheet
     */
    public function writeSheetDataBegin(Sheet $sheet)
    {
        //if already initialized
        if ($sheet->open) {
            return;
        }

        $sheet->writeDataBegin($this);
    }

    /**
     * @param Sheet $sheet
     */
    public function writeSheetDataEnd(Sheet $sheet)
    {
        if ($sheet->close) {
            return;
        }

        $sheet->writeDataEnd();

        $mergedCells = $sheet->getMergedCells();
        if ($mergedCells) {
            $sheet->fileWriter->write('<mergeCells>');
            foreach ($mergedCells as $range) {
                $sheet->fileWriter->write('<mergeCell ref="' . $range . '"/>');
            }
            $sheet->fileWriter->write('</mergeCells>');
        }

        if ($sheet->autoFilter) {
            $minCell = $sheet->autoFilter;
            $maxCell = Excel::cellAddress($sheet->rowCount, $sheet->colCount);
            $sheet->fileWriter->write('<autoFilter ref="' . $minCell . ':' . $maxCell . '"/>');
        }

        $pageSetupAttr = 'orientation="' . $sheet->getPageOrientation() . '"';
        if ($sheet->getPageFit()) {
            $pageFitToWidth = $sheet->getPageFitToWidth();
            $pageFitToHeight = $sheet->getPageFitToHeight();
            if ($pageFitToWidth === 1) {
                $pageSetupAttr .= ' fitToHeight="' . $pageFitToHeight . '" ';
            } else {
                $pageSetupAttr .= ' fitToHeight="' . $pageFitToHeight . '" fitToWidth="' . $pageFitToWidth . '"';
            }
        }
        $sheet->fileWriter->write('<printOptions headings="false" gridLines="false" gridLinesSet="true" horizontalCentered="false" verticalCentered="false"/>');

        $links = $sheet->getExternalLinks();
        if ($links && $this->linksEnabled) {
            $sheet->fileWriter->write('<hyperlinks>');
            foreach ($links as $id => $data) {
                $sheet->fileWriter->write('<hyperlink ref="' . $data['cell'] . '" r:id="rId' . $id . '" xr:uid="{' . $data['uid'] . '}"/>');
            }
            $sheet->fileWriter->write('</hyperlinks>');
        }

        $sheet->fileWriter->write('<pageMargins left="0.5" right="0.5" top="1.0" bottom="1.0" header="0.5" footer="0.5"/>');

        $sheet->fileWriter->write("<pageSetup  paperSize=\"1\" useFirstPageNumber=\"1\" horizontalDpi=\"0\" verticalDpi=\"0\" $pageSetupAttr r:id=\"rId1\"/>'");

        $sheet->fileWriter->write('<headerFooter differentFirst="false" differentOddEven="false">');
        $sheet->fileWriter->write('<oddHeader>&amp;C&amp;&quot;Times New Roman,Regular&quot;&amp;12&amp;A</oddHeader>');
        $sheet->fileWriter->write('<oddFooter>&amp;C&amp;&quot;Times New Roman,Regular&quot;&amp;12Page &amp;P</oddFooter>');
        $sheet->fileWriter->write('</headerFooter>');

        if($sheet->getCustomPageBreakXml()!=='' && $sheet->getCustomPageBreakXml()){
            $sheet->fileWriter->write($sheet->getCustomPageBreakXml());
        }

        $sheet->fileWriter->write('</worksheet>');
        $sheet->fileWriter->flush(true);

        $headWriter = $this->_writeSheetHead($sheet);
        $headWriter->appendFileWriter($sheet->fileWriter, $this->tempFilename());;

        $sheet->fileWriter->close();
        $sheet->close = true;

        $sheet->resetFileWriter($headWriter);
    }

    /**
     * @param $formula
     * @param $baseAddress
     *
     * @return string
     */
    protected function _convertFormula($formula, $baseAddress): string
    {
        static $functionNames = [];

        $mark = md5(microtime());
        $replace = [];
        // temporary replace strings
        if (strpos($formula, '"') !== false) {
            $replace = [[], []];
            $formula = preg_replace_callback('/"[^"]+"/', static function ($matches) use ($mark, &$replace) {
                $key = '<<' . $mark . '-' . md5($matches[0]) . '>>';
                $replace[0][] = $key;
                $replace[1][] = $matches[0];
                return $key;
            }, $formula);
        }
        // change relative addresses
        $formula = preg_replace_callback('/(\W)(R\[?(-?\d+)?\]?C\[?(-?\d+)?\]?)/', static function ($matches) use ($baseAddress) {
            $indexes = Excel::rangeRelOffsets($matches[2]);
            if (isset($indexes[0], $indexes[1])) {
                $row = $baseAddress[0] + $indexes[0];
                $col = $baseAddress[1] + $indexes[1];
                $cell = Excel::cellAddress($row, $col);
                if ($cell) {
                    return $matches[1] . $cell;
                }
            }
            return $matches[0];
        }, $formula);

        if (!empty($this->excel->style->localeSettings['functions']) && strpos($formula, '(')) {
            // replace national function names
            if (empty($functionNames)) {
                $functionNames = [[], []];
                foreach ($this->excel->style->localeSettings['functions'] as $name => $nameEn) {
                    $functionNames[0][] = $name . '(';
                    $functionNames[1][] = $nameEn . '(';
                }
            }
            $formula = str_replace($functionNames[0], $functionNames[1], $formula);
        }

        if ($replace && !empty($replace[0])) {
            // restore strings
            $formula = str_replace($replace[0], $replace[1], $formula);
        }

        return $formula;
    }

    /**
     * @param WriterBuffer $file
     * @param int $rowNumber
     * @param int $colNumber
     * @param mixed $value
     * @param mixed $numFormatType
     * @param int|null $cellStyleIdx
     */
    public function _writeCell(WriterBuffer $file, int $rowNumber, int $colNumber, $value, $numFormatType, ?int $cellStyleIdx = 0)
    {
        $cellName = Excel::cellAddress($rowNumber, $colNumber);

        if (!is_scalar($value) || $value === '') { //objects, array, empty; null is not scalar
            $file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '"/>');
        }
        elseif (is_string($value) && $value[0] === '=') {
            // formula
            $value = $this->_convertFormula($value, [$rowNumber, $colNumber]);
            $file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" t="s"><f>' . self::xmlSpecialChars($value) . '</f></c>');
        }
        elseif ($numFormatType === 'n_shared_string') {
            $file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" t="s"><v>' . $value . '</v></c>');
        }
        elseif ($numFormatType === 'n_string' || ($numFormatType === 'n_numeric' && !is_numeric($value))) {
            $file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" t="inlineStr"><is><t xml:space="preserve">' . self::xmlSpecialChars($value) . '</t></is></c>');
        }
        else {
            if ($numFormatType === 'n_date' || $numFormatType === 'n_datetime') {
                $dateValue = self::convertDateTime($value);
                if ($dateValue === false) {
                    $numFormatType = 'n_auto';
                }
                else {
                    $value = $dateValue;
                }
            }
            if ($numFormatType === 'n_date') {
                //$file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" t="n"><v>' . (int)self::convertDateTime($value) . '</v></c>');
                $file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '"><v>' . (int)$value . '</v></c>');
            }
            elseif ($numFormatType === 'n_datetime') {
                $file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" t="n"><v>' . $value . '</v></c>');
            }
            elseif ($numFormatType === 'n_numeric') {
                //$file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" t="n"><v>' . self::xmlSpecialChars($value) . '</v></c>');//int,float,currency
                if (!is_int($value) && !is_float($value)) {
                    $value = self::xmlSpecialChars($value);
                }
                $file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" ><v>' . $value . '</v></c>');//int,float,currency
            }
            elseif ($numFormatType === 'n_auto' || 1) { //auto-detect unknown column types
                if (!is_string($value) || $value === '0' || ($value[0] !== '0' && preg_match('/^\d+$/', $value)) || preg_match("/^-?(0|[1-9]\d*)(\.\d+)?$/", $value)) {
                    //$file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" t="n"><v>' . self::xmlSpecialChars($value) . '</v></c>');//int,float,currency
                    $file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" t="n"><v>' . $value . '</v></c>');//int,float,currency
                }
                else {
                    //implied: ($cellFormat=='string')
                    if (strpos($value, '\=') === 0 || strpos($value, '\\\\=') === 0) {
                        $value = substr($value, 1);
                    }
                    $file->write('<c r="' . $cellName . '" s="' . $cellStyleIdx . '" t="inlineStr"><is><t xml:space="preserve">' . self::xmlSpecialChars($value) . '</t></is></c>');
                }
            }
        }
    }

    /**
     * @param array $border
     * @param string $side
     *
     * @return string
     */
    protected function _makeBorderSideTag(array $border, string $side)
    {
        if (empty($border[$side]) || empty($border[$side]['style'])) {
            $tag = "<$side/>";
        } elseif (empty($border[$side]['color'])) {
            $tag = "<$side style=\"" . $border[$side]['style'] . '"/>';
        } else {
            $tag = "<$side style=\"" . $border[$side]['style'] . '">';
            $tag .= '<color rgb="' . $border[$side]['color'] . '"/>';
            $tag .= "</$side>";
        }
        return $tag;
    }

    /**
     * @param array $borders
     *
     * @return string
     */
    protected function _makeBordersTag(array $borders)
    {
        $tag = '<borders count="' . (count($borders)) . '">';
        foreach ($borders as $border) {
            $tag .= '<border diagonalDown="false" diagonalUp="false">';
            $tag .= $this->_makeBorderSideTag($border, 'left');
            $tag .= $this->_makeBorderSideTag($border, 'right');
            $tag .= $this->_makeBorderSideTag($border, 'top');
            $tag .= $this->_makeBorderSideTag($border, 'bottom');
            $tag .= '<diagonal/>';
            $tag .= '</border>';
        }
        $tag .= '</borders>';

        return $tag;
    }

    /**
     * @param string $name
     * @param array|null $data
     *
     * @return string
     */
    protected function _makeTag(string $name, ?array $data): string
    {
        $tag = '<' . $name;
        if (!empty($data['_attributes'])) {
            foreach ($data['_attributes'] as $key => $val) {
                $tag .= ' ' . $key . '="' . $val . '"';
            }
        }
        if (empty($data['_children'])) {
            $tag .= '/>';
        } else {
            $tag .= '>';
            foreach ($data['_children'] as $key => $val) {
                $tag .= $this->_makeTag($key, $val);
            }
            $tag .= '</' . $name . '>';
        }

        return $tag;
    }

    /**
     * @param string $name
     * @param array|null $data
     *
     * @return string
     */
    protected function _makeFillTag(string $name, ?array $data): string
    {
        if (!empty($data['_children'])) {
            // orders of children is very important!
            $children = [];
            if (!empty($data['_children']['fgColor'])) {
                $children['fgColor'] = $data['_children']['fgColor'];
            }
            if (!empty($data['_children']['bgColor'])) {
                $children['bgColor'] = $data['_children']['bgColor'];
            }
            $data['_children'] = $children;
        }

        return $this->_makeTag($name, $data);
    }

    /**
     * @return bool|string
     */
    protected function _writeStylesXML()
    {
        $schemaLinks = [
            'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"',
            //'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x14ac x16r2 xr"',
            //'xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac"',
            //'xmlns:x16r2="http://schemas.microsoft.com/office/spreadsheetml/2015/02/main"',
            //'xmlns:xr="http://schemas.microsoft.com/office/spreadsheetml/2014/revision"',
        ];

        $temporaryFilename = $this->tempFilename();
        $file = new WriterBuffer($temporaryFilename);
        $file->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n");
        $links = implode("\n", $schemaLinks);
        $file->write("<styleSheet $links>");
        // cell styles, table styles, and pivot styles
        //$file->write('');

        //// +++++++++++
        //// <numFmts/>
        $numberFormats = $this->excel->style->_getNumberFormats();
        if (!$numberFormats) {
            $file->write('<numFmts count="0"/>');
        } else {
            $file->write('<numFmts count="' . count($numberFormats) . '">');
            foreach ($numberFormats as $num => $fmt) {
                $file->write('<numFmt numFmtId="' . (164 + $num) . '" formatCode="' . self::xmlSpecialChars($fmt) . '" />');
            }
            $file->write('</numFmts>');
        }

        //// +++++++++++
        //// <fonts/>
        $fonts = $this->excel->style->getStyleFonts();
        if (!$fonts) {
            $file->write('<numFmts count="0"/>');
        }
        else {
            $file->write('<fonts count="' . count($fonts) . '">');
            foreach ($fonts as $font) {
                if (!empty($font)) { //fonts have 2 empty placeholders in array to offset the 4 static xml entries above
                    $file->write('<font>');
                    $file->write('<name val="' . $font['name'] . '"/><charset val="1"/><family val="' . (int)$font['family'] . '"/>');
                    $file->write('<sz val="' . (int)$font['size'] . '"/>');
                    if (!empty($font['color'])) {
                        if (is_array($font['color'])) {
                            $s = '<color';
                            foreach ($font['color'] as $key => $val) {
                                $s .= ' ' . $key . '="' . $val . '"';
                            }
                            $s .= '/>';
                            $file->write($s);
                        }
                        else {
                            $file->write('<color rgb="' . $font['color'] . '"/>');
                        }
                    }
                    if (!empty($font['style-bold'])) {
                        $file->write('<b val="true"/>');
                    }
                    if (!empty($font['style-italic'])) {
                        $file->write('<i val="true"/>');
                    }
                    if (!empty($font['style-underline'])) {
                        $file->write('<u val="single"/>');
                    }
                    if (!empty($font['style-strike'])) {
                        $file->write('<strike val="true"/>');
                    }
                    $file->write('</font>');
                }
            }
            $file->write('</fonts>');
        }

        //// +++++++++++
        //// <fills/>
        $fills = $this->excel->style->getStyleFills();
        if (!$fills) {
            $file->write('<fills count="0"/>');
        }
        else {
            $file->write('<fills count="' . count($fills) . '">');
            foreach ($fills as $fill) {
                if (!empty($fill['patternFill'])) {
                    $file->write('<fill>');
                    $file->write($this->_makeFillTag('patternFill', $fill['patternFill']));
                    $file->write('</fill>');
                }
            }

            $file->write('</fills>');
        }

        //// +++++++++++
        // <borders/>
        $borders = $this->excel->style->getStyleBorders();
        if (!$borders) {
            $file->write('<borders count="0"/>');
        }
        else {
            $file->write($this->_makeBordersTag($borders));
        }

        //// +++++++++++
        // <cellStyleXfs/>
        $cellStyleXfs = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>',
            //'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false"/>',
            //'<xf numFmtId="9" fontId="0" fillId="0" borderId="0" applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false"/>',
            //'<xf numFmtId="41" fontId="0" fillId="0" borderId="0" applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false"/>',
            //'<xf numFmtId="42" fontId="0" fillId="0" borderId="0" applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false"/>',
            //'<xf numFmtId="43" fontId="0" fillId="0" borderId="0" applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false"/>',
            //'<xf numFmtId="44" fontId="0" fillId="0" borderId="0" applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false"/>',
        ];
        $file->write('<cellStyleXfs count="' . count($cellStyleXfs) . '">');
        foreach ($cellStyleXfs as $cellStyleXf) {
            $file->write($cellStyleXf);
        }
        $file->write('</cellStyleXfs>');

        //// +++++++++++
        // <cellXfs/>
        $cellXfs = $this->excel->style->getStyleCellXfs();
        if (!$cellXfs) {
            $file->write('<cellXfs count="1">');
            $file->write('<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>');
            $file->write('</cellXfs>');
        }
        else {
            $file->write('<cellXfs count="' . count($cellXfs) . '">');
            foreach ($cellXfs as $cellXf) {
                $xfAttr = 'applyFont="true" ';
                if (!empty($cellXf['alignment'])) {
                    $xfAttr .= 'applyAlignment="true" ';
                }
                if (isset($cellXf['border_idx'])) {
                    $xfAttr .= 'applyBorder="true" ';
                }
                $alignmentAttr = '';
                if (!empty($cellXf['text-align'])) {
                    $alignmentAttr .= ' horizontal="' . $cellXf['text-align'] . '"';
                }
                if (!empty($cellXf['vertical-align'])) {
                    $alignmentAttr .= ' vertical="' . $cellXf['vertical-align'] . '"';
                }
                if (!empty($cellXf['text-wrap'])) {
                    $alignmentAttr .= ' wrapText="true"';
                }
                $xfId = $cellXf['xfId'] ?? 0;
                if ($alignmentAttr) {
                    $file->write('<xf ' . $xfAttr . ' borderId="' . $cellXf['borderId'] . '" fillId="' . $cellXf['fillId'] . '" '
                        . 'fontId="' . $cellXf['fontId'] . '" numFmtId="' . (164 + $cellXf['numFmtId']) . '" xfId="' . $xfId . '">');
                    $file->write('	<alignment ' . $alignmentAttr . '/>');
                    $file->write('</xf>');
                }
                else {
                    $file->write('<xf ' . $xfAttr . ' borderId="' . $cellXf['borderId'] . '" fillId="' . $cellXf['fillId'] . '" '
                        . 'fontId="' . $cellXf['fontId'] . '" numFmtId="' . (164 + $cellXf['numFmtId']) . '" xfId="' . $xfId . '" />');
                }
            }

            $file->write('</cellXfs>');
        }

        //// +++++++++++
        // <cellStyles/>
        $cellStyles = [
            '<cellStyle builtinId="0" customBuiltin="false" name="Normal" xfId="0"/>',
            //'<cellStyle builtinId="8" customBuiltin="false" name="Hyperlink" xfId="1" />',
            //'<cellStyle builtinId="3" customBuiltin="false" name="Comma" xfId="2"/>',
            //'<cellStyle builtinId="6" customBuiltin="false" name="Comma [0]" xfId="3"/>',
            //'<cellStyle builtinId="4" customBuiltin="false" name="Currency" xfId="4"/>',
            //'<cellStyle builtinId="7" customBuiltin="false" name="Currency [0]" xfId="5"/>',
            //'<cellStyle builtinId="5" customBuiltin="false" name="Percent" xfId="6"/>',
        ];
        $file->write('<cellStyles count="' . count($cellStyles) . '">');
        foreach ($cellStyles as $cellStyle) {
            $file->write($cellStyle);
        }
        $file->write('</cellStyles>');

        // <dxfs/>
        $file->write('<dxfs count="0"/>');

        //// +++++++++++
        // <tableStyles/>
        $file->write('<tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>');

        $file->write('</styleSheet>');
        $file->close();

        return $temporaryFilename;
    }

    /**
     * @return string
     */
    protected function _buildAppXML($metadata)
    {
        $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $appXml .= '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">';
        $appXml .= '<TotalTime>0</TotalTime>';
        $appXml .= '<Company>' . self::xmlSpecialChars($metadata['company'] ?? '') . '</Company>';
        $appXml .= '</Properties>';

        return $appXml;
    }

    /**
     * @return string
     */
    protected function _buildCoreXML($metadata)
    {
        $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $coreXml .= '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
        $coreXml .= '<dcterms:created xsi:type="dcterms:W3CDTF">' . date("Y-m-d\TH:i:s.00\Z") . '</dcterms:created>';//$dateTime = '2014-10-25T15:54:37.00Z';
        $coreXml .= '<dc:title>' . self::xmlSpecialChars($metadata['title'] ?? '') . '</dc:title>';
        $coreXml .= '<dc:subject>' . self::xmlSpecialChars($metadata['subject'] ?? '') . '</dc:subject>';
        $coreXml .= '<dc:creator>' . self::xmlSpecialChars($metadata['author'] ?? '') . '</dc:creator>';
        if (!empty($metadata['keywords'])) {
            $coreXml .= '<cp:keywords>' . self::xmlSpecialChars(implode(", ", (array)$metadata['keywords'])) . '</cp:keywords>';
        }
        $coreXml .= '<dc:description>' . self::xmlSpecialChars($metadata['description'] ?? '') . '</dc:description>';
        $coreXml .= '<cp:revision>0</cp:revision>';
        $coreXml .= '</cp:coreProperties>';

        return $coreXml;
    }

    /**
     * @return string
     */
    protected function _buildRelationshipsXML()
    {
        $relsXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $relsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $relsXml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $relsXml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>';
        $relsXml .= '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>';
        $relsXml .= "\n";
        $relsXml .= '</Relationships>';

        return $relsXml;
    }

    /**
     * @param Sheet[] $sheets
     *
     * @return string
     */
    protected function _buildWorkbookXML($sheets, $custom_workbook_print_area_xml='')
    {
        $i = 0;
        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $workbookXml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $workbookXml .= '<fileVersion appName="Calc"/><workbookPr backupFile="false" showObjects="all" date1904="false"/><workbookProtection/>';
        $workbookXml .= '<bookViews><workbookView activeTab="0" firstSheet="0" showHorizontalScroll="true" showSheetTabs="true" showVerticalScroll="true" tabRatio="212" windowHeight="8192" windowWidth="16384" xWindow="0" yWindow="0"/></bookViews>';
        $workbookXml .= '<sheets>';
        $definedNames = '';
        foreach ($sheets as $sheet) {
            $sheetName = self::sanitizeSheetName($sheet->sheetName);
            $workbookXml .= '<sheet name="' . self::xmlSpecialChars($sheetName) . '" sheetId="' . ($i + 1) . '" state="visible" r:id="rId' . ($i + 2) . '"/>';
            if ($sheet->absoluteAutoFilter) {
                $filterRange = $sheet->absoluteAutoFilter . ':' . Excel::cellAddress($sheet->rowCount, $sheet->colCount, true);
                $definedNames .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . $sheetName . '\'!' . $filterRange . '</definedName>';
            }
            $i++;
        }
        $workbookXml .= '</sheets>';
        if($custom_workbook_print_area_xml!='' && $custom_workbook_print_area_xml){
            if ($definedNames) {
                $workbookXml .= '<definedNames>' . $custom_workbook_print_area_xml . $definedNames . '</definedNames>';
            }else{
                $workbookXml .= '<definedNames>' . $custom_workbook_print_area_xml . '</definedNames>';
            }
        }
        else if ($definedNames) {
            $workbookXml .= '<definedNames>' . $definedNames . '</definedNames>';
        }
        else {
            $workbookXml .= '<definedNames/>';
        }

        $workbookXml .= '<calcPr iterateCount="100" refMode="A1" iterate="false" iterateDelta="0.001"/></workbook>';

        return $workbookXml;
    }

    /**
     * @param Sheet[] $sheets
     * @param mixed $sharedString
     *
     * @return string
     */
    protected function _buildWorkbookRelsXML($sheets, $sharedString)
    {
        $wkbkrelsXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $wkbkrelsXml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $wkbkrelsXml .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $i = 2;
        foreach ($sheets as $sheet) {
            $wkbkrelsXml .= '<Relationship Id="rId' . ($i++) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/' . ($sheet->xmlName) . '"/>';
        }
        $wkbkrelsXml .= "\n";
        if ($sharedString) {
            $wkbkrelsXml .= '<Relationship Id="rId' . ($i++) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';        }
        $wkbkrelsXml .= '</Relationships>';

        return $wkbkrelsXml;
    }

    /**
     * @param Sheet[] $sheets
     * @param mixed $xmlSharedStrings
     *
     * @return string
     */
    protected function _buildContentTypesXML(array $sheets, $xmlSharedStrings): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';

        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        foreach ($sheets as $sheet) {
            $xml .= '<Override PartName="/xl/worksheets/' . ($sheet->xmlName) . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        if ($xmlSharedStrings) {
            $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        }

        $xml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $xml .= "\n";
        $xml .= '</Types>';

        return $xml;
    }

    protected function _x_buildContentTypesXML(array $sheets, $xmlSharedStrings): string
    {
        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $contentTypesXml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $contentTypesXml .= '<Override PartName="/_rels/.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $contentTypesXml .= '<Override PartName="/xl/_rels/workbook.xml.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        foreach ($sheets as $sheet) {
            $contentTypesXml .= '<Override PartName="/xl/worksheets/' . ($sheet->xmlName) . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $contentTypesXml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $contentTypesXml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        if ($xmlSharedStrings) {
            $contentTypesXml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        }

        $contentTypesXml .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $contentTypesXml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $contentTypesXml .= "\n";
        $contentTypesXml .= '</Types>';

        return $contentTypesXml;
    }

    /**
     * @param $filename
     *
     * @return mixed
     */
    public static function sanitizeFilename($filename) //http://msdn.microsoft.com/en-us/library/aa365247%28VS.85%29.aspx
    {
        $nonPrinting = array_map('chr', range(0, 31));
        $invalidChars = ['<', '>', '?', '"', ':', '|', '\\', '/', '*', '&'];
        $allInvalids = array_merge($nonPrinting, $invalidChars);

        return str_replace($allInvalids, "", $filename);
    }

    /**
     * @param $sheetName
     *
     * @return string
     */
    public static function sanitizeSheetName($sheetName)
    {
        static $badChars = '\\/?*:[]';
        static $goodChars = '        ';

        $sheetName = strtr($sheetName, $badChars, $goodChars);
        $sheetName = mb_substr($sheetName, 0, 31);
        $sheetName = trim(trim(trim($sheetName), "'"));//trim before and after trimming single quotes

        return !empty($sheetName) ? $sheetName : 'Sheet' . ((mt_rand() % 900) + 100);
    }

    /**
     * @param $val
     *
     * @return string
     */
    public static function xmlSpecialChars($val)
    {
        //note, bad chars does not include \t\n\r (\x09\x0a\x0d)
        static $badChars = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0b\x0c\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";
        static $goodChars = "                              ";

        return strtr(htmlspecialchars($val, ENT_QUOTES | ENT_XML1), $badChars, $goodChars);//strtr appears to be faster than str_replace
    }

    /**
     * @param mixed $dateInput
     *
     * @return int|float|bool
     */
    public static function convertDateTime($dateInput) //thanks to Excel::Writer::XLSX::Worksheet.pm (perl)
    {
        if (is_int($dateInput) || (is_string($dateInput) && preg_match('/^\d+$/', $dateInput))) {
            // date as timestamp
            $time = (int)$dateInput;
        } elseif (preg_match('/^(\d+:\d{1,2})(:\d{1,2})?$/', $dateInput, $matches)) {
            // time only
            $time = strtotime('1900-01-00 ' . $matches[1] . ($matches[2] ?? ':00'));
        } elseif (is_string($dateInput) && $dateInput && $dateInput[0] >= '0' && $dateInput[0] <= '9') {
            //starts with a digit
            $time = strtotime($dateInput);
        } else {
            $time = 0;
        }

        if ($time && preg_match('/(\d{4})-(\d{2})-(\d{2})\s(\d+):(\d{2}):(\d{2})/', date('Y-m-d H:i:s', $time), $matches)) {
            [$junk, $year, $month, $day, $hour, $min, $sec] = $matches;
            $seconds = $sec / 86400 + $min / 1440 + $hour / 24;
        } else {
            // wrong data/time string
            return false;
        }

        //using 1900 as epoch, not 1904, ignoring 1904 special case

        # Special cases for Excel.
        if ("$year-$month-$day" === '1899-12-31') {
            return $seconds;
        }    # Excel 1900 epoch
        if ("$year-$month-$day" === '1900-01-00') {
            return $seconds;
        }    # Excel 1900 epoch
        if ("$year-$month-$day" === '1900-02-29') {
            return 60 + $seconds;
        }    # Excel false leap day

        # We calculate the date by calculating the number of days since the epoch
        # and adjust for the number of leap days. We calculate the number of leap
        # days by normalising the year in relation to the epoch. Thus the year 2000
        # becomes 100 for 4 and 100 year leap days and 400 for 400 year leap days.
        $epoch = 1900;
        $offset = 0;
        $norm = 300;
        $range = $year - $epoch;

        # Set month days and check for leap year.
        $leap = (($year % 400 === 0) || (($year % 4 === 0) && ($year % 100))) ? 1 : 0;
        $mdays = [31, ($leap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        # Some boundary checks
        if ($year !== 0 || $month !== 0 || $day !== 0) {
            if ($year < $epoch || $year > 9999) {
                // wrong year
                return false;
            }
            if ($month < 1 || $month > 12) {
                // wrong month
                return false;
            }
            if ($day < 1 || $day > $mdays[$month - 1]) {
                // wrong day
                return false;
            }
        }

        # Accumulate the number of days since the epoch.
        $days = $day;    # Add days for current month
        $days += array_sum(array_slice($mdays, 0, $month - 1));    # Add days for past months
        $days += $range * 365;                      # Add days for past years
        $days += (int)(($range) / 4);             # Add leapdays
        $days -= (int)(($range + $offset) / 100); # Subtract 100 year leapdays
        $days += (int)(($range + $offset + $norm) / 400);  # Add 400 year leapdays
        $days -= $leap;                                      # Already counted above

        # Adjust for Excel erroneously treating 1900 as a leap year.
        if ($days > 59) {
            $days++;
        }

        return $days + $seconds;
    }
}

// EOF