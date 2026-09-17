<?php

namespace App\Services\Message;

use Illuminate\Support\Facades\DB;

/**
 * هستهٔ ساختِ یک Message واقعی — استخراج‌شده از MessageController::store()
 * (Phase 3، Phase C) تا هم مسیرِ فعلیِ ترکیبِ دستیِ پیام و هم مسیرِ جدیدِ
 * Message-from-Template از یک کدِ واحد استفاده کنند، بدونِ کپیِ منطق.
 *
 * Behavior-Preserving: دقیقاً همان `EXEC sp_InsertMessage` و همان منطقِ ضمیمه‌هایِ
 * قبلی، بدونِ هیچ تغییرِ پارامتر یا رفتار. `sp_InsertMessage` خودش هرگز تغییر نکرده.
 */
class MessageComposer
{
    /**
     * @param  array{
     *     MessageTypeID:int, msgPriorityID:int, Subject:string, MessageText:?string,
     *     RecipientType:int, RecipientUserIDs:?array, CopyUserIDs:?array, CopyDescription:?string,
     *     SenderUserID:int, CreateUserID:int, DueDate:?string
     * }  $fields
     * @return array{success:bool, message:?string, messageId:?int}
     */
    public function insert(array $fields): array
    {
        $jalaliYear = $this->getJalaliYear(now());

        $result = DB::select(
            'EXEC sp_InsertMessage
                @MessageTypeID = ?, @msgPriorityID = ?, @Subject = ?, @MessageText = ?,
                @RecipientType = ?, @RecipientUserIDs = ?, @CopyUserIDs = ?,
                @CopyDescription = ?, @SenderUserID = ?, @Year = ?, @CreateUser = ?, @DueDate = ?',
            [
                $fields['MessageTypeID'],
                $fields['msgPriorityID'],
                $fields['Subject'],
                $fields['MessageText'] ?? null,
                $fields['RecipientType'],
                ! empty($fields['RecipientUserIDs']) ? implode(',', $fields['RecipientUserIDs']) : null,
                ! empty($fields['CopyUserIDs']) ? implode(',', $fields['CopyUserIDs']) : null,
                $fields['CopyDescription'] ?? null,
                $fields['SenderUserID'],
                $jalaliYear,
                $fields['CreateUserID'],
                $fields['DueDate'] ?? null,
            ]
        );

        $response = (array) ($result[0] ?? []);

        return [
            'success'   => ! empty($response['Success']),
            'message'   => $response['Message'] ?? null,
            'messageId' => $response['NewMessageID'] ?? null,
        ];
    }

    /**
     * @param  iterable<\Illuminate\Http\UploadedFile>  $files
     */
    public function attachFiles(int $messageId, iterable $files, int $createUserId): void
    {
        foreach ($files as $file) {
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $size = $file->getSize();

            $path = $file->store('messages/' . $messageId, 'public');

            DB::select(
                'EXEC sp_InsertMessageAttachment
                    @MessageID = ?, @FileName = ?, @FileExtension = ?, @FileSize = ?, @FilePath = ?, @CreateUser = ?',
                [$messageId, $originalName, $extension, $size, $path, $createUserId]
            );
        }
    }

    /** عیناً همان الگوریتمِ MessageController::getJalaliYear() قبلی — بدونِ تغییر. */
    private function getJalaliYear($date): int
    {
        $gy = (int) $date->format('Y');
        $gm = (int) $date->format('n');
        $gd = (int) $date->format('j');

        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $jy = ($gy <= 1600) ? 0 : 979;
        $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
              + intdiv($gy2 + 399, 400) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
        }

        return $jy;
    }
}
