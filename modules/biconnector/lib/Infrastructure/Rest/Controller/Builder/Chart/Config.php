<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Controller\Builder\Chart;

use Bitrix\BIConnector\Infrastructure\Rest\Action\Builder\BuilderOperationSupport;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Controller\AbstractBuilderController;
use Bitrix\BIConnector\Infrastructure\Rest\Dto\Builder\ChartDto;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart\SetChartConfigRequest;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\ReplaceChartConfigOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * Configuration of a chart, a part of the chart resource.
 */
#[DtoType(ChartDto::class)]
final class Config extends AbstractBuilderController
{
	use BuilderOperationSupport;

	/**
	 * biconnector.builder.chart.config.set: full replace of the configuration of a chart, the answer of
	 * replace_chart_config. The name and the description are kept.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function setAction(SetChartConfigRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(ReplaceChartConfigOperation::class)->execute(
			$request->chartId,
			$request->toConfig(),
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}
}
