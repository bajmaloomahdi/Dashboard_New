<?php

namespace App\Console\Commands;

use RuntimeException;

final class PatchBatches
{
    /** @return array<int, array{line:int, sql:string}> */
    public static function split(string $sql): array
    {
        $lines = preg_split('/\r\n|\n|\r/', preg_replace('/^\xEF\xBB\xBF/', '', $sql));
        $quote = '';
        $comments = 0;
        $start = 1;
        $buffer = [];
        $batches = [];
        foreach ($lines as $index => $line) {
            if ($quote === '' && $comments === 0) {
                if (preg_match('/^\s*GO\s*(?:--.*)?$/i', $line)) {
                    self::append($batches, $start, $buffer);
                    $buffer = [];
                    $start = $index + 2;
                    continue;
                }
                if (preg_match('/^\s*(?:GO\s+\d|:|!!)/i', $line)) {
                    throw new RuntimeException('Unsupported SQLCMD directive at line '.($index + 1));
                }
            }
            $buffer[] = $line;
            for ($i = 0, $length = strlen($line); $i < $length; $i++) {
                $char = $line[$i];
                $pair = substr($line, $i, 2);
                if ($comments > 0) {
                    if ($pair === '/*' || $pair === '*/') {
                        $comments += $pair === '/*' ? 1 : -1;
                        $i++;
                    }
                } elseif ($quote !== '') {
                    if ($char === $quote) {
                        if (($line[$i + 1] ?? '') === $quote) {
                            $i++;
                        } else {
                            $quote = '';
                        }
                    }
                } elseif ($pair === '--') {
                    break;
                } elseif ($pair === '/*') {
                    $comments++;
                    $i++;
                } elseif (in_array($char, ["'", '"', '['], true)) {
                    $quote = $char === '[' ? ']' : $char;
                }
            }
        }
        if ($quote !== '' || $comments !== 0) {
            throw new RuntimeException('Unterminated SQL string, identifier or comment.');
        }
        self::append($batches, $start, $buffer);

        return $batches;
    }

    private static function append(array &$batches, int $start, array $lines): void
    {
        $sql = implode("\n", $lines);
        if (trim($sql) !== '') {
            $batches[] = ['line' => $start, 'sql' => $sql];
        }
    }
}
