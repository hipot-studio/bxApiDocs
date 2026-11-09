<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Action\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset\GetDatasetRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Dataset\ListDatasetsRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Dataset\GetDatasetOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Dataset\ListDatasetsOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * biconnector.builder.dataset.list and .get: the answers of list_datasets and get_dataset.
 *
 * Like the tools, both check access to BI only and do not narrow datasets to the ones the caller may use
 * (the known gap of PRD §4.2, closed by S2/S3).
 */
trait DatasetReadActions
{
	use BuilderOperationSupport;

	/**
	 * Datasets of the portal, up to 50 a page, with `total`, `limit`, `offset` and `has_more`.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function listAction(ListDatasetsRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(ListDatasetsOperation::class)->execute(
			$request->limit,
			$request->offset,
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}

	/**
	 * Definition of a dataset: table name, description, SQL of a virtual dataset and the columns.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::NotFound,
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function getAction(GetDatasetRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(GetDatasetOperation::class)->execute($request->datasetId, $actor);

		return new ArrayResponse($this->answer($result, $request->datasetId));
	}
}
