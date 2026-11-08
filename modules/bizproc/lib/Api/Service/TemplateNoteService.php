<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Api\Service;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\CompleteNoteRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\CreateNoteRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\DeleteMessageRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\GetNoteThreadRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\GetNotesRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\ReplyToNoteRequest;
use Bitrix\Bizproc\Api\Request\TemplateNoteService\UpdateMessageRequest;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\CompleteNoteResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\CreateNoteResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\DeleteMessageResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\GetNoteThreadResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\GetNotesResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\ReplyToNoteResponse;
use Bitrix\Bizproc\Api\Response\TemplateNoteService\UpdateMessageResponse;
use Bitrix\Bizproc\Integration\Im\TemplateNoteNotification;
use Bitrix\Bizproc\Internal\Access\AccessController;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNote;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\EO_TemplateNoteMessage;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteMessageTable;
use Bitrix\Bizproc\Workflow\Template\Note\Entity\TemplateNoteTable;
use Bitrix\Bizproc\Workflow\Template\Note\ErrorCode;
use Bitrix\Bizproc\Workflow\Template\Note\Limits;
use Bitrix\Bizproc\Workflow\Template\Note\MessageText;
use Bitrix\Intranet\Integration\HumanResources\HrUserService;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * The discussions pinned to the scheme of a workflow template: reading them, writing into them and
 * closing them. The only gate is the right to edit the template the note belongs to; a note or a message
 * carries the template with it, so nothing but the id of the object and the id of the current user is
 * ever taken from the caller.
 *
 * A completed note is a note that is gone together with its messages: there is no column of a status
 * and no archive of what has been discussed. Hence the two idempotent operations - completing a note and
 * deleting a message answer a neutral success when the object is already gone (ALG-04), while everything
 * else answers ACCESS_DENIED without disclosing whether the object was ever there.
 */
class TemplateNoteService
{
	private const CREATE_LOCK_PREFIX = 'bp_template_note_';
	private const CREATE_LOCK_TIMEOUT = 0;
	private const COMPLETION_AUDIT_TYPE = 'bizproc_template_note_completed';

	private TemplateAccessService $templateAccessService;
	private ExceptionHandler $exceptionHandler;
	private TemplateNoteNotification $notification;

	public function __construct(
		?TemplateAccessService $templateAccessService = null,
		?ExceptionHandler $exceptionHandler = null,
		?TemplateNoteNotification $notification = null,
	)
	{
		$this->templateAccessService = $templateAccessService ?? new TemplateAccessService();
		$this->exceptionHandler = $exceptionHandler ?? Application::getInstance()->getExceptionHandler();
		$this->notification = $notification ?? new TemplateNoteNotification();
	}

	/**
	 * The notes of a template without their conversations: opening a scheme must not pull two hundred
	 * discussions along with it, so only the number of messages of every note comes with them.
	 */
	public function getNotes(GetNotesRequest $request): GetNotesResponse
	{
		$response = new GetNotesResponse();

		if (!$this->canEditTemplate($request->templateId, $request->userId))
		{
			$this->denyAccess($response);

			return $response;
		}

		$notes = TemplateNoteTable::query()
			->setSelect(['*'])
			->where('TEMPLATE_ID', $request->templateId)
			->setOrder(['ID' => 'ASC'])
			->fetchCollection()
			->getAll()
		;

		return $response
			->setNotes($notes)
			->setMessageCounts($this->countMessagesOfNotes(array_map(
				static fn(EO_TemplateNote $note): int => $note->getId(),
				$notes,
			)))
			->setLimits(Limits::toArray())
		;
	}

	public function getNoteThread(GetNoteThreadRequest $request): GetNoteThreadResponse
	{
		$response = new GetNoteThreadResponse();

		$note = $this->findNote($request->noteId);
		if ($note === null || !$this->canEditTemplate($note->getTemplateId(), $request->userId))
		{
			$this->denyAccess($response);

			return $response;
		}

		$messages = TemplateNoteMessageTable::query()
			->setSelect(['*'])
			->where('NOTE_ID', $note->getId())
			->setOrder(['ID' => 'ASC'])
			->fetchCollection()
			->getAll()
		;

		return $response->setNote($note)->setMessages($messages);
	}

