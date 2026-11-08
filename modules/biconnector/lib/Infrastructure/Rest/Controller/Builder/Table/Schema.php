<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder\Table;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\BuilderOperationSupport;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\DatasetDto;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Table\GetTableSchemaRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Table\GetTableSchemaOperation;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Columns of a table of the BI database, a part of the table resource, with the DTO the table borrows.
 */
#[DtoType(DatasetDto::class)]
final class Schema extends AbstractBuilderController
{
	use BuilderOperationSupport;

	/**
	 * biconnector.builder.table.schema.get: column names and types of one table, the answer of
	 * get_bi_table_schema. Like the tool, it requires the right to edit dashboards globally.
	 */
	#[Quota(BuilderQuotaClass::Read)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function getAction(GetTableSchemaRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(GetTableSchemaOperation::class)->execute(
			$request->tableName,
			$request->schema,
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}
}
