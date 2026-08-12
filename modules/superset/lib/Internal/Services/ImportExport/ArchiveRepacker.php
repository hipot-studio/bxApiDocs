<?php

namespace Bitrix\Superset\Internal\Services\ImportExport;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\IO;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\FileNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Json;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\HttpStatus;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ArchiveRepacker
{
	public function repackDashboard(
		array $dashboardFile,
		string $databaseFileName,
		string $databaseContent,
		string $databaseUuid,
		?string $dashboardUuid,
		string $lang = 'en',
		string $currency = '',
	): Result
	{
		$result = new Result();

		$ds = DIRECTORY_SEPARATOR;
		$extractResult = $this->extractSupersetEntity($dashboardFile);
		if (!$extractResult->isSuccess())
		{
			return $result->addErrors([new Error('cannot extract superset entity'), ...$extractResult->getErrors()]);
		}

		$prepareResult = $this->prepareEntityImportDirectory(
			entityDir: $extractResult->getData(),
			dirListForDelete: ['databases'],
			entityLangId: $lang,
			loadDatasets: false,
			culturePlaceholders: ['#CURRENCY#' => $currency],
		);
		if (!$prepareResult->isSuccess())
		{
			return $result->addErrors([new Error('cannot prepare dir for dashboard repack'), ...$prepareResult->getErrors()]);
		}

		/** @var IO\Directory $baseDirectory */
		$baseDirectory = $extractResult->getData()['entity'];
		$databaseDirectory = $baseDirectory->createSubdirectory('databases');
		file_put_contents($databaseDirectory->getPhysicalPath() . $ds . $databaseFileName . '.yaml', $databaseContent);

		$updateDatasetsResult = $this->updateDatasets(
			$baseDirectory->getPhysicalPath() . $ds . 'datasets' . $ds . $databaseFileName,
			$databaseUuid
		);
		if (!$updateDatasetsResult->isSuccess())
		{
			$result->addErrors($updateDatasetsResult->getErrors());

			return $result;
		}

		$metadata = new IO\File($baseDirectory->getPhysicalPath() . $ds . 'metadata.yaml');
		if ($metadata->isExists())
		{
			$value = Yaml::parseFile($metadata->getPhysicalPath(), Yaml::PARSE_OBJECT_FOR_MAP);
			$value->type = 'Dashboard';
			file_put_contents(
				$metadata->getPhysicalPath(),
				Yaml::dump($value, flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)
			);
		}

		$slugs = [];
		$dashboardDirectory = new IO\Directory($baseDirectory->getPhysicalPath() . $ds . 'dashboards');
		if (!$dashboardDirectory->isExists())
		{
			$result->addError(new Error('Dashboards directory not found'));

			return $result;
		}

		foreach ($dashboardDirectory->getChildren() as $dashboard)
		{
			if ($dashboard->isFile())
			{
				$slug = Random::getString(10);
				$slugs[] = $slug;

				$value = Yaml::parseFile($dashboard->getPhysicalPath(), Yaml::PARSE_OBJECT_FOR_MAP);
				$value->slug = $slug;
				if ($dashboardUuid)
				{
					$value->uuid = $dashboardUuid;
				}
				file_put_contents(
					$dashboard->getPhysicalPath(),
					Yaml::dump($value, flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)
				);
			}
		}

		$packResult = self::pack($extractResult->getData()['target_dir']);
		if ($packResult->isSuccess())
		{
			$newFilePath = $packResult->getData()['filePath'];
		}
		else
		{
			$result->addErrors($packResult->getErrors());

			return $result;
		}

		$result->setData([
			'newFilePath' => $newFilePath,
			'slugs' => $slugs,
			'usedLangId' => $prepareResult->getData()['usedLangId'] ?? $lang,
		]);

		return $result;
	}

