<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\UndoSend;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageService;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class UndoSendService
{
	public const ERROR_PREFERENCE_DISABLED = 'preference_disabled';

	public function __construct(
		private readonly ScheduledMessageService $scheduledMessageService = new ScheduledMessageService(),
		private readonly UndoSendPreference $preference = new UndoSendPreference(),
		private readonly UndoSendCancelToken $cancelToken = new UndoSendCancelToken(),
	)
	{
	}

	/** @param array<string, mixed> $snapshot */
	public function create(
		int $userId,
		array $snapshot,
		string $idempotencyKey,
		?int $draftId = null,
		?int $draftRevision = null,
		?DateTime $now = null,
	): Result
	{
		$delaySeconds = $this->preference->get($userId);
		if ($delaySeconds === 0)
		{
			return (new Result())->addError(
				new Error('Undo send is disabled by user preference.', self::ERROR_PREFERENCE_DISABLED),
			);
		}

		$result = $this->scheduledMessageService->createUndo(
			$userId,
			$snapshot,
			$delaySeconds,
			$idempotencyKey,
			draftId: $draftId,
			draftRevision: $draftRevision,
			now: $now,
		);
		$serverNow = $now ?? new DateTime();

		return $this->operationResult($result, $serverNow, $userId);
	}

	public function cancel(
		int $userId,
		int $id,
		int $revision,
		?DateTime $now = null,
	): Result
	{
		$now ??= new DateTime();
		$result = $this->scheduledMessageService->cancelUndo($userId, $id, $revision, $now);

		return $this->operationResult($result, $now);
	}

	private function operationResult(Result $source, DateTime $serverNow, ?int $userId = null): Result
	{
		if (!$source->isSuccess())
		{
			return (new Result())->addErrors($source->getErrors());
		}

		$row = $source->getData()['scheduledMessage'] ?? null;
		if (!is_array($row) || !($row['CANCEL_UNTIL'] ?? null) instanceof DateTime)
		{
			return (new Result())->addError(
				new Error('Undo send operation is invalid.', ScheduledMessageService::ERROR_INVALID_OPERATION),
			);
		}

		$cancelToken = $userId === null
			? null
			: $this->cancelToken->issue(
				$userId,
				(int)$row['ID'],
				(int)$row['REVISION'],
				$row['CANCEL_UNTIL'],
			)
		;

		$visibleCancelUntil = DateTime::createFromTimestamp(max(
			0,
			$row['CANCEL_UNTIL']->getTimestamp() - ScheduledMessageService::UNDO_CANCEL_GUARD_SECONDS,
		));
		$draftId = max(0, (int)($source->getData()['draftId'] ?? 0));
		$mailboxId = max(0, (int)($row['MAILBOX_ID'] ?? 0));

		return (new Result())->setData([
			'operationId' => (int)$row['ID'],
			'revision' => (int)$row['REVISION'],
			'status' => (string)$row['STATUS'],
			'cancelUntil' => $visibleCancelUntil->format(DATE_ATOM),
			'serverNow' => $serverNow->format(DATE_ATOM),
			'subject' => (string)($row['SUBJECT'] ?? ''),
			'restoreUrl' => $draftId > 0 && $mailboxId > 0
				? sprintf('/mail/message/new?id=%d&draftId=%d', $mailboxId, $draftId)
				: null,
			'restoreSliderWidth' => Feature::getMessageSliderWidth(),
			'cancelToken' => $cancelToken,
			// Present only when the author asked to keep the letter as a template of their own.
			...(isset($source->getData()['templateSaveWarning'])
				? ['templateSaveWarning' => (bool)$source->getData()['templateSaveWarning']]
				: []),
		]);
	}
}
