<?php

namespace {
    function update_user_meta($user_id, $key, $value) {
        \UpstateInternational\LGL\Tests\Unit\TestRegistry::recordUserMeta($user_id, $key, $value);
        return true;
    }
}

namespace UpstateInternational\LGL\Tests\Unit {

use PHPUnit\Framework\TestCase;
use UpstateInternational\LGL\Memberships\MembershipRegistrationService;

class TestRegistry {
    public static array $userMeta = [];

    public static function recordUserMeta(int $userId, string $key, $value): void {
        if (!isset(self::$userMeta[$userId])) {
            self::$userMeta[$userId] = [];
        }
        self::$userMeta[$userId][$key] = $value;
    }
}

class MembershipRegistrationServiceTest extends TestCase {
    public function testRegistersNewConstituentAndPayment(): void {
        $connection = $this->createMock('UpstateInternational\\LGL\\LGL\\Connection');
        $helper = $this->createMock('UpstateInternational\\LGL\\LGL\\Helper');
        $constituents = $this->createMock('UpstateInternational\\LGL\\LGL\\Constituents');
        $payments = $this->createMock('UpstateInternational\\LGL\\LGL\\Payments');

        $constituents->expects($this->once())->method('setData')->with(123);
        $constituents->expects($this->once())->method('createConstituent')->willReturn(['payload' => true]);
        $connection->expects($this->once())->method('searchByName')->willReturn(false);
        $connection->expects($this->once())->method('createConstituent')->willReturn([
            'success' => true,
            'data' => ['id' => 'ABC123']
        ]);
        $payments->expects($this->once())->method('setupMembershipPayment')->willReturn([
            'success' => true,
            'id' => 777
        ]);

        $service = new MembershipRegistrationService($connection, $helper, $constituents, $payments);
        $result = $service->register([
            'user_id' => 123,
            'search_name' => 'Test%20User',
            'email' => 'test@example.com',
            'membership_level' => 'Individual Membership',
            'membership_level_id' => 412,
            'order_id' => 555,
            'price' => 75.00,
            'payment_type' => 'online',
            'request' => []
        ]);

        $this->assertSame('ABC123', $result['lgl_id']);
        $this->assertTrue($result['created']);
        $this->assertSame(777, $result['payment_id']);
        $this->assertArrayHasKey('lgl_id', TestRegistry::$userMeta[123]);
        $this->assertSame('ABC123', TestRegistry::$userMeta[123]['lgl_id']);
        $this->assertSame(412, TestRegistry::$userMeta[123]['lgl_membership_level_id']);
    }
}

}
