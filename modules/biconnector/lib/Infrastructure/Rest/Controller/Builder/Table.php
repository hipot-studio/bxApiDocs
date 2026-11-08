<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\BuilderOperationSupport;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\DatasetDto;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Table\ListBiTablesRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Table\ListBiTablesOperation;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Physical tables of the BI database, the sources of new datasets. Apart from biconnector.table.* of the
 * first stage, which manages tables of external sources.
 *
 * A table has no entity of its own in the schema: V3 builds a typed request only for a controller with a DTO,
 * so the controller borrows the one of the dataset, the resource tables are sources of.
 */
#[DtoType(DatasetDto::class)]
final class Table extends AbstractBuilderController
{
	use BuilderOperationSupport;

	/**
	 * biconnector.builder.table.list: tables of the BI database, up to 50 a page, the answer of list_bi_tables.
	 * Like the tool, it requires the right to edit dashboards globally.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::RateLimit,
	)]
	public function listAction(ListBiTablesRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(ListBiTablesOperation::class)->execute(
			$request->schema,
			$request->limit,
			$request->offset,
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}
}