	public function repackDataset(
		array $datasetFile,
		string $databaseFileName,
		string $databaseContent,
		string $databaseUuid,
		Api\Dataset $api,
		string $lang = 'en',
		string $currency = '',
		bool $forceImport = false,
	): Result
	{
		$result = new Result();

		$ds = DIRECTORY_SEPARATOR;

		$extractResult = $this->extractSupersetEntity($datasetFile);
		if (!$extractResult->isSuccess())
		{
			return $result->addErrors([new Error('cannot extract superset entity'), ...$extractResult->getErrors()]);
		}

		/** @var IO\Directory $baseDirectory */
		$baseDirectory = $extractResult->getData()['entity'];

		$actualizeResult = $this->actualizeDashboards($baseDirectory->getPhysicalPath() . $ds . 'datasets' . $ds . $databaseFileName, $api, $forceImport);
		if (!$actualizeResult->isSuccess())
		{
			return $result->addErrors([
				new Error('Cannot actualize dashboards'),
				...$actualizeResult->getErrors(),
			]);
		}

		if (empty($actualizeResult->getData()['requireImportDatasets']))
		{
			return $result->setData([
				'noDatasets' => true,
			]);
		}

		$prepareResult = $this->prepareEntityImportDirectory(
			entityDir: $extractResult->getData(),
			dirListForDelete: ['dashboards', 'charts', 'databases'],
			entityLangId: $lang,
			loadDatasets: true,
			culturePlaceholders: ['#CURRENCY#' => $currency],
		);
		if (!$prepareResult->isSuccess())
		{
			return $result->addErrors([new Error('Cannot prepare dir for dataset repack'), ...$prepareResult->getErrors()]);
		}

		$databaseDirectory = $baseDirectory->createSubdirectory('databases');
		file_put_contents($databaseDirectory->getPhysicalPath() . $ds . $databaseFileName . '.yaml', $databaseContent);

		$updateDatasetsResult = $this->updateDatasets(
			$baseDirectory->getPhysicalPath() . $ds . 'datasets' . $ds . $databaseFileName,
			$databaseUuid
		);
		if (!$updateDatasetsResult->isSuccess())
		{
			$result->addErrors($updateDatasetsResult->getErrors());

			return $result;
		}

		$metadata = new IO\File($baseDirectory->getPhysicalPath() . $ds . 'metadata.yaml');
		if ($metadata->isExists())
		{
			$value = Yaml::parseFile($metadata->getPhysicalPath(), Yaml::PARSE_OBJECT_FOR_MAP);
			$value->type = 'SqlaTable';
			file_put_contents(
				$metadata->getPhysicalPath(),
				Yaml::dump($value, flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)
			);
		}

		$packResult = self::pack($extractResult->getData()['target_dir']);
		if ($packResult->isSuccess())
		{
			$newFilePath = $packResult->getData()['filePath'];
		}
		else
		{
			$result->addErrors($packResult->getErrors());

			return $result;
		}

		$result->setData([
			'newFilePath' => $newFilePath,
			'usedLangId' => $prepareResult->getData()['usedLangId'] ?? $lang,
		]);

		return $result;
	}

	public function repackChart(
		array $chartFile,
		string $databaseFileName,
		string $databaseContent,
		string $databaseUuid,
		string $lang = 'en',
		string $currency = '',
	): Result
	{
		$result = new Result();

		$ds = DIRECTORY_SEPARATOR;
		$extractResult = $this->extractSupersetEntity($chartFile);
		if (!$extractResult->isSuccess())
		{
			return $result->addErrors([new Error('Cannot extract superset entity'), ...$extractResult->getErrors()]);
		}

		$prepareResult = $this->prepareEntityImportDirectory(
			entityDir: $extractResult->getData(),
			dirListForDelete: ['dashboards', 'databases'],
			entityLangId: $lang,
			loadDatasets: false,
			culturePlaceholders: ['#CURRENCY#' => $currency],
		);

		if (!$prepareResult->isSuccess())
		{
			return $result->addErrors([new Error('Cannot prepare dir for chart repack'), ...$prepareResult->getErrors()]);
		}

		/** @var IO\Directory $baseDirectory */
		$baseDirectory = $extractResult->getData()['entity'];
		$databaseDirectory = $baseDirectory->createSubdirectory('databases');
		file_put_contents($databaseDirectory->getPhysicalPath() . $ds . $databaseFileName . '.yaml', $databaseContent);

		$updateDatasetsResult = $this->updateDatasets(
			$baseDirectory->getPhysicalPath() . $ds . 'datasets' . $ds . $databaseFileName,
			$databaseUuid
		);
		if (!$updateDatasetsResult->isSuccess())
		{
			$result->addErrors($updateDatasetsResult->getErrors());

			return $result;
		}

		$metadata = new IO\File($baseDirectory->getPhysicalPath() . $ds . 'metadata.yaml');
		if ($metadata->isExists())
		{
			$value = Yaml::parseFile($metadata->getPhysicalPath(), Yaml::PARSE_OBJECT_FOR_MAP);
			$value->type = 'Slice';
			file_put_contents(
				$metadata->getPhysicalPath(),
				Yaml::dump($value, flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)
			);
		}

		$packResult = self::pack($extractResult->getData()['target_dir']);
		if ($packResult->isSuccess())
		{
			$newFilePath = $packResult->getData()['filePath'];
		}
		else
		{
			$result->addErrors($packResult->getErrors());

			return $result;
		}

		$chartUuids = [];
		$chartDirectory = new IO\Directory($baseDirectory->getPhysicalPath() . $ds . 'charts');
		if ($chartDirectory->isExists())
		{
			foreach ($chartDirectory->getChildren() as $chartYaml)
			{
				$chartData = Yaml::parseFile($chartYaml->getPhysicalPath(), Yaml::PARSE_OBJECT_FOR_MAP);
				$chartUuids[] = $chartData->uuid;
			}
		}

		$result->setData([
			'newFilePath' => $newFilePath,
			'chartUuids' => $chartUuids,
			'usedLangId' => $prepareResult->getData()['usedLangId'] ?? $lang,
		]);

		return $result;
	}