	public function createNote(CreateNoteRequest $request): CreateNoteResponse
	{
		$response = new CreateNoteResponse();

		// The template is checked for being there and not only for being allowed: the quota of the notes is
		// counted per template, so an id of a template that never was would carry a quota of its own, and the
		// cascade of a deletion reaches the notes of a template that exists alone. A template that is not
		// there answers exactly as a foreign one does (ALG-04).
		if (
			!$this->canEditTemplate($request->templateId, $request->userId)
			|| !$this->templateExists($request->templateId)
		)
		{
			$this->denyAccess($response);

			return $response;
		}

		$text = $this->prepareText($request->message, $response);
		if ($text === null)
		{
			return $response;
		}

		$note = null;
		$message = null;
		$isWritten = false;

		$connection = Application::getConnection();
		$lockName = self::CREATE_LOCK_PREFIX . $request->templateId;

		// The quota is checked by counting the notes that are there, so two creations at the border would
		// both pass it. The lock is taken without waiting, as the merge of the history journal takes it: a
		// creation runs inside the request of a person, and holding its worker costs more than the note the
		// loser of the race gets over the quota.
		$isLocked = $connection->lock($lockName, self::CREATE_LOCK_TIMEOUT);

		try
		{
			if (TemplateNoteTable::getCount(['=TEMPLATE_ID' => $request->templateId]) >= Limits::NOTES_PER_TEMPLATE)
			{
				$response->addError(ErrorCode::NoteLimitReached->toError());

				return $response;
			}

			// A note without a message would take a place in the quota and draw as an empty label, so the
			// two writes are one: either both of them or neither.
			$isWritten = $this->runInTransaction(
				function () use ($request, $text, &$note, &$message): void
				{
					$note = $this->addNote($request);
					$message = $this->addMessage($note->getId(), $request->userId, $text);
				},
				$response,
			);
		}
		finally
		{
			if ($isLocked)
			{
				$connection->unlock($lockName);
			}
		}

		if (!$isWritten)
		{
			return $response;
		}

		$this->dispatchMentions($response, $request->templateId, $note->getId(), $request->userId, $text);

		return $response->setNote($note)->setMessage($message);
	}

	public function replyToNote(ReplyToNoteRequest $request): ReplyToNoteResponse
	{
		$response = new ReplyToNoteResponse();

		$note = $this->findNote($request->noteId);
		if ($note === null || !$this->canEditTemplate($note->getTemplateId(), $request->userId))
		{
			$this->denyAccess($response);

			return $response;
		}

		$text = $this->prepareText($request->message, $response);
		if ($text === null)
		{
			return $response;
		}

		if (TemplateNoteMessageTable::getCount(['=NOTE_ID' => $note->getId()]) >= Limits::MESSAGES_PER_NOTE)
		{
			$response->addError(ErrorCode::MessageLimitReached->toError());

			return $response;
		}

		$message = null;
		$isWritten = $this->runInTransaction(
			function () use ($note, $request, $text, &$message): void
			{
				$message = $this->addMessage($note->getId(), $request->userId, $text);
			},
			$response,
		);

		if (!$isWritten)
		{
			return $response;
		}

		$this->dispatchMentions($response, $note->getTemplateId(), $note->getId(), $request->userId, $text);

		return $response->setMessage($message);
	}

	public function updateMessage(UpdateMessageRequest $request): UpdateMessageResponse
	{
		$response = new UpdateMessageResponse();

		$message = $this->findMessage($request->messageId);
		$note = $message === null ? null : $this->findNote($message->getNoteId());
		if ($message === null || $note === null || !$this->isOwnMessageEditable($message, $note, $request->userId))
		{
			$this->denyAccess($response);

			return $response;
		}

		$text = $this->prepareText($request->message, $response);
		if ($text === null)
		{
			return $response;
		}

		$previousText = (string)$message->getMessage();

		if (!$this->rewriteMessage($request->messageId, $request->userId, $request->revision, $text))
		{
			$response->addError(ErrorCode::RevisionMismatch->toError());

			$actual = $this->findMessage($request->messageId);

			return $actual === null ? $response : $response->setActualMessage($actual);
		}

		$this->dispatchMentions(
			$response,
			$note->getTemplateId(),
			$note->getId(),
			$request->userId,
			$text,
			$previousText,
		);

		$written = $this->findMessage($request->messageId);
		if ($written === null)
		{
			// the message is gone between the rewrite of it and the read of it back: a message that is not
			// there answers the same way it does at the start of the operation, and never an untyped failure
			$this->denyAccess($response);

			return $response;
		}

		return $response->setMessage($written);
	}

