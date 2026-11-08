<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MailTemplate;

use Bitrix\Mail\Integration\Crm\CrmTemplateProvider;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateReference;
use Bitrix\Mail\Public\Service\Draft\Dto\DraftSendClaim;
use Bitrix\Mail\Public\Service\Draft\DraftSendService;
use Bitrix\Main\Diag\LoggerFactory;
use Closure;
use Bitrix\Main\Error;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps a composed letter as a personal template of its author. Every way of sending shares it: the letter
 * that leaves at once, the letter held back by the undo window and the letter put on the schedule are one and
 * the same letter to the author, and the template they ask for does not depend on what happens to it later.
 *
 * The saving never fails the sending: a refusal of the source of the templates is written to the log and
 * reported back as a plain "not saved", so the caller can warn the author without losing the letter.
 */
final class ComposedMessageTemplateSaver
{
	private const LOGGER_ID = 'mail.Template';

	/**
	 * @param (Closure(int, int, int): array)|null $ownedAttachmentsResolver attachment copies of a draft
	 */
	public function __construct(
		private readonly ?CrmTemplateProvider $provider = null,
		private readonly ?LoggerInterface $logger = null,
		private readonly TemplateUsageHistory $history = new TemplateUsageHistory(),
		private readonly ?Closure $ownedAttachmentsResolver = null,
	)
	{
	}

	/**
	 * The objects of Disk a template should keep: the files of the author themselves, not the copies a draft
	 * made of them. A letter written in a draft carries the copies, and those live only as long as the draft
	 * does — the cleanup of the drafts takes them, and a template built on them loses its attachments.
	 *
	 * Ask for this before the draft is closed by the send: the copies are released only for a draft that is
	 * still active, at the revision the send was built on.
	 *
	 * @param int[] $diskObjectIds
	 * @return int[]
	 */
	public function resolveAuthorObjectIds(
		int $userId,
		?int $draftId,
		?int $draftRevision,
		array $diskObjectIds,
	): array
	{
		if ($diskObjectIds === [] || ($draftId ?? 0) <= 0 || ($draftRevision ?? 0) <= 0)
		{
			return $diskObjectIds;
		}

		$sourceByCopy = [];
		try
		{
			foreach ($this->readOwnedAttachments($userId, (int)$draftId, (int)$draftRevision) as $attachment)
			{
				if ($attachment->objectId > 0 && $attachment->sourceObjectId > 0)
				{
					$sourceByCopy[$attachment->objectId] = $attachment->sourceObjectId;
				}
			}
		}
		catch (Throwable $exception)
		{
			$this->logFailure($userId, [$exception->getMessage()]);

			return $diskObjectIds;
		}

		return array_map(
			static fn(int $objectId): int => $sourceByCopy[$objectId] ?? $objectId,
			$diskObjectIds,
		);
	}

	/**
	 * @param int[] $diskObjectIds identifiers of the ordinary attachments of the letter
	 * @return bool whether the template was created
	 */
	public function save(
		int $userId,
		string $subject,
		string $bodyHtml,
		string $emailFrom,
		array $diskObjectIds,
	): bool
	{
		try
		{
			$provider = $this->provider ?? new CrmTemplateProvider();
			if (!$provider->canCreatePersonal($userId))
			{
				$this->logFailure($userId, ['provider unavailable']);

				return false;
			}

			// The signature belongs to the form that writes the letter, not to the template it is saved as.
			$result = $provider->createPersonal(
				$subject,
				TemplateBody::stripSignature($bodyHtml),
				$emailFrom,
				$diskObjectIds,
				$userId,
			);
			if ($result->isSuccess())
			{
				$this->remember($userId, (int)($result->getData()['templateId'] ?? 0));

				return true;
			}

			$this->logFailure(
				$userId,
				array_map(
					static fn(Error $error): string => (string)($error->getCode() ?: $error->getMessage()),
					$result->getErrors(),
				),
			);
		}
		catch (Throwable $exception)
		{
			$this->logFailure($userId, [$exception->getMessage()]);
		}

		return false;
	}

	/**
	 * @return iterable<\Bitrix\Mail\Public\Service\Draft\Dto\DraftOwnedAttachment>
	 */
	private function readOwnedAttachments(int $userId, int $draftId, int $draftRevision): iterable
	{
		if ($this->ownedAttachmentsResolver !== null)
		{
			return ($this->ownedAttachmentsResolver)($userId, $draftId, $draftRevision);
		}

		return DraftSendService::getOwnedAttachments(
			DraftSendClaim::forMail($userId, $draftId, $draftRevision),
		);
	}

	/**
	 * The quick list of the form leads with the templates the author last worked with, so a letter just kept
	 * as a template belongs at the head of it: without this the author would look for what they had only now
	 * saved behind the five templates they applied before.
	 */
	private function remember(int $userId, int $templateId): void
	{
		if ($templateId <= 0)
		{
			return;
		}

		$this->history->recordUsage($userId, new TemplateReference(CrmTemplateProvider::SOURCE, $templateId));
	}

	/**
	 * @param string[] $errors
	 */
	private function logFailure(int $userId, array $errors): void
	{
		try
		{
			$logger = $this->logger ?? (new LoggerFactory())->createById(self::LOGGER_ID);
			$logger?->warning(
				'Template creation failed for a composed message.',
				[
					'userId' => $userId,
					'errors' => $errors,
				],
			);
		}
		catch (Throwable)
		{
		}
	}
}
