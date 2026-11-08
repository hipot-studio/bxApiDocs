<?php

declare(strict_types=1);

namespace Bitrix\Crm\MessageSender\CustomTemplate;

use Bitrix\Crm\ItemIdentifier;
use Bitrix\MessageService\Public\UI\MessageEditor\Context;

/**
 * Sole owner of the CRM custom-template target key format "{entityTypeId}:{categoryId}".
 * Parses and formats the key and resolves it from an editor {@see Context}.
 */
final class CrmTemplateTargetId
{
	public function __construct(
		private readonly int $entityTypeId,
		private readonly int $categoryId,
	)
	{
	}

	/**
	 * The colon-anchored prefix matching every category of the entity type. The trailing
	 * colon makes it safe as a LIKE prefix: "20:" never matches "200:5".
	 */
	public static function entityTypeIdPrefix(int $entityTypeId): string
	{
		return "{$entityTypeId}:";
	}

	public static function fromString(string $targetId): ?self
	{
		if (!preg_match('/^(0|[1-9]\d*):(0|[1-9]\d*)$/', $targetId, $matches))
		{
			return null;
		}

		return new self((int)$matches[1], (int)$matches[2]);
	}

	/**
	 * Resolve the target key from an editor context. The category is taken from the
	 * item itself (resolved by entityId via {@see ItemIdentifier}) when available,
	 * otherwise from the explicit categoryId in the context.
	 */
	public static function fromEditorContext(Context $context): self
	{
		$entityTypeId = $context->getCustomDataInt('entityTypeId') ?? 0;
		$entityId = $context->getCustomDataInt('entityId');
		$categoryId = $context->getCustomDataInt('categoryId');

		$resolvedCategoryId = match (true) {
			$entityId !== null && $entityId > 0
				=> ItemIdentifier::createByParams(
					$entityTypeId,
					$entityId,
					$categoryId,
				)?->getCategoryId() ?? 0,
			default => $categoryId ?? 0,
		};

		return new self($entityTypeId, $resolvedCategoryId);
	}

	public function getEntityTypeId(): int
	{
		return $this->entityTypeId;
	}

	public function getCategoryId(): int
	{
		return $this->categoryId;
	}

	/**
	 * @return array{entityTypeId: int, categoryId: int}
	 */
	public function toArray(): array
	{
		return [
			'entityTypeId' => $this->entityTypeId,
			'categoryId' => $this->categoryId,
		];
	}

	public function toString(): string
	{
		return "{$this->entityTypeId}:{$this->categoryId}";
	}
}
