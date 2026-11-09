<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Chart;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\EntityLookup;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Operation\Builder\SupersetIdentity;
use Bitrix\Main\Result;

/**
 * Name and description of a chart, as update_chart_metadata changes them, with its checks and texts.
 * The chart is loaded on both steps, so the right on its dashboards is checked on the preview as well.
 */
final class UpdateChartOperation
{
	private readonly ChartWriteGuard $guard;
	private readonly SupersetIdentity $identity;

	public function __construct(?ChartWriteGuard $guard = null, ?SupersetIdentity $identity = null)
	{
		$this->guard = $guard ?? new ChartWriteGuard();
		$this->identity = $identity ?? new SupersetIdentity();
	}

	/**
	 * @param array $changes `name` and/or `description` as the client sent them; a missing key keeps the value.
	 *
	 * Data: `id` and, for each key of $changes, `name` / `description` as `{old, new}`.
	 */
	public function preview(int $chartId, array $changes, Actor $actor): Result
	{
		$loaded = $this->load($chartId, $changes, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		['fields' => $fields, 'chart' => $chartData] = $loaded->getData();

		$preview = ['id' => $chartId];
		if (array_key_exists('slice_name', $fields))
		{
			$preview['name'] = [
				'old' => (string)($chartData['slice_name'] ?? $chartData['chart_name'] ?? ''),
				'new' => $fields['slice_name'],
			];
		}
		if (array_key_exists('description', $fields))
		{
			$preview['description'] = [
				'old' => (string)($chartData['description'] ?? ''),
				'new' => $fields['description'],
			];
		}

		return (new Result())->setData($preview);
	}

	/**
	 * Data: the answer of update_chart_metadata — `id`, `name` and, when it was changed, `description`.
	 *
	 * @param bool $nameFromLoadedChart When Superset answers without a name and the name was not changed,
	 * `name` is the one of the chart as loaded before the update rather than an empty string.
	 */
	public function execute(int $chartId, array $changes, Actor $actor, bool $nameFromLoadedChart = false): Result
	{
		$loaded = $this->load($chartId, $changes, $actor);
		if (!$loaded->isSuccess())
		{
			return $loaded;
		}

		['fields' => $fields, 'chart' => $chartData] = $loaded->getData();

		$response = IntegratorFactory::getInstance()->updateChart($chartId, $fields);
		$classified = (new EntityLookup())->classify($response, $chartId);
		if (!$classified->isSuccess())
		{
			return $classified;
		}

		$data = $response->getData();
		if (!is_array($data))
		{
			$data = [];
		}

		$name = $data['slice_name'] ?? $data['chart_name'] ?? $fields['slice_name'] ?? null;
		if ($nameFromLoadedChart)
		{
			$name ??= $chartData['slice_name'] ?? $chartData['chart_name'] ?? null;
		}

		$result = [
			'id' => $chartId,
			'name' => (string)($name ?? ''),
		];
		if (array_key_exists('description', $fields))
		{
			$result['description'] = (string)($data['description'] ?? $fields['description'] ?? '');
		}

		return (new Result())->setData($result);
	}

	private function load(int $chartId, array $changes, Actor $actor): Result
	{
		$allowed = $this->guard->forChart($chartId, $actor);
		if (!$allowed->isSuccess())
		{
			return $allowed;
		}

		$fields = self::readFields($changes);
		if (!$fields->isSuccess())
		{
			return $fields;
		}

		$identityResult = $this->identity->forRead($actor->userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$lookup = (new EntityLookup())->chart($chartId, $identityResult->getData()['user']);
		if (!$lookup->isSuccess())
		{
			return $lookup;
		}

		$chartData = $lookup->getData()['entity'];
		if (!is_array($chartData['dashboards'] ?? []))
		{
			return Refusal::unavailableBi([], ['stage' => 'update_chart_metadata.load', 'chart_id' => $chartId]);
		}

		$editable = $this->guard->checkEditable(
			$chartData['dashboards'] ?? [],
			$actor,
		);
		if (!$editable->isSuccess())
		{
			return $editable;
		}

		return (new Result())->setData(['fields' => $fields->getData(), 'chart' => $chartData]);
	}

	/**
	 * The Superset fields of the change: `slice_name`, `description`.
	 */
	private static function readFields(array $changes): Result
	{
		$hasName = array_key_exists('name', $changes);
		$hasDescription = array_key_exists('description', $changes);

		if (!$hasName && !$hasDescription)
		{
			return Refusal::error(
				FailureCode::InvalidArgument,
				'Nothing to update. Provide at least one of `name` or `description`. '
				. 'To modify the chart configuration use `replace_chart_config` instead.',
			);
		}

		$fields = [];
		if ($hasName)
		{
			if (!is_string($changes['name']) || trim($changes['name']) === '')
			{
				return Refusal::error(
					FailureCode::InvalidArgument,
					'`name` must be a non-empty string. Omit it to keep the current name.',
				);
			}
			$fields['slice_name'] = trim($changes['name']);
		}

		if ($hasDescription)
		{
			if (!is_string($changes['description']))
			{
				return Refusal::error(
					FailureCode::InvalidArgument,
					'`description` must be a string. Pass an empty string to clear it.',
				);
			}
			$fields['description'] = $changes['description'];
		}

		return (new Result())->setData($fields);
	}
}