	public function repackDashboardForMarket(string $content, array $dashboardSettings): Result
	{
		$result = new Result();

		$ds = DIRECTORY_SEPARATOR;
		$fileName = 'dashboard.zip';

		$targetDir = \CTempFile::GetDirectoryName(1);
		CheckDirPath($targetDir);
		$targetDir = IO\Path::normalize($targetDir) . $ds;
		$filePath = $targetDir . $fileName;

		$contentSize = File::putFileContents($filePath, $content);
		if ((int)$contentSize <= 0)
		{
			$result->addError(new Error('Content is empty'));

			return $result;
		}

		$baseDirectory = new IO\Directory($targetDir);
		$repackResult = $this->repackWithoutDatabases($baseDirectory, $filePath);
		if (!$repackResult->isSuccess())
		{
			$result->addErrors($repackResult->getErrors());

			return $result;
		}

		$createStructureResult = $this->createMarketStructure($baseDirectory, $dashboardSettings);
		if (!$createStructureResult->isSuccess())
		{
			$result->addErrors($createStructureResult->getErrors());

			return $result;
		}

		$packResult = self::pack($baseDirectory->getPhysicalPath() . $ds);
		if (!$packResult->isSuccess())
		{
			$result->addErrors($packResult->getErrors());

			return $result;
		}

		foreach ($baseDirectory->getChildren() as $child)
		{
			$child->delete();
		}

		$archivePath = $packResult->getData()['filePath'];
		$file = new File($archivePath);
		$file->rename($baseDirectory->getPhysicalPath() . $ds . $file->getName());

		$result->setData([
			'content' => base64_encode(File::getFileContents($file->getPath())),
		]);

		return $result;
	}

	public function saveUploadedFile(array $pathToDashboard): Result
	{
		$result = new Result();

		$dashboardFileId = \CFile::SaveFile($pathToDashboard, 'superset');
		if (!$dashboardFileId)
		{
			$result->addError(new Error('Zip file not saved'));

			return $result;
		}

		$pathToDashboardZip = \CFile::GetByID($dashboardFileId)->fetch();
		if (!$pathToDashboardZip)
		{
			\CFile::Delete($dashboardFileId);
			$result->addError(new Error('Zip file not found'));

			return $result;
		}

		$result->setData([
			'id' => $dashboardFileId,
			'file' => $pathToDashboardZip,
		]);

		return $result;
	}

