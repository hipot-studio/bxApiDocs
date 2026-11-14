<?php

namespace Bitrix\Crm\Controller\Autorun;

use Bitrix\Crm\Component\EntityList\ClientFieldHelper;
use Bitrix\Crm\Controller\Autorun\Access\ReadableIdsFilter;
use Bitrix\Crm\Controller\Autorun\Dto\PreparedData;
use Bitrix\Crm\Controller\Autorun\Dto\Progress;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Filter\RelatedEntity\GridFilterApplier;
use Bitrix\Crm\Item;
use Bitrix\Crm\ListEntity;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\Operation\TransactionWrapper;
use Bitrix\Crm\Service\Router;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\Application;
use Bitrix\Main\Data\LocalStorage\SessionLocalStorage;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\StringHelper;
use Bitrix\Main\Type\Collection;

abstract class Base extends \Bitrix\Crm\Controller\Base
{
	private const STEP_LIMIT = 10;

	/** MySQL errors that ask to retry the transaction: a changed table definition and a deadlock. */
	private const RETRYABLE_SQL_ERROR_CODES = [1412, 1213];

	/**
	 * The message of a plain SystemException that carries an SQL error: the recycle bin catches the SqlQueryException
	 * and the recycle bin controllers of crm throw its message again, the database message after this prefix.
	 */
	private const SQL_ERROR_MESSAGE_PATTERN = '/^Mysql query error: (\(\d+\).*)$/s';

	private Connection $connection;
	private Router $router;

	private SessionLocalStorage $dataStorage;
	private SessionLocalStorage $progressStorage;
	protected Progress $progress;
	private PreparedData $data;

	/** The wrapper the current attempt of an item launched its operation in, see launchInTransactionWrapper() */
	private ?TransactionWrapper $itemTransactionWrapper = null;

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

		$filter = $this->prepareFilter($factory, $gridId, $params);

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

	private function prepareFilter(Factory $factory, string $gridId, array $params): ?array
	{
		$entityTypeId = $factory->getEntityTypeId();
		if (!empty($params['entityIds']) && is_array($params['entityIds']))
		{
			$entityIds = $params['entityIds'];
			Collection::normalizeArrayValuesByInt($entityIds);

			if (empty($entityIds))
			{
				$this->addError(new Error('entityIds should be a int[]', ErrorCode::INVALID_ARG_VALUE));

				return null;
			}

			$readableIds = (new ReadableIdsFilter())->narrowToReadable($factory, $entityIds);

			// no error on an unreadable id: the answer must not tell it apart from an id that does not exist
			return ['@ID' => empty($readableIds) ? [0] : $readableIds];
		}

		$filterFactory = Container::getInstance()->getFilterFactory();
		$filter = $filterFactory->getFilter($filterFactory::getSettingsByGridId($entityTypeId, $gridId));

		$rawUIFilter = (!empty($params['filter']) && is_array($params['filter'])) ? $params['filter'] : null;
		$filterValue =
			is_array($rawUIFilter)
				? $filter->getValue($rawUIFilter)
				: $filterFactory->getFilterValue($entityTypeId, $filter)
		;

		if ($this->isUseOrmApproach($factory))
		{
			foreach ($this->getClientFieldHelpers($factory) as $clientFieldHelper)
			{
				$filterValue = $clientFieldHelper->normalizeFilter($filterValue);
			}
		}

		return $filterValue;
	}

	/**
	 * @return ClientFieldHelper[]
	 */
	private function getClientFieldHelpers(Factory $factory): array
	{
		$clientFieldHelpers = [];
		if ($factory->isClientContactEnabled())
		{
			$clientFieldHelpers[] = new ClientFieldHelper(\CCrmOwnerType::Contact);
		}
		if ($factory->isClientCompanyEnabled())
		{
			$clientFieldHelpers[] = new ClientFieldHelper(\CCrmOwnerType::Company);
		}

		return $clientFieldHelpers;
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

		if (!$this->initProcessDataByHash($hash))
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($this->data->entityTypeId);
		if (!$factory || !\CCrmOwnerType::isUseFactoryBasedApproach($this->data->entityTypeId))
		{
			throw new InvalidOperationException(
				"Factory not found for type {$this->data->entityTypeId}. It seems that 'prepare' action added invalid data",
			);
		}

		$this->initProgressByHash($hash);

		if ($this->progress->totalCount === null)
		{
			$this->progress->totalCount = $this->getItemsCount($factory);
		}

		$itemsToProcess = $this->getItemsToProcess($factory);

		$this->processItems($factory, $itemsToProcess);

		$isCompleted = count($itemsToProcess) < self::STEP_LIMIT;
		if ($isCompleted)
		{
			unset($this->dataStorage[$hash]);
			unset($this->progressStorage[$hash]);
		}
		else
		{
			$this->progressStorage->set($hash, $this->progress->toArray());
		}

		$response = [
			'status' => $isCompleted ? 'COMPLETED' : 'PROGRESS',
			'processedItems' => $this->progress->processedCount,
			'totalItems' => $this->progress->totalCount,
		];

		if ($this->progress->hasErrors())
		{
			$response['errors'] = $this->progress->getErrors();
		}

		$this->sendAnalyticsData($this->data, $response);

		return $response;
	}

