<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 — Phase C: بازتستِ رگرسیونیِ مسیرِ متنِ آزادِ MessageController::store().
 *
 * هدف: قفل‌کردنِ رفتارِ MessageController بعد از استخراجِ MessageComposer —
 * هیچ تغییری در sp_InsertMessage یا پارامترهایِ آن رخ نداده؛ این تست همان
 * مسیرِ قدیمی (POST /messages، بدونِ Template) را از سرِ نو تأیید می‌کند.
 */
class MessageComposerRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_SENDER = 2;
    private const USER_RECIPIENT = 3;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    public function test_free_text_message_is_created_and_redirects_with_success_flash(): void
    {
        $subject = 'موضوعِ تستِ رگرسیون ' . bin2hex(random_bytes(4));

        $response = $this->as(self::USER_SENDER)->post('/messages', [
            'MessageTypeID' => 1,
            'msgPriorityID' => 1,
            'Subject' => $subject,
            'MessageText' => 'متنِ آزادِ تستِ رگرسیون',
            'RecipientType' => 1,
            'RecipientUserIDs' => [self::USER_RECIPIENT],
        ]);

        $response->assertRedirect(route('messages.index'));
        $response->assertSessionHas('success');

        $row = DB::selectOne('SELECT TOP 1 MessageID, Subject, MessageText FROM dbo.Messages WHERE Subject = ? ORDER BY MessageID DESC', [$subject]);
        $this->assertNotNull($row);
        $this->assertSame($subject, $row->Subject);
        $this->assertSame('متنِ آزادِ تستِ رگرسیون', $row->MessageText);
    }

    public function test_free_text_message_failure_returns_back_with_error_and_creates_no_message(): void
    {
        $subject = 'موضوعِ تستِ خطایِ رگرسیون ' . bin2hex(random_bytes(4));

        $countBefore = DB::selectOne('SELECT COUNT(*) AS C FROM dbo.Messages')->C;

        $response = $this->as(self::USER_SENDER)->post('/messages', [
            'MessageTypeID' => 999999, // نوعِ پیامِ نامعتبر → Successِ SP = 0
            'msgPriorityID' => 1,
            'Subject' => $subject,
            'RecipientType' => 1,
            'RecipientUserIDs' => [self::USER_RECIPIENT],
        ]);

        $response->assertSessionHasErrors('Subject');

        $countAfter = DB::selectOne('SELECT COUNT(*) AS C FROM dbo.Messages')->C;
        $this->assertSame($countBefore, $countAfter);
    }

    public function test_store_requires_authentication(): void
    {
        $this->post('/messages', [
            'MessageTypeID' => 1,
            'msgPriorityID' => 1,
            'Subject' => 'x',
            'RecipientType' => 1,
        ])->assertRedirect('/login');
    }
}
