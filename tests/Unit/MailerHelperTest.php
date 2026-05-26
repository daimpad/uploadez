<?php
declare(strict_types=1);

namespace UploadEz\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests für formatBytes() aus includes/mailer.php
 */
class MailerHelperTest extends TestCase
{
    #[Test]
    #[DataProvider('bytesProvider')]
    public function it_formats_bytes_correctly(int $bytes, string $expected): void
    {
        $this->assertSame($expected, formatBytes($bytes));
    }

    public static function bytesProvider(): array
    {
        return [
            'zero bytes'       => [0,             '0 B'],
            '1 byte'           => [1,             '1 B'],
            '1023 bytes'       => [1023,          '1023 B'],   // below 1 KB threshold, no number_format
            '1 KB exactly'     => [1024,          '1.00 KB'],
            '1 MB exactly'     => [1048576,       '1.00 MB'],
            '1 GB exactly'     => [1073741824,    '1.00 GB'],
            '1.5 MB'           => [1572864,       '1.50 MB'],
            '2 GB'             => [2147483648,    '2.00 GB'],
            '500 KB'           => [512000,        '500.00 KB'],
        ];
    }

    #[Test]
    public function it_returns_gb_for_very_large_files(): void
    {
        $twoGb = 2 * 1024 * 1024 * 1024;
        $this->assertStringContainsString('GB', formatBytes($twoGb));
    }

    #[Test]
    public function it_returns_bytes_for_small_values(): void
    {
        $this->assertStringContainsString('B', formatBytes(500));
        $this->assertStringNotContainsString('KB', formatBytes(500));
    }
}
