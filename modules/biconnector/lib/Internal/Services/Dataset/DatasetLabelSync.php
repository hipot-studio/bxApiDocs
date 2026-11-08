<?php

namespace Bitrix\BIConnector\Internal\Services\Dataset;

use Bitrix\BIConnector\Integration\Superset\CultureFormatter;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorInterface;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Error;

/**
 * Fills `verbose_name` and `description` of the physical datasets of the Superset instance.
 *
 * Datasets are enumerated from Superset itself, not walked through dashboards, so the ones attached
 * to nothing are covered too. Each field is decided on its own by {@see self::isFieldWritable()};
 * a system table is told from a user dataset by its technical name, see {@see UserDatasetNameProvider}.
 *
 * Best-effort by contract: never throws, and a per-dataset error is logged without aborting the sweep.
 */
final class DatasetLabelSync
{
	private IntegratorInterface $integrator;
	private VerboseNameResolver $resolver;

	private TableFullDescriptionProvider $fullDescriptionProvider;
	private UserDatasetNameProvider $userDatasetNameProvider;

	public function __construct(
		?IntegratorInterface $integrator = null,
		?VerboseNameResolver $resolver = null,
		?TableFullDescriptionProvider $fullDescriptionProvider = null,
		?UserDatasetNameProvider $userDatasetNameProvider = null,
	)
	{
		$this->integrator = $integrator ?? IntegratorFactory::getInstance();
		$this->resolver = $resolver ?? new VerboseNameResolver();
		$this->fullDescriptionProvider = $fullDescriptionProvider ?? new TableFullDescriptionProvider();
		$this->userDatasetNameProvider = $userDatasetNameProvider ?? new UserDatasetNameProvider();
	}

	/**
	 * Label every physical dataset of the instance. Best-effort, never throws.
	 *
	 * @return array{enumerated: bool, checked: int, filled: int, failed: int} Run summary: whether the
	 *         physical dataset list was fetched, how many datasets were examined, how many were filled
	 *         (verbose_name and/or description) and how many failed to update.
	 */
	public function syncAllPhysicalDatasets(): array
	{
		try
		{
			return $this->syncLabels();
		}
		catch (\Throwable $e)
		{
			Logger::logErrors([new Error($e->getMessage())], [
				'message' => 'Dataset label sync failed',
			]);

			return ['enumerated' => false, 'checked' => 0, 'filled' => 0, 'failed' => 0];
		}
	}

	/**
	 * @return array{enumerated: bool, checked: int, filled: int, failed: int}
	 */
	private function syncLabels(): array
	{
		$summary = ['enumerated' => false, 'checked' => 0, 'filled' => 0, 'failed' => 0];

		$response = $this->integrator->getPhysicalDatasetList();
		if ($response->hasErrors())
		{
			Logger::logWarning($response->getErrors(), [
				'message' => 'Dataset label sync: cannot enumerate physical datasets',
				'status' => $response->getStatus(),
			]);

			return $summary;
		}

		$datasets = $response->getData();
		if (!is_array($datasets))
		{
			return $summary;
		}

		$summary['enumerated'] = true;
		$dashboardLang = CultureFormatter::getLanguageCode();
		$userDatasetNames = array_flip($this->userDatasetNameProvider->getNames());

		foreach ($datasets as $dataset)
		{
			if (!is_array($dataset))
			{
				continue;
			}

			$tableName = (string)($dataset['table_name'] ?? '');
			$datasetId = (int)($dataset['id'] ?? 0);
			if ($tableName === '' || $datasetId <= 0)
			{
				continue;
			}

			$summary['checked']++;

			$fields = [];
			$isSystemTable = !isset($userDatasetNames[$tableName]);

			// Relies on the physical dataset list carrying `verbose_name` and `description` (Superset
			// REST list_columns); an absent value reads as empty.
			$currentName = trim((string)($dataset['verbose_name'] ?? ''));
			$name = $this->resolver->resolveVerboseName($tableName);
			if ($name !== null && $this->isFieldWritable($isSystemTable, $currentName, $name))
			{
				$fields['verbose_name'] = $name;
			}

			$currentDescription = trim((string)($dataset['description'] ?? ''));
			$description = $this->fullDescriptionProvider->getDescription($tableName, $dashboardLang);
			if ($description !== null && $this->isFieldWritable($isSystemTable, $currentDescription, $description))
			{
				$fields['description'] = $description;
			}

			if ($fields === [])
			{
				continue;
			}

			$updateResponse = $this->integrator->updateDataset($datasetId, $fields);
			if ($updateResponse->hasErrors())
			{
				Logger::logWarning($updateResponse->getErrors(), [
					'message' => 'Dataset label sync: dataset update failed',
					'dataset_id' => $datasetId,
					'status' => $updateResponse->getStatus(),
				]);

				$summary['failed']++;

				continue;
			}

			$summary['filled']++;
		}

		return $summary;
	}

	/**
	 * The labels of a system table belong to the portal, so the resolved value wins and is written
	 * whenever it differs. A user dataset carries text of its own: there the policy is fill-if-empty.
	 */
	private function isFieldWritable(bool $isSystemTable, string $currentValue, string $resolvedValue): bool
	{
		if ($isSystemTable)
		{
			return $resolvedValue !== $currentValue;
		}

		return $currentValue === '';
	}
}
