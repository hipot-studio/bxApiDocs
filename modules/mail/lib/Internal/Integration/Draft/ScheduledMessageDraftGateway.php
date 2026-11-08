<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Integration\Draft;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Service\Draft\Contract\DraftServiceInterface;
use Bitrix\Mail\Internal\Service\Draft\DraftCompletion;
use Bitrix\Mail\Internal\Service\Draft\Dto\DraftFromScheduledMessage;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Mail\Internals\ScheduledMessageTable;
use Bitrix\Mail\Repository\ScheduledMessageRepository;
use Bitrix\Mail\Service\ScheduledMessage\ScheduledMessageBody;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Type\DateTime;

final class ScheduledMessageDraftGateway
{
	public const ERROR_DRAFT_UNAVAILABLE = 'draft_unavailable';
	public const ERROR_DRAFT_SAVE_FAILED = 'draft_save_failed';

	public function __construct(
		private readonly ScheduledMessageRepository $scheduledMessageRepository,
		private readonly ?DraftServiceInterface $draftService = null,
	)
	{
	}

	public function convert(array $scheduledMessage, int $revision): Result
	{
		// Feature::isScheduledMessageAvailable() already requires drafts, so this is the last barrier
		// for a caller that reaches the service without going through the scheduling surface.
		if (!Feature::isInternalDraftsAvailable())
		{
			return $this->error(self::ERROR_DRAFT_UNAVAILABLE, 'Drafts are unavailable.');
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$draftResult = $this->createDraft($scheduledMessage);
			if (!$draftResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return $this->error(self::ERROR_DRAFT_SAVE_FAILED, 'Draft was not saved.');
			}

			$cancelResult = $this->scheduledMessageRepository->updateByRevision(
				(int)$scheduledMessage['ID'],
				$revision,
				[
					'STATUS' => ScheduledMessageTable::STATUS_CANCELLED,
					'NEXT_ATTEMPT_AT' => null,
				],
				[ScheduledMessageTable::STATUS_SCHEDULED, ScheduledMessageTable::STATUS_ERROR],
				[],
				hydrate: false,
				manageTransaction: false,
			);
			if (!$cancelResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return $cancelResult;
			}

			$connection->commitTransaction();
			$cancelledMessage = $scheduledMessage;
			$cancelledMessage['STATUS'] = ScheduledMessageTable::STATUS_CANCELLED;
			$cancelledMessage['NEXT_ATTEMPT_AT'] = null;
			$cancelledMessage['REVISION'] = $revision + 1;
			$cancelledMessage['ATTACHMENTS'] = [];
			$cancelResult->setData([
				'scheduledMessage' => $cancelledMessage,
			] + $draftResult->getData());

			return $cancelResult;
		}
		catch (\Throwable)
		{
			$connection->rollbackTransaction();

			return $this->error(self::ERROR_DRAFT_SAVE_FAILED, 'Draft was not saved.');
		}
	}

	public function restore(array $scheduledMessage): Result
	{
		if (!Feature::isInternalDraftsAvailable())
		{
			return $this->error(self::ERROR_DRAFT_UNAVAILABLE, 'Drafts are unavailable.');
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$result = $this->createDraft($scheduledMessage);
			if (!$result->isSuccess())
			{
				$connection->rollbackTransaction();

				return $this->error(self::ERROR_DRAFT_SAVE_FAILED, 'Draft was not saved.');
			}

			$connection->commitTransaction();

			return $result;
		}
		catch (\Throwable)
		{
			$connection->rollbackTransaction();

			return $this->error(self::ERROR_DRAFT_SAVE_FAILED, 'Draft was not saved.');
		}
	}

