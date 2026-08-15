<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MessageController extends Controller
{
    public function index(Request $request, string $mode = 'inbox')
    {
        $userId = Auth::id();
        $modeValue = $mode === 'sent' ? 2 : 1;

        $searchText = $request->input('search');
        $messageTypeId = $request->input('message_type_id');
        $messageStatusId = $request->input('message_status_id');

        $messages = DB::select(
            'EXEC sp_GetMessages @UserID = ?, @Mode = ?, @SearchText = ?, @MessageTypeID = ?, @MessageStatusID = ?',
            [
                $userId,
                $modeValue,
                $searchText ?: null,
                $messageTypeId !== null && $messageTypeId !== '' ? (int) $messageTypeId : null,
                $messageStatusId !== null && $messageStatusId !== '' ? (int) $messageStatusId : null,
            ]
        );

        $messageTypes = DB::select('EXEC sp_GetMessageTypes @SearchText = NULL, @IsActive = 1');
        $messageStatuses = DB::select('EXEC sp_GetMessageStatuses @SearchText = NULL, @IsActive = 1');

        return Inertia::render('Messages/Index', [
            'messages' => $messages,
            'mode' => $mode,
            'messageTypes' => $messageTypes,
            'messageStatuses' => $messageStatuses,
            'filters' => [
                'search' => $searchText,
                'message_type_id' => $messageTypeId,
                'message_status_id' => $messageStatusId,
            ],
        ]);
    }

    public function create()
    {
        $userId = Auth::id();

        $messageTypes = DB::select('EXEC sp_GetMessageTypes @SearchText = NULL, @IsActive = 1');
        $targets = DB::select('EXEC sp_GetSendTargets @UserID = ?, @IsTask = 0', [$userId]);
        $taskUnits = DB::select('EXEC sp_GetSendTargets @UserID = ?, @IsTask = 1', [$userId]);

        return Inertia::render('Messages/Create', [
            'messageTypes' => $messageTypes,
            'targets' => $targets,
            'taskUnits' => $taskUnits,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'MessageTypeID' => 'required|integer',
            'Subject' => 'required|string|max:500',
            'MessageText' => 'nullable|string',
            'RecipientType' => 'required|in:1,2,3',
            'RecipientUserIDs' => 'nullable|array',
            'RecipientUserIDs.*' => 'integer',
            'CopyUserIDs' => 'nullable|array',
            'CopyUserIDs.*' => 'integer',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|max:10240',
        ]);

        $result = DB::select(
            'EXEC sp_InsertMessage
                @MessageTypeID = ?, @Subject = ?, @MessageText = ?,
                @RecipientType = ?, @RecipientUserIDs = ?, @CopyUserIDs = ?,
                @SenderUserID = ?, @CreateUser = ?',
            [
                $validated['MessageTypeID'],
                $validated['Subject'],
                $validated['MessageText'] ?? null,
                (int) $validated['RecipientType'],
                isset($validated['RecipientUserIDs']) ? implode(',', $validated['RecipientUserIDs']) : null,
                isset($validated['CopyUserIDs']) ? implode(',', $validated['CopyUserIDs']) : null,
                Auth::id(),
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

    /**
     * جزئیات پیام
     */
    public function show(int $id)
    {
        $header = DB::select('EXEC sp_GetMessageHeader @MessageID = ?', [$id]);
        $message = $header[0] ?? null;

        if (!$message) {
            return redirect()->route('messages.index')
                ->with('error', 'پیام مورد نظر یافت نشد');
        }

        $details = DB::select('EXEC sp_GetMessageDetailsList @MessageID = ?', [$id]);
        $copies = DB::select('EXEC sp_GetMessageCopiesList @MessageID = ?', [$id]);
        $attachments = DB::select('EXEC sp_GetMessageAttachmentsList @MessageID = ?', [$id]);

        return Inertia::render('Messages/Show', [
            'message' => $message,
            'details' => $details,
            'copies' => $copies,
            'attachments' => $attachments,
        ]);
    }
}