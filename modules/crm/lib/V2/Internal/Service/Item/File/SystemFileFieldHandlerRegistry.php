<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File;

use Bitrix\Crm\Field;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\EntityType;

final class SystemFileFieldHandlerRegistry
{
	/** @var list<SystemFileFieldHandler> */
	private array $handlers;

	/** @param list<SystemFileFieldHandler>|null $handlers */
	public function __construct(?array $handlers = null, ?\Closure $fieldResolver = null)
	{
		$fieldResolver ??= static function (EntityType $entityType, string $fieldName): ?Field
		{
			return Container::getInstance()->getFactory($entityType->getId())
				?->getFieldsCollection()
				->getField($fieldName)
			;
		};
		$this->handlers = $handlers ?? [
			new ContactPhotoHandler($fieldResolver),
			new CompanyLogoHandler($fieldResolver),
		];
	}

	public function get(EntityType $entityType, string $fieldName): ?SystemFileFieldHandler
	{
		foreach ($this->handlers as $handler)
		{
			if ($handler->supports($entityType, $fieldName))
			{
				return $handler;
			}
		}

		return null;
	}
}
