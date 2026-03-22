<?php

namespace Bitrix\Crm\RepeatSale\DataCollector;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\NotSupportedException;
use CCrmOwnerType;

abstract class BaseDataCollector implements CopilotMarkerProviderInterface
{
	protected const DEFAULT_LIMIT = 10;
	private const DEFAULT_OFFSET = 0;

	protected const SUPPORTED_ENTITY_TYPES = [];

	protected readonly Factory $factory;
	private ?int $limit = null;

	public function __construct(protected readonly int $entityTypeId)
	{
		if (!$this->isSupportedEntityType())
		{
			throw new NotSupportedException(
				sprintf(
					'Cannot create data collector "%s" for unsupported entity type %s',
					static::class,
					CCrmOwnerType::ResolveName($entityTypeId)
				)
			);
		}

		$factory = Container::getInstance()->getFactory($this->entityTypeId);
		if (!$factory)
		{
			throw new NotSupportedException(
				sprintf(
					'Entity type "%s" is not supported',
					CCrmOwnerType::ResolveName($entityTypeId)
				)
			);
		}

		$this->factory = $factory;
	}

	final public function getData(array $parameters = []): array
	{
		$select = $parameters['select'] ?? ['*'];
		$filter = $parameters['filter'] ?? [];
		$order = $parameters['order'] ?? [
			Item::FIELD_NAME_ID => 'DESC',
		];
		$offset = $parameters['offset'] ?? self::DEFAULT_OFFSET;
		$limit = $parameters['limit'] ?? $this->getLimit();

		return $this->factory->getItems([
			'select' => $select,
			'filter' => $filter,
			'limit' => $limit,
			'order' => $order,
			'offset' => $offset,
		]);
	}

	final protected function isSupportedEntityType(): bool
	{
		return in_array($this->entityTypeId, static::SUPPORTED_ENTITY_TYPES, true);
	}

	public function setLimit(int $limit): self
	{
		$this->limit = $limit;

		return $this;
	}

	protected function getLimit(): int
	{
		return $this->limit ?? static::DEFAULT_LIMIT;
	}
}