	public function deleteMessage(DeleteMessageRequest $request): DeleteMessageResponse
	{
		$response = new DeleteMessageResponse();

		$message = $this->findMessage($request->messageId);
		if ($message === null)
		{
			// the deletion has already happened, and its repeat says the same as it did (ALG-04)
			return $response->setNoteRemoved(false);
		}

		$note = $this->findNote($message->getNoteId());
		if ($note === null || !$this->isOwnMessageEditable($message, $note, $request->userId))
		{
			$this->denyAccess($response);

			return $response;
		}

		$noteId = $message->getNoteId();
		$isNoteRemoved = false;

		$isDeleted = $this->runInTransaction(
			function () use ($request, $noteId, &$isNoteRemoved): void
			{
				$this->requireWritten(TemplateNoteMessageTable::delete($request->messageId));

				if (TemplateNoteMessageTable::getCount(['=NOTE_ID' => $noteId]) === 0)
				{
					$this->requireWritten(TemplateNoteTable::delete($noteId));
					$isNoteRemoved = true;
				}
			},
			$response,
		);

		if (!$isDeleted)
		{
			return $response;
		}

		$this->revokeMentionsOfMessage($noteId, (string)$message->getMessage(), $request->userId, $isNoteRemoved);

		return $response->setNoteRemoved($isNoteRemoved);
	}

	/**
	 * Completing a note removes it with everything written in it. The author closes his own question, and
	 * the administrator of the portal closes a discussion nobody comes back to.
	 */
	public function completeNote(CompleteNoteRequest $request): CompleteNoteResponse
	{
		$response = new CompleteNoteResponse();

		$note = $this->findNote($request->noteId);
		if ($note === null)
		{
			// the note is already gone, and the repeat of its completion says the same as it did (ALG-04)
			return $response;
		}

		$templateId = $note->getTemplateId();
		if (
			!$this->canEditTemplate($templateId, $request->userId)
			|| ($note->getAuthorId() !== $request->userId && !$this->isPortalAdmin($request->userId))
		)
		{
			$this->denyAccess($response);

			return $response;
		}

		$isCompleted = $this->runInTransaction(
			function () use ($note): void
			{
				TemplateNoteMessageTable::deleteByFilter(['=NOTE_ID' => $note->getId()]);
				$this->requireWritten(TemplateNoteTable::delete($note->getId()));
			},
			$response,
		);

		if ($isCompleted)
		{
			$this->journalCompletion($templateId, $note->getId(), $request->userId);
		}

		return $response;
	}

	/**
	 * The completion is the only irreversible action here - the discussion is gone with the note - so it
	 * leaves a trace outside the discussion itself. The journal of the template is not that trace: it is
	 * off by default and is cleaned by age. The text of the messages is not written down.
	 */
	private function journalCompletion(int $templateId, int $noteId, int $userId): void
	{
		try
		{
			$this->writeCompletionToEventLog($templateId, $noteId, $userId);
		}
		catch (\Throwable)
		{
			// The trace is best-effort and never decides the outcome of the completion.
		}
	}

	protected function writeCompletionToEventLog(int $templateId, int $noteId, int $userId): void
	{
		\CEventLog::Log(
			'INFO',
			self::COMPLETION_AUDIT_TYPE,
			'bizproc',
			(string)$noteId,
			sprintf('Template note completed: templateId=%d noteId=%d userId=%d', $templateId, $noteId, $userId),
		);
	}

	private function addNote(CreateNoteRequest $request): EO_TemplateNote
	{
		$anchor = $request->anchor;

		$note = TemplateNoteTable::createObject();
		$note
			->setTemplateId($request->templateId)
			->setAuthorId($request->userId)
			->setAnchorType($anchor->type->toStorage())
			->setAnchorNodeId($anchor->nodeId)
			->setAnchorSourceBlockId($anchor->sourceBlockId)
			->setAnchorSourcePortId($anchor->sourcePortId)
			->setAnchorTargetBlockId($anchor->targetBlockId)
			->setAnchorTargetPortId($anchor->targetPortId)
			->setOffsetX($request->offset->x)
			->setOffsetY($request->offset->y)
			->setPositionX($request->position->x)
			->setPositionY($request->position->y)
			->setCreated(new DateTime())
		;

		$this->requireWritten($note->save());

		return $note;
	}

