<?php
declare(strict_types=1);

namespace UploadEz\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Tests für checkRateLimit() und getClientIp() aus includes/rate_limiter.php
 */
class RateLimiterTest extends TestCase
{
    private function makePdo(int $currentCount): MockObject&PDO
    {
        $countStmt = $this->createMock(PDOStatement::class);
        $countStmt->method('execute')->willReturn(true);
        $countStmt->method('fetchColumn')->willReturn($currentCount);

        $insertStmt = $this->createMock(PDOStatement::class);
        $insertStmt->method('execute')->willReturn(true);

        $cleanupStmt = $this->createMock(PDOStatement::class);
        $cleanupStmt->method('execute')->willReturn(true);

        $pdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->getMock();

        $pdo->method('prepare')->willReturnCallback(
            function (string $sql) use ($countStmt, $insertStmt, $cleanupStmt): PDOStatement {
                if (str_contains($sql, 'SELECT COUNT')) return $countStmt;
                if (str_contains($sql, 'INSERT'))       return $insertStmt;
                return $cleanupStmt; // DELETE
            }
        );

        return $pdo;
    }

    #[Test]
    public function it_allows_upload_when_under_limit(): void
    {
        $pdo = $this->makePdo(currentCount: 5);

        // Darf keine Exception werfen
        $this->expectNotToPerformAssertions();
        checkRateLimit($pdo, '192.168.1.1');
    }

    #[Test]
    public function it_allows_upload_at_exact_limit_minus_one(): void
    {
        $pdo = $this->makePdo(currentCount: RATE_LIMIT_MAX_UPLOADS - 1);

        $this->expectNotToPerformAssertions();
        checkRateLimit($pdo, '10.0.0.1');
    }

    #[Test]
    public function it_blocks_upload_when_limit_reached(): void
    {
        $pdo = $this->makePdo(currentCount: RATE_LIMIT_MAX_UPLOADS);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(429);
        checkRateLimit($pdo, '1.2.3.4');
    }

    #[Test]
    public function it_blocks_upload_when_over_limit(): void
    {
        $pdo = $this->makePdo(currentCount: RATE_LIMIT_MAX_UPLOADS + 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(429);
        checkRateLimit($pdo, '5.6.7.8');
    }

    #[Test]
    public function it_mentions_max_uploads_in_error_message(): void
    {
        $pdo = $this->makePdo(currentCount: RATE_LIMIT_MAX_UPLOADS);

        try {
            checkRateLimit($pdo, '1.2.3.4');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) RATE_LIMIT_MAX_UPLOADS, $e->getMessage());
        }
    }

    // ── getClientIp() ─────────────────────────────────────────────────────────

    #[Test]
    public function it_returns_remote_addr_when_no_forwarded_header(): void
    {
        $_SERVER['REMOTE_ADDR']          = '203.0.113.1';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);

        $ip = getClientIp();
        $this->assertSame('203.0.113.1', $ip);
    }

    #[Test]
    public function it_uses_first_ip_from_x_forwarded_for(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.1, 10.0.0.1, 172.16.0.1';
        $_SERVER['REMOTE_ADDR']          = '10.0.0.1';

        $ip = getClientIp();
        $this->assertSame('192.0.2.1', $ip);
    }

    #[Test]
    public function it_returns_null_for_invalid_ip(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
        unset($_SERVER['REMOTE_ADDR']);

        $ip = getClientIp();
        $this->assertNull($ip);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        unset($_SERVER['REMOTE_ADDR']);
    }
}
