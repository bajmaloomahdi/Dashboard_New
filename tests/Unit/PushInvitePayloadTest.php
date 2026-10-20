<?php

namespace Tests\Unit;

use App\Services\Push\PushPayloadFactory;
use PHPUnit\Framework\TestCase;

/** متنِ Pushِ دعوت: دعوتِ تازه و «تغییرِ جلسه» (Tagِ invu-) — هر دو عمومی و بدونِ عنوانِ جلسه */
class PushInvitePayloadTest extends TestCase
{
    private function body(string $tag): array
    {
        $outbox = (object) ['PushOutboxID' => 7, 'Kind' => 'INVITE', 'Tag' => $tag, 'TargetUrl' => '/calendar?invites=1'];

        return json_decode((new PushPayloadFactory)->make($outbox), true);
    }

    public function test_new_and_updated_invitations_have_distinct_generic_text(): void
    {
        $new = $this->body('inv-12-261019101500');
        $updated = $this->body('invu-12-261019103000');

        $this->assertSame('دعوت به جلسهٔ تازه‌ای دارید.', $new['body']);
        $this->assertSame('یکی از جلسه‌هایتان تغییر کرد؛ لطفاً دوباره پاسخ دهید.', $updated['body']);
        $this->assertSame(['INVITE', '/calendar?invites=1'], [$updated['kind'], $updated['url']]);
        $this->assertNotSame(PushPayloadFactory::topic('inv-12-261019101500'), PushPayloadFactory::topic('invu-12-261019103000'));
    }
}