	private function addMessage(int $noteId, int $userId, string $text): EO_TemplateNoteMessage
	{
		$message = TemplateNoteMessageTable::createObject();
		$message
			->setNoteId($noteId)
			->setAuthorId($userId)
			->setMessage($text)
			->setRevision(1)
			->setCreated(new DateTime())
		;

		$this->requireWritten($message->save());

		return $message;
	}

	/**
	 * The revision the editor has been showing is a condition of the very statement that rewrites the
	 * message: reading it first and writing after would let the edit of another window in between and
	 * overwrite it silently.
	 *
	 * @return bool whether this call is the one that rewrote the message
	 */
	private function rewriteMessage(int $messageId, int $userId, int $revision, string $text): bool
	{
		$connection = Application::getConnection();
		$helper = $connection->getSqlHelper();
		$tableName = TemplateNoteMessageTable::getTableName();

		// the text is already escaped by prepareUpdate() and cannot go through a SqlExpression template:
		// a question mark of the author would be read there as a placeholder of its own
		[$assignments] = $helper->prepareUpdate($tableName, ['MESSAGE' => $text, 'MODIFIED' => new DateTime()]);

		$revisionColumn = $helper->quote('REVISION');
		$condition = new SqlExpression(
			'?# = ?i AND ?# = ?i AND ?# = ?i',
			'ID', $messageId,
			'AUTHOR_ID', $userId,
			'REVISION', $revision,
		);

		$connection->queryExecute(
			'UPDATE ' . $helper->quote($tableName)
			. ' SET ' . $assignments . ', ' . $revisionColumn . ' = ' . $revisionColumn . ' + 1'
			. ' WHERE ' . $condition->compile()
		);

		$isRewritten = $connection->getAffectedRowsCount() > 0;

		if ($isRewritten)
		{
			TemplateNoteMessageTable::cleanCache();
		}

		return $isRewritten;
	}

	/**
	 * The text is brought to the allowed markup before anything is measured, so that a message of spaces
	 * alone counts as empty. The length is measured on what the author has typed, the way the editor counts
	 * it against the limit it was given: the escaping of a foreign bracket happens afterwards and must not
	 * turn an accepted message into a refused one.
	 *
	 * @return string|null null when the text is refused; the reason is already in the response
	 */
	private function prepareText(string $message, Result $response): ?string
	{
		$text = MessageText::sanitize($message);

		if ($text === '')
		{
			$response->addError(ErrorCode::EmptyMessage->toError());

			return null;
		}

		if (mb_strlen(trim($message)) > Limits::MESSAGE_LENGTH)
		{
			$response->addError(ErrorCode::MessageTooLong->toError());

			return null;
		}

		if (count(MessageText::extractMentions($text)) > Limits::MENTIONS_PER_MESSAGE)
		{
			$response->addError(ErrorCode::MentionLimitReached->toError());

			return null;
		}

		return $text;
	}

	/**
	 * A message is reached through its note and its template: the right to edit the template lets a person
	 * into the discussion, and only the author of a message changes or removes it - the administrator of
	 * the portal is no exception to that.
	 */
	private function isOwnMessageEditable(
		EO_TemplateNoteMessage $message,
		EO_TemplateNote $note,
		int $userId,
	): bool
	{
		return $message->getAuthorId() === $userId && $this->canEditTemplate($note->getTemplateId(), $userId);
	}

