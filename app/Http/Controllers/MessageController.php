<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MessageController extends Controller
{
    /**
     * لیست اولویت‌های فعال پیام (برای فیلترها و فرم ارسال)
     */
    private function activePriorities(): array
    {
        return DB::select(
            'EXEC sp_GetMsgPriorities @SearchText = ?, @IsActive = ?',
            [null, 1]
        );
    }

    public function index(Request $request, string $mode = 'inbox')
    {
        $userId = Auth::id();
        $modeValue = $mode === 'sent' ? 2 : 1;

        $searchText = $request->input('search');
        $messageTypeId = $request->input('message_type_id');
        $messageStatusId = $request->input('message_status_id');
        $msgPriorityId = $request->input('msg_priority_id');

        $messages = DB::select(
            'EXEC sp_GetMessages @UserID = ?, @Mode = ?, @IsArchive = 0, @SearchText = ?, @MessageTypeID = ?, @MessageStatusID = ?, @msgPriorityID = ?',
            [
                $userId,
                $modeValue,
                $searchText ?: null,
                $messageTypeId !== null && $messageTypeId !== '' ? (int) $messageTypeId : null,
                $messageStatusId !== null && $messageStatusId !== '' ? (int) $messageStatusId : null,
                $msgPriorityId !== null && $msgPriorityId !== '' ? (int) $msgPriorityId : null,
            ]
        );

        $messageTypes = DB::select('EXEC sp_GetMessageTypes @SearchText = NULL, @IsActive = 1');
        $messageStatuses = DB::select('EXEC sp_GetMessageStatuses @SearchText = NULL, @IsActive = 1');
        $priorities = $this->activePriorities();

        return Inertia::render('Messages/Index', [
            'messages' => $messages,
            'mode' => $mode,
            'messageTypes' => $messageTypes,
            'messageStatuses' => $messageStatuses,
            'priorities' => $priorities,
            'filters' => [
                'search' => $searchText,
                'message_type_id' => $messageTypeId,
                'message_status_id' => $messageStatusId,
                'msg_priority_id' => $msgPriorityId,
            ],
        ]);
    }

    public function archive(Request $request)
    {
        $userId = Auth::id();

        $searchText = $request->input('search');
        $messageTypeId = $request->input('message_type_id');
        $messageStatusId = $request->input('message_status_id');
        $msgPriorityId = $request->input('msg_priority_id');

        $messages = DB::select(
            'EXEC sp_GetMessages @UserID = ?, @Mode = 1, @IsArchive = 1, @SearchText = ?, @MessageTypeID = ?, @MessageStatusID = ?, @msgPriorityID = ?',
            [
                $userId,
                $searchText ?: null,
                $messageTypeId !== null && $messageTypeId !== '' ? (int) $messageTypeId : null,
                $messageStatusId !== null && $messageStatusId !== '' ? (int) $messageStatusId : null,
                $msgPriorityId !== null && $msgPriorityId !== '' ? (int) $msgPriorityId : null,
            ]
        );

        $messageTypes = DB::select('EXEC sp_GetMessageTypes @SearchText = NULL, @IsActive = 1');
        $messageStatuses = DB::select('EXEC sp_GetMessageStatuses @SearchText = NULL, @IsActive = 1');
        $priorities = $this->activePriorities();

        return Inertia::render('Messages/Archive', [
            'messages' => $messages,
            'messageTypes' => $messageTypes,
            'messageStatuses' => $messageStatuses,
            'priorities' => $priorities,
            'filters' => [
                'search' => $searchText,
                'message_type_id' => $messageTypeId,
                'message_status_id' => $messageStatusId,
                'msg_priority_id' => $msgPriorityId,
            ],
        ]);
    }

    public function create()
    {
        $userId = Auth::id();

        $messageTypes = DB::select('EXEC sp_GetMessageTypes @SearchText = NULL, @IsActive = 1');
        $targets = DB::select('EXEC sp_GetSendTargets @UserID = ?, @IsTask = 0', [$userId]);
        $taskUnits = DB::select('EXEC sp_GetSendTargets @UserID = ?, @IsTask = 1', [$userId]);
        $priorities = $this->activePriorities();

        return Inertia::render('Messages/Create', [
            'messageTypes' => $messageTypes,
            'priorities' => $priorities,
            'targets' => $targets,
            'taskUnits' => $taskUnits,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'MessageTypeID' => 'required|integer',
            'msgPriorityID' => 'required|integer',
            'Subject' => 'required|string|max:500',
            'MessageText' => 'nullable|string',
            'RecipientType' => 'required|in:1,2,3',
            'RecipientUserIDs' => 'nullable|array',
            'RecipientUserIDs.*' => 'integer',
            'CopyUserIDs' => 'nullable|array',
            'CopyUserIDs.*' => 'integer',
            'CopyDescription' => 'nullable|string|max:1000',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ], [
            'msgPriorityID.required' => 'انتخاب اولویت پیام الزامی است.',
        ]);

        $jalaliYear = $this->getJalaliYear(now());

        $result = DB::select(
            'EXEC sp_InsertMessage
                @MessageTypeID = ?, @msgPriorityID = ?, @Subject = ?, @MessageText = ?,
                @RecipientType = ?, @RecipientUserIDs = ?, @CopyUserIDs = ?,
                @CopyDescription = ?, @SenderUserID = ?, @Year = ?, @CreateUser = ?',
            [
                $validated['MessageTypeID'],
                $validated['msgPriorityID'],
                $validated['Subject'],
                $validated['MessageText'] ?? null,
                (int) $validated['RecipientType'],
                isset($validated['RecipientUserIDs']) ? implode(',', $validated['RecipientUserIDs']) : null,
                isset($validated['CopyUserIDs']) ? implode(',', $validated['CopyUserIDs']) : null,
                $validated['CopyDescription'] ?? null,
                Auth::id(),
                $jalaliYear,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'Subject' => $response['Message'] ?? 'خطا در ارسال پیام',
            ]);
        }

        $messageId = $response['NewMessageID'] ?? null;

        if ($messageId && $request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();
                $size = $file->getSize();

                $path = $file->store('messages/' . $messageId, 'public');

                DB::select(
                    'EXEC sp_InsertMessageAttachment
                        @MessageID = ?, @FileName = ?, @FileExtension = ?, @FileSize = ?, @FilePath = ?, @CreateUser = ?',
                    [
                        $messageId,
                        $originalName,
                        $extension,
                        $size,
                        $path,
                        Auth::id(),
                    ]
                );
            }
        }

        return redirect()->route('messages.index')
            ->with('success', $response['Message']);
    }

    public function show($id)
    {
        $id = (int) $id;

        $header = DB::select('EXEC sp_GetMessageHeader @MessageID = ?', [$id]);
        $message = $header[0] ?? null;

        if (!$message) {
            return redirect()->route('messages.index')
                ->with('error', 'پیام مورد نظر یافت نشد');
        }

        DB::statement('EXEC sp_MarkNotificationRead @UserID = ?, @MessageID = ?', [Auth::id(), $id]);

        $details = DB::select('EXEC sp_GetMessageDetailsList @MessageID = ?', [$id]);
        $copies = DB::select('EXEC sp_GetMessageCopiesList @MessageID = ?', [$id]);
        $attachments = DB::select('EXEC sp_GetMessageAttachmentsList @MessageID = ?', [$id]);
        $statuses = DB::select('EXEC sp_GetMessageStatuses @SearchText = NULL, @IsActive = 1');
        $priorities = $this->activePriorities();

        $users = DB::select('EXEC sp_GetForwardTargets @UserID = ?', [Auth::id()]);

        $comments = DB::select('EXEC sp_GetMessageComments @MessageID = ?', [$id]);

        // ضمیمه‌های هر نظر
        foreach ($comments as $i => $c) {
            $commentAtts = DB::select('EXEC sp_GetMessageCommentAttachments @MessageCommentID = ?', [$c->MessageCommentID]);
            $comments[$i]->attachments = $commentAtts;
        }

        $lastDetail = !empty($details) ? $details[count($details) - 1] : null;
        $currentUserId = Auth::id();

        $isLastRecipient = $lastDetail && (int) $lastDetail->ToUserID === (int) $currentUserId;
        $isTask = $message->MessageTypeName === 'وظیفه';

        $perm = DB::select('EXEC sp_CheckMessageCommentPermission @MessageID = ?, @UserID = ?', [$id, $currentUserId]);
        $canComment = !empty($perm) && (int) ($perm[0]->CanComment ?? 0) === 1;

        return Inertia::render('Messages/Show', [
            'message' => $message,
            'details' => $details,
            'copies' => $copies,
            'attachments' => $attachments,
            'statuses' => $statuses,
            'priorities' => $priorities,
            'users' => $users,
            'comments' => $comments,
            'currentUserId' => $currentUserId,
            'isLastRecipient' => $isLastRecipient,
            'isTask' => $isTask,
            'canComment' => $canComment,
        ]);
    }

    public function changeStatus(Request $request, $id)
    {
        $id = (int) $id;

        $validated = $request->validate([
            'MessageStatusID' => 'required|integer',
            'Description' => 'nullable|string|max:1000',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateMessageDetailStatus
                @MessageID = ?, @UserID = ?, @MessageStatusID = ?, @Description = ?, @CreateUser = ?',
            [
                $id,
                Auth::id(),
                $validated['MessageStatusID'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'status' => $response['Message'] ?? 'خطا در تغییر وضعیت',
            ]);
        }

        return redirect()->route('messages.show', $id)
            ->with('success', $response['Message']);
    }

    public function forward(Request $request, $id)
    {
        $id = (int) $id;

        $validated = $request->validate([
            'ToUserID' => 'required|integer',
            'Description' => 'nullable|string|max:1000',
        ]);

        $result = DB::select(
            'EXEC sp_ForwardMessage
                @MessageID = ?, @FromUserID = ?, @ToUserID = ?, @Description = ?, @CreateUser = ?',
            [
                $id,
                Auth::id(),
                $validated['ToUserID'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'status' => $response['Message'] ?? 'خطا در ارجاع',
            ]);
        }

        return redirect()->route('messages.show', $id)
            ->with('success', $response['Message']);
    }

    /**
     * افزودن توضیح (کامنت) روی پیام — ضمیمه‌ها به ضمیمه اصلی پیام می‌روند
     */
    public function addComment(Request $request, $id)
    {
        $id = (int) $id;

        $validated = $request->validate([
            'Comment' => 'nullable|string',
            'comment_attachments' => 'nullable|array',
            'comment_attachments.*' => 'file|max:10240',
        ]);

        $comment = $validated['Comment'] ?? null;
        $hasComment = $comment !== null && trim($comment) !== '';
        $hasFiles = $request->hasFile('comment_attachments') && count($request->file('comment_attachments')) > 0;

        if (!$hasComment && !$hasFiles) {
            return back()->withErrors([
                'comment' => 'توضیح یا ضمیمه را وارد کنید.',
            ]);
        }

        // ثبت توضیح (اگر فقط فایل است، متن پیش‌فرض می‌رود چون ستون NOT NULL است)
        $result = DB::select(
            'EXEC sp_InsertMessageComment @MessageID = ?, @UserID = ?, @Comment = ?',
            [$id, Auth::id(), $hasComment ? $comment : 'ضمیمه اضافه شد']
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'comment' => $response['Message'] ?? 'خطا در ثبت',
            ]);
        }

        // ضمیمه‌ها → به ضمیمه اصلی پیام (MessageAttachments)
        if ($hasFiles) {
            foreach ($request->file('comment_attachments') as $file) {
                $originalName = $file->getClientOriginalName();
                $extension = $file->getClientOriginalExtension();
                $size = $file->getSize();

                $path = $file->store('messages/' . $id, 'public');

                DB::select(
                    'EXEC sp_InsertMessageAttachment
                        @MessageID = ?, @FileName = ?, @FileExtension = ?, @FileSize = ?, @FilePath = ?, @CreateUser = ?',
                    [
                        $id,
                        $originalName,
                        $extension,
                        $size,
                        $path,
                        Auth::id(),
                    ]
                );
            }
        }

        // پیام موفقیت بر اساس محتوا
        $successMessage = $hasComment && $hasFiles
            ? 'توضیح و ضمیمه با موفقیت ثبت شد.'
            : ($hasComment
                ? 'توضیح با موفقیت ثبت شد.'
                : 'ضمیمه با موفقیت اضافه شد.');

        return redirect()->route('messages.show', $id)
            ->with('success', $successMessage);
    }

    /**
     * افزودن رونوشت جدید به پیام
     */
    public function addCopy(Request $request, $id)
    {
        $id = (int) $id;

        $validated = $request->validate([
            'CopyUserID' => 'required|integer',
            'Description' => 'nullable|string|max:1000',
        ]);

        $result = DB::select(
            'EXEC sp_AddMessageCopy
                @MessageID = ?, @UserID = ?, @CopyUserID = ?, @Description = ?, @CreateUser = ?',
            [
                $id,
                Auth::id(),
                $validated['CopyUserID'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'copy' => $response['Message'] ?? 'خطا در افزودن رونوشت',
            ]);
        }

        return redirect()->route('messages.show', $id)
            ->with('success', $response['Message']);
    }

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
