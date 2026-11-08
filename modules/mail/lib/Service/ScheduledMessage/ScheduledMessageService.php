<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\ScheduledMessage;

use Bitrix\Mail\Access\Model\ScheduledMessageModel;
use Bitrix\Mail\Access\Rule\ScheduledMessageManageRule;
use Bitrix\Mail\Access\Rule\ScheduledMessageViewRule;
use Bitrix\Mail\Helper\LicenseManager;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Service\MailTemplate\ComposedMessageTemplateSaver;
use Bitrix\Mail\Internals\ScheduledMessageTable;
use Bitrix\Mail\Internal\Integration\Draft\ScheduledMessageDraftGateway;
use Bitrix\Mail\Repository\ScheduledMessageRepository;
use Bitrix\Mail\Service\Message\SendLimitChecker;
use Bitrix\Mail\Service\Message\SendMessageService;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class ScheduledMessageService
{
	public const ERROR_INVALID_TIME = 'invalid_time';
	public const ERROR_ACCESS_DENIED = 'access_denied';
	public const ERROR_NOT_FOUND = 'not_found';
	public const ERROR_REVISION_CONFLICT = 'revision_conflict';
	public const ERROR_ALREADY_SENDING = 'already_sending';
	/** The letter has left, so there is nothing left to change, to cancel or to send again. */
	public const ERROR_ALREADY_SENT = 'already_sent';
	/** The sending was cancelled and the letter itself is waiting in the drafts. */
	public const ERROR_ALREADY_CANCELLED = 'already_cancelled';
	/** The author has no access to the mailbox they are scheduling from; the mailbox itself may be healthy. */
	public const ERROR_MAILBOX_ACCESS_DENIED = 'mailbox_access_denied';
	public const ERROR_RECIPIENT_LIMIT = 'recipient_limit';
	public const ERROR_ATTACHMENT_LIMIT = 'attachment_limit';
	public const ERROR_INVALID_HEADER = 'invalid_header';
	public const ERROR_ATTACHMENT_ACCESS_DENIED = 'attachment_access_denied';
	public const ERROR_BATCH_LIMIT = 'batch_limit';
	public const ERROR_INVALID_OPERATION = 'invalid_operation';
	public const ERROR_CANCEL_WINDOW_EXPIRED = 'cancel_window_expired';
	public const MAX_ATTACHMENTS = 20;

	/**
	 * The ask to keep the letter as a template of its author, named the way the service names its own inner
	 * fields of the request.
	 */
	public const SAVE_AS_TEMPLATE_KEY = '_SAVE_AS_TEMPLATE';
	public const MAX_BATCH_SIZE = 50;
	/** Keeps the server cancellation boundary behind the visible timer and both HTTP trips. */
	public const UNDO_CANCEL_GUARD_SECONDS = 7;

	/** The grid of the list falls back to the same zone when it cannot read the one of the author. */
	private const FALLBACK_TIMEZONE_ID = 'UTC';

	/**
	 * The budget of automatic attempts belongs to one run of the sending and not to the letter: a letter the
	 * author sends into the queue again — by the repeat of a failed one or by a new moment for it — starts that
	 * run afresh, and the attempts the previous one spent must not end it on the first temporary failure.
	 */
	private const ATTEMPTS_UNSPENT = 0;

	private const MUTABLE_STATUSES = [
		ScheduledMessageTable::STATUS_SCHEDULED,
		ScheduledMessageTable::STATUS_ERROR,
	];

	public function __construct(
		private readonly ScheduledMessageRepository $repository = new ScheduledMessageRepository(),
		private readonly ScheduledMessageViewRule $viewRule = new ScheduledMessageViewRule(),
		private readonly ScheduledMessageManageRule $manageRule = new ScheduledMessageManageRule(),
		private readonly ScheduledMessageAttachmentService $attachmentService = new ScheduledMessageAttachmentService(),
		private readonly ?ScheduledMessageDraftGateway $draftGateway = null,
		private readonly SendLimitChecker $limitChecker = new SendLimitChecker(),
		private readonly ScheduledMessageSenderChecker $senderChecker = new ScheduledMessageSenderChecker(),
		private readonly ScheduledMessageLargeAttachmentService $largeAttachmentService = new ScheduledMessageLargeAttachmentService(),
		private readonly ComposedMessageTemplateSaver $templateSaver = new ComposedMessageTemplateSaver(),
	)
	{
	}

	/**
	 * The draft the letter was written in is named by the caller: the form saves one while the author types,
	 * and a letter that is now scheduled must not stay in the drafts as well.
	 *
	 * @param array<string, mixed> $data
	 */
	public function create(int $userId, array $data, ?int $draftId = null, ?int $draftRevision = null): Result
	{
		$data = $this->normalizeSnapshot($data);
		$mode = (string)($data['MODE'] ?? ScheduledMessageTable::MODE_SCHEDULED);
		$mailboxId = (int)($data['MAILBOX_ID'] ?? 0);
		if ($mode === ScheduledMessageTable::MODE_SCHEDULED || $mailboxId > 0)
		{
			$model = new ScheduledMessageModel([
				'AUTHOR_ID' => $userId,
				'MAILBOX_ID' => $mailboxId,
			]);
			// Scheduling asks no more of the author than access to the mailbox: whether they may still send with
			// it is asked again at the moment of sending, which happens outside this request.
			if (!$this->viewRule->canView($userId, $model))
			{
				return $this->error(self::ERROR_MAILBOX_ACCESS_DENIED, 'Mailbox is not available to the author.');
			}
		}

		$validationResult = $this->validateSnapshot($data);
		if (!$validationResult->isSuccess())
		{
			return $validationResult;
		}

		$limitResult = $this->checkSendLimits($data);
		if (!$limitResult->isSuccess())
		{
			return $limitResult;
		}

		$senderResult = $this->checkSender($userId, $data);
		if (!$senderResult->isSuccess())
		{
			return $senderResult;
		}

		$largeAttachmentResult = $this->largeAttachmentService->prepare(
			$userId,
			(array)($data['LARGE_ATTACHMENTS'] ?? []),
			(array)($data['ATTACHMENTS'] ?? []),
			(string)($data['BODY'] ?? ''),
		);
		if (!$largeAttachmentResult->isSuccess())
		{
			return $largeAttachmentResult;
		}
		$largeAttachmentData = $largeAttachmentResult->getData();
		$data['LARGE_ATTACHMENTS'] = $largeAttachmentData['largeAttachments'];

		$largeSourceIds = (array)($largeAttachmentData['sourceFileIds'] ?? []);
		$attachmentResult = $largeSourceIds === []
			? $this->attachmentService->copyForUser($userId, (array)($data['ATTACHMENTS'] ?? []))
			: $this->attachmentService->copyForUser(
				$userId,
				(array)($data['ATTACHMENTS'] ?? []),
				$largeSourceIds,
			)
		;
		if (!$attachmentResult->isSuccess())
		{
			return $attachmentResult;
		}

		$attachments = (array)($attachmentResult->getData()['attachments'] ?? []);
		$now = $mode === ScheduledMessageTable::MODE_UNDO && ($data['_UNDO_BASE_TIME'] ?? null) instanceof DateTime
			? $data['_UNDO_BASE_TIME']
			: new DateTime()
		;
		if ($mode === ScheduledMessageTable::MODE_UNDO)
		{
			$delaySeconds = max(1, (int)($data['_UNDO_DELAY_SECONDS'] ?? 0));
			$cancelUntil = DateTime::createFromTimestamp(
				$now->getTimestamp() + $delaySeconds + self::UNDO_CANCEL_GUARD_SECONDS,
			);
			$data['CANCEL_UNTIL'] = $cancelUntil;
			$data['SCHEDULED_AT'] = $cancelUntil;
		}
		$fields = $this->extractSnapshotFields($data);
		$fields['AUTHOR_ID'] = $userId;
		$fields['STATUS'] = ScheduledMessageTable::STATUS_SCHEDULED;
		$fields['ATTEMPTS'] = self::ATTEMPTS_UNSPENT;
		$fields['MODE'] = $mode;
		$fields['SOURCE_TYPE'] = (string)($data['SOURCE_TYPE'] ?? ScheduledMessageTable::SOURCE_TYPE_MAIL);
		$fields['NEXT_ATTEMPT_AT'] = $mode === ScheduledMessageTable::MODE_UNDO
			? $fields['CANCEL_UNTIL']
			: $fields['SCHEDULED_AT']
		;
		$fields['REVISION'] = 1;
		$fields['CREATED_AT'] = $now;
		$fields['UPDATED_AT'] = $now;

		$result = $this->repository->create(
			$fields,
			$attachments,
			operationProjection: $mode === ScheduledMessageTable::MODE_UNDO,
		);
		if (!$result->isSuccess())
		{
			$this->attachmentService->deleteCopies($attachments);

			return $result;
		}
		$created = ($result->getData()['created'] ?? true) !== false;
		if (!$created)
		{
			$this->attachmentService->deleteCopies($attachments);
		}

		/*
		 * The files a template should keep are asked about before the draft is closed below: the copies of a
		 * draft are released only while it is still active, and a template built on the copies would lose
		 * its attachments to the cleanup of the drafts.
		 */
		$templateDiskObjectIds = $this->templateSaver->resolveAuthorObjectIds(
			$userId,
			$draftId,
			$draftRevision,
			$this->templateDiskObjectIds($data, $largeSourceIds),
		);

		// Only now, once the letter is really scheduled: a draft closed before the row exists would leave the
		// author with neither of the two.
		$this->getDraftGateway()->completeAfterSchedule($userId, $draftId, $draftRevision);

		return $this->keepAsTemplateIfAsked($result, $userId, $data, $created, $templateDiskObjectIds);
	}

	/**
	 * The letter of the schedule and the letter held back by the undo window leave later, from the background
	 * dispatcher, so the template of their author is kept the moment the letter is accepted: the template
	 * lives in a catalog of its own and does not depend on the delivery.
	 *
	 * The ask is acted upon only on the row this call has really created. A repeat of the same request comes
	 * back as the operation already accepted, and a second template for one letter would be the author's
	 * copy of a letter they sent once.
	 *
	 * Only the ordinary attachments reach the template: an inline image belongs to the body of the letter,
	 * and a file carried over into a link of a large attachment travels as that link. The identifiers are the
	 * ones the author sent, every one of them already read-checked by the copying of the attachments above.
	 *
	 * The name of the template is the subject the letter leaves with, not the raw field of the request: a
	 * letter of the schedule keeps an empty subject in its row, and a template without a name is refused by
	 * the catalog. The same helper names such a letter on the immediate path.
	 *
	 * @param array<string, mixed> $data
	 * @param int[] $diskObjectIds objects of Disk the template keeps, already resolved to the files of the author
	 */
	private function keepAsTemplateIfAsked(
		Result $result,
		int $userId,
		array $data,
		bool $created,
		array $diskObjectIds = [],
	): Result
	{
		if (!$created || empty($data[self::SAVE_AS_TEMPLATE_KEY]))
		{
			return $result;
		}

		$sender = (array)($data['SENDER'] ?? []);
		$bodyHtml = (string)($data['BODY'] ?? '');
		$saved = $this->templateSaver->save(
			userId: $userId,
			subject: Message::getOutgoingSubject((string)($data['SUBJECT'] ?? ''), $bodyHtml),
			bodyHtml: $bodyHtml,
			emailFrom: (string)($sender['email'] ?? ''),
			diskObjectIds: $diskObjectIds,
		);

		return $result->setData(array_merge($result->getData(), ['templateSaveWarning' => !$saved]));
	}

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed>|null $sourceContext
	 */
	public function createUndo(
		int $userId,
		array $data,
		int $delaySeconds,
		string $idempotencyKey,
		string $sourceType = ScheduledMessageTable::SOURCE_TYPE_MAIL,
		?array $sourceContext = null,
		?int $draftId = null,
		?int $draftRevision = null,
		?DateTime $now = null,
	): Result
	{
		$idempotencyKey = trim($idempotencyKey);
		if ($idempotencyKey !== '')
		{
			$existing = $this->repository->getByIdempotencyKey($userId, $idempotencyKey);
			if ($existing !== null)
			{
				return (new Result())->setData([
					'scheduledMessage' => $existing,
					'created' => false,
				]);
			}
		}

		$baseTime = $now;
		$now ??= new DateTime();
		$validationDeadline = DateTime::createFromTimestamp($now->getTimestamp() + max(1, $delaySeconds));
		if (
			$sourceType === ScheduledMessageTable::SOURCE_TYPE_MAIL
			&& ($draftId ?? 0) > 0
			&& ($draftRevision ?? 0) > 0
		)
		{
			$sourceContext = [
				'version' => 1,
				'draftId' => $draftId,
				'draftRevision' => $draftRevision,
			];
		}

		$data['MODE'] = ScheduledMessageTable::MODE_UNDO;
		$data['SOURCE_TYPE'] = $sourceType;
		$data['CANCEL_UNTIL'] = $validationDeadline;
		$data['SCHEDULED_AT'] = $validationDeadline;
		$data['SOURCE_CONTEXT'] = $sourceContext;
		$data['IDEMPOTENCY_KEY'] = $idempotencyKey;
		$data['_UNDO_DELAY_SECONDS'] = $delaySeconds;
		$data['_UNDO_BASE_TIME'] = $baseTime;

		return $this->create($userId, $data, $draftId, $draftRevision);
	}

	public function get(int $userId, int $id): Result
	{
		$row = $this->repository->get($id);
		if ($row === null)
		{
			return $this->error(self::ERROR_NOT_FOUND, 'Scheduled message is not found.');
		}
		if ((string)$row['MODE'] !== ScheduledMessageTable::MODE_SCHEDULED)
		{
			return $this->error(self::ERROR_NOT_FOUND, 'Scheduled message is not found.');
		}

		if (!$this->viewRule->canView($userId, new ScheduledMessageModel($row)))
		{
			return $this->error(self::ERROR_ACCESS_DENIED, 'Access denied.');
		}

		$result = new Result();
		$result->setData(['scheduledMessage' => $row]);

		return $result;
	}

	/**
	 * @param string[] $statuses
	 */
	public function getList(
		int $userId,
		int $mailboxId,
		array $statuses = [],
		int $offset = 0,
		int $limit = 50,
		bool $withAttachments = false,
	): Result
	{
		$model = new ScheduledMessageModel(['MAILBOX_ID' => $mailboxId]);
		if (!$this->viewRule->canView($userId, $model))
		{
			return $this->error(self::ERROR_ACCESS_DENIED, 'Access denied.');
		}

		$list = $this->repository->getList([$mailboxId], $statuses, $offset, $limit, $withAttachments);
		$result = new Result();
		$result->setData([
			'items' => $list['items'],
			'total' => $list['total'],
			'hasMore' => $offset + count($list['items']) < $list['total'],
		]);

		return $result;
	}

	public function getUndoProjections(int $userId, int $mailboxId, int $limit = 25): Result
	{
		$model = new ScheduledMessageModel(['MAILBOX_ID' => $mailboxId]);
		if (!$this->viewRule->canView($userId, $model))
		{
			return $this->error(self::ERROR_ACCESS_DENIED, 'Access denied.');
		}

		return (new Result())->setData([
			'items' => $this->repository->getUndoProjections($userId, $mailboxId, $limit),
		]);
	}

	/**
	 * How many letters the section holds for the mailbox. The author of a letter takes no part in the count:
	 * the section of a shared mailbox lists the letters of everyone who writes from it, and a number counted
	 * by author would not match the rows underneath it.
	 */
	public function getSectionCount(int $userId, int $mailboxId): Result
	{
		$model = new ScheduledMessageModel(['MAILBOX_ID' => $mailboxId]);
		if (!$this->viewRule->canView($userId, $model))
		{
			return $this->error(self::ERROR_ACCESS_DENIED, 'Access denied.');
		}

		return (new Result())->setData(['count' => $this->repository->countSection($mailboxId)]);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update(int $userId, int $id, int $revision, array $data): Result
	{
		$data = $this->normalizeSnapshot($data);
		$accessResult = $this->getManageable($userId, $id, $revision);
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		$oldRow = (array)($accessResult->getData()['scheduledMessage'] ?? []);
		$limitResult = $this->checkSendLimits($data, $oldRow);
		if (!$limitResult->isSuccess())
		{
			return $limitResult;
		}

		$senderResult = $this->checkSender($userId, $data);
		if (!$senderResult->isSuccess())
		{
			return $senderResult;
		}

		$existingAttachments = (array)($oldRow['ATTACHMENTS'] ?? []);
		$retainedAttachments = $existingAttachments;
		if (array_key_exists('EXISTING_ATTACHMENT_IDS', $data))
		{
			$retainIds = array_values(array_unique(array_map('intval', (array)$data['EXISTING_ATTACHMENT_IDS'])));
			$ownedById = [];
			foreach ($existingAttachments as $attachment)
			{
				$ownedById[(int)$attachment['ID']] = $attachment;
			}
			if ($retainIds !== [] && array_diff($retainIds, array_keys($ownedById)) !== [])
			{
				return $this->error(self::ERROR_ATTACHMENT_ACCESS_DENIED, 'Attachment access denied.');
			}
			$retainedAttachments = array_values(array_intersect_key($ownedById, array_fill_keys($retainIds, true)));
		}

		$newAttachments = null;
		$createdAttachments = [];
		if (array_key_exists('ATTACHMENTS', $data))
		{
			$attachmentResult = $this->attachmentService->copyForUser($userId, (array)$data['ATTACHMENTS']);
			if (!$attachmentResult->isSuccess())
			{
				return $attachmentResult;
			}

			$createdAttachments = (array)($attachmentResult->getData()['attachments'] ?? []);
			$newAttachments = array_merge(
				array_map($this->attachmentRowForSave(...), $retainedAttachments),
				$createdAttachments,
			);
		}
		elseif (array_key_exists('EXISTING_ATTACHMENT_IDS', $data))
		{
			$newAttachments = array_map($this->attachmentRowForSave(...), $retainedAttachments);
		}

		$validationData = $data;
		if ($newAttachments !== null)
		{
			$validationData['ATTACHMENTS'] = $newAttachments;
		}
		$validationResult = $this->validateSnapshot($validationData, requireTime: false);
		if (!$validationResult->isSuccess())
		{
			if ($createdAttachments !== [])
			{
				$this->attachmentService->deleteCopies($createdAttachments);
			}

			return $validationResult;
		}

		$fields = $this->extractSnapshotFields($data, includeMailbox: false);
		if (isset($fields['SCHEDULED_AT']))
		{
			$fields['NEXT_ATTEMPT_AT'] = $fields['SCHEDULED_AT'];
			$fields['STATUS'] = ScheduledMessageTable::STATUS_SCHEDULED;
			$fields['ATTEMPTS'] = self::ATTEMPTS_UNSPENT;
			$fields['LAST_ERROR'] = null;
		}

		$result = $this->repository->updateByRevision(
			$id,
			$revision,
			$fields,
			self::MUTABLE_STATUSES,
			$newAttachments,
		);
		if (!$result->isSuccess())
		{
			if ($createdAttachments !== [])
			{
				$this->attachmentService->deleteCopies($createdAttachments);
			}
		}
		elseif ($newAttachments !== null)
		{
			$retainedFileIds = array_fill_keys(array_map(
				static fn(array $attachment): int => (int)$attachment['FILE_ID'],
				$retainedAttachments,
			), true);
			$removedAttachments = array_filter(
				$existingAttachments,
				static fn(array $attachment): bool => !isset($retainedFileIds[(int)$attachment['FILE_ID']]),
			);
			if ($removedAttachments !== [])
			{
				$this->attachmentService->deleteCopies($removedAttachments);
			}
		}

		return $result;
	}

	public function cancel(int $userId, int $id, int $revision): Result
	{
		$accessResult = $this->getManageable($userId, $id, $revision);
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		$row = (array)($accessResult->getData()['scheduledMessage'] ?? []);

		return $this->getDraftGateway()->convert($row, $revision);
	}

	public function cancelUndo(int $userId, int $id, int $revision, ?DateTime $now = null): Result
	{
		return $this->getDraftGateway()->cancelUndo($userId, $id, $revision, $now ?? new DateTime());
	}

	/**
	 * @param array<int, array<string, mixed>> $items
	 */
	public function cancelBatch(int $userId, array $items): Result
	{
		return $this->manageBatch(
			$userId,
			$items,
			fn (array $row, int $revision): Result => $this->getDraftGateway()->convert($row, $revision),
		);
	}

	public function retry(int $userId, int $id, int $revision): Result
	{
		$accessResult = $this->getManageable($userId, $id, $revision);
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		return $this->repository->updateByRevision(
			$id,
			$revision,
			[
				'STATUS' => ScheduledMessageTable::STATUS_SCHEDULED,
				'ATTEMPTS' => self::ATTEMPTS_UNSPENT,
				'NEXT_ATTEMPT_AT' => new DateTime(),
				'LAST_ERROR' => null,
			],
			[ScheduledMessageTable::STATUS_ERROR],
		);
	}

	/**
	 * Sending on demand sends nothing of its own: it makes the moment of the letter the current one and leaves
	 * the sending to the claim of the dispatcher, which stays the only arbiter of who sends the letter. Unlike
	 * a repeat, it takes a letter that is still waiting as well.
	 */
	public function sendNow(int $userId, int $id, int $revision): Result
	{
		$accessResult = $this->getManageable($userId, $id, $revision);
		if (!$accessResult->isSuccess())
		{
			return $accessResult;
		}

		return $this->makeDueNow((array)($accessResult->getData()['scheduledMessage'] ?? []), $revision);
	}

	/**
	 * Sending on demand writes the moments of the letter and answers with the row of the section, so it reads
	 * the row of the section: the body and the attachments of the whole batch are of no use to it. The full
	 * snapshot stays with the cancellation, which writes the letter into a draft.
	 *
	 * @param array<int, array<string, mixed>> $items
	 */
	public function sendNowBatch(int $userId, array $items): Result
	{
		return $this->manageBatch($userId, $items, $this->makeDueNow(...), withSnapshot: false);
	}

	public function claim(int $id, int $revision): Result
	{
		return $this->repository->claim(
			$id,
			$revision,
			[ScheduledMessageTable::STATUS_SCHEDULED],
		);
	}

	/**
	 * `getDue()` asks for both moments at once, so both are moved: a letter planned for next week would stay
	 * unseen by the dispatcher if only the moment of its next attempt were brought forward.
	 *
	 * The caller already holds the letter, so the state it answers with is that letter with the written fields
	 * applied. Reading the row back would carry a `BODY` of every letter of a batch through the request twice.
	 *
	 * @param array<string, mixed> $row
	 */
	private function makeDueNow(array $row, int $revision): Result
	{
		$now = new DateTime();
		$fields = [
			'STATUS' => ScheduledMessageTable::STATUS_SCHEDULED,
			'ATTEMPTS' => self::ATTEMPTS_UNSPENT,
			'SCHEDULED_AT' => $now,
			'NEXT_ATTEMPT_AT' => $now,
			'LAST_ERROR' => null,
		];
		$result = $this->repository->updateByRevision(
			(int)$row['ID'],
			$revision,
			$fields,
			self::MUTABLE_STATUSES,
			hydrate: false,
		);
		if (!$result->isSuccess())
		{
			return $result;
		}

		return $result->setData([
			'scheduledMessage' => array_merge($row, $fields, [
				'REVISION' => $revision + 1,
				'UPDATED_AT' => $now,
			]),
		]);
	}

	/**
	 * The letters of a batch are checked independently and a refusal of one does not stop the others; what the
	 * batch does with a letter that passed the check, and how much of that letter it needs, is all that tells
	 * one batch operation from another. The path of the checks is one for all of them on purpose: the limit of
	 * the batch, the collapsing of repeats, the access and the revision must not part ways between actions.
	 *
	 * @param array<int, array<string, mixed>> $items
	 * @param callable(array<string, mixed>, int): Result $operation
	 * @param bool $withSnapshot whether the operation reads the letter itself and not only the row of it
	 */
	private function manageBatch(
		int $userId,
		array $items,
		callable $operation,
		bool $withSnapshot = true,
	): Result
	{
		if (count($items) > self::MAX_BATCH_SIZE)
		{
			return $this->error(self::ERROR_BATCH_LIMIT, 'Scheduled message batch limit exceeded.');
		}

		$seenIds = [];
		$uniqueItems = [];
		foreach ($items as $item)
		{
			$id = (int)($item['id'] ?? 0);
			if ($id > 0 && isset($seenIds[$id]))
			{
				continue;
			}
			if ($id > 0)
			{
				$seenIds[$id] = true;
			}
			$uniqueItems[] = $item;
		}

		$messages = $this->repository->getByIds(array_keys($seenIds), $withSnapshot);
		$models = [];
		foreach ($messages as $id => $message)
		{
			$models[$id] = new ScheduledMessageModel($message);
		}
		$manageable = $this->manageRule->canManageBatch($userId, $models);

		$results = [];
		foreach ($uniqueItems as $item)
		{
			$id = (int)($item['id'] ?? 0);
			$revision = (int)($item['revision'] ?? 0);
			$row = $messages[$id] ?? null;
			if ($id <= 0 || $revision <= 0 || $row === null)
			{
				$itemResult = $this->error(self::ERROR_NOT_FOUND, 'Scheduled message is not found.');
			}
			elseif (!($manageable[$id] ?? false))
			{
				$itemResult = $this->error(self::ERROR_ACCESS_DENIED, 'Access denied.');
			}
			else
			{
				$itemResult = $this->validateManageableRow($row, $revision);
				if ($itemResult->isSuccess())
				{
					$itemResult = $operation($row, $revision);
				}
			}

			$results[] = $this->batchItem($id, $itemResult);
		}

		return (new Result())->setData(['results' => $results]);
	}

	private function getManageable(int $userId, int $id, int $revision): Result
	{
		$row = $this->repository->get($id);
		if ($row === null)
		{
			return $this->error(self::ERROR_NOT_FOUND, 'Scheduled message is not found.');
		}
		if ((string)$row['MODE'] !== ScheduledMessageTable::MODE_SCHEDULED)
		{
			return $this->error(self::ERROR_NOT_FOUND, 'Scheduled message is not found.');
		}

		$model = new ScheduledMessageModel($row);
		if (!$this->manageRule->canManage($userId, $model))
		{
			return $this->error(self::ERROR_ACCESS_DENIED, 'Access denied.');
		}

		return $this->validateManageableRow($row, $revision);
	}

	/** @param array<string, mixed> $row */
	private function validateManageableRow(array $row, int $revision): Result
	{
		$status = (string)$row['STATUS'];
		if ($status === ScheduledMessageTable::STATUS_SENDING)
		{
			return $this->error(self::ERROR_ALREADY_SENDING, 'Message is already sending.');
		}

		// A page that stood open while the letter left holds a stale revision as well, so the statuses nothing
		// can be done about are named before the revision: a conflict would ask the reader to repeat an action
		// that is no longer possible.
		if ($status === ScheduledMessageTable::STATUS_SENT)
		{
			return $this->error(self::ERROR_ALREADY_SENT, 'Message is already sent.');
		}

		if ($status === ScheduledMessageTable::STATUS_CANCELLED)
		{
			return $this->error(self::ERROR_ALREADY_CANCELLED, 'Message is already cancelled.');
		}

		if ((int)$row['REVISION'] !== $revision)
		{
			return $this->error(self::ERROR_REVISION_CONFLICT, 'Revision conflict.');
		}

		return (new Result())->setData(['scheduledMessage' => $row]);
	}

	/**
	 * Everything the browser sends is brought to a shape the portal may keep: the body is sanitized once,
	 * on the way in, and the fields it renders for other users are held to what they are allowed to be.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function normalizeSnapshot(array $data): array
	{
		if (array_key_exists('BODY', $data))
		{
			$data['BODY'] = ScheduledMessageBody::sanitize((string)$data['BODY']);
		}

		if (array_key_exists('AUTHOR_TIMEZONE_ID', $data))
		{
			$data['AUTHOR_TIMEZONE_ID'] = $this->normalizeTimezoneId((string)$data['AUTHOR_TIMEZONE_ID']);
		}

		if (array_key_exists('IN_REPLY_TO', $data))
		{
			$inReplyTo = trim(trim((string)($data['IN_REPLY_TO'] ?? '')), '<>');
			$data['IN_REPLY_TO'] = $inReplyTo !== '' ? $inReplyTo : null;
		}

		if (array_key_exists('SENDER', $data))
		{
			$data['SENDER'] = $this->normalizeContact((array)$data['SENDER']);
		}

		if (array_key_exists('RECIPIENTS', $data))
		{
			$data['RECIPIENTS'] = $this->normalizeRecipients((array)$data['RECIPIENTS']);
		}

		if (
			(string)($data['MODE'] ?? ScheduledMessageTable::MODE_SCHEDULED) === ScheduledMessageTable::MODE_UNDO
			&& array_key_exists('SUBJECT', $data)
		)
		{
			$data['SUBJECT'] = Message::getOutgoingSubject(
				(string)$data['SUBJECT'],
				(string)($data['BODY'] ?? ''),
			);
		}

		return $data;
	}

	/**
	 * Ordinary attachments of the letter, the ones a template keeps: an inline image belongs to the body of
	 * the letter, and a file the send carried over into a link of a large attachment travels as that link.
	 *
	 * @param array<string, mixed> $data
	 * @param array<int, int|string> $largeSourceIds files the send carried over into links
	 * @return int[]
	 */
	private function templateDiskObjectIds(array $data, array $largeSourceIds): array
	{
		if (empty($data[self::SAVE_AS_TEMPLATE_KEY]))
		{
			return [];
		}

		$carriedOverIds = array_fill_keys(array_map('intval', $largeSourceIds), true);
		$diskObjectIds = [];
		foreach ((array)($data['ATTACHMENTS'] ?? []) as $attachment)
		{
			if (!is_array($attachment) || !empty($attachment['IS_INLINE']))
			{
				continue;
			}

			$objectId = (int)($attachment['OBJECT_ID'] ?? 0);
			if ($objectId > 0 && !isset($carriedOverIds[$objectId]))
			{
				$diskObjectIds[] = $objectId;
			}
		}

		return $diskObjectIds;
	}

	private function normalizeTimezoneId(string $timezoneId): string
	{
		$identifiers = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);

		return in_array($timezoneId, $identifiers, true) ? $timezoneId : self::FALLBACK_TIMEZONE_ID;
	}

	/**
	 * @param array<string, mixed> $recipients
	 * @return array<string, mixed>
	 */
	private function normalizeRecipients(array $recipients): array
	{
		foreach (['to', 'cc', 'bcc'] as $type)
		{
			if (!is_array($recipients[$type] ?? null))
			{
				continue;
			}

			$recipients[$type] = array_map(
				fn (mixed $recipient): mixed => is_array($recipient)
					? $this->normalizeContact($recipient)
					: $recipient,
				$recipients[$type],
			);
		}

		return $recipients;
	}

	/**
	 * The avatar is drawn by the browser of everyone who has the mailbox, so an address outside the portal
	 * would report them to a third party. The portal names its own avatars by path — the selector gives
	 * `/upload/...`, the address book its own icon — so only a path is kept and no host takes part in the
	 * decision. The field is optional and the chip stands without a picture, so a source that may not be
	 * requested is dropped instead of failing the whole message.
	 *
	 * @param array<string, mixed> $contact
	 * @return array<string, mixed>
	 */
	private function normalizeContact(array $contact): array
	{
		if (isset($contact['avatar']) && !$this->isPortalPath((string)$contact['avatar']))
		{
			unset($contact['avatar']);
		}

		return $contact;
	}

	/** A path of the portal itself, but not "//host" or "/\host": the browser reads both as another origin. */
	private function isPortalPath(string $url): bool
	{
		return preg_match('#^/[^/\\\\]#', $url) === 1;
	}

	/** @param array<string, mixed> $data */
	private function validateSnapshot(array $data, bool $requireTime = true): Result
	{
		$operationResult = $this->validateOperation($data);
		if (!$operationResult->isSuccess())
		{
			return $operationResult;
		}

		if (count((array)($data['ATTACHMENTS'] ?? [])) > self::MAX_ATTACHMENTS)
		{
			return $this->error(self::ERROR_ATTACHMENT_LIMIT, 'Attachment limit exceeded.');
		}

		if ($this->containsHeaderControlCharacters($data))
		{
			return $this->error(self::ERROR_INVALID_HEADER, 'Mail header contains invalid characters.');
		}

		$hasTime = array_key_exists('SCHEDULED_AT', $data);
		if (
			($requireTime || $hasTime)
			&& (!$data['SCHEDULED_AT'] instanceof DateTime || !$this->isFuture($data['SCHEDULED_AT']))
		)
		{
			return $this->error(self::ERROR_INVALID_TIME, 'Scheduled time must be in the future.');
		}

		$recipients = (array)($data['RECIPIENTS'] ?? []);
		$to = (array)($recipients['to'] ?? []);
		$cc = (array)($recipients['cc'] ?? []);
		$bcc = (array)($recipients['bcc'] ?? []);
		if (
			$to === []
			|| count($to) + count($cc) + count($bcc) > LicenseManager::getMessageRecipientsTotalLimit()
		)
		{
			return $this->error(self::ERROR_RECIPIENT_LIMIT, 'Recipient list is invalid.');
		}

		return new Result();
	}

	/** @param array<string, mixed> $data */
	private function validateOperation(array $data): Result
	{
		$mode = (string)($data['MODE'] ?? ScheduledMessageTable::MODE_SCHEDULED);
		$sourceType = (string)($data['SOURCE_TYPE'] ?? ScheduledMessageTable::SOURCE_TYPE_MAIL);
		if (
			!in_array($mode, [ScheduledMessageTable::MODE_SCHEDULED, ScheduledMessageTable::MODE_UNDO], true)
			|| !in_array(
				$sourceType,
				[ScheduledMessageTable::SOURCE_TYPE_MAIL, ScheduledMessageTable::SOURCE_TYPE_CRM],
				true,
			)
		)
		{
			return $this->error(self::ERROR_INVALID_OPERATION, 'Scheduled message operation is invalid.');
		}

		if ($mode === ScheduledMessageTable::MODE_SCHEDULED)
		{
			return new Result();
		}

		$cancelUntil = $data['CANCEL_UNTIL'] ?? null;
		$idempotencyKey = (string)($data['IDEMPOTENCY_KEY'] ?? '');
		if (
			!$cancelUntil instanceof DateTime
			|| !$this->isFuture($cancelUntil)
			|| $idempotencyKey === ''
			|| mb_strlen($idempotencyKey) > 64
		)
		{
			return $this->error(self::ERROR_INVALID_OPERATION, 'Undo send operation is invalid.');
		}

		return new Result();
	}

	/**
	 * Limits of the tariff and of the portal are read here as well, not only when the letter leaves: an author
	 * whose portal has no quota left learns it while the form is open instead of hours later, from a
	 * notification. The refusal carries the code the moment of sending would answer with.
	 *
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $existingRow
	 */
	private function checkSendLimits(array $data, array $existingRow = []): Result
	{
		$sender = (array)($data['SENDER'] ?? $existingRow['SENDER'] ?? []);
		$recipients = (array)($data['RECIPIENTS'] ?? $existingRow['RECIPIENTS'] ?? []);
		$scheduledAt = $data['SCHEDULED_AT'] ?? $existingRow['SCHEDULED_AT'] ?? null;

		return $this->limitChecker->check(
			(string)($sender['email'] ?? ''),
			$this->recipientEmails((array)($recipients['to'] ?? [])),
			$this->recipientEmails((array)($recipients['cc'] ?? [])),
			$this->recipientEmails((array)($recipients['bcc'] ?? [])),
			$scheduledAt instanceof DateTime ? $scheduledAt : null,
		);
	}

	/**
	 * The address of the sender is held to the same list here and at the moment of sending: an address the
	 * portal does not offer the author is answered into the open form instead of a notification a day later.
	 * A change that names no sender leaves the one already stored, which was asked about when it was written.
	 *
	 * @param array<string, mixed> $data
	 */
	private function checkSender(int $userId, array $data): Result
	{
		if (!array_key_exists('SENDER', $data))
		{
			return new Result();
		}

		$sender = (array)$data['SENDER'];

		return $this->senderChecker->check($userId, (string)($sender['email'] ?? ''));
	}

	/**
	 * Addresses as the limits count them. A repeated address is one address: the letter leaves with the list
	 * deduplicated, and counting a duplicate here would refuse a letter the sending itself would let through.
	 *
	 * @param array<int, array<string, mixed>|string> $items
	 * @return string[]
	 */
	private function recipientEmails(array $items): array
	{
		$emails = [];
		foreach ($items as $item)
		{
			$email = mb_strtolower(trim(is_array($item) ? (string)($item['email'] ?? '') : (string)$item));
			if ($email !== '')
			{
				$emails[] = $email;
			}
		}

		return array_values(array_unique($emails));
	}

	/** @param array<string, mixed> $data */
	private function containsHeaderControlCharacters(array $data): bool
	{
		$inReplyTo = (string)($data['IN_REPLY_TO'] ?? '');
		if (mb_strlen($inReplyTo) > 255)
		{
			return true;
		}

		$values = [(string)($data['SUBJECT'] ?? ''), $inReplyTo];
		$sender = (array)($data['SENDER'] ?? []);
		$values[] = (string)($sender['name'] ?? '');
		$values[] = (string)($sender['email'] ?? '');

		$recipients = (array)($data['RECIPIENTS'] ?? []);
		foreach (['to', 'cc', 'bcc'] as $type)
		{
			foreach ((array)($recipients[$type] ?? []) as $recipient)
			{
				if (is_array($recipient))
				{
					$values[] = (string)($recipient['name'] ?? '');
					$values[] = (string)($recipient['email'] ?? '');
				}
				else
				{
					$values[] = (string)$recipient;
				}
			}
		}

		foreach ((array)($data['ATTACHMENTS'] ?? []) as $attachment)
		{
			$values[] = (string)($attachment['FILE_NAME'] ?? '');
			$values[] = (string)($attachment['CONTENT_ID'] ?? '');
		}

		foreach ($values as $value)
		{
			if (preg_match('/[\x00-\x1F\x7F]/', $value))
			{
				return true;
			}
		}

		return false;
	}

	private function isFuture(DateTime $dateTime): bool
	{
		return $dateTime->getTimestamp() > time();
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function extractSnapshotFields(
		array $data,
		bool $includeTime = true,
		bool $includeMailbox = true,
	): array
	{
		$allowed = [
			'SENDER',
			'RECIPIENTS',
			'SUBJECT',
			'BODY',
			'LARGE_ATTACHMENTS',
			'IN_REPLY_TO',
			'AUTHOR_TIMEZONE_ID',
			'MODE',
			'SOURCE_TYPE',
			'CANCEL_UNTIL',
			'SOURCE_CONTEXT',
			'IDEMPOTENCY_KEY',
		];
		if ($includeMailbox)
		{
			$allowed[] = 'MAILBOX_ID';
		}
		if ($includeTime)
		{
			$allowed[] = 'SCHEDULED_AT';
		}

		return array_intersect_key($data, array_fill_keys($allowed, true));
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}

	private function batchItem(int $id, Result $result): array
	{
		$item = [
			'id' => $id,
			'success' => $result->isSuccess(),
		];
		if ($result->isSuccess())
		{
			$item['data'] = $result->getData();

			return $item;
		}

		$item['errors'] = array_map(
			static fn (Error $error): array => [
				'code' => (string)$error->getCode(),
				'message' => $error->getMessage(),
			],
			$result->getErrors(),
		);

		return $item;
	}

	private function getDraftGateway(): ScheduledMessageDraftGateway
	{
		return $this->draftGateway ?? new ScheduledMessageDraftGateway($this->repository);
	}

	private function attachmentRowForSave(array $attachment): array
	{
		unset($attachment['ID'], $attachment['SCHEDULED_MESSAGE_ID']);

		return $attachment;
	}
}
