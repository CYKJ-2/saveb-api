<?php

namespace App\Common;

class CsvResponse
{
    public static function download(iterable $rows, array $columns, string $name)
    {
        $locale = ExportHeaders::locale();
        $lines = (function () use ($rows, $columns, $locale) {
            yield array_map(fn ($label) => ExportHeaders::translate($label, $locale), array_values($columns));
            foreach ($rows as $row) {
                yield array_map(function ($key) use ($row, $locale) {
                    $value = data_get($row, $key, '');
                    if ($key === 'classification') {
                        return ExportValue::classification((string) $value, $locale);
                    }
                    $money = ['amount', 'amountOriginal', 'amountUsd', 'cost', 'balance', 'received', 'withdrawn'];

                    return in_array($key, $money, true) && is_numeric($value)
                        ? number_format((float) $value, 2, '.', '') : $value;
                }, array_keys($columns));
            }
        })();

        return self::lines($lines, $name);
    }

    /** 同时支持普通表格与 SA 原格式的多段报表。 */
    public static function lines(iterable $lines, string $name)
    {
        return response()->streamDownload(function () use ($lines) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            foreach ($lines as $line) {
                $values = [];
                foreach ($line as $original) {
                    $value = is_bool($original) ? ($original ? 'true' : 'false') : (string) $original;
                    $values[] = !is_int($original) && !is_float($original) && !preg_match('/^-?\d+(\.\d+)?$/D', $value) && preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
                }
                fputcsv($file, $values, ',', '"', '', "\r\n");
            }
            fclose($file);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
