<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\FieldMetadata;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Repository\Dictionary\EntityTypeTitleProvider;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Localization\LocalizableMessage;

/**
 * @internal
 */
final class RelatedEntityTitle extends LocalizableMessage
{
	public function __construct(
		private int $entityTypeId,
		private string $fallback,
	)
	{
		parent::__construct('', null, null, 'lib/V2/Internal/Entity/FieldMetadata/RelatedEntityTitle.php');
	}

	public function localize(string $language): string
	{
		if (!EntityType::isValid($this->entityTypeId))
		{
			return $this->fallback;
		}

		$entityType = EntityType::fromId($this->entityTypeId);
		if ($entityType->isSmartProcess())
		{
			$title = Container::getInstance()->getFactory($this->entityTypeId)?->getEntityDescription();
		}
		else
		{
			$title = (new EntityTypeTitleProvider())->getTitle($entityType, $language);
		}

		return is_string($title) && $title !== '' ? $title : $this->fallback;
	}

	public static function __set_state(array $state): self
	{
		return new self($state['entityTypeId'], $state['fallback']);
	}

	public function __serialize(): array
	{
		return [
			'entityTypeId' => $this->entityTypeId,
			'fallback' => $this->fallback,
		];
	}

	public function __unserialize(array $data): void
	{
		$this->__construct($data['entityTypeId'], $data['fallback']);
	}
}
