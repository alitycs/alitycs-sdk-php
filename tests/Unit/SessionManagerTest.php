<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\SessionManager;
use PHPUnit\Framework\TestCase;

final class SessionManagerTest extends TestCase
{
    private float $now = 1_000_000.0;
    private SessionManager $sessions;

    protected function setUp(): void
    {
        $this->sessions = new SessionManager(30 * 60 * 1000, fn (): float => $this->now);
    }

    public function testFreshSessionUsesSdkIdPrefixes(): void
    {
        $session = $this->sessions->getSession();

        $this->assertSame('sess_', substr($session->id, 0, 5));
        $this->assertSame('anon_', substr($session->anonymousId, 0, 5));
        $this->assertNull($session->userId);
    }

    public function testTouchWithinTimeoutKeepsTheSession(): void
    {
        $before = $this->sessions->getSession();
        $this->now += 1000.0; // one second later
        $this->sessions->touch();

        $after = $this->sessions->getSession();

        $this->assertSame($before->id, $after->id);
        $this->assertSame($before->anonymousId, $after->anonymousId);
        $this->assertSame((int) ($this->now * 1000), $after->lastActivity);
    }

    public function testTouchPastTimeoutRotatesTheSessionButKeepsTheAnonymousId(): void
    {
        $before = $this->sessions->getSession();
        $this->now += 31 * 60.0; // past the 30 minute timeout, in seconds
        $this->sessions->touch();

        $after = $this->sessions->getSession();

        $this->assertNotSame($before->id, $after->id);
        $this->assertSame($before->anonymousId, $after->anonymousId);
    }

    public function testSetUserIdAttachesToTheCurrentSession(): void
    {
        $before = $this->sessions->getSession();
        $this->sessions->setUserId('usr_1');

        $after = $this->sessions->getSession();

        $this->assertSame($before->id, $after->id);
        $this->assertSame('usr_1', $after->userId);
    }

    public function testResetRotatesBothIdentifiersAndClearsTheUser(): void
    {
        $this->sessions->setUserId('usr_1');
        $before = $this->sessions->getSession();

        $reset = $this->sessions->reset();

        $this->assertNotSame($before->id, $reset->id);
        $this->assertNotSame($before->anonymousId, $reset->anonymousId);
        $this->assertNull($reset->userId);
        $this->assertNull($this->sessions->getSession()->userId);
    }

    public function testTouchAtExactlyTheThresholdDoesNotRotate(): void
    {
        $created = $this->sessions->getSession();

        $this->now += 30 * 60.0; // exactly the 30 minute threshold
        $this->sessions->touch();

        $this->assertSame($created->id, $this->sessions->getSession()->id);
    }

    public function testTouchOneMillisecondPastTheThresholdRotates(): void
    {
        $created = $this->sessions->getSession();

        $this->now += 30 * 60.0 + 0.001;
        $this->sessions->touch();

        $this->assertNotSame($created->id, $this->sessions->getSession()->id);
    }
}
