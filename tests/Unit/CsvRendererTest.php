<?php
declare(strict_types=1);

use MiniERP\Infrastructure\CsvRenderer;
use PHPUnit\Framework\TestCase;

final class CsvRendererTest extends TestCase
{
    public function testRendersUtf8BomHeadersAndProjectedValues(): void
    {
        $csv = CsvRenderer::render(
            ['invoice_no' => 'Số hóa đơn', 'buyer_name' => 'Khách hàng', 'total_vnd' => 'Tổng tiền'],
            [['invoice_no' => 'INV-001', 'buyer_name' => 'Công ty A', 'total_vnd' => 125000]]
        );

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        self::assertSame(['Số hóa đơn', 'Khách hàng', 'Tổng tiền'], fgetcsv($stream, null, ',', '"', ''));
        self::assertSame(['INV-001', 'Công ty A', '125000'], fgetcsv($stream, null, ',', '"', ''));
        fclose($stream);
    }

    public function testNeutralizesFormulaPrefixesAndControlCharacters(): void
    {
        $csv = CsvRenderer::render(['value' => 'Giá trị'], [
            ['value' => '=1+1'],
            ['value' => "\t@SUM(A1:A2)"],
            ['value' => '-SUM(A1:A2)'],
            ['value' => '+cmd|\' /C calc!A0'],
            ['value' => 'An toàn'],
        ]);
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        fgetcsv($stream, null, ',', '"', '');
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row[0];
        }
        fclose($stream);
        self::assertSame(["'=1+1", "'\t@SUM(A1:A2)", "'-SUM(A1:A2)", "'+cmd|' /C calc!A0", 'An toàn'], $rows);
    }

    public function testRejectsNonscalarCells(): void
    {
        $this->expectException(RuntimeException::class);
        CsvRenderer::render(['value' => 'Giá trị'], [['value' => ['nested']]]);
    }
}
