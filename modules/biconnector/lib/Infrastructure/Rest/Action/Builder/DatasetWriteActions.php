<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Action\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset\CreateDatasetFromTableRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset\CreateDatasetRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset\DeleteDatasetRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset\UpdateDatasetRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Dataset\WriteDatasetOperations;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\HttpRequest;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * biconnector.builder.dataset.add, .addfromtable, .update and .delete: the answers of create_dataset_from_sql,
 * create_virtual_dataset and of a confirmed update_dataset and delete_dataset. REST has no confirmation
 * step, so an update and a deletion run at once; the deletion spends the destructive budget delete_dataset
 * spends.
 */
trait DatasetWriteActions
{
	use BuilderOperationSupport;

	/**
	 * A virtual dataset over a SELECT: `id`, `name` and the stored `sql`.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::DomainRule,
		FailureCode::RateLimit,
	)]
	public function addAction(CreateDatasetRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(WriteDatasetOperations::class)->createFromSql(
			$request->datasetName,
			$this->getSqlBeforeProactiveFilter($request),
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}

	/**
	 * A virtual dataset projecting columns of a BI table: `id`, `name`, the generated `sql`, `source_table`,
	 * `source_schema` and `columns`.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::DomainRule,
		FailureCode::RateLimit,
	)]
	public function addFromTableAction(CreateDatasetFromTableRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(WriteDatasetOperations::class)->createFromTable(
			$request->datasetName,
			$request->sourceTable,
			$request->columns,
			$request->sourceSchema === '' ? null : $request->sourceSchema,
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}

	/**
	 * Replaces the projected columns of a dataset the caller owns: `updated`, `id`, `name`, `columns`.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function updateAction(UpdateDatasetRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(WriteDatasetOperations::class)->update(
			$request->datasetId,
			$request->columns,
			$actor,
		);

		return new ArrayResponse($this->answer($result, $request->datasetId));
	}

	/**
	 * Deletes a dataset the caller owns: `deleted`, `id`, `name`. Deleting a dataset that is already gone
	 * answers `already_deleted`.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::DestructiveBudget,
		FailureCode::RateLimit,
	)]
	public function deleteAction(DeleteDatasetRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(WriteDatasetOperations::class)->delete(
			$request->datasetId,
			$actor,
		);

		return new ArrayResponse(WriteDatasetOperations::deletionAnswer($this->answer($result, $request->datasetId)));
	}

	/**
	 * The Proactive Filter (WAF) rewrites SQL keywords (SELECT -> SEL ECT) in the JSON of the request it guards,
	 * and the arguments of the action are bound from the filtered values; the value before the filter is taken,
	 * as create_dataset_from_sql takes it. An item of a batch is a request built in-process from values already
	 * filtered, so it has nothing older to give and its own value is taken.
	 */
	private function getSqlBeforeProactiveFilter(CreateDatasetRequest $request): string
	{
		$httpRequest = $this->getRequest();
		$sql = $httpRequest instanceof HttpRequest ? $httpRequest->getJsonList()->getRaw('sql') : null;

		return is_string($sql) ? $sql : $request->sql;
	}
}
