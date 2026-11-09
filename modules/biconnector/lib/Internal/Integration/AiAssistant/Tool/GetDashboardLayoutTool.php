<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\ValidationException;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\RestAvailability;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\BIConnector\Internal\Operation\Builder\Layout\GetLayoutOperation;

final class GetDashboardLayoutTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'get_dashboard_layout';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
	}

	public function getDescription(): string
	{
		return 'Reads the layout of an accessible BI dashboard: tabs, rows, charts, sizes, headers, warnings '
			. 'and version. Does not change the dashboard. Use its version when calling apply_dashboard_layout.';
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
			],
			'required' => ['dashboardId'],
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
		$operation = ServiceLocator::getInstance()->get(GetLayoutOperation::class);
		$result = $operation->execute((int)$args['dashboardId'], new Actor($userId, Channel::Mcp));
		if (!$result->isSuccess())
		{
			throw new ValidationException($result->getErrors()[0]->getMessage());
		}

		return $result->getData();
	}
}