	/** @param array{code: string, message: string, retryable: bool} $error */
	public function restoreAfterUndoFailure(
		array $scheduledMessage,
		int $revision,
		int $attempts,
		array $error,
	): Result
	{
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$draftResult = $this->createDraft($scheduledMessage);
			if (!$draftResult->isSuccess() || (int)($draftResult->getData()['draftId'] ?? 0) <= 0)
			{
				$connection->rollbackTransaction();

				return $this->error(self::ERROR_DRAFT_SAVE_FAILED, 'Draft was not saved.');
			}

			$errorResult = $this->scheduledMessageRepository->markError(
				(int)$scheduledMessage['ID'],
				$revision,
				$attempts,
				$error,
				manageTransaction: false,
			);
			if (!$errorResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return $errorResult;
			}

			$connection->commitTransaction();
			$errorResult->setData($draftResult->getData());

			return $errorResult;
		}
		catch (\Throwable)
		{
			$connection->rollbackTransaction();

			return $this->error(self::ERROR_DRAFT_SAVE_FAILED, 'Draft was not saved.');
		}
	}

	public function cancelUndo(int $userId, int $id, int $revision, DateTime $now): Result
	{
		if (!Feature::isInternalDraftsAvailable())
		{
			return $this->error(self::ERROR_DRAFT_UNAVAILABLE, 'Drafts are unavailable.');
		}

		$scheduledMessage = $this->scheduledMessageRepository->get($id);
		if (
			$scheduledMessage === null
			|| (int)($scheduledMessage['AUTHOR_ID'] ?? 0) !== $userId
			|| (string)($scheduledMessage['MODE'] ?? '') !== ScheduledMessageTable::MODE_UNDO
		)
		{
			return $this->scheduledMessageRepository->cancelUndo($userId, $id, $revision, $now);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			$draftResult = $this->createDraft($scheduledMessage);
			if (!$draftResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return $this->error(self::ERROR_DRAFT_SAVE_FAILED, 'Draft was not saved.');
			}

			$cancelResult = $this->scheduledMessageRepository->cancelUndo(
				$userId,
				$id,
				$revision,
				$now,
				manageTransaction: false,
			);
			if (!$cancelResult->isSuccess())
			{
				$connection->rollbackTransaction();

				return $cancelResult;
			}

			$connection->commitTransaction();
			$cancelResult->setData($cancelResult->getData() + $draftResult->getData());

			return $cancelResult;
		}
		catch (\Throwable)
		{
			$connection->rollbackTransaction();

			return $this->error(self::ERROR_DRAFT_SAVE_FAILED, 'Draft was not saved.');
		}
	}

	/**
	 * The letter has left the form, so the draft it was written in is closed the same way a send closes it:
	 * by identifier and revision of its own author, and a refusal is logged instead of answered. The letter is
	 * already scheduled by the time this runs, and a draft row must not take that back.
	 */
	public function completeAfterSchedule(int $userId, ?int $draftId, ?int $draftRevision): void
	{
		// The revision is not optional here: closing a draft by identifier alone would throw away a change
		// made to it meanwhile in another tab or on another device.
		if (($draftId ?? 0) <= 0 || ($draftRevision ?? 0) <= 0)
		{
			return;
		}

		DraftCompletion::afterSuccessfulSend(
			userId: $userId,
			draftId: $draftId,
			contextType: DraftTable::CONTEXT_MAIL,
			expectedRevision: $draftRevision,
		);
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}

	private function createDraft(array $scheduledMessage): Result
	{
		[$mode, $parentMessageId] = $this->resolveComposeContext($scheduledMessage);

		return $this->getDraftService()->createFromScheduledMessage(
			new DraftFromScheduledMessage(
				userId: (int)$scheduledMessage['AUTHOR_ID'],
				scheduledMessageId: (int)$scheduledMessage['ID'],
				sender: (array)($scheduledMessage['SENDER'] ?? []),
				recipients: (array)($scheduledMessage['RECIPIENTS'] ?? []),
				subject: (string)($scheduledMessage['SUBJECT'] ?? ''),
				// The marks of the scheduling form belong to the form, not to a restored draft.
				body: ScheduledMessageBody::stripFormMarks((string)($scheduledMessage['BODY'] ?? '')),
				attachments: (array)($scheduledMessage['ATTACHMENTS'] ?? []),
				largeAttachments: (array)($scheduledMessage['LARGE_ATTACHMENTS'] ?? []),
				mode: $mode,
				parentMessageId: $parentMessageId,
			),
		);
	}

	/** @return array{string, ?int} */
	private function resolveComposeContext(array $scheduledMessage): array
	{
		$sourceContext = (array)($scheduledMessage['SOURCE_CONTEXT'] ?? []);
		$draftId = (int)($sourceContext['draftId'] ?? 0);
		$draftRevision = (int)($sourceContext['draftRevision'] ?? 0);
		if ((int)($sourceContext['version'] ?? 0) !== 1 || $draftId <= 0 || $draftRevision <= 0)
		{
			return ['new', null];
		}

		$row = DraftTable::query()
			->setSelect(['COMPOSE_MODE', 'PARENT_MESSAGE_ID'])
			->where('ID', $draftId)
			->where('USER_ID', (int)$scheduledMessage['AUTHOR_ID'])
			->where('CONTEXT_TYPE', DraftTable::CONTEXT_MAIL)
			->where('REVISION', $draftRevision)
			->setLimit(1)
			->fetch()
		;
		if ($row === false)
		{
			return ['new', null];
		}

		$mode = (string)($row['COMPOSE_MODE'] ?? 'new');
		if (!in_array($mode, ['new', 'reply', 'forward'], true))
		{
			$mode = 'new';
		}
		$parentMessageId = (int)($row['PARENT_MESSAGE_ID'] ?? 0);

		return [$mode, $parentMessageId > 0 ? $parentMessageId : null];
	}

	private function getDraftService(): DraftServiceInterface
	{
		if ($this->draftService !== null)
		{
			return $this->draftService;
		}

		return ServiceLocator::getInstance()->get(DraftServiceInterface::class);
	}
}
