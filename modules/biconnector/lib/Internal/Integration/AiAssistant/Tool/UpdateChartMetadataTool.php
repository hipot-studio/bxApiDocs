<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\UpdateChartOperation;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

final class UpdateChartMetadataTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'update_chart_metadata';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Modify;
	}

	public function getDescription(): string
	{
		return 'Updates chart metadata (name and/or description) without touching its '
			. 'visualization config. Does NOT modify visualization type, dataset, '
			. 'metrics, dimensions, filters, or any other config field — for those '
			. 'use `replace_chart_config`. '
			. 'At least one of `name` or `description` must be provided. '
			. 'To clear the description pass an empty string. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview of the change (old name/description vs the '
			. 'new values) and changes nothing. Relay it to the user, get EXPLICIT approval, '
			. 'then call again with `confirm=true`. Never set `confirm=true` on your own, never '
			. 'chain the two calls, never infer approval from earlier conversation. '
			. 'Returns the preview on the first call, and the updated chart id, name, and '
			. 'description on the confirmed call.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'description' => 'Chart id to update (from list_charts). Positive integer.',
					'minimum' => 1,
				],
				'name' => [
					'type' => 'string',
					'description' => 'New chart display name. Must be non-empty if provided. '
						. 'Omit to keep current name.',
					'minLength' => 1,
				],
				'description' => [
					'type' => 'string',
					'description' => 'New chart description. Pass an empty string to clear '
						. 'the existing description. Omit to keep current.',
				],
				'confirm' => [
					'type' => 'boolean',
					'description' => 'Set to true ONLY after the user has explicitly approved this metadata change. '
						. 'Omit or set false to only preview. Do NOT self-confirm or assume approval from earlier context.',
				],
			],
			'required' => ['chartId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_OPERATIONS, $userId))
		{
			return $this->legacyExecuteStructured($userId, ...$args);
		}

		self::requireBiconnectorModule();

		$chartId = (int)($args['chartId'] ?? 0);
		$changes = array_intersect_key($args, array_flip(['name', 'description']));
		$actor = new Actor($userId, Channel::Mcp);
		// Resolved per call: aiassistant keeps a tool as a process-wide singleton.
		$operation = ServiceLocator::getInstance()->get(UpdateChartOperation::class);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			$preview = $operation->preview($chartId, $changes, $actor);
			if (!$preview->isSuccess())
			{
				throw self::toMcpException($preview);
			}

			return
				[
					'requiresConfirmation' => true,
					'action' => 'update_chart_metadata',
				]
				+ $preview->getData()
				+ [
					'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call '
						. 'update_chart_metadata again with the same arguments and confirm=true.',
				]
			;
		}

		$result = $operation->execute(
			$chartId,
			$changes,
			$actor,
			(new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_ALIGNMENT, $userId),
		);
		if (!$result->isSuccess())
		{
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

		$fields = [];

		$hasName = array_key_exists('name', $args);
		$hasDescription = array_key_exists('description', $args);

		if (!$hasName && !$hasDescription)
		{
			throw new McpException(
				'Nothing to update. Provide at least one of `name` or `description`. '
				. 'To modify the chart configuration use `replace_chart_config` instead.'
			);
		}

		if ($hasName)
		{
			if (!is_string($args['name']) || trim($args['name']) === '')
			{
				throw new McpException('`name` must be a non-empty string. Omit it to keep the current name.');
			}
			$fields['slice_name'] = trim($args['name']);
		}

		if ($hasDescription)
		{
			if (!is_string($args['description']))
			{
				throw new McpException('`description` must be a string. Pass an empty string to clear it.');
			}
			$fields['description'] = $args['description'];
		}

		$integrator = IntegratorFactory::getInstance();

		// Load the chart before branching so the object-level scope is enforced on
		// both the preview and the confirmed call (the confirm branch does not
		// reload the chart) and the preview can echo the current values.
		$chartResponse = $integrator->getChartById($chartId);
		if ($chartResponse->hasErrors())
		{
			throw self::unavailableBiException(
				$chartResponse->getErrors(),
				['stage' => 'update_chart_metadata.load', 'user_id' => $userId, 'chart_id' => $chartId],
			);
		}

		$chartData = $chartResponse->getData();
		if (!is_array($chartData))
		{
			$chartData = [];
		}

		// Object-level scope: only a user who can edit one of the chart's consumer
		// dashboards may update it (matches the web UI).
		$this->assertChartEditableByDashboards(
			$userId,
			is_array($chartData['dashboards'] ?? null) ? $chartData['dashboards'] : [],
		);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			$preview = [
				'requiresConfirmation' => true,
				'action' => 'update_chart_metadata',
				'id' => $chartId,
			];

			if ($hasName)
			{
				$preview['name'] = [
					'old' => (string)($chartData['slice_name'] ?? $chartData['chart_name'] ?? ''),
					'new' => $fields['slice_name'],
				];
			}
			if ($hasDescription)
			{
				$preview['description'] = [
					'old' => (string)($chartData['description'] ?? ''),
					'new' => $fields['description'],
				];
			}

			$preview['confirmHint'] =
				'Show this preview to the user; only AFTER they explicitly approve, call update_chart_metadata again with the same arguments and confirm=true.';

			return $preview;
		}

		$response = $integrator->updateChart($chartId, $fields);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'update_chart_metadata',
					'user_id' => $userId,
					'chart_id' => $chartId,
				],
			);
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		$name = $data['slice_name'] ?? $data['chart_name'] ?? $fields['slice_name'] ?? null;
		if ((new FlagGate())->isEnabled(FlagGate::BUILDER_MCP_ALIGNMENT, $userId))
		{
			$name ??= $chartData['slice_name'] ?? $chartData['chart_name'] ?? null;
		}

		$result = [
			'id' => $chartId,
			'name' => (string)($name ?? ''),
		];

		if ($hasDescription)
		{
			$result['description'] = (string)($data['description'] ?? $fields['description'] ?? '');
		}

		return $result;
	}
}
