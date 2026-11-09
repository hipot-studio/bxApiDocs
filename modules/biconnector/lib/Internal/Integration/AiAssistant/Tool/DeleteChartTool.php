<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Argument\BuilderArgumentContract;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\DeleteChartOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\EntityLookup;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class DeleteChartTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'delete_chart';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Destructive;
	}

	public function getDescription(): string
	{
		return 'Permanently deletes a chart from BI Constructor (Apache Superset). '
			. 'DESTRUCTIVE and irreversible. The chart is also removed from every '
			. 'dashboard it is placed on; the dashboards themselves and their other '
			. 'charts are NOT deleted. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview of what will be deleted (chart name and the '
			. 'dashboards it sits on) and changes nothing. Relay it to the user, get EXPLICIT '
			. 'approval, then call again with `confirm=true`. Never set `confirm=true` on your '
			. 'own, never chain the two calls, never infer approval from earlier conversation. '
			. 'Returns the preview on the first call, and `{deleted:true, id, name}` on '
			. 'the confirmed call. Idempotent: deleting an already-deleted chart is a safe '
			. 'no-op and returns `{deleted:true, already_deleted:true}`.';
	}

	public function getInputSchema(): array
	{
		$schema = BuilderArgumentContract::schema(
			'biconnector.builder.chart.delete',
			'mcp',
			[
				'chartId' => 'Chart id to delete (from list_charts). Positive integer.',
			],
		);
		$schema['properties']['confirm'] = [
			'type' => 'boolean',
			'description' => 'Set to true ONLY after the user has explicitly approved '
				. 'this previewed deletion. Omit or set false to only preview the impact. '
				. 'Do NOT self-confirm or assume approval from earlier context.',
		];

		return $schema;
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		self::requireBiconnectorModule();

		$chartId = (int)($args['chartId'] ?? 0);
		$actor = new Actor($userId, Channel::Mcp);
		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$operation = ServiceLocator::getInstance()->get(DeleteChartOperation::class);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			$preview = $operation->preview($chartId, $actor);
			if (!$preview->isSuccess())
			{
				throw self::toMcpException($preview);
			}

			$data = $preview->getData();
			if ($data['alreadyDeleted'])
			{
				return [
					'deleted' => true,
					'id' => $data['id'],
					'name' => $data['name'],
					'already_deleted' => true,
				];
			}

			return [
				'requiresConfirmation' => true,
				'action' => 'delete_chart',
				'id' => $data['id'],
				'name' => $data['name'],
				'dashboards' => $data['dashboards'],
				'impact' => 'The chart will be permanently deleted and removed from any '
					. 'dashboards it is placed on. This cannot be undone.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call '
					. 'delete_chart again with the same chartId and confirm=true.',
			];
		}

		$result = $operation->execute($chartId, $actor);
		if (!$result->isSuccess())
		{
			// Not OperationRefusal::forTool(): retryAfter of a spent budget stays out of the text, as before.
			throw self::toMcpException($result);
		}

		return $result->getData();
	}

	private function legacyExecuteStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!Feature::isBuilderEnabled())
		{
			throw new McpException('BI Builder feature is not enabled on this portal.');
		}

		$chartId = (int)($args['chartId'] ?? 0);
		if ($chartId <= 0)
		{
			throw new McpException('chartId is required and must be a positive integer.');
		}

		$accessController = $this->getAccessController($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}

		$integrator = IntegratorFactory::getInstance();

		$lookup = (new EntityLookup())->chart($chartId, self::findActAsUser($userId));
		if (!$lookup->isSuccess())
		{
			if ($lookup->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return [
					'deleted' => true,
					'id' => $chartId,
					'name' => '',
					'already_deleted' => true,
				];
			}

			throw self::toMcpException(OperationRefusal::forTool($lookup));
		}

		$chartData = $lookup->getData()['entity'];

		$name = (string)($chartData['slice_name'] ?? $chartData['chart_name'] ?? '');
		$dashboards = $chartData['dashboards'] ?? [];
		if (!is_array($dashboards))
		{
			throw self::unavailableBiException([], ['stage' => 'delete_chart.load', 'chart_id' => $chartId]);
		}

		// Object-level scope: only a user who can edit one of the chart's consumer
		// dashboards may delete it (matches the web UI).
		$this->assertChartEditableByDashboards($userId, $dashboards);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'delete_chart',
				'id' => $chartId,
				'name' => $name,
				'dashboards' => $dashboards,
				'impact' => 'The chart will be permanently deleted and removed from any '
					. 'dashboards it is placed on. This cannot be undone.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call delete_chart again with the same chartId and confirm=true.',
			];
		}

		// Charged here and not earlier: a preview, a denied or an already-deleted
		// call returns above and must not cost the user a destructive slot.
		$this->spendDestructiveBudget($userId, 'chart', $chartId);

		$response = $integrator->deleteChart($chartId);
		$classified = (new EntityLookup())->classify($response, $chartId);
		if (!$classified->isSuccess())
		{
			if ($classified->getErrors()[0]->getCode() === FailureCode::NotFound->value)
			{
				return [
					'deleted' => true,
					'id' => $chartId,
					'name' => $name,
					'already_deleted' => true,
				];
			}

			throw self::toMcpException(OperationRefusal::forTool($classified));
		}

		return [
			'deleted' => true,
			'id' => $chartId,
			'name' => $name,
		];
	}
}
