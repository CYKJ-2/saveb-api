<?php

namespace App\Services;

use Generator;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/** 只读取 XLSX 的单元格文本和公式缓存，不执行公式、不加载外部链接和图片。 */
class AnalysisWorkbookReader
{
    private const XML_LIMIT = 100_000_000;

    /**
     * 逐个读取工作表，包括隐藏行列，并保留真实行号与列字母。
     *
     * @param string $path 本地 XLSX 文件绝对路径；文件不会被修改
     * @param callable|null $acceptSheet 可选 Sheet 名过滤器，在读取工作表 XML 前执行
     * @return Generator<int, array{name: string, rows: array}> 各工作表名称和非空行；公式保留文本及已有缓存
     */
    public function sheets(string $path, ?callable $acceptSheet = null): Generator
    {
        $archive = new ZipArchive();
        if ($archive->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Invalid XLSX file.']);
        }
        try {
            $workbook = $this->xml($this->entry($archive, 'xl/workbook.xml'));
            $relationships = $this->xml($this->entry($archive, 'xl/_rels/workbook.xml.rels'));
            $sharedStrings = $this->sharedStrings($archive);
            $targets = [];
            foreach ($relationships->xpath('/*/*[local-name()="Relationship"]') ?: [] as $relationship) {
                if ((string) $relationship['TargetMode'] === 'External') {
                    continue;
                }
                $target = (string) $relationship['Target'];
                if (str_contains($target, '..') || str_contains($target, ':')) {
                    continue;
                }
                $targets[(string) $relationship['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
            $sheets = $workbook->xpath('/*/*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [];
            if (count($sheets) > 40) {
                throw ValidationException::withMessages(['file' => 'At most 40 worksheets are supported.']);
            }
            $date1904 = (string) ($workbook->workbookPr['date1904'] ?? '') === '1';
            foreach ($sheets as $sheet) {
                if ($acceptSheet !== null && !$acceptSheet((string) $sheet['name'])) {
                    continue;
                }
                $relationshipId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                if (!isset($targets[$relationshipId])) {
                    throw ValidationException::withMessages(['file' => 'Worksheet relationship is missing.']);
                }
                yield [
                    'name' => (string) $sheet['name'],
                    'date1904' => $date1904,
                    'rows' => $this->rows($this->entry($archive, $targets[$relationshipId]), $sharedStrings),
                ];
            }
        } finally {
            $archive->close();
        }
    }

    /**
     * 读取受大小限制的 XML 文件，防止压缩包异常膨胀。
     *
     * @param ZipArchive $archive 已打开的来源压缩包
     * @param string $name 已校验的压缩包内路径
     * @return string XML 原文；不存在或超过限制时抛出校验异常
     */
    private function entry(ZipArchive $archive, string $name): string
    {
        $information = $archive->statName($name);
        if (!$information || $information['size'] > self::XML_LIMIT) {
            throw ValidationException::withMessages(['file' => 'Missing or oversized worksheet XML.']);
        }
        $contents = $archive->getFromName($name);
        if ($contents === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $contents)) {
            throw ValidationException::withMessages(['file' => 'Unsupported XML document.']);
        }

        return $contents;
    }

    /**
     * 安全解析 XML，拒绝外部实体和格式损坏的内容。
     *
     * @param string $contents 已做大小和实体校验的 XML
     * @return SimpleXMLElement 解析后的节点树
     */
    private function xml(string $contents): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($contents, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            if ($xml === false) {
                throw ValidationException::withMessages(['file' => 'Malformed worksheet XML.']);
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * 合并共享字符串中的富文本片段，不依赖单元格显示宽度。
     *
     * @param ZipArchive $archive 来源工作簿
     * @return array<int, string> 共享字符串索引；未使用共享字符串时为空
     */
    private function sharedStrings(ZipArchive $archive): array
    {
        if ($archive->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $strings = [];
        $reader = XMLReader::XML($this->entry($archive, 'xl/sharedStrings.xml'), null, LIBXML_NONET | LIBXML_COMPACT);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $node = $this->xml($reader->readOuterXml());
                $strings[] = implode('', array_map(static fn (SimpleXMLElement $text): string => (string) $text, $node->xpath('.//*[local-name()="t"]') ?: []));
            }
        }
        $reader->close();

        return $strings;
    }

    /**
     * 提取非空行，保留没有公式缓存的标记，禁止把公式文本当作金额。
     *
     * @param string $contents 工作表 XML
     * @param array<int, string> $sharedStrings 共享字符串表
     * @return array<int, array{number: int, cells: array, formulas: array}> 原始行及单元格值
     */
    private function rows(string $contents, array $sharedStrings): array
    {
        $rows = [];
        $reader = XMLReader::XML($contents, null, LIBXML_NONET | LIBXML_COMPACT);
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }
            $node = $this->xml($reader->readOuterXml());
            $cells = [];
            $formulas = [];
            foreach ($node->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                preg_match('/^([A-Z]+)[0-9]+$/', (string) $cell['r'], $address);
                if (!isset($address[1])) {
                    continue;
                }
                $type = (string) $cell['t'];
                $value = (string) (($cell->xpath('./*[local-name()="v"]') ?: [])[0] ?? '');
                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = implode('', array_map(static fn (SimpleXMLElement $text): string => (string) $text, $cell->xpath('.//*[local-name()="t"]') ?: []));
                }
                $formula = ($cell->xpath('./*[local-name()="f"]') ?: [])[0] ?? null;
                if ($formula !== null) {
                    $formulas[$address[1]] = (string) $formula;
                }
                if ($value !== '') {
                    $cells[$address[1]] = $value;
                }
            }
            if ($cells !== [] || $formulas !== []) {
                $rows[] = ['number' => (int) $node['r'], 'cells' => $cells, 'formulas' => $formulas];
            }
            if (count($rows) > 100_000) {
                throw ValidationException::withMessages(['file' => 'Worksheet exceeds 100,000 populated rows.']);
            }
        }
        $reader->close();

        return $rows;
    }
}