	/**
	 * @param string $hash
	 *
	 * @return bool
	 * @throws InvalidOperationException
	 */
	private function initProcessDataByHash(string $hash): bool
	{
		$dataArray = $this->dataStorage->get($hash);
		if (!is_array($dataArray))
		{
			$this->addError(ErrorCode::getNotFoundError());

			return false;
		}

		$class = $this->getPreparedDataDtoClass();

		$this->data = new $class($dataArray);
		if ($this->data->hasValidationErrors())
		{
			$errorMessages = array_map(
				fn(Error $error) => $error->getMessage(),
				$this->data->getValidationErrors()->toArray(),
			);

			throw new InvalidOperationException(
				'Invalid prepared data in session: ' . implode('|', $errorMessages),
			);
		}

		return true;
	}

	private function initProgressByHash(string $hash): void
	{
		$this->progress = new Dto\Progress($this->progressStorage->get($hash));
		if ($this->progress->hasValidationErrors())
		{
			$errorMessages = array_map(
				fn(Error $error) => $error->getMessage(),
				$this->progress->getValidationErrors()->toArray(),
			);

			throw new InvalidOperationException(
				'Invalid progress data in session: ' . implode('|', $errorMessages),
			);
		}
	}

	private function getItemsCount(Factory $factory): int
	{
		$parameters = ['filter' => $this->data->filter->filter];
		GridFilterApplier::getDefault()->apply($parameters, $factory->getEntityTypeId());

		if ($this->isUseOrmApproach($factory))
		{
			return $factory->getItemsCountFilteredByPermissions(
				$parameters['filter'],
				null,
				UserPermissions::OPERATION_READ,
				$parameters['runtime'] ?? [],
			);
		}

		return ListEntity\Entity::getInstance($factory->getEntityName())->getCount($parameters['filter']);
	}

	private function isUseOrmApproach(Factory $factory): bool
	{
		return \CCrmOwnerType::isUseDynamicTypeBasedApproach($factory->getEntityTypeId());
	}

	private function getItemsToProcess(Factory $factory): array
	{
		$filter = $this->data->filter->filter;
		if ($this->progress->lastId > 0)
		{
			$filter['>ID'] = $this->progress->lastId;
		}

		return $this->getItems($factory, $filter, self::STEP_LIMIT);
	}

	/**
	 * Reads the item anew the same way the items of the step are read: with the filter of the batch,
	 * the permission check and the select of the action.
	 */
	private function readItemAnew(Factory $factory, int $itemId): ?Item
	{
		$filter = $this->data->filter->filter;
		$filter['=ID'] = $itemId;

		$items = $this->getItems($factory, $filter, 1);

		return $items[0] ?? null;
	}

	/**
	 * @return Item[]
	 */
	private function getItems(Factory $factory, array $filter, int $limit): array
	{
		if ($this->isUseOrmApproach($factory))
		{
			return $this->getItemsToProcessViaOrm($factory, $filter, $limit);
		}

		return $this->getItemsToProcessViaListEntity($factory, $filter, $limit);
	}

	private function getItemsToProcessViaOrm(Factory $factory, array $filter, int $limit): array
	{
		$parameters = ['filter' => $filter];
		GridFilterApplier::getDefault()->apply($parameters, $factory->getEntityTypeId());

		return $factory->getItemsFilteredByPermissions(
			[
				'select' => $this->getSelect(),
				'filter' => $parameters['filter'],
				'runtime' => $parameters['runtime'] ?? [],
				'order' => [
					'ID' => 'ASC',
				],
				'limit' => $limit,
			],
			null,
			UserPermissions::OPERATION_READ,
		);
	}