	private function actualizeDashboards(string $pathToDatasets, Api\Dataset $api, bool $forceImport = false): Result
	{
		$result = new Result();

		$datasetDirectory = new IO\Directory($pathToDatasets);
		if (!$datasetDirectory->isExists())
		{
			return $result->addError(new Error("Datasets directory '{$datasetDirectory->getName()}' not found"));
		}

		$datasetListResult = $api->getDatasetsList();
		if (
			!$datasetListResult->isSuccess()
			|| $datasetListResult->getHttpStatus() !== HttpStatus::OK
		)
		{
			return $result->addErrors([
				new Error("Cannot get list of existing datasets on superset instance (response code {$datasetListResult->getHttpStatus()})"),
				new Error($datasetListResult->getAnswer()),
				...$datasetListResult->getErrors(),
			]);
		}

		try
		{
			$listAnswer = Json::decode($datasetListResult->getAnswer());
			if (!isset($listAnswer['result']) || !is_array($listAnswer['result']))
			{
				throw new ArgumentException("Dataset list must contain 'result' array field");
			}
		}
		catch (ArgumentException $e)
		{
			return $result->addError(new Error("Cannot decode dataset list answer: {$e->getMessage()}"));
		}

		$supersetDatasets = [];
		foreach ($listAnswer['result'] as $datasetInfoRaw)
		{
			$datasetInfo = [
				'id' => $datasetInfoRaw['id'],
				'table_name' => $datasetInfoRaw['table_name'],
			];

			try
			{
				if (!empty($datasetInfoRaw['extra']))
				{
					$extra = Json::decode($datasetInfoRaw['extra']);
					$datasetInfo['version'] = $extra['version'] ?? 0;
				}
			}
			catch (ArgumentException)
			{
			}

			$datasetInfo['version'] ??= 0;
			$datasetInfo['version'] = (int)$datasetInfo['version'];

			$supersetDatasets[$datasetInfo['table_name']] = $datasetInfo;
		}

		$requireImportDatasets = [];
		foreach ($datasetDirectory->getChildren() as $dataset)
		{
			if (!$dataset->isFile())
			{
				continue;
			}

			$datasetContent = Yaml::parseFile($dataset->getPhysicalPath(), Yaml::PARSE_OBJECT_FOR_MAP);
			$archiveDatasetVersion = (int)($datasetContent?->extra?->version ?? 0);
			$supersetDatasetVersion = (int)($supersetDatasets[$datasetContent->table_name]['version'] ?? 0);

			if (($supersetDatasetVersion > $archiveDatasetVersion)
				|| (
					!$forceImport
					&& ($supersetDatasetVersion === $archiveDatasetVersion)
					&& ($supersetDatasetVersion !== 0)
				)
			)
			{
				$dataset->delete();
			}
			else
			{
				$requireImportDatasets[] = $datasetContent->table_name;
			}
		}

		return $result->setData(['requireImportDatasets' => $requireImportDatasets]);
	}

	private function repackWithoutDatabases(IO\Directory $baseDirectory, string $filePath): Result
	{
		$result = new Result();
		$ds = DIRECTORY_SEPARATOR;
		$extractResult = self::extract($baseDirectory->getPhysicalPath(), $filePath);
		File::deleteFile($filePath);
		if (!$extractResult->isSuccess())
		{
			$result->addErrors($extractResult->getErrors());

			return $result;
		}

		if (!$baseDirectory->isExists())
		{
			$result->addError(new Error("Directory {$baseDirectory->getPhysicalPath()} is not exists."));

			return $result;
		}

		$childDirectory = current($baseDirectory->getChildren());
		$childDirectory = new IO\Directory($childDirectory->getPhysicalPath());
		foreach ($childDirectory->getChildren() as $child)
		{
			if (
				$child->isExists()
				&& $child->isDirectory()
				&& $child->getName() === 'databases'
			)
			{
				$child->delete();
			}
		}

		$packResult = self::pack($baseDirectory->getPhysicalPath() . $ds);
		if (!$packResult->isSuccess())
		{
			$result->addErrors($packResult->getErrors());

			return $result;
		}

		foreach ($baseDirectory->getChildren() as $child)
		{
			$child->delete();
		}

		$archivePath = $packResult->getData()['filePath'];
		$file = new File($archivePath);
		$file->rename($baseDirectory->getPhysicalPath() . $ds . $file->getName());

		return $result;
	}

	private function createMarketStructure(IO\Directory $baseDirectory, array $dashboardSettings): Result
	{
		$result = new Result();
		$ds = DIRECTORY_SEPARATOR;
		$archivePath = current($baseDirectory->getChildren())->getPath();
		$archiveContent = File::getFileContents($archivePath);
		if (!$archiveContent)
		{
			$result->addError(new Error("Archive content is empty: $archivePath"));

			return $result;
		}

		File::putFileContents(
			$baseDirectory->getPhysicalPath() . $ds . 'files' . $ds . '1',
			$archiveContent
		);
		File::deleteFile($archivePath);

		$jsonMetadata = [
			'type' => 'APACHE_SUPERSET',
			'fileId' => 1,
			'dashboardSettings' => $dashboardSettings,
		];
		File::putFileContents(
			$baseDirectory->getPhysicalPath() . $ds . 'BUSINESS_INTELLIGENCE' . $ds . '0.json',
			Json::encode($jsonMetadata)
		);

		File::putFileContents(
			$baseDirectory->getPhysicalPath() . $ds . 'files.json',
			'{"1": {"NAME": "dashboard.zip", "ID": 1}}'
		);

		File::putFileContents(
			$baseDirectory->getPhysicalPath() . $ds . 'manifest.json',
			'{"CODE": "bi_superset", "VERSION": 1, "USES": ["bi"]}'
		);

		return $result;
	}

	private static function extract(string $targetDir, string $filePath): Result
	{
		$result = new Result();

		$zipOrigin = new \ZipArchive();
		if ($zipOrigin->open($filePath) !== true)
		{
			$result->addError(new Error('Zip file not opened'));

			return $result;
		}

		if (!$zipOrigin->extractTo($targetDir))
		{
			$result->addError(new Error('Zip file not extracted'));
			$zipOrigin->close();

			return $result;
		}

		$zipOrigin->close();

		return $result;
	}

	private static function pack(string $targetDirectory): Result
	{
		$result = new Result();

		$ds = DIRECTORY_SEPARATOR;

		$newName = md5(uniqid('sr', true)) . '.zip';
		$filePath = IO\Path::normalize($targetDirectory . '..' . $ds . $newName);

		$repackedZip = new \ZipArchive();
		if ($repackedZip->open($filePath, \ZipArchive::CREATE) !== true)
		{
			$result->addError(new Error('Zip file not created'));

			return $result;
		}

		$source = realpath($targetDirectory);
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($files as $file)
		{
			$file = str_replace('\\', '/', (string)$file);
			$file = realpath($file);

			if (is_dir($file) === true)
			{
				$repackedZip->addEmptyDir(str_replace('\\', '/', str_replace($source . $ds, '', $file . $ds)));
			}
			elseif (is_file($file) === true)
			{
				$repackedZip->addFile($file, str_replace('\\', '/', str_replace($source . $ds, '', $file)));
			}
		}

		$repackedZip->close();

		$result->setData([
			'filePath' => $filePath,
		]);

		return $result;
	}

	private function updateDatasets(string $pathToDataset, string $databaseUuid): Result
	{
		$result = new Result();

		$datasetDirectory = new IO\Directory($pathToDataset);
		if ($datasetDirectory->isExists())
		{
			foreach ($datasetDirectory->getChildren() as $dataset)
			{
				if ($dataset->isFile())
				{
					$value = Yaml::parseFile($dataset->getPhysicalPath(), Yaml::PARSE_OBJECT_FOR_MAP);
					$value->database_uuid = $databaseUuid;
					if ($value->template_params === '')
					{
						$value->template_params = null;
					}
					file_put_contents(
						$dataset->getPhysicalPath(),
						Yaml::dump($value, flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)
					);
				}
			}
		}
		else
		{
			$result->addError(new Error('Datasets directory "' . $datasetDirectory->getName() . '" not found'));

			return $result;
		}

		return $result;
	}

	private function extractSupersetEntity(array $entityFile): Result
	{
		$result = new Result();

		$targetDir = \CTempFile::getDirectoryName(1, 'superset_repack' . DIRECTORY_SEPARATOR . md5(uniqid('sr', true)));
		checkDirPath($targetDir);
		$targetDir = IO\Path::normalize($targetDir) . DIRECTORY_SEPARATOR;

		$extractResult = self::extract($targetDir, $_SERVER['DOCUMENT_ROOT'] . $entityFile['SRC']);
		if (!$extractResult->isSuccess())
		{
			$result->addErrors($extractResult->getErrors());

			return $result;
		}

		$baseDirectory = new IO\Directory($targetDir);
		foreach ($baseDirectory->getChildren() as $child)
		{
			if ($child->getName() === 'langs')
			{
				$langsDir = new IO\Directory($child->getPhysicalPath());
			}
			else
			{
				$baseDirectory = new IO\Directory($child->getPhysicalPath());
			}
		}

		return $result->setData([
			'target_dir' => $targetDir,
			'entity' => $baseDirectory,
			'langs' => $langsDir ?? null,
		]);
	}

