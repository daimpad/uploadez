<?php
declare(strict_types=1);

namespace UploadEz\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests für sanitizeFilename() aus includes/uploader.php
 */
class SanitizerTest extends TestCase
{
    #[Test]
    #[DataProvider('validFilenamesProvider')]
    public function it_preserves_valid_filenames(string $input, string $expected): void
    {
        $this->assertSame($expected, sanitizeFilename($input));
    }

    public static function validFilenamesProvider(): array
    {
        return [
            'simple name'              => ['document.pdf',          'document.pdf'],
            'name with spaces'         => ['my file.pdf',           'my file.pdf'],
            'name with dash'           => ['my-file.pdf',           'my-file.pdf'],
            'name with underscore'     => ['my_file.pdf',           'my_file.pdf'],
            'uppercase extension'      => ['IMAGE.JPG',             'IMAGE.JPG'],
            'leading/trailing spaces'  => ['  file.txt  ',          'file.txt'],
        ];
    }

    #[Test]
    #[DataProvider('maliciousFilenamesProvider')]
    public function it_strips_path_traversal_and_dangerous_chars(string $input, string $expected): void
    {
        $result = sanitizeFilename($input);
        $this->assertSame($expected, $result);
    }

    public static function maliciousFilenamesProvider(): array
    {
        return [
            'path traversal unix'      => ['../../etc/passwd',       'passwd'],
            'path traversal win'       => ['..\\..\\windows\\cmd',   '.windowscmd'],
            'double extension php+jpg' => ['shell.php.jpg',          'shell.php.jpg'],
            'null byte'                => ["file\0.txt",              'file.txt'],
            'semicolon injection'      => ['file;rm -rf.txt',        'filerm -rf.txt'],
            'multiple dots collapsed'  => ['file...php',             'file.php'],
            'angle brackets'          => ['<script>.js',             'script.js'],
            'absolute path'           => ['/etc/passwd',             'passwd'],
        ];
    }

    #[Test]
    public function it_returns_empty_string_for_empty_input(): void
    {
        $this->assertSame('', sanitizeFilename(''));
    }

    #[Test]
    public function it_returns_empty_string_for_only_dangerous_chars(): void
    {
        $result = sanitizeFilename('<>&"\'');
        $this->assertSame('', $result);
    }

    #[Test]
    public function it_handles_unicode_filenames(): void
    {
        $result = sanitizeFilename('Präsentation über München.pdf');
        $this->assertNotEmpty($result);
        $this->assertStringEndsWith('.pdf', $result);
    }
}