	private function getItemsToProcessViaListEntity(Factory $factory, array $filter, int $limit): array
	{
		$parameters = ['filter' => $filter];
		GridFilterApplier::getDefault()->apply($parameters, $factory->getEntityTypeId());

		$dbResult = ListEntity\Entity::getInstance($factory->getEntityName())->getItems([
			'select' => ['ID'],
			'filter' => $parameters['filter'],
			'order' => [
				'ID' => 'ASC',
			],
			'limit' => $limit,
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
			'select' => $this->getSelect(),
			'filter' => [
				'@ID' => $ids,
			],
			'order' => [
				'ID' => 'ASC',
			],
		]);
	}

	protected function getSelect(): array
	{
		return ['*'];
	}

	private function processItems(Factory $factory, array $itemsToProcess): void
	{
		$this->onBeforeItemsProcessing($factory, $itemsToProcess);

		$itemsThatShouldBeProcessed = $this->filterOutSkippableItems($factory, $itemsToProcess, $this->data);

		foreach ($itemsToProcess as $item)
		{
			// an attempt may change the id of the object: the ORM leaves a deleted item with the id 0
			$itemId = $item->getId();

			$this->progress->processedCount++;
			$this->progress->lastId = $itemId;

			if (!in_array($item, $itemsThatShouldBeProcessed, true))
			{
				continue;
			}

			$result = $this->processItemWithRetry($factory, $item, $itemId);

			if ($result->isSuccess())
			{
				$this->progress->addSuccessId($itemId);
			}
			else
			{
				$this->progress->addErrorId($itemId);

				foreach ($result->getErrors() as $error)
				{
					$this->progress->addError(
						new Error(
							$error->getMessage(),
							$error->getCode(),
							[
								'info' => [
									'title' => $item->getHeading(),
									'showUrl' => $this->router->getItemDetailUrl($item->getEntityTypeId(), $itemId),
								],
							],
						)
					);
				}
			}
		}
	}

	/**
	 * Processes the item. An SQL error MySQL asks to retry the transaction for (a changed table definition,
	 * a deadlock) makes the item read anew and processed once more, unless the action turns the repeat off.
	 * An item read anew that is already in the target state of the action is not processed again and counts
	 * as a success. Any other SQL error, an error of the repeat or an item gone by the repeat makes the item
	 * failed, without the text of the exception.
	 *
	 * An SQL error comes as an SqlQueryException or as a SystemException of exactly that class whose message is
	 * the one of an SqlQueryException ("Mysql query error: (<code>) ..."): this is how the recycle bin controllers
	 * of crm pass on the SQL error the recycle bin caught. Both are handled alike, whichever of the attempts threw
	 * which; any other exception goes up to the caller.
	 *
	 * An SQL error that came after the TransactionWrapper of the attempt had committed the operation
	 * (from business processes or robots run after the commit) is only logged: the work is done, so the item
	 * is not repeated and counts as a success.
	 *
	 * @param int $itemId the id of the item taken before the first attempt: an attempt may change the id of
	 * the object (the ORM leaves a deleted item with the id 0), so the item is read anew and logged by this id.
	 */
	private function processItemWithRetry(Factory $factory, Item $item, int $itemId): Result
	{
		try
		{
			return $this->processItemInTransaction($factory, $item);
		}
		catch (SystemException $exception)
		{
			$databaseMessage = $this->getSqlErrorDatabaseMessage($exception);
			if ($databaseMessage === null)
			{
				throw $exception;
			}

			$this->logItemProcessingException($item->getEntityTypeId(), $itemId, $databaseMessage);

			if ($this->isItemProcessingCommitted())
			{
				return new Result();
			}

			if (!$this->isRetryOnTransientSqlErrorEnabled() || !$this->isRetryableSqlError($databaseMessage))
			{
				return $this->getItemProcessingFailedResult();
			}
		}

		try
		{
			$itemReadAnew = $this->readItemAnew($factory, $itemId);
			if (!$itemReadAnew)
			{
				return $this->getItemProcessingFailedResult();
			}

			if ($this->isItemShouldBeSkipped($factory, $itemReadAnew, $this->data))
			{
				return new Result();
			}

			return $this->processItemInTransaction($factory, $itemReadAnew);
		}
		catch (SystemException $exception)
		{
			$databaseMessage = $this->getSqlErrorDatabaseMessage($exception);
			if ($databaseMessage === null)
			{
				throw $exception;
			}

			$this->logItemProcessingException($item->getEntityTypeId(), $itemId, $databaseMessage);

			if ($this->isItemProcessingCommitted())
			{
				return new Result();
			}

			return $this->getItemProcessingFailedResult();
		}
	}

