<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\ValidationException;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\RestAvailability;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DraftPolicy;
use Bitrix\BIConnector\Internal\Operation\Builder\Layout\ApplyLayoutCommand;
use Bitrix\BIConnector\Internal\Operation\Builder\Layout\ApplyLayoutOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\Layout\OperationSchema;

final class ApplyDashboardLayoutTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'apply_dashboard_layout';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Applies a batch of layout operations to a custom BI dashboard, atomically and without confirmation. '
			. 'Overwrites the dashboard layout. Read get_dashboard_layout first and pass its current version. '
			. 'With dryRun=true, returns the predicted layout and summary without writing; version is optional. '
			. 'Writing requires a draft dashboard and a version. For a published dashboard, create a copy first. '
			. 'On a stale or busy version conflict, re-read the layout before retrying. '
			. 'Edits made in the Superset UI after this batch reads the dashboard can be overwritten.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Local dashboard id from list_dashboards or create_dashboard.',
				],
				'operations' => OperationSchema::jsonSchema(),
				'version' => [
					'type' => 'string',
					'description' => 'Version from get_dashboard_layout. Required unless dryRun is true.',
				],
				'dryRun' => [
					'type' => 'boolean',
					'description' => 'Predict the result without writing. Defaults to false.',
				],
			],
			'required' => ['dashboardId', 'operations'],
			'additionalProperties' => false,
		];
	}

	public function canList(int $userId): bool
	{
		if ($userId <= 0 || !parent::canList($userId))
		{
			return false;
		}
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_AGENT_SKILL, $userId))
		{
			return false;
		}

		return ServiceLocator::getInstance()->get(RestAvailability::class)
			->check(new Actor($userId, Channel::Mcp))->isSuccess()
		;
	}

	public function canRun(int $userId): bool
	{
		return $this->canList($userId) && parent::canRun($userId);
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$command = new ApplyLayoutCommand(
			(int)$args['dashboardId'],
			$args['operations'],
			$args['version'] ?? null,
			($args['dryRun'] ?? false) === true,
		);
		$operation = ServiceLocator::getInstance()->get(ApplyLayoutOperation::class);
		$result = $operation->execute($command, new Actor($userId, Channel::Mcp), DraftPolicy::Enforce);
		if (!$result->isSuccess())
		{
			throw new ValidationException($result->getErrors()[0]->getMessage());
		}

		return $result->getData();
	}
}
