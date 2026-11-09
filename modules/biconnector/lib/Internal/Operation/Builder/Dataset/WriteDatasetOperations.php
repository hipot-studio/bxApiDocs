<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dataset;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\DestructiveBudget;
use Bitrix\BIConnector\Internal\Operation\Builder\EntityLookup;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Operation\Builder\SupersetIdentity;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Creation, update and deletion of a Superset dataset with the checks and texts of create_dataset_from_sql,
 * create_virtual_dataset, update_dataset and delete_dataset.
 *
 * Creating takes ACTION_BIC_ACCESS and ACTION_BIC_DASHBOARD_EDIT; an update and a deletion also take
 * the ownership of the dataset, compared on CLIENT_ID. The preview and the step it precedes run the same
 * checks; the destructive budget is spent by the deletion only. A dataset that is already gone is
 * an idempotent success of the deletion, not a refusal.
 */
final class WriteDatasetOperations
{
	private const ALLOWED_COLUMN_TYPES = ['INT', 'STRING', 'DOUBLE', 'DATE', 'DATETIME'];
	private const LIMITER_SERVICE_CODE = 'biconnector.service.biToolsRateLimiter';
	private const UNAVAILABLE_BI_MESSAGE = 'The BI request could not be completed right now: the BI service '
		. 'may be busy or still syncing. Try again in a moment.'
	;

	/** @var \Closure(int): AccessController */
	private readonly \Closure $accessControllerFactory;
	private readonly SupersetIdentity $identity;

	/**
	 * @param (\Closure(int): AccessController)|null $accessControllerFactory
	 */
	public function __construct(?\Closure $accessControllerFactory = null, ?SupersetIdentity $identity = null)
	{
		$this->accessControllerFactory = $accessControllerFactory
			?? static fn(int $userId): AccessController => new AccessController($userId)
		;
		$this->identity = $identity ?? new SupersetIdentity();
	}