	private function prepareEntityImportDirectory(
		array $entityDir,
		array $dirListForDelete,
		string $entityLangId,
		bool $loadDatasets = false,
		array $culturePlaceholders = []
	): Result
	{
		$ds = DIRECTORY_SEPARATOR;

		$result = new Result();

		/** @var IO\Directory $baseDirectory */
		$baseDirectory = $entityDir['entity'];
		$langsDirectory = $entityDir['langs'] ?? null;

		foreach ($dirListForDelete as $directoryName)
		{
			$directory = new IO\Directory($baseDirectory->getPhysicalPath() . $ds . $directoryName);
			if ($directory->isExists())
			{
				$directory->delete();
			}
		}

		if (isset($langsDirectory))
		{
			$secondLang = $this->getSecondLang($entityLangId);

			if (
				file_exists($langsDirectory->getPath() . $ds . "{$entityLangId}.json")
				|| file_exists($langsDirectory->getPath() . $ds . "{$secondLang}.json")
			)
			{
				$localizeResult = $this->localizeFileSystemEntries(
					$baseDirectory->getChildren(),
					$langsDirectory,
					$entityLangId,
					$culturePlaceholders
				);
				if (!$localizeResult->isSuccess())
				{
					return $result->addErrors([new Error('Cannot localize base entity'), ...$localizeResult->getErrors()]);
				}

				$result->setData([
					'usedLangId' => $localizeResult->getData()['usedLangId'] ?? $entityLangId,
				]);
			}

			$datasetsLangsDir = new IO\Directory($langsDirectory->getPath() . $ds . 'datasets');
			$datasetsDir = new IO\Directory($baseDirectory->getPath() . $ds . 'datasets' . $ds . 'trino');
			if ($loadDatasets && $datasetsDir->isExists() && $datasetsLangsDir->isExists())
			{
				$injectDatasetsResult = $this->injectDatasetsPhrases(
					$datasetsDir,
					$datasetsLangsDir,
					$entityLangId,
					$culturePlaceholders
				);
				if (!$injectDatasetsResult->isSuccess())
				{
					return $result->addErrors([new Error('Cannot localize entity datasets'), ...$injectDatasetsResult->getErrors()]);
				}

				$result->setData([
					'usedLangId' => $injectDatasetsResult->getData()['usedLangId'] ?? $entityLangId,
				]);
			}
		}

		return $result;
	}

	private function injectPhrasesToEntityDir(IO\Directory $directory, array $phrases): Result
	{
		$result = new Result();

		try
		{
			$files = $directory->getChildren();
		}
		catch (FileNotFoundException $e)
		{
			return $result->addError(new Error("cannot inject phrases for directory {$directory->getPhysicalPath()}: {$e->getMessage()}"));
		}

		foreach ($files as $file)
		{
			$iterResult = null;
			if ($file->isDirectory())
			{
				$iterResult = $this->injectPhrasesToEntityDir(new IO\Directory($file->getPhysicalPath()), $phrases);
			}
			elseif ($file->isFile())
			{
				$iterResult = $this->injectPhrasesToEntityFile(new IO\File($file->getPhysicalPath()), $phrases);
			}

			if (isset($iterResult) && !$iterResult->isSuccess())
			{
				return $result->addErrors($iterResult->getErrors());
			}
		}

		return $result;
	}

