<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptMaintenance;

use Bitrix\Crm\Copilot\CallScriptMaintenance\Entity\MaintenanceContextTable;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;

final class StateRepository
{
	private const MODULE_ID = 'crm';
	private const OPTION_KEY = 'call_script_maintenance_state';

	public function load(): State
	{
		$raw = (string)Option::get(self::MODULE_ID, self::OPTION_KEY, '');
		if ($raw === '')
		{
			return new State();
		}

		try
		{
			$data = Json::decode($raw);
		}
		catch (\Throwable $e)
		{
			return new State();
		}

		return State::fromArray(is_array($data) ? $data : []);
	}

	public function save(State $state): void
	{
		Option::set(self::MODULE_ID, self::OPTION_KEY, Json::encode($state->toArray()));
	}

	public function registerMaintenanceJob(int $jobId, array $info): void
	{
		if ($jobId <= 0)
		{
			return;
		}

		$type = (string)($info['type'] ?? '');
		unset($info['type']);

		$result = MaintenanceContextTable::add([
			'JOB_ID' => $jobId,
			'TYPE' => $type,
			'DATA' => Json::encode($info),
		]);

		if (!$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: failed to register maintenance context for job {job}: {errors}',
				[
					'class' => self::class,
					'job' => $jobId,
					'errors' => implode('; ', $result->getErrorMessages()),
				],
			);
		}
	}

	public function deleteExpiredContext(int $ttlSeconds): void
	{
		if ($ttlSeconds <= 0)
		{
			return;
		}

		$threshold = (new DateTime())->add('-' . $ttlSeconds . ' seconds');

		MaintenanceContextTable::deleteByFilter(['<CREATED_AT' => $threshold]);
	}

	public function popMaintenanceJob(int $jobId, ?array $expectedTypes = null): ?array
	{
		if ($jobId <= 0)
		{
			return null;
		}

		$row = MaintenanceContextTable::query()
			->setSelect(['ID', 'TYPE', 'DATA'])
			->where('JOB_ID', $jobId)
			->setLimit(1)
			->fetch()
		;

		if (!$row)
		{
			return null;
		}

		$type = (string)($row['TYPE'] ?? '');
		if ($expectedTypes !== null && !in_array($type, $expectedTypes, true))
		{
			return null;
		}

		MaintenanceContextTable::delete((int)$row['ID']);

		$data = [];
		if (!empty($row['DATA']))
		{
			try
			{
				$decoded = Json::decode((string)$row['DATA']);
				if (is_array($decoded))
				{
					$data = $decoded;
				}
			}
			catch (\Throwable $e)
			{
			}
		}

		$criteriaIdsToReplace = $data['criteriaIdsToReplace'] ?? $data['basicCriteriaIds'] ?? [];

		return [
			'type' => $type,
			'selectionIds' => $data['selectionIds'] ?? [],
			'groupName' => $data['groupName'] ?? null,
			'assessmentId' => $data['assessmentId'] ?? null,
			'criteriaIdsToReplace' => array_values(array_filter(
				array_map('intval', (array)$criteriaIdsToReplace),
				static fn(int $id): bool => $id > 0,
			)),
		];
	}
}
