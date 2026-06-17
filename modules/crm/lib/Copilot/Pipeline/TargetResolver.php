<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use CCrmActivity;
use CCrmOwnerType;
use Psr\Log\LoggerInterface;

class TargetResolver
{
	/** Sorted by priority — first found entity type wins. */
	private const ENTITY_TYPE_WHITELIST = [
		CCrmOwnerType::Deal => CCrmOwnerType::Deal,
		CCrmOwnerType::Lead => CCrmOwnerType::Lead,
	];

	private Container $container;
	private LoggerInterface $logger;

	/** @var array<int, ItemIdentifier|null> per-request cache keyed by activityId */
	private array $targetCache = [];

	public function __construct()
	{
		$this->container = Container::getInstance();
		$this->logger = AIManager::logger();
	}

	public function findTarget(int $activityId): ?ItemIdentifier
	{
		if (array_key_exists($activityId, $this->targetCache))
		{
			return $this->targetCache[$activityId];
		}

		$bindings = CCrmActivity::GetBindings($activityId);
		$bindings = is_array($bindings) ? $bindings : [];

		$result = $this->findTargetByBindings($bindings);

		$this->targetCache[$activityId] = $result;

		return $result;
	}

	public function findTargetByBindings(array $bindings): ?ItemIdentifier
	{
		$this->logger->debug(
			'{date}: Trying to find possible fill item fields target by activity bindings {bindings}' . PHP_EOL,
			[
				'bindings' => $bindings,
			],
		);

		$filteredBindings = $this->filterBindings($bindings, self::ENTITY_TYPE_WHITELIST);
		if (empty($filteredBindings))
		{
			$this->logger->debug('{date}: All given bindings were filtered out, cant find target' . PHP_EOL);

			return null;
		}

		foreach (self::ENTITY_TYPE_WHITELIST as $entityTypeId)
		{
			$this->logger->debug('{date}: Checking type {entityTypeId}' . PHP_EOL, ['entityTypeId' => $entityTypeId]);

			$factory = $this->container->getFactory($entityTypeId);

			if (!$factory || empty($filteredBindings[$entityTypeId]))
			{
				$this->logger->debug(
					'{date}: No bindings or factory, skipping type {entityTypeId}' . PHP_EOL,
					['entityTypeId' => $entityTypeId],
				);

				continue;
			}

			if (count($filteredBindings[$entityTypeId]) === 1)
			{
				$id = reset($filteredBindings[$entityTypeId]);

				$target = new ItemIdentifier(
					$entityTypeId,
					$id,
					$factory->getItemCategoryId($id)
				);

				$this->logger->debug(
					'{date}: Exactly one binding exists, found target {target} for bindings' . PHP_EOL,
					['target' => $target, 'bindings' => $bindings]
				);

				return $target;
			}

			$items = $factory->getItems([
				'select' => array_filter([
					Item::FIELD_NAME_ID,
					$factory->isCategoriesSupported() ? Item::FIELD_NAME_CATEGORY_ID : null,
				]),
				'filter' => [
					'@' . Item::FIELD_NAME_ID => $filteredBindings[$entityTypeId],
				],
				'order' => [
					Item::FIELD_NAME_ID => 'DESC',
				],
				'limit' => 1,
			]);

			if (!empty($items))
			{
				$target = ItemIdentifier::createByItem(reset($items));

				$this->logger->debug(
					'{date}: Found target {target} for by filtering for bindings {bindings}' . PHP_EOL,
					['target' => $target, 'bindings' => $bindings]
				);

				return $target;
			}
		}

		$this->logger->debug(
			'{date}: No target found for bindings {bindings}' . PHP_EOL,
			[
				'bindings' => $bindings,
			]
		);

		return null;
	}

	private function filterBindings(array $bindings, array $whitelist): array
	{
		$filteredBindings = [];
		foreach ($bindings as $binding)
		{
			$ownerTypeId = (int)$binding['OWNER_TYPE_ID'];
			$ownerId = (int)$binding['OWNER_ID'];

			if (isset($whitelist[$ownerTypeId]) && $ownerId > 0)
			{
				$filteredBindings[$ownerTypeId][$ownerId] = $ownerId;
			}
		}

		return $filteredBindings;
	}
}