	/**
	 * Data: the answer of create_dataset_from_sql — `id`, `name`, `sql`.
	 *
	 * @param string $sql As the client sent it: the entry reads it past the Proactive Filter.
	 */
	public function createFromSql(string $datasetName, string $sql, Actor $actor): Result
	{
		$userId = $actor->userId;

		$refused = $this->checkCreation($userId);
		if ($refused)
		{
			return $refused;
		}

		$datasetName = trim($datasetName);
		$sql = trim($sql);

		if ($datasetName === '')
		{
			return self::invalidArgument('datasetName is required: provide a unique name for the new dataset.');
		}
		if ($sql === '')
		{
			return self::invalidArgument('sql is required: provide the SELECT query that defines the dataset.');
		}

		// The dataset is owned by the actor in both states of the axis, see SupersetIdentity::forWrite().
		$identityResult = $this->identity->forWrite($userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$taken = self::checkNameIsFree($datasetName);
		if ($taken)
		{
			return $taken;
		}

		$response = IntegratorFactory::getInstance()
			->createDatasetFromSql($identityResult->getData()['user'], $datasetName, $sql)
		;
		if ($response->hasErrors())
		{
			return self::unavailableBi(
				$response->getErrors(),
				[
					'stage' => 'create_dataset_from_sql',
					'user_id' => $userId,
					'dataset_name' => $datasetName,
				],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		return (new Result())->setData([
			'id' => (int)($data['id'] ?? 0),
			'name' => (string)($data['name'] ?? $datasetName),
			'sql' => (string)($data['sql'] ?? $sql),
		]);
	}

	/**
	 * Data: the answer of create_virtual_dataset — `id`, `name`, `sql`, `source_table`, `source_schema`,
	 * `columns`.
	 *
	 * @param array $columns Column names; blank and non-string entries are dropped.
	 * @param string|null $sourceSchema Null takes the default BI schema.
	 */
	public function createFromTable(
		string $datasetName,
		string $sourceTable,
		array $columns,
		?string $sourceSchema,
		Actor $actor,
	): Result
	{
		$userId = $actor->userId;

		$refused = $this->checkCreation($userId);
		if ($refused)
		{
			return $refused;
		}

		$datasetName = trim($datasetName);
		$sourceTable = trim($sourceTable);

		if ($datasetName === '')
		{
			return self::invalidArgument('datasetName is required: provide a unique name for the new dataset.');
		}
		if ($sourceTable === '')
		{
			return self::invalidArgument(
				'sourceTable is required: provide the source table name (call list_bi_tables to see available tables).'
			);
		}
		if (empty($columns))
		{
			return self::invalidArgument('At least one column must be provided.');
		}

		$columns = array_values(array_filter(
			array_map(static fn ($c): string => is_string($c) ? trim($c) : '', $columns),
			static fn (string $c): bool => $c !== '',
		));
		if (empty($columns))
		{
			return self::invalidArgument('At least one non-empty column must be provided.');
		}

		// The dataset is owned by the actor in both states of the axis, see SupersetIdentity::forWrite().
		$identityResult = $this->identity->forWrite($userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$taken = self::checkNameIsFree($datasetName);
		if ($taken)
		{
			return $taken;
		}

		$response = IntegratorFactory::getInstance()->createVirtualDataset(
			$identityResult->getData()['user'],
			$datasetName,
			$sourceTable,
			$columns,
			$sourceSchema,
		);
		if ($response->hasErrors())
		{
			return self::unavailableBi(
				$response->getErrors(),
				[
					'stage' => 'create_virtual_dataset',
					'user_id' => $userId,
					'dataset_name' => $datasetName,
					'source_table' => $sourceTable,
					'source_schema' => $sourceSchema,
				],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		return (new Result())->setData([
			'id' => (int)($data['id'] ?? 0),
			'name' => (string)($data['name'] ?? $datasetName),
			'sql' => (string)($data['sql'] ?? ''),
			'source_table' => (string)($data['sourceTable'] ?? $sourceTable),
			'source_schema' => (string)($data['sourceSchema'] ?? $sourceSchema ?? ''),
			'columns' => $data['columns'] ?? $columns,
		]);
	}

	/**
	 * Data: `id`, `name` and the normalized `columns` that would replace the projected set.
	 *
	 * @param array $columns Full replacement list of `{name, type}` objects.
	 */
	public function previewUpdate(int $datasetId, array $columns, Actor $actor): Result
	{
		$inspected = $this->inspectUpdate($datasetId, $columns, $actor);
		if (!$inspected->isSuccess())
		{
			return $inspected;
		}

		$data = $inspected->getData();

		return (new Result())->setData([
			'id' => $data['id'],
			'name' => $data['name'],
			'columns' => $data['columns'],
		]);
	}

	/**
	 * Data: the answer of update_dataset — `updated`, `id`, `name`, `columns`.
	 *
	 * @param array $columns Full replacement list of `{name, type}` objects.
	 */
	public function update(int $datasetId, array $columns, Actor $actor): Result
	{
		$inspected = $this->inspectUpdate($datasetId, $columns, $actor);
		if (!$inspected->isSuccess())
		{
			return $inspected;
		}

		$columns = $inspected->getData()['columns'];
		$name = $inspected->getData()['name'];

		$response = IntegratorFactory::getInstance()->updateDataset($datasetId, ['columns' => $columns]);
		$classified = (new EntityLookup())->classify($response, $datasetId);
		if (!$classified->isSuccess())
		{
			return $classified;
		}

		return (new Result())->setData([
			'updated' => true,
			'id' => $datasetId,
			'name' => $name,
			'columns' => $columns,
		]);
	}

	/**
	 * Data: `id`, `name`, `alreadyDeleted`.
	 */
	public function previewDelete(int $datasetId, Actor $actor): Result
	{
		$inspected = $this->inspectDeletion($datasetId, $actor);
		if (!$inspected->isSuccess())
		{
			return $inspected;
		}

		return self::deleted($datasetId, $inspected->getData()['name'], $inspected->getData()['alreadyDeleted']);
	}

	/**
	 * Data: `id`, `name`, `alreadyDeleted`.
	 */
	public function delete(int $datasetId, Actor $actor): Result
	{
		$inspected = $this->inspectDeletion($datasetId, $actor);
		if (!$inspected->isSuccess())
		{
			return $inspected;
		}

		$preview = $inspected->getData();
		if ($preview['alreadyDeleted'])
		{
			return self::deleted($datasetId, '', true);
		}

		$spent = self::getDestructiveBudget()->spend($actor, 'dataset', $datasetId);
		if (!$spent->isSuccess())
		{
			return $spent;
		}

		$name = $preview['name'];
		$response = IntegratorFactory::getInstance()->deleteDataset($datasetId);
		$classified = (new EntityLookup())->classify($response, $datasetId);
		if (!$classified->isSuccess())
		{
			if ($classified->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return self::deleted($datasetId, $name, true);
			}

			return $classified;
		}

		return self::deleted($datasetId, $name, false);
	}

	/**
	 * The answer of delete_dataset to a deletion, which biconnector.builder.dataset.delete repeats.
	 *
	 * @param array{id: int, name: string, alreadyDeleted: bool} $deleted Data of delete().
	 */
	public static function deletionAnswer(array $deleted): array
	{
		$answer = [
			'deleted' => true,
			'id' => $deleted['id'],
			'name' => $deleted['name'],
		];
		if ($deleted['alreadyDeleted'])
		{
			$answer['already_deleted'] = true;
		}

		return $answer;
	}

	private function checkCreation(int $userId): ?Result
	{
		if (!Feature::isBuilderEnabled())
		{
			return self::builderDisabled();
		}

		$accessController = ($this->accessControllerFactory)($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied: ACTION_BIC_ACCESS is required.');
		}
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied: ACTION_BIC_DASHBOARD_EDIT is required.');
		}

		return null;
	}

	/**
	 * Dataset names are unique on the portal. Superset would reject the duplicate later anyway, but checking
	 * up front names the existing id instead of a generic failure. A point lookup by name keeps the cost
	 * independent of the number of datasets; a not-found answer carries errors and lets the creation go on.
	 */
	private static function checkNameIsFree(string $datasetName): ?Result
	{
		$existingResponse = IntegratorFactory::getInstance()->getDatasetByName($datasetName);
		if ($existingResponse->hasErrors())
		{
			return null;
		}

		$existingData = $existingResponse->getData();
		$existingId = is_array($existingData) ? (int)($existingData['id'] ?? 0) : 0;
		if ($existingId <= 0)
		{
			return null;
		}

		return Refusal::error(
			FailureCode::DomainRule,
			"Dataset with name '{$datasetName}' already exists (id={$existingId}). "
			. 'Dataset names must be unique on the portal. '
			. 'Pick a different name or reuse the existing dataset id when creating charts.',
		);
	}

	/**
	 * The checks of both update steps, in the order of update_dataset. Data: `id`, `name`, `columns`.
	 */
	private function inspectUpdate(int $datasetId, array $columns, Actor $actor): Result
	{
		$userId = $actor->userId;

		if (!Feature::isBuilderEnabled())
		{
			return self::builderDisabled();
		}

		$accessController = ($this->accessControllerFactory)($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied: ACTION_BIC_ACCESS is required.');
		}
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			return Refusal::error(
				FailureCode::AccessDenied,
				'Access denied: ACTION_BIC_DASHBOARD_EDIT is required to update datasets.',
			);
		}

		if ($datasetId <= 0)
		{
			return self::invalidArgument('datasetId is required and must be a positive integer.');
		}

		$normalized = self::normalizeColumns($columns);
		if (!$normalized->isSuccess())
		{
			return $normalized;
		}

		$identityResult = $this->identity->forRead($userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$lookup = (new EntityLookup())->dataset($datasetId, $identityResult->getData()['user']);
		if (!$lookup->isSuccess())
		{
			return $lookup;
		}

		$current = $lookup->getData()['entity'];

		$owned = self::checkOwned($userId, is_array($current['owners'] ?? null) ? $current['owners'] : []);
		if ($owned)
		{
			return $owned;
		}

		return (new Result())->setData([
			'id' => $datasetId,
			'name' => (string)($current['table_name'] ?? ''),
			'columns' => $normalized->getData()['columns'],
		]);
	}

	/**
	 * The checks of both deletion steps, in the order of delete_dataset. Data: `name`, `alreadyDeleted`.
	 */
	private function inspectDeletion(int $datasetId, Actor $actor): Result
	{
		$userId = $actor->userId;

		if (!Feature::isBuilderEnabled())
		{
			return self::builderDisabled();
		}

		if ($datasetId <= 0)
		{
			return self::invalidArgument('datasetId is required and must be a positive integer.');
		}

		$accessController = ($this->accessControllerFactory)($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			return Refusal::error(FailureCode::AccessDenied, 'Access denied: ACTION_BIC_ACCESS is required.');
		}
		if (!$accessController->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			return Refusal::error(
				FailureCode::AccessDenied,
				'Access denied: ACTION_BIC_DASHBOARD_EDIT is required to delete datasets.',
			);
		}

		$identityResult = $this->identity->forRead($userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$lookup = (new EntityLookup())->dataset($datasetId, $identityResult->getData()['user']);
		if (!$lookup->isSuccess())
		{
			if ($lookup->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return (new Result())->setData(['name' => '', 'alreadyDeleted' => true]);
			}

			return $lookup;
		}

		$current = $lookup->getData()['entity'];

		$owned = self::checkOwned($userId, is_array($current['owners'] ?? null) ? $current['owners'] : []);
		if ($owned)
		{
			return $owned;
		}

		return (new Result())->setData([
			'name' => (string)($current['table_name'] ?? ''),
			'alreadyDeleted' => false,
		]);
	}

	/**
	 * Only an owner may modify or delete a dataset. Ownership is compared on CLIENT_ID, the user key shared
	 * by the box and the cloud: the owners of getDatasetById() are already CLIENT_IDs, and the caller's is
	 * resolved from the local link with Superset. A dataset without owners and a caller without the link
	 * are refused alike.
	 *
	 * @param array $datasetOwners Dataset owners as CLIENT_ID strings.
	 */
	private static function checkOwned(int $userId, array $datasetOwners): ?Result
	{
		$row = SupersetUserTable::getRow([
			'select' => ['CLIENT_ID'],
			'filter' => ['=USER_ID' => $userId],
		]);
		$clientId = (string)($row['CLIENT_ID'] ?? '');

		$owners = [];
		foreach ($datasetOwners as $owner)
		{
			if (is_string($owner) && $owner !== '')
			{
				$owners[] = $owner;
			}
		}

		if ($clientId === '' || !in_array($clientId, $owners, true))
		{
			return Refusal::error(FailureCode::AccessDenied, 'You can modify or delete only datasets you own.');
		}

		return null;
	}

	/**
	 * Data: `columns` as a list of `{name, type}` with the name trimmed and the type upper-cased.
	 */
	private static function normalizeColumns(array $rawColumns): Result
	{
		if (empty($rawColumns))
		{
			return self::invalidArgument('At least one column must be provided.');
		}

		$columns = [];
		foreach ($rawColumns as $rawColumn)
		{
			if (!is_array($rawColumn))
			{
				return self::invalidArgument('Each column must be an object with `name` and `type`.');
			}

			$columnName = trim((string)($rawColumn['name'] ?? ''));
			$columnType = strtoupper(trim((string)($rawColumn['type'] ?? '')));

			if ($columnName === '')
			{
				return self::invalidArgument('Each column must have a non-empty `name`.');
			}
			if (!in_array($columnType, self::ALLOWED_COLUMN_TYPES, true))
			{
				return self::invalidArgument(
					"Unsupported column type '{$columnType}' for column '{$columnName}'. "
					. 'Allowed types: ' . implode(', ', self::ALLOWED_COLUMN_TYPES) . '.'
				);
			}

			$columns[] = [
				'name' => $columnName,
				'type' => $columnType,
			];
		}

		return (new Result())->setData(['columns' => $columns]);
	}

	/**
	 * Built over the limiter the service code holds at the moment of the call, never kept: a limiter
	 * replaced later (tests do it per case) would not reach a budget built earlier.
	 */
	private static function getDestructiveBudget(): DestructiveBudget
	{
		return new DestructiveBudget(ServiceLocator::getInstance()->get(self::LIMITER_SERVICE_CODE));
	}

	private static function deleted(int $datasetId, string $name, bool $alreadyDeleted): Result
	{
		return (new Result())->setData([
			'id' => $datasetId,
			'name' => $name,
			'alreadyDeleted' => $alreadyDeleted,
		]);
	}

	private static function builderDisabled(): Result
	{
		return Refusal::error(FailureCode::FeatureDisabled, 'BI Builder feature is not enabled on this portal.');
	}

	private static function invalidArgument(string $message): Result
	{
		return Refusal::error(FailureCode::InvalidArgument, $message);
	}

	/**
	 * The refusal BiToolBehaviour::unavailableBiException() makes: the real cause is logged, not told.
	 * A refusal of act-as keeps its own answer, see Refusal::actAsFailure().
	 *
	 * @param Error[] $errors
	 */
	private static function unavailableBi(array $errors, array $context): Result
	{
		$actAsRefusal = Refusal::actAsFailure($errors, $context);
		if ($actAsRefusal !== null)
		{
			return $actAsRefusal;
		}

		AiToolsLogger::logWarning($errors, $context);

		$details = implode('; ', array_map(static fn(Error $e): string => $e->getMessage(), $errors));

		return Refusal::error(
			FailureCode::Unavailable,
			Refusal::appendDebugDetail(
				self::UNAVAILABLE_BI_MESSAGE,
				$details . ' | ctx: ' . json_encode($context, JSON_UNESCAPED_UNICODE),
			),
		);
	}
}