	private function injectPhrasesToEntityFile(IO\File $file, array $phrases): Result
	{
		$result = new Result();
		try
		{
			$fileContent = (array)Yaml::parseFile($file->getPhysicalPath(), Yaml::PARSE_OBJECT_FOR_MAP);
		}
		catch (ParseException $e)
		{
			return $result->addError(new Error("cannot inject phrases for file {$file->getPhysicalPath()}: {$e->getMessage()}"));
		}

		$fileContent = $this->injectPhrasesToYamlContent($fileContent, $phrases);

		$writeResult = file_put_contents(
			$file->getPhysicalPath(),
			Yaml::dump($fileContent, inline: 100, indent: 2, flags: Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)
		);

		if (!$writeResult)
		{
			$result->addError(new Error("cannot re-write yaml content after phrases inject in file {$file->getPhysicalPath()}"));
		}

		return $result;
	}

	private function injectPhrasesToYamlContent(array $target, array $phrases): array
	{
		$replaceList = [];
		foreach ($target as $key => &$value)
		{
			if (is_object($value) && empty((array)$value))
			{
				continue;
			}

			if (is_object($value))
			{
				$value = (array)$value;
			}

			if (is_array($value))
			{
				$value = $this->injectPhrasesToYamlContent($value, $phrases);
			}
			elseif (is_string($value))
			{
				$value = $this->compileStringWithPhrases($value, $phrases);
			}

			if (is_string($key))
			{
				$newKey = $this->compileStringWithPhrases($key, $phrases);
				if ($key !== $newKey)
				{
					$replaceList[$key] = $newKey;
				}
			}
		}

		foreach ($replaceList as $oldKey => $newKey)
		{
			if (isset($target[$newKey]))
			{
				continue;
			}

			$val = $target[$oldKey];
			unset($target[$oldKey]);
			$target[$newKey] = $val;
		}

		return $target;
	}

	private function compileStringWithPhrases(string $innerString, array $phrases): string
	{
		$matches = [];
		preg_match_all("#{{([a-zA-Z0-9_-]+)}}#", $innerString, $matches);

		if (!is_array($matches[1]) || empty($matches[1]))
		{
			return $innerString;
		}

		$foundKeys = array_unique($matches[1]);
		foreach ($foundKeys as $key)
		{
			if (isset($phrases[$key]))
			{
				$innerString = str_replace('{{' . $key . '}}', $phrases[$key], $innerString);
			}
		}

		return $innerString;
	}

	private function loadPhrases(IO\Directory $phrasesDir, string $langId): Result
	{
		$result = new Result();
		try
		{
			$files = $phrasesDir->getChildren();
		}
		catch (FileNotFoundException $e)
		{
			return $result->addError(new Error("cannot load phrases dir: {$e->getMessage()}"));
		}

		$langFile = null;
		foreach ($files as $file)
		{
			if ($file->getName() === "{$langId}.json")
			{
				$langFile = $file;
			}
		}

		if ($langFile === null)
		{
			return $result->addError(new Error("cannot find phrases file for '{$langId}'"));
		}

		$content = IO\File::getFileContents($langFile->getPath());
		try
		{
			$phrases = Json::decode($content);
		}
		catch (ArgumentException $e)
		{
			return $result->addError(new Error("cannot parse lang phrases for '{$langId}': {$e->getMessage()}"));
		}

		$result->setData([
			'phrases' => $phrases,
		]);

		return $result;
	}

	private function injectDatasetsPhrases(
		IO\Directory $datasetsDir,
		IO\Directory $datasetsLangDir,
		string $entityLangId,
		array $placeholders = []
	): Result
	{
		$result = new Result();
		foreach ($datasetsDir->getChildren() as $datasetFile)
		{
			if (!$datasetFile->isFile())
			{
				continue;
			}

			$datasetLangsPath = $this->resolveDatasetLangsPath($datasetFile, $datasetsLangDir);
			if ($datasetLangsPath === null)
			{
				continue;
			}

			$replaceResult = $this->localizeFileSystemEntries(
				[$datasetFile],
				new IO\Directory($datasetLangsPath),
				$entityLangId,
				$placeholders,
			);
			if (!$replaceResult->isSuccess())
			{
				$result->addErrors([
					new Error("Cannot inject phrases for dataset '{$datasetFile->getName()}'"),
					...$replaceResult->getErrors(),
				]);
			}

			$result->setData([
				'usedLangId' => $replaceResult->getData()['usedLangId'] ?? $entityLangId,
			]);
		}

		return $result;
	}

