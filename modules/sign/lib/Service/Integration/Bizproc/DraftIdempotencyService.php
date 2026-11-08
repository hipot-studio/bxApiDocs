<?php

namespace Bitrix\Sign\Service\Integration\Bizproc;

use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Document\CreationSourceMetaKey;
use Bitrix\Sign\Type\Document\CreationSourceType;
use Bitrix\Sign\Type\DocumentStatus;

/**
 * Finds the draft a repeated run of the draft activity has to reuse instead of creating a second one.
 *
 * The check is optimistic and takes no lock, and the service decides nothing beyond the search: what
 * to do with the found document is up to the caller.
 */
final class DraftIdempotencyService
{
	/**
	 * A document is reusable while it is still a draft the activity itself has left behind. The
	 * status `new` is out on purpose: such a document is either still being created or is a
	 * leftover of a failure the compensation never cleaned, and it has neither blank nor file.
	 *
	 * @var array<string>
	 */
	private const IDEMPOTENCY_WINDOW = [DocumentStatus::UPLOADED, DocumentStatus::READY];

	private readonly DocumentRepository $documentRepository;

	public function __construct()
	{
		$this->documentRepository = Container::instance()->getDocumentRepository();
	}

	/**
	 * The key is compared only among the newest drafts of the entity: the repository is called with
	 * its default limit, which is finite because an entity normally holds a handful of drafts and
	 * reading its whole history is not worth it. Once an entity has more reusable drafts than that
	 * limit, an older matching one stays invisible here and a repeated run of the activity creates a
	 * second draft instead of reusing it.
	 *
	 * @param int|null $sourceEntityTypeId type of the entity the process runs on
	 * @param int|null $sourceEntityId id of that entity
	 * @param int $workflowTemplateId id of the template of the automation
	 * @param string $activityName system name of the activity within that template
	 */
	public function findReusable(
		?int $sourceEntityTypeId,
		?int $sourceEntityId,
		int $workflowTemplateId,
		string $activityName,
	): ?Item\Document
	{
		// a missing link to the source entity is no match and no error: the flow degrades to
		// creating a new document, the way it behaved before the key existed
		if (($sourceEntityTypeId ?? 0) < 1 || ($sourceEntityId ?? 0) < 1)
		{
			return null;
		}

		$candidates = $this->documentRepository->listByCreationSourceEntity(
			$sourceEntityTypeId,
			$sourceEntityId,
			self::IDEMPOTENCY_WINDOW,
		);

		// the candidates arrive ordered by id descending, so the first match is the last created one
		foreach ($candidates as $candidate)
		{
			if (self::matchesKey($candidate, $workflowTemplateId, $activityName))
			{
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * The rest of the key lives in the serialized specifics of the source, which no query can
	 * filter by, so it is compared here among the documents of the entity.
	 */
	private static function matchesKey(
		Item\Document $candidate,
		int $workflowTemplateId,
		string $activityName,
	): bool
	{
		if ($candidate->creationSourceType !== CreationSourceType::BIZPROC)
		{
			return false;
		}

		$specifics = $candidate->creationSourceSpecifics;
		$templateId = $specifics[CreationSourceMetaKey::BIZPROC_TEMPLATE_ID->value] ?? null;
		$name = $specifics[CreationSourceMetaKey::BIZPROC_ACTIVITY_NAME->value] ?? null;

		// a missing or non-scalar value means the specifics were not written by this activity
		if (!is_scalar($templateId) || !is_scalar($name))
		{
			return false;
		}

		return (int)$templateId === $workflowTemplateId && (string)$name === $activityName;
	}
}
