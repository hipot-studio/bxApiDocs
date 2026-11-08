<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Draft;

use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Entity\Draft\DraftSnapshot;
use Bitrix\Mail\Internal\Repository\DraftRepository;
use Bitrix\Mail\Internal\Service\Draft\Contract\DraftServiceInterface;
use Bitrix\Mail\Internal\Service\Draft\Dto\DraftFromScheduledMessage;
use Bitrix\Mail\Internal\Service\LargeAttachment\LargeAttachmentService;
use Bitrix\Mail\Internal\Service\Signature\Image\SignatureImageSendPreparation;
use Bitrix\Mail\Integration\Disk\LargeAttachmentStorageFactory;
use Bitrix\Mail\Integration\Disk\LargeAttachmentStorageInterface;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class DraftService implements DraftServiceInterface
{
	public const ERROR_NOT_FOUND = 'DRAFT_NOT_FOUND';
	public const ERROR_ACCESS_DENIED = 'DRAFT_ACCESS_DENIED';
	public const ERROR_REVISION_CONFLICT = 'DRAFT_REVISION_CONFLICT';
	public const ERROR_INVALID_SNAPSHOT = 'DRAFT_INVALID_SNAPSHOT';
	public const ERROR_ATTACHMENT_UNAVAILABLE = 'DRAFT_ATTACHMENT_UNAVAILABLE';
	public const ERROR_SAVE_FAILED = 'DRAFT_SAVE_FAILED';
	public const ERROR_INVALID_DRAFT_IDS = 'DRAFT_INVALID_DRAFT_IDS';

	private const DELETE_MANY_LIMIT = 50;
	private const COMPLETE_MAX_ATTEMPTS = 3;

	private readonly DraftRepository $repository;
	private readonly AttachmentStorage $attachmentStorage;
	private readonly SignatureImageSendPreparation $signatureImagePreparation;
	private ?LargeAttachmentStorageInterface $largeAttachmentStorage;

	public function __construct(
		?DraftRepository $repository = null,
		?AttachmentStorage $attachmentStorage = null,
		?LargeAttachmentStorageInterface $largeAttachmentStorage = null,
		?SignatureImageSendPreparation $signatureImagePreparation = null,
	)
	{
		$this->repository = $repository ?? new DraftRepository();
		$this->attachmentStorage = $attachmentStorage ?? new AttachmentStorage();
		$this->largeAttachmentStorage = $largeAttachmentStorage;
		$this->signatureImagePreparation = $signatureImagePreparation ?? new SignatureImageSendPreparation();
	}

	public function save(
		int $userId,
		string $contextType,
		?int $draftId,
		?int $revision,
		array $snapshotData,
		?int $crmEntityTypeId = null,
		?int $crmEntityId = null,
	): Result
	{
		$result = new Result();
		$isCreate = $draftId === null && $revision === null;
		$isUpdate = ($draftId ?? 0) > 0 && ($revision ?? 0) > 0;
		if (!$isCreate && !$isUpdate)
		{
			return $result->addError(new Error('Invalid draft revision.', self::ERROR_INVALID_SNAPSHOT));
		}
		if ($userId <= 0 || !in_array($contextType, [DraftTable::CONTEXT_MAIL, DraftTable::CONTEXT_CRM], true))
		{
			return $result->addError(new Error('Invalid draft context.', self::ERROR_INVALID_SNAPSHOT));
		}
		if (
			($contextType === DraftTable::CONTEXT_MAIL && ($crmEntityTypeId !== null || $crmEntityId !== null))
			|| (
				$contextType === DraftTable::CONTEXT_CRM
				&& (($crmEntityTypeId ?? 0) <= 0 || ($crmEntityId ?? 0) <= 0)
			)
		)
		{
			return $result->addError(new Error('Invalid draft context.', self::ERROR_INVALID_SNAPSHOT));
		}

		try
		{
			$snapshot = DraftSnapshot::fromArray($snapshotData);
			$snapshot = $snapshot->withBody($this->signatureImagePreparation->sanitizeHtml($snapshot->body));
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Invalid draft snapshot.', self::ERROR_INVALID_SNAPSHOT));
		}

		if (!$snapshot->isMeaningful())
		{
			return $result->addError(new Error('Draft snapshot is empty.', self::ERROR_INVALID_SNAPSHOT));
		}

		$preparedAttachments = $this->getAttachmentStorage()->prepare(
			$userId,
			$snapshot->attachments,
			$draftId,
			$contextType,
			$crmEntityTypeId,
			$crmEntityId,
		);
		if ($preparedAttachments === null)
		{
			return $result->addError(
				new Error('Draft attachment is unavailable.', self::ERROR_ATTACHMENT_UNAVAILABLE),
			);
		}
		$snapshot = $snapshot->withLargeAttachments(
			$this->validateLargeAttachments($userId, $snapshot->largeAttachments, $preparedAttachments['attachments']),
		);

		$draft = $this->getRepository()->save(
			userId: $userId,
			contextType: $contextType,
			draftId: $draftId,
			expectedRevision: $revision,
			snapshot: $snapshot,
			crmEntityTypeId: $crmEntityTypeId,
			crmEntityId: $crmEntityId,
			attachments: $preparedAttachments['attachments'],
		);
		if ($draft === null)
		{
			$this->getAttachmentStorage()->deleteFiles($preparedAttachments['createdFileIds']);
			$errorCode = $this->getRepository()->getLastSaveFailure() === DraftRepository::SAVE_FAILURE_CONFLICT
				? self::ERROR_REVISION_CONFLICT
				: self::ERROR_SAVE_FAILED;

			return $result->addError(new Error('Draft was not saved.', $errorCode));
		}
		if ($this->getRepository()->wasLastSaveIdempotent())
		{
			$this->getAttachmentStorage()->deleteFiles($preparedAttachments['createdFileIds']);

			return $result->setData(['draft' => $this->serializeDraft($userId, $draft)]);
		}

		$currentFileIds = array_map(
			static fn(array $attachment): int => (int)$attachment['FILE_ID'],
			$preparedAttachments['attachments'],
		);
		$this->getAttachmentStorage()->deleteFiles(
			array_diff($preparedAttachments['previousFileIds'], $currentFileIds),
		);

		return $result->setData(['draft' => $this->serializeDraft($userId, $draft)]);
	}

	public function createFromScheduledMessage(DraftFromScheduledMessage $message): Result
	{
		$result = new Result();

		try
		{
			$attachments = $this->getAttachmentStorage()->prepareTransferred($message->attachments);
			if ($attachments === null)
			{
				return $result->addError(
					new Error('Draft attachment is unavailable.', self::ERROR_ATTACHMENT_UNAVAILABLE),
				);
			}

			$snapshot = DraftSnapshot::fromArray([
				'clientId' => $this->scheduledMessageClientId($message->userId, $message->scheduledMessageId),
				'sender' => $message->sender,
				'to' => (array)($message->recipients['to'] ?? []),
				'cc' => (array)($message->recipients['cc'] ?? []),
				'bcc' => (array)($message->recipients['bcc'] ?? []),
				'subject' => $message->subject,
				'body' => Message::sanitizeHtml($message->body),
				'bodyFormat' => 'html',
				'mode' => $message->mode,
				'parentMessageId' => $message->parentMessageId,
				'attachments' => [],
				'largeAttachments' => $message->largeAttachments,
			]);
			$draft = $this->getRepository()->save(
				userId: $message->userId,
				contextType: DraftTable::CONTEXT_MAIL,
				draftId: null,
				expectedRevision: null,
				snapshot: $snapshot,
				crmEntityTypeId: null,
				crmEntityId: null,
				attachments: $attachments,
				manageTransaction: false,
			);
			if ($draft === null)
			{
				return $result->addError(new Error('Draft was not saved.', self::ERROR_SAVE_FAILED));
			}

			return $result->setData([
				'draftId' => $draft->id,
				'draft' => $this->serializeDraft($message->userId, $draft),
			]);
		}
		catch (\Throwable)
		{
			return $result->addError(new Error('Draft was not saved.', self::ERROR_SAVE_FAILED));
		}
	}

	public function get(int $userId, int $draftId, ?string $contextType = null): Result
	{
		$result = new Result();
		$draft = $this->getRepository()->findActiveById($userId, $draftId, $contextType);
		if ($draft === null)
		{
			return $result->addError(new Error('Draft was not found.', self::ERROR_NOT_FOUND));
		}

		return $result->setData(['draft' => $this->serializeDraft($userId, $draft)]);
	}

	public function getByCrmContext(int $userId, int $entityTypeId, int $entityId): Result
	{
		$draft = $this->getRepository()->findActiveByCrmContext($userId, $entityTypeId, $entityId);

		return (new Result())->setData(['draft' => $draft !== null ? $this->serializeDraft($userId, $draft) : null]);
	}

	public function list(
		int $userId,
		int $page,
		int $pageSize,
		string $search = '',
		string $recipient = '',
		?bool $hasAttachments = null,
	): Result
	{
		$page = max(1, $page);
		$pageSize = min(50, max(1, $pageSize));

		return (new Result())->setData([
			'page' => $this->getRepository()->listMail(
				$userId,
				$page,
				$pageSize,
				trim($search),
				mb_strtolower(trim($recipient)),
				$hasAttachments,
			)->toArray(),
		]);
	}

	/**
	 * A caller that reports the revision and the compose form it was showing gets a compare-and-delete:
	 * a draft written into since is newer work nobody asked to drop, and `deleted` is then false. A caller
	 * that reports neither, as the drafts list dropping a row by its identifier does, deletes as before.
	 */
	public function delete(
		int $userId,
		int $draftId,
		?string $contextType = null,
		?int $expectedRevision = null,
		?string $expectedClientId = null,
	): Result
	{
		$deleted = $this->getRepository()->delete(
			$userId,
			$draftId,
			$contextType,
			$expectedRevision,
			$expectedClientId,
		);

		return (new Result())->setData(['deleted' => $deleted]);
	}

	public function deleteMany(int $userId, array $draftIds, ?string $contextType = null): Result
	{
		$result = new Result();
		$normalizedDraftIds = [];
		foreach ($draftIds as $draftId)
		{
			if (is_string($draftId) && preg_match('/^[1-9]\d*$/D', $draftId) === 1)
			{
				$normalizedDraftId = (int)$draftId;
				if ((string)$normalizedDraftId !== $draftId)
				{
					return $result->addError(
						new Error('Invalid draft identifiers.', self::ERROR_INVALID_DRAFT_IDS),
					);
				}
			}
			elseif (is_int($draftId) && $draftId > 0)
			{
				$normalizedDraftId = $draftId;
			}
			else
			{
				return $result->addError(
					new Error('Invalid draft identifiers.', self::ERROR_INVALID_DRAFT_IDS),
				);
			}

			$normalizedDraftIds[$normalizedDraftId] = $normalizedDraftId;
		}

		$draftIds = array_values($normalizedDraftIds);
		if ($draftIds === [] || count($draftIds) > self::DELETE_MANY_LIMIT)
		{
			return $result->addError(
				new Error('Invalid draft identifiers.', self::ERROR_INVALID_DRAFT_IDS),
			);
		}

		$deletedIds = $this->getRepository()->deleteMany($userId, $draftIds, $contextType);
		$failedIds = array_values(array_diff($draftIds, $deletedIds));

		return $result->setData([
			'deletedIds' => $deletedIds,
			'failedIds' => $failedIds,
		]);
	}

	public function complete(
		int $userId,
		int $draftId,
		?string $contextType = null,
		?int $crmEntityTypeId = null,
		?int $crmEntityId = null,
		?int $expectedRevision = null,
		?string $expectedClientId = null,
	): Result
	{
		$result = new Result();
		for ($attempt = 1; $attempt <= self::COMPLETE_MAX_ATTEMPTS; $attempt++)
		{
			try
			{
				// Ownership is deterministic: retry only transient DB failures, never a refusal.
				if (
					!$this->getRepository()->complete(
						$userId,
						$draftId,
						$contextType,
						$crmEntityTypeId,
						$crmEntityId,
						$expectedRevision,
						$expectedClientId,
					)
				)
				{
					if ($expectedRevision !== null)
					{
						$draft = $this->getRepository()->findActiveById($userId, $draftId, $contextType);
						$isSameCrmContext = $contextType !== DraftTable::CONTEXT_CRM
							|| (
								$draft?->crmEntityTypeId === $crmEntityTypeId
								&& $draft?->crmEntityId === $crmEntityId
							);
						if ($draft !== null && $isSameCrmContext && $draft->revision !== $expectedRevision)
						{
							return $result->addError(
								new Error('Draft revision conflict.', self::ERROR_REVISION_CONFLICT),
							);
						}
					}

					return $result->addError(new Error('Draft access denied.', self::ERROR_ACCESS_DENIED));
				}

				return $result;
			}
			catch (\Throwable)
			{
				if ($attempt === self::COMPLETE_MAX_ATTEMPTS)
				{
					return $result->addError(new Error('Draft completion failed.', self::ERROR_SAVE_FAILED));
				}
			}
		}

	}

	public function deleteByUser(int $userId): void
	{
		$this->getRepository()->deleteByUser($userId);
	}

	private function getRepository(): DraftRepository
	{
		return $this->repository;
	}

	private function scheduledMessageClientId(int $userId, int $scheduledMessageId): string
	{
		$hash = hash('sha256', "mail-scheduled-draft:{$userId}:{$scheduledMessageId}");

		return sprintf(
			'%s-%s-5%s-%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 13, 3),
			'a' . substr($hash, 17, 3),
			substr($hash, 20, 12),
		);
	}

	private function getAttachmentStorage(): AttachmentStorage
	{
		return $this->attachmentStorage;
	}

	private function validateLargeAttachments(int $userId, array $largeAttachments, array $attachments): array
	{
		$availableSourceIds = array_fill_keys(
			array_filter(array_map(
				static fn(array $attachment): int => (int)($attachment['SOURCE_OBJECT_ID'] ?? 0),
				$attachments,
			), static fn(int $sourceObjectId): bool => $sourceObjectId > 0),
			true,
		);
		$validated = [];
		foreach ($largeAttachments as $largeAttachment)
		{
			$sourceFileIds = $largeAttachment['sourceFileIds'];
			$tokenFileIds = $largeAttachment['tokenFileIds'] ?? $sourceFileIds;
			if (array_diff($sourceFileIds, array_keys($availableSourceIds)) !== [])
			{
				continue;
			}

			$resolved = LargeAttachmentService::resolveForSend(
				$userId,
				$largeAttachment['token'],
				$tokenFileIds,
				$this->getLargeAttachmentStorage(),
			);
			if (!$resolved->isSuccess())
			{
				continue;
			}

			$attachment = $resolved->getData()[LargeAttachmentService::RESULT_KEY];
			$validated[] = [
				'token' => $attachment->token,
				'publicUrl' => $attachment->publicUrl,
				'fileIds' => $largeAttachment['fileIds'],
				'sourceFileIds' => $sourceFileIds,
				'tokenFileIds' => $tokenFileIds,
			];
		}

		return $validated;
	}

	private function serializeDraft(int $userId, \Bitrix\Mail\Internal\Entity\Draft\DraftView $draft): array
	{
		$data = $draft->toArray();
		$data['snapshot']['largeAttachments'] = [];
		foreach ($draft->snapshot->largeAttachments as $largeAttachment)
		{
			$fileIds = $draft->mapSourceObjectIds($largeAttachment['sourceFileIds']);
			if ($fileIds === null)
			{
				continue;
			}

			$resolved = LargeAttachmentService::resolveForSend(
				$userId,
				$largeAttachment['token'],
				$largeAttachment['tokenFileIds'],
				$this->getLargeAttachmentStorage(),
			);
			$isValid = $resolved->isSuccess();
			$resolvedAttachment = $isValid
				? $resolved->getData()[LargeAttachmentService::RESULT_KEY]
				: null;
			$data['snapshot']['largeAttachments'][] = [
				'token' => $isValid ? $resolvedAttachment->token : '',
				'publicUrl' => $isValid ? $resolvedAttachment->publicUrl : $largeAttachment['publicUrl'],
				'fileIds' => $fileIds,
				'sourceFileIds' => $largeAttachment['sourceFileIds'],
				'tokenFileIds' => $largeAttachment['tokenFileIds'],
				'valid' => $isValid,
			];
		}

		return $data;
	}

	private function getLargeAttachmentStorage(): LargeAttachmentStorageInterface
	{
		return $this->largeAttachmentStorage ??= LargeAttachmentStorageFactory::getInstance();
	}
}