	/**
	 * Superset v6 export adds dataset id to filename (e.g. task_eff_103.yaml), while
	 * the langs directory is packed by table_name (e.g. langs/datasets/task_eff/).
	 * Match by table_name from yaml first, fall back to filename for legacy archives.
	 */
	private function resolveDatasetLangsPath(IO\FileSystemEntry $datasetFile, IO\Directory $datasetsLangDir): ?string
	{
		$candidates = [];
		try
		{
			$content = Yaml::parseFile($datasetFile->getPhysicalPath(), Yaml::PARSE_OBJECT_FOR_MAP);
			if (is_object($content) && !empty($content->table_name))
			{
				$candidates[] = (string)$content->table_name;
			}
		}
		catch (ParseException)
		{
		}

		$candidates[] = str_replace('.yaml', '', $datasetFile->getName());

		foreach ($candidates as $candidate)
		{
			$normalizedCandidate = trim(str_replace('\\', '/', $candidate), '/');
			if (
				$normalizedCandidate === ''
				|| $normalizedCandidate === '.'
				|| $normalizedCandidate === '..'
				|| str_contains($normalizedCandidate, '/')
			)
			{
				continue;
			}

			$candidatePath = $datasetsLangDir->getPath() . DIRECTORY_SEPARATOR . $normalizedCandidate;
			if (is_dir($candidatePath))
			{
				return $candidatePath;
			}
		}

		return null;
	}

	private function localizeFileSystemEntries(
		array $entries,
		IO\Directory $langsDir,
		string $entityLangId,
		array $placeholders = []
	): Result
	{
		$result = new Result();
		$phrasesLoadResult = $this->loadPhrases($langsDir, $entityLangId);
		$phrasesLoadErrors = [];

		$usedLangId = $entityLangId;
		$secondLang = $this->getSecondLang($entityLangId);
		if (!$phrasesLoadResult->isSuccess() && $secondLang !== $entityLangId)
		{
			$phrasesLoadErrors = $phrasesLoadResult->getErrors();
			$phrasesLoadResult = $this->loadPhrases($langsDir, $secondLang);
			$usedLangId = $secondLang;
		}

		if (!$phrasesLoadResult->isSuccess())
		{
			$phrasesLoadErrors = [...$phrasesLoadErrors, ...$phrasesLoadResult->getErrors()];

			return $result->addErrors([new Error("Cannot load langs for localize entity (try to load {$entityLangId}&en)"), ...$phrasesLoadErrors]);
		}

		$phrases = $phrasesLoadResult->getData()['phrases'];
		if (!empty($placeholders))
		{
			foreach ($placeholders as $placeholderName => $placeholderValue)
			{
				foreach ($phrases as $phraseCode => $phraseValue)
				{
					$phrases[$phraseCode] = str_replace($placeholderName, $placeholderValue, $phraseValue);
				}
			}
		}

		foreach ($entries as $entry)
		{
			$localizeResult = $this->injectPhrasesToFileSystemEntry($entry, $phrases);
			if (!$localizeResult->isSuccess())
			{
				$result->addErrors($localizeResult->getErrors());
			}
		}

		if (!$result->isSuccess())
		{
			$result->addErrors([new Error('Error while localize entries'), ...$result->getErrors()]);
		}

		$result->setData([
			'usedLangId' => $usedLangId,
		]);

		return $result;
	}

	private function getSecondLang(string $mainLangId): string
	{
		if (in_array($mainLangId, ['by', 'kz', 'ru', 'uz'], true))
		{
			return 'ru';
		}

		return 'en';
	}

	private function injectPhrasesToFileSystemEntry(IO\FileSystemEntry $entry, array $phrases): Result
	{
		$result = new Result();
		if ($entry->isFile())
		{
			$file = new IO\File($entry->getPhysicalPath());
			$injectResult = $this->injectPhrasesToEntityFile($file, $phrases);
			if (!$injectResult->isSuccess())
			{
				$result->addErrors($injectResult->getErrors());
			}
		}
		elseif ($entry->isDirectory())
		{
			$dir = new IO\Directory($entry->getPhysicalPath());
			$injectResult = $this->injectPhrasesToEntityDir($dir, $phrases);
			if (!$injectResult->isSuccess())
			{
				$result->addErrors($injectResult->getErrors());
			}
		}

		return $result;
	}
}