	/**
	 * Remembers the wrapper an heir launches the operation of an item in, so that an SQL error after its commit
	 * is not taken for an error inside the transaction. The heir launches the returned wrapper itself.
	 */
	protected function launchInTransactionWrapper(TransactionWrapper $wrapper): TransactionWrapper
	{
		$this->itemTransactionWrapper = $wrapper;

		return $wrapper;
	}

	/**
	 * Whether the wrapper of the current attempt committed the operation for real. Inside the transaction
	 * of Base its commit is a nested one and the rollback of Base undoes it.
	 */
	private function isItemProcessingCommitted(): bool
	{
		return
			!$this->isWrapItemProcessingInTransaction()
			&& $this->itemTransactionWrapper?->isCommitted()
		;
	}

	private function processItemInTransaction(Factory $factory, Item $item): Result
	{
		$this->itemTransactionWrapper = null;

		if (!$this->isWrapItemProcessingInTransaction())
		{
			return $this->processItem($factory, $item, $this->data);
		}

		$this->connection->startTransaction();

		try
		{
			$result = $this->processItem($factory, $item, $this->data);
		}
		catch (\Throwable $throwable)
		{
			$this->rollbackTransactionSafely();

			throw $throwable;
		}

		if ($result->isSuccess())
		{
			$this->connection->commitTransaction();
		}
		else
		{
			$this->rollbackTransactionSafely();
		}

		return $result;
	}

	private function rollbackTransactionSafely(): void
	{
		try
		{
			$this->connection->rollbackTransaction();
		}
		catch (TransactionException)
		{
			// MySQL rolls back to a savepoint and reports nested rollback as an exception.
		}
	}

	/**
	 * The database message of an SQL error the item failed with, or null if the exception is not an SQL error:
	 * an SqlQueryException gives it as is, a SystemException of exactly that class has it in its message after
	 * the prefix of an SqlQueryException. An heir of SystemException is never taken for an SQL error by its text.
	 */
	private function getSqlErrorDatabaseMessage(SystemException $exception): ?string
	{
		if ($exception instanceof SqlQueryException)
		{
			return (string)$exception->getDatabaseMessage();
		}

		if (
			get_class($exception) === SystemException::class
			&& preg_match(self::SQL_ERROR_MESSAGE_PATTERN, $exception->getMessage(), $matches) === 1
		)
		{
			return $matches[1];
		}

		return null;
	}

	private function isRetryableSqlError(string $databaseMessage): bool
	{
		foreach (self::RETRYABLE_SQL_ERROR_CODES as $code)
		{
			if (str_starts_with($databaseMessage, "({$code})"))
			{
				return true;
			}
		}

		return false;
	}

	private function getItemProcessingFailedResult(): Result
	{
		return (new Result())->addError(ErrorCode::getGeneralError());
	}

	private function logItemProcessingException(int $entityTypeId, int $itemId, string $databaseMessage): void
	{
		Container::getInstance()->getLogger('Default')->error(
			'Group action {action}: item {entityTypeId}:{itemId} failed with an SQL error: {databaseMessage}',
			[
				'action' => static::class,
				'entityTypeId' => $entityTypeId,
				'itemId' => $itemId,
				'databaseMessage' => $databaseMessage,
			],
		);
	}

	/**
	 * Called once per page of a step, before its items are processed. Does nothing by default.
	 *
	 * An heir whose `processItem` reads something per item may warm that read here for the whole page.
	 *
	 * @param Item[] $itemsToProcess
	 */
	protected function onBeforeItemsProcessing(Factory $factory, array $itemsToProcess): void
	{
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
	 * Its designed to skip unneeded work. It also guards against repeating an operation that is already
	 * committed: after an SQL error MySQL asks to retry the transaction for (1412, 1213) the item is read
	 * anew and checked here, and the item that is already in the target state is not processed again.
	 * The error could come after the commit, from robots or business processes, so an heir whose action
	 * has a recognizable target state should implement this method.
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
	 * Returns true if an item may be read anew and processed once more after an SQL error MySQL asks to retry
	 * the transaction for (1412 "Table definition has changed", 1213 "Deadlock found").
	 *
	 * Return `false` if the work of `$this->processItem` inside the transaction has side effects the rollback
	 * does not undo: sending messages, starting robots or business processes. Then such an error makes the item
	 * failed without a repeat, as any other SQL error does.
	 *
	 * @return bool
	 */
	protected function isRetryOnTransientSqlErrorEnabled(): bool
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

	protected function sendAnalyticsData(PreparedData $data, array $response): void
	{
	}
}
