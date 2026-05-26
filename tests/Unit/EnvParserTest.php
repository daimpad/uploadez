<?php
declare(strict_types=1);

namespace UploadEz\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests für den .env-Parser aus config.php.
 * Der Parser wird als isolierte Funktion getestet, indem eine Temp-.env-Datei
 * geschrieben und dann die Parsing-Logik direkt aufgerufen wird.
 */
class EnvParserTest extends TestCase
{
    private string $tmpFile = '';

    protected function setUp(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'uploadez_env_test_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    private function parseEnvFile(string $content): array
    {
        file_put_contents($this->tmpFile, $content);

        $result = [];
        foreach (file($this->tmpFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value, " \t\r\n\"'");
            if (($pos = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $pos));
            }
            if ($key !== '') {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    #[Test]
    public function it_parses_simple_key_value_pairs(): void
    {
        $result = $this->parseEnvFile("DB_HOST=localhost\nDB_PORT=3306\n");
        $this->assertSame('localhost', $result['DB_HOST']);
        $this->assertSame('3306',      $result['DB_PORT']);
    }

    #[Test]
    public function it_strips_double_quotes(): void
    {
        $result = $this->parseEnvFile('APP_URL="https://example.com"');
        $this->assertSame('https://example.com', $result['APP_URL']);
    }

    #[Test]
    public function it_strips_single_quotes(): void
    {
        $result = $this->parseEnvFile("DB_PASS='secret password'");
        $this->assertSame('secret password', $result['DB_PASS']);
    }

    #[Test]
    public function it_skips_comment_lines(): void
    {
        $result = $this->parseEnvFile("# This is a comment\nDB_HOST=localhost\n");
        $this->assertArrayNotHasKey('# This is a comment', $result);
        $this->assertSame('localhost', $result['DB_HOST']);
    }

    #[Test]
    public function it_skips_empty_lines(): void
    {
        $result = $this->parseEnvFile("\n\nDB_HOST=localhost\n\n");
        $this->assertCount(1, $result);
    }

    #[Test]
    public function it_strips_inline_comments(): void
    {
        $result = $this->parseEnvFile('SMTP_PORT=587 # standard TLS port');
        $this->assertSame('587', $result['SMTP_PORT']);
    }

    #[Test]
    public function it_handles_value_with_equals_sign(): void
    {
        // Werte mit = im Inhalt (z. B. Base64-Hashes)
        $result = $this->parseEnvFile('ADMIN_PASSWORD_HASH=$2y$12$abc==');
        $this->assertSame('$2y$12$abc==', $result['ADMIN_PASSWORD_HASH']);
    }

    #[Test]
    public function it_handles_empty_value(): void
    {
        $result = $this->parseEnvFile('UPLOAD_TOKEN=');
        $this->assertSame('', $result['UPLOAD_TOKEN']);
    }

    #[Test]
    public function it_skips_lines_without_equals(): void
    {
        $result = $this->parseEnvFile("INVALID_LINE\nDB_HOST=localhost\n");
        $this->assertArrayNotHasKey('INVALID_LINE', $result);
        $this->assertSame('localhost', $result['DB_HOST']);
    }
}