	/**
	 * The recipients come out of the saved text and out of nothing else, and every one of them is matched
	 * against the circle the notes are open to and the right to edit the template: a link to a discussion
	 * of a template a person may not see tells him that both exist. The ones a notification may not reach
	 * come back as a list of their own, so that the editor offers to take the mention away or to pick
	 * somebody else.
	 *
	 * An edit revokes what it has taken away and notifies what it has added; a mention that stayed keeps
	 * the notification it already got instead of ringing again. A mention taken away here may live on in
	 * a neighbouring message of the same note, and then the notification of it stays as well.
	 *
	 * Nothing here decides the outcome of the operation: the message is written already, and a messenger
	 * that is missing or broken only shows in the answer.
	 */
	private function dispatchMentions(
		CreateNoteResponse|ReplyToNoteResponse|UpdateMessageResponse $response,
		int $templateId,
		int $noteId,
		int $authorId,
		string $text,
		string $previousText = '',
	): void
	{
		$recipients = $this->mentionRecipients($text, $authorId);
		$previousRecipients = $this->mentionRecipients($previousText, $authorId);

		$allowed = $this->recipientsToNotify($templateId, $recipients);

		$response->setUnavailableMentions(array_values(array_diff($recipients, $allowed)));

		if (!$this->notification->isAvailable())
		{
			return;
		}

		// the message is rewritten by now, so the note reads back the way the editor has left it
		$this->revokeMentionsNoLongerHeld($noteId, array_diff($previousRecipients, $recipients));

		$notified = [];
		$isNotifyFailed = false;
		foreach (array_diff($allowed, $previousRecipients) as $userId)
		{
			if ($this->sendMention($templateId, $noteId, $authorId, $userId))
			{
				$notified[] = $userId;
			}
			else
			{
				$isNotifyFailed = true;
			}
		}

		$response->setNotified($notified)->setNotifyFailed($isNotifyFailed);
	}

	/**
	 * The addressees a notification may reach: an employee of the portal holding the right to edit the
	 * template. Both questions are asked of the whole set at once - a question per addressee is what a
	 * message of twenty mentions used to pay for.
	 *
	 * @param int[] $recipients
	 * @return int[] the ones to notify, in the order of the input
	 */
	private function recipientsToNotify(int $templateId, array $recipients): array
	{
		if (!$recipients || $templateId <= 0)
		{
			return [];
		}

		$employees = $this->employeesAmong($recipients);
		if (!$employees)
		{
			return [];
		}

		$rights = $this->templateAccessService->canEditBatch($templateId, $employees);

		return array_values(array_filter(
			$employees,
			static fn(int $userId): bool => $rights[$userId] ?? false,
		));
	}

	/**
	 * The circle the notes are open to at all: an external or a mail user is not notified of a mention, the
	 * same way he is not let into the notes by the filter of the actions
	 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Controller\TemplateNote::getDefaultPreFilters()}).
	 * Somebody outside the circle answers here exactly as somebody without the right does: the mention comes
	 * back unavailable, and the editor offers to take it away or to pick another addressee.
	 *
	 * A portal without the modules of the circle knows no such division - it has no employees to tell from
	 * external and mail users, and refusing everybody there would leave the mentions of the notes unusable.
	 * That is the reading the action filter of the notes has as well, where the filter is added only while
	 * the module is there.
	 *
	 * @param int[] $userIds
	 * @return int[] the employees among them, in the order of the input
	 */
	protected function employeesAmong(array $userIds): array
	{
		if (!Loader::includeModule('intranet') || !Loader::includeModule('humanresources'))
		{
			return $userIds;
		}

		return (new HrUserService())->filterEmployeesByUserIds($userIds);
	}

	/**
	 * A deleted message takes its notifications with it: the note may still be there, but the mention
	 * that led to it is gone.
	 *
	 * A notification is addressed by the pair of the note and its recipient, so what another message of
	 * the same note still calls a person for is not taken away from him: the recipients that are left
	 * mentioned keep the link to the discussion where they are still expected.
	 */
	private function revokeMentionsOfMessage(int $noteId, string $text, int $authorId, bool $isNoteRemoved): void
	{
		if (!$this->notification->isAvailable())
		{
			return;
		}

		$recipients = $this->mentionRecipients($text, $authorId);

		if (!$isNoteRemoved)
		{
			$this->revokeMentionsNoLongerHeld($noteId, $recipients);

			return;
		}

		// the note is gone together with its last message, so nothing of it mentions anybody any more
		foreach ($recipients as $userId)
		{
			$this->revokeMention($noteId, $userId);
		}
	}

