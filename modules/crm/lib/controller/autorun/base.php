<?php

namespace Bitrix\Crm\Controller\Autorun;

use Bitrix\Crm\Controller\Autorun\Dto\PreparedData;
use Bitrix\Crm\Controller\Autorun\Dto\Progress;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Filter;
use Bitrix\Crm\Item;
use Bitrix\Crm\ListEntity;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\Router;
use Bitrix\Main\Application;
use Bitrix\Main\Data\LocalStorage\SessionLocalStorage;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Result;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Main\Type\ArrayHelper;

abstract class Base extends \Bitrix\Crm\Controller\Base
{
	private const STEP_LIMIT = 10;

	private Connection $connection;
	private Router $router;

	private SessionLocalStorage $dataStorage;
	private SessionLocalStorage $progressStorage;

	final protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		$filters[] = new ActionFilter\Scope(ActionFilter\Scope::AJAX);

		return $filters;
	}

	final protected function init(): void
	{
		parent::init();

		$prefix = 'crm_batch_' . $this->getSessionKeyPrefix();

		$this->dataStorage = Application::getInstance()->getLocalSession("{$prefix}_data");
		$this->progressStorage = Application::getInstance()->getLocalSession("{$prefix}_progress");
		$this->connection = Application::getConnection();
		$this->router = Container::getInstance()->getRouter();
	}

	private function getSessionKeyPrefix(): string
	{
		$reflection = new \ReflectionClass($this);

		// \Bitrix\Crm\Controller\Autorun\SetStage -> SetStage -> set_stage
		return StringHelper::camel2snake($reflection->getShortName());
	}

	final public function prepareAction(array $params): ?array
	{
		$entityTypeId = (int)($params['entityTypeId'] ?? null);
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory || !\CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId) || !$this->isEntityTypeSupported($factory))
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError($entityTypeId));

			return null;
		}

		$gridId = (string)($params['gridId'] ?? null);
		if (empty($gridId))
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('gridId'));

			return null;
		}

		$filter = $this->prepareFilter($factory->getEntityTypeId(), $gridId, $params);

		$hash = $this->calculateHash($entityTypeId, $gridId, $filter);

		$data = $this->prepareData($hash, $gridId, $entityTypeId, $filter, $params, $factory);

		if ($data->hasValidationErrors())
		{
			$this->addErrors($data->getValidationErrors()->toArray());

			return null;
		}

		$this->dataStorage->set($hash, $data->toArray());

		if ($this->progressStorage->get($hash))
		{
			unset($this->progressStorage[$hash]);
		}

		return [ 'hash' => $hash ];
	}

	protected function isEntityTypeSupported(Factory $factory): bool
	{
		return true;
	}

	private function prepareFilter(int $entityTypeId, string $gridId, array $params): ?array
	{
		if (!empty($params['entityIds']) && is_array($params['entityIds']))
		{
			$entityIds = $params['entityIds'];
			ArrayHelper::normalizeArrayValuesByInt($entityIds);

			if (empty($entityIds))
			{
				$this->addError(new Error('entityIds should be a int[]', ErrorCode::INVALID_ARG_VALUE));

				return null;
			}

			return ['@ID' => $entityIds];
		}

		$filterFactory = Container::getInstance()->getFilterFactory();
		$filter = $filterFactory->getFilter($filterFactory::getSettingsByGridId($entityTypeId, $gridId));

		$rawUIFilter = (!empty($params['filter']) && is_array($params['filter'])) ? $params['filter'] : null;
		if (is_array($rawUIFilter))
		{
			return $filter->getValue($rawUIFilter);
		}

		return $filterFactory->getFilterValue($filter);
	}

	private function calculateHash(int $entityTypeId, string $gridId, array $filter): string
	{
		// normalize filter for hash computation
		ksort($filter, SORT_STRING);

		return md5(
			\CCrmOwnerType::ResolveName($entityTypeId)
			. ':'
			. mb_strtoupper($gridId)
			. ':'
			. implode(',', array_map(fn($k, $v) => "{$k}:{$v}", array_keys($filter), $filter))
		);
	}

	protected function prepareData(
		string $hash,
		string $gridId,
		int $entityTypeId,
		array $filter,
		array $params,
		Factory $factory
	): Dto\PreparedData
	{
		$class = $this->getPreparedDataDtoClass();

		return new $class([
			'hash' => $hash,
			'gridId' => $gridId,
			'entityTypeId' => $entityTypeId,
			'filter' => $filter,
		]);
	}

	/**
	 * @return class-string<Dto\PreparedData>
	 */
	protected function getPreparedDataDtoClass(): string
	{
		return Dto\PreparedData::class;
	}

	final public function processAction(array $params): ?array
	{
		$hash = (string)($params['hash'] ?? '');
		if (empty($hash))
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('hash'));

			return null;
		}

		[$data, $progress] = $this->initDataAndProgressByHash($hash);
		if (!$data || !$progress)
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($data->entityTypeId);
		if (!$factory || !\CCrmOwnerType::isUseFactoryBasedApproach($data->entityTypeId))
		{
			throw new InvalidOperationException(
				"Factory not found for type {$data->entityTypeId}. It seems that 'prepare' action added invalid data",
			);
		}

		if ($progress->totalCount === null)
		{
			$progress->totalCount = $this->getItemsCount($factory, $data);
		}

		$itemsToProcess = $this->getItemsToProcess($factory, $data, $progress);

		$errors = $this->processItems($factory, $itemsToProcess, $data, $progress);

		$isCompleted = count($itemsToProcess) < self::STEP_LIMIT;
		if ($isCompleted)
		{
			unset($this->dataStorage[$hash]);
			unset($this->progressStorage[$hash]);
		}
		else
		{
			$this->progressStorage->set($hash, $progress->toArray());
		}

		$response = [
			'status' => $isCompleted ? 'COMPLETED' : 'PROGRESS',
			'processedItems' => $progress->processedCount,
			'totalItems' => $progress->totalCount,
		];

		if (!empty($errors))
		{
			$response['errors'] = $errors;
		}

		return $response;
	}

	/**
	 * @param string $hash
	 *
	 * @return array{0: ?PreparedData, 1: ?Progress}
	 * @throws InvalidOperationException
	 */
	private function initDataAndProgressByHash(string $hash): array
	{
		$dataArray = $this->dataStorage->get($hash);
		if (!is_array($dataArray))
		{
			$this->addError(ErrorCode::getNotFoundError());

			return [null, null];
		}

		$class = $this->getPreparedDataDtoClass();
		/** @var Dto\PreparedData $data */
		$data = new $class($dataArray);
		if ($data->hasValidationErrors())
		{
			$errorMessages = array_map(
				fn(Error $error) => $error->getMessage(),
				$data->getValidationErrors()->toArray(),
			);

			throw new InvalidOperationException(
				'Invalid prepared data in session: ' . implode('|', $errorMessages),
			);
		}

		$progress = new Dto\Progress($this->progressStorage->get($hash));
		if ($progress->hasValidationErrors())
		{
			$errorMessages = array_map(
				fn(Error $error) => $error->getMessage(),
				$progress->getValidationErrors()->toArray(),
			);

			throw new InvalidOperationException(
				'Invalid progress data in session: ' . implode('|', $errorMessages),
			);
		}

		return [$data, $progress];
	}

	private function getItemsCount(Factory $factory, PreparedData $data): int
	{
		$filter = $data->filter->filter;

		if ($this->isUseOrmApproach($factory))
		{
			return $factory->getItemsCount($filter);
		}

		return ListEntity\Entity::getInstance($factory->getEntityName())->getCount($filter);
	}

	private function isUseOrmApproach(Factory $factory): bool
	{
		return \CCrmOwnerType::isUseDynamicTypeBasedApproach($factory->getEntityTypeId());
	}

	private function getItemsToProcess(Factory $factory, PreparedData $data, Progress $progress): array
	{
		$filter = $data->filter->filter;
		if ($progress->lastId > 0)
		{
			$filter['>ID'] = $progress->lastId;
		}

		if ($this->isUseOrmApproach($factory))
		{
			return $this->getItemsToProcessViaOrm($factory, $filter);
		}

		return $this->getItemsToProcessViaListEntity($factory, $filter);
	}

	private function getItemsToProcessViaOrm(Factory $factory, array $filter): array
	{
		return $factory->getItems([
			'select' => ['*'],
			'filter' => $filter,
			'order' => [
				'ID' => 'ASC',
			],
			'limit' => self::STEP_LIMIT,
		]);
	}

	private function getItemsToProcessViaListEntity(Factory $factory, array $filter): array
	{
		$dbResult = ListEntity\Entity::getInstance($factory->getEntityName())->getItems([
			'select' => ['ID'],
			'filter' => $filter,
			'order' => [
				'ID' => 'ASC',
			],
			'limit' => self::STEP_LIMIT,
			'offset' => 0,
		]);

		$ids = [];
		while ($row = $dbResult->Fetch())
		{
			$ids[] = $row['ID'];
		}

		if (empty($ids))
		{
			return [];
		}

		return $factory->getItems([
			'select' => ['*'],
			'filter' => [
				'@ID' => $ids,
			],
			'order' => [
				'ID' => 'ASC',
			],
		]);
	}

	private function processItems(Factory $factory, array $itemsToProcess, PreparedData $data, Progress $progress): array
	{
		$itemsThatShouldBeProcessed = $this->filterOutSkippableItems($factory, $itemsToProcess, $data);

		$errors = [];
		foreach ($itemsToProcess as $item)
		{
			$progress->processedCount++;
			$progress->lastId = $item->getId();

			if (!in_array($item, $itemsThatShouldBeProcessed, true))
			{
				continue;
			}

			if ($this->isWrapItemProcessingInTransaction())
			{
				$this->connection->startTransaction();
			}

			$result = $this->processItem($factory, $item, $data);

			if ($result->isSuccess() && $this->isWrapItemProcessingInTransaction())
			{
				$this->connection->commitTransaction();
			}
			elseif (!$result->isSuccess())
			{
				if ($this->isWrapItemProcessingInTransaction())
				{
					$this->connection->rollbackTransaction();
				}

				foreach ($result->getErrors() as $error)
				{
					$errors[] = new Error(
						$error->getMessage(),
						$error->getCode(),
						[
							'info' => [
								'title' => $item->getHeading(),
								'showUrl' => $this->router->getItemDetailUrl($item->getEntityTypeId(), $item->getId()),
							],
						],
					);
				}
			}
		}

		return $errors;
	}

	protected function filterOutSkippableItems(Factory $factory, array $itemsToProcess, PreparedData $data): array
	{
		return array_filter($itemsToProcess, fn(Item $item) => !$this->isItemShouldBeSkipped($factory, $item, $data));
	}

	/**
	 * Is item should be skipped since there is no sense in processing it.
	 * For example, if this action changes item stage, and item is already at that stage, there is no sense processing
	 * it.
	 *
	 * Note that this method should not check permissions or data correctness.
	 * Its designed only for performance optimization to skip unneeded work
	 *
	 */
	protected function isItemShouldBeSkipped(Factory $factory, Item $item, PreparedData $data): bool
	{
		return false;
	}

	/**
	 * Returns true if `$this->processItem` should be wrapped with transaction. You can return `false` from this method
	 * if you want to manage transaction yourself.
	 *
	 * But it's highly recommended that you use some form of transaction in `$this->processItem` anyway to maintain
	 * consistency, even if you decide to return `false` from this method.
	 *
	 * @return bool
	 */
	protected function isWrapItemProcessingInTransaction(): bool
	{
		return true;
	}

	/**
	 * Do the work here. Should check all permissions necessary
	 *
	 * @param Factory $factory
	 * @param Item $item
	 * @param PreparedData $data
	 *
	 * @return Result
	 */
	abstract protected function processItem(Factory $factory, Item $item, PreparedData $data): Result;

	final public function cancelAction(array $params): ?array
	{
		$hash = $params['hash'] ?? '';
		if (empty($hash))
		{
			$this->addError(ErrorCode::getRequiredArgumentMissingError('hash'));
			return null;
		}

		unset($this->dataStorage[$hash], $this->progressStorage[$hash]);

		return [ 'hash' => $hash ];
	}
}
