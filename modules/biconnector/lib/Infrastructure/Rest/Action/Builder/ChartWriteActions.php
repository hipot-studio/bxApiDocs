<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Action\Builder;

use Bitrix\BIConnector\Infrastructure\Rest\Attribute\FailureCodes;
use Bitrix\BIConnector\Infrastructure\Rest\Attribute\Quota;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart\CreateChartRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart\DeleteChartRequest;
use Bitrix\BIConnector\Infrastructure\Rest\Request\Builder\Chart\UpdateChartRequest;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\CreateChartOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\DeleteChartOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\UpdateChartOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Services\Builder\BuilderQuotaClass;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;

/**
 * biconnector.builder.chart.add, .update and .delete: the answers of create_chart, update_chart_metadata
 * and delete_chart. There is no confirmation step, the change is made at once.
 *
 * An existing chart is updated or deleted only by a caller with the edit right on at least one dashboard that
 * uses it; the status of that dashboard is not checked, as the tools do not check it. A new chart is on no
 * dashboard yet, so .add takes the global ACTION_BIC_DASHBOARD_EDIT.
 */
trait ChartWriteActions
{
	use BuilderOperationSupport;

	/**
	 * A new chart over a dataset, built from the high-level config; it is placed on no dashboard.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::RateLimit,
	)]
	public function addAction(CreateChartRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(CreateChartOperation::class)->execute(
			$request->toConfig(),
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}

	/**
	 * New name and/or description of a chart; its configuration is kept.
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
	public function updateAction(UpdateChartRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$this->requireResultData(self::checkSomethingToUpdate($request));

		$changes = [];
		if ($request->name !== null)
		{
			$changes['name'] = $request->name;
		}
		if ($request->description !== null)
		{
			$changes['description'] = $request->description;
		}

		$result = ServiceLocator::getInstance()->get(UpdateChartOperation::class)->execute(
			$request->chartId,
			$changes,
			$actor,
			$this->createFlagGate()->isEnabled(FlagGate::BUILDER_MCP_ALIGNMENT, $actor->userId),
		);

		return new ArrayResponse($this->answer($result, $request->chartId));
	}

	/**
	 * Told here rather than by the operation: its text sends the agent to the MCP tools, a REST caller
	 * is sent to the REST method.
	 */
	private static function checkSomethingToUpdate(UpdateChartRequest $request): Result
	{
		$result = new Result();
		if ($request->name === null && $request->description === null)
		{
			$result->addError(new Error(
				'Provide at least one of: name, description. To modify the chart configuration use '
				. 'biconnector.builder.chart.config.set.',
				FailureCode::InvalidArgument->value,
			));
		}

		return $result;
	}

	/**
	 * Deletes a chart for good and takes it off every dashboard. A chart that is already gone answers
	 * `already_deleted`.
	 */
	#[Quota(BuilderQuotaClass::Write)]
	#[FailureCodes(
		FailureCode::AccessDenied,
		FailureCode::FeatureDisabled,
		FailureCode::Unavailable,
		FailureCode::InvalidArgument,
		FailureCode::DestructiveBudget,
		FailureCode::RateLimit,
	)]
	public function deleteAction(DeleteChartRequest $request): ArrayResponse
	{
		$actor = $this->createAvailableActor();

		$result = ServiceLocator::getInstance()->get(DeleteChartOperation::class)->execute(
			$request->chartId,
			$actor,
		);

		return new ArrayResponse($this->answer($result));
	}
}