	/**
	 * The notification of a mention is revoked only when no message of the note mentions that person any
	 * more: the same pair of the note and its recipient stands behind every mention of him in it.
	 *
	 * @param int[] $recipients the ones whose mention is gone from the message at hand
	 */
	private function revokeMentionsNoLongerHeld(int $noteId, array $recipients): void
	{
		if (!$recipients)
		{
			return;
		}

		$stillMentioned = $this->recipientsMentionedInNote($noteId, $recipients);

		foreach (array_diff($recipients, $stillMentioned) as $userId)
		{
			$this->revokeMention($noteId, $userId);
		}
	}

	/**
	 * One reading of the messages that are left in the note instead of a lookup per recipient: the texts
	 * of a single discussion are read at once and parsed by the very same parse the recipients come from.
	 *
	 * @param int[] $recipientIds
	 * @return int[] the ones of them a message of the note still mentions
	 */
	private function recipientsMentionedInNote(int $noteId, array $recipientIds): array
	{
		$rows = TemplateNoteMessageTable::query()
			->setSelect(['MESSAGE'])
			->where('NOTE_ID', $noteId)
			->fetchAll()
		;

		$mentioned = [];
		foreach ($rows as $row)
		{
			$mentioned = array_merge($mentioned, MessageText::extractMentions((string)$row['MESSAGE']));
		}

		return array_values(array_intersect($recipientIds, $mentioned));
	}

	/**
	 * @return int[] the mentioned users except the author himself - nobody is notified of his own words
	 */
	private function mentionRecipients(string $text, int $authorId): array
	{
		return array_values(array_diff(MessageText::extractMentions($text), [$authorId]));
	}

	private function sendMention(int $templateId, int $noteId, int $authorId, int $recipientId): bool
	{
		try
		{
			return $this->notification->notifyMention($templateId, $noteId, $authorId, $recipientId);
		}
		catch (\Throwable $exception)
		{
			$this->exceptionHandler->writeToLog($exception);

			return false;
		}
	}

	private function revokeMention(int $noteId, int $recipientId): void
	{
		try
		{
			$this->notification->revokeMention($noteId, $recipientId);
		}
		catch (\Throwable $exception)
		{
			// a notification left hanging is a stale link, not a failure of the edit that removed it
			$this->exceptionHandler->writeToLog($exception);
		}
	}

	private function canEditTemplate(int $templateId, int $userId): bool
	{
		return $templateId > 0 && $userId > 0 && $this->templateAccessService->canEdit($templateId, $userId);
	}

	private function templateExists(int $templateId): bool
	{
		$row = WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->where('ID', $templateId)
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}

	private function isPortalAdmin(int $userId): bool
	{
		return AccessController::getInstance($userId)->isAdmin();
	}

	private function findNote(int $noteId): ?EO_TemplateNote
	{
		return $noteId > 0 ? TemplateNoteTable::getByPrimary($noteId)->fetchObject() : null;
	}

	private function findMessage(int $messageId): ?EO_TemplateNoteMessage
	{
		return $messageId > 0 ? TemplateNoteMessageTable::getByPrimary($messageId)->fetchObject() : null;
	}

	/**
	 * @param int[] $noteIds
	 * @return array<int, int> note id => number of messages in it
	 */
	private function countMessagesOfNotes(array $noteIds): array
	{
		if (!$noteIds)
		{
			return [];
		}

		$rows = TemplateNoteMessageTable::query()
			->setSelect(['NOTE_ID'])
			->addSelect(Query::expr()->count('ID'), 'CNT')
			->whereIn('NOTE_ID', $noteIds)
			->fetchAll()
		;

		$counts = [];
		foreach ($rows as $row)
		{
			$counts[(int)$row['NOTE_ID']] = (int)$row['CNT'];
		}

		return $counts;
	}

	/**
	 * @param callable $write throws when a write of its own has not gone through
	 * @return bool whether the writes are committed; a failure is already in the response
	 */
	private function runInTransaction(callable $write, Result $response): bool
	{
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$write();
		}
		catch (\Throwable $exception)
		{
			$this->rollbackTransaction($connection);
			$this->exceptionHandler->writeToLog($exception);
			$response->addError(ErrorMessage::UNKNOWN_ERROR->getCodedError());

			return false;
		}

		$connection->commitTransaction();

		return true;
	}

	private function requireWritten(Result $result): void
	{
		if (!$result->isSuccess())
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}
	}

	private function rollbackTransaction(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	private function denyAccess(Result $response): void
	{
		$response->addError(ErrorCode::AccessDenied->toError());
	}
}
