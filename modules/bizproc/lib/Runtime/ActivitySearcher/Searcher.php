<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Runtime\ActivitySearcher;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Mixins\ActivityFilterChecker;
use Bitrix\Bizproc\RestActivityTable;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Activity\Mixins\ActivityDescriptionBuilder;
use Bitrix\Main\IO;
use Bitrix\Main\Loader;
use CBPRuntime;

class Searcher
{
	use ActivityDescriptionBuilder;

	private const DESCRIPTION_FILE_NAME = '.description.php';
	private const AI_DESCRIPTION_FILE_NAME = '.ai.php';

	private readonly array $folders;

	private array $loadedActivities = [];

	public function __construct()
	{
		$root = $_SERVER['DOCUMENT_ROOT'];
		$this->folders = [
			$root . '/local/activities',
			$root . '/local/activities/custom',
			$root . BX_ROOT . '/activities/custom',
			$root . BX_ROOT . '/activities/bitrix',
			$root . BX_ROOT . '/modules/bizproc/activities',
		];

		Loader::requireModule('ui');
	}

	private function addLoadedActivity(string $code): void
	{
		$this->loadedActivities[$code] = true;
	}

	private function isLoadedActivity(string $code): bool
	{
		return isset($this->loadedActivities[$code]);
	}

	public function getLoadedActivities(): array
	{
		return array_keys($this->loadedActivities);
	}

	public function searchByType(string|array $type, ?array $documentType = null): Activities
	{
		$targetTypes = array_map(
			static fn($t) => mb_strtolower(trim((string)$t)),
			\CBPHelper::flatten($type),
		);

		$activities = new Activities();
		foreach ($this->folders as $folder)
		{
			$directory = new IO\Directory($folder);
			if ($directory->isExists())
			{
				foreach ($directory->getChildren() as $dir)
				{
					if (!$dir->isDirectory())
					{
						continue;
					}

					$dirName = $dir->getName();
					$key = mb_strtolower($dirName);
					if ($activities->has($key))
					{
						continue;
					}

					if (!IO\File::isFileExists($dir->getPath() . '/' . self::DESCRIPTION_FILE_NAME))
					{
						continue;
					}

					$description = $this->includeActivityDescription($folder, $dirName, $documentType);
					//Support multiple types
					$activityType = (array)($description['TYPE'] ?? null);
					foreach ($activityType as $i => $singleType)
					{
						$activityType[$i] = mb_strtolower(trim($singleType));
					}

					if (count(array_intersect($targetTypes, $activityType)) > 0)
					{
						$description['PATH_TO_ACTIVITY'] = $folder . '/' . $dirName;
						$activities->add($key, $this->buildActivityDescription($description));
					}
				}
			}
		}

		$restTypes = [];
		if (in_array(ActivityType::ACTIVITY->value, $targetTypes, true))
		{
			$restTypes[] = ActivityType::ACTIVITY;
			$restTypes[] = ActivityType::ROBOT;
		}
		if (in_array(ActivityType::ROBOT->value, $targetTypes, true))
		{
			$restTypes[] = ActivityType::ROBOT;
		}
		if ($restTypes)
		{
			$activities->addCollection($this->searchRestByType($restTypes));
		}

		return $activities;
	}

	public function searchByCode(string $code, ?string $lang = null): ?ActivityDescription
	{
		if (!$this->isCorrectActivityCode($code))
		{
			return null;
		}

		$normalizedCode = $this->normalizeActivityCode($code);
		if (!$normalizedCode)
		{
			return null;
		}

		if ($this->isRestActivityCode($normalizedCode))
		{
			$activity = $this->findRestActivityByInternalCode($this->extractRestInternalCode($normalizedCode), ['*']);
			if (!$activity)
			{
				return null;
			}

			return $this->buildRestActivityDescription($activity, $lang);
		}

		[, $dirPath] = $this->findActivityFile($normalizedCode);
		if (empty($dirPath))
		{
			return null;
		}

		$activityDescription = $this->includeActivityDescriptionByDirectoryPath($dirPath);
		if (empty($activityDescription))
		{
			return null;
		}

		return $this
			->buildActivityDescription($activityDescription)
			->setPathToActivity($dirPath)
		;
	}

	/**
	 * @param ActivityType|ActivityType[] $type
	 * @param string|null $lang
	 *
	 * @return Activities
	 */
	public function searchRestByType(ActivityType | array $type, ?string $lang = null): Activities
	{
		$targetTypes = array_filter(
			array_map(
				static fn($t) => $t instanceof ActivityType ? $t : null,
				\CBPHelper::flatten($type),
			),
		);

		$activities = [];

		if (in_array(ActivityType::ACTIVITY, $targetTypes, true))
		{
			$iterator = RestActivityTable::getList(['filter' => ['=IS_ROBOT' => 'N'], 'cache' => ['ttl' => 3600]]);
			while ($activity = $iterator->fetch())
			{
				$key = CBPRuntime::REST_ACTIVITY_PREFIX . $activity['INTERNAL_CODE'];
				$activities[$key] = $this->buildRestActivityDescription($activity, $lang);
			}
		}

		if (in_array(ActivityType::ROBOT, $targetTypes, true))
		{
			$iterator = RestActivityTable::getList(['filter' => ['=IS_ROBOT' => 'Y'], 'cache' => ['ttl' => 3600]]);
			while ($activity = $iterator->fetch())
			{
				$key = CBPRuntime::REST_ACTIVITY_PREFIX . $activity['INTERNAL_CODE'];
				$activities[$key] = $this->buildRestRobotDescription($activity, $lang);
			}
		}

		return new Activities($activities);
	}

	public function isActivityExists(string $code): bool
	{
		if (!$this->isCorrectActivityCode($code))
		{
			return false;
		}

		$normalizedCode = $this->normalizeActivityCode($code);
		if (!$normalizedCode)
		{
			return false;
		}

		if ($this->isRestActivityCode($normalizedCode))
		{
			return (bool)$this->findRestActivityByInternalCode($this->extractRestInternalCode($normalizedCode));
		}

		[$fileName] = $this->findActivityFile($normalizedCode);

		return $fileName !== null;
	}

	public function includeActivityFile(string $code): bool | string
	{
		$normalizedCode = $this->normalizeActivityCode($code);
		if ($this->isLoadedActivity($normalizedCode))
		{
			return $normalizedCode;
		}

		$isRestActivity = $this->isRestActivityCode($normalizedCode);

		if (
			!$this->isCorrectActivityCode($normalizedCode)
			|| (!$this->isActivityExists($normalizedCode) && !$isRestActivity)
		)
		{
			return false;
		}

		if ($isRestActivity)
		{
			$internalCode = $this->extractRestInternalCode($normalizedCode);
			$activity = $this->findRestActivityByInternalCode($internalCode);

			eval(
				'class CBP'
				. CBPRuntime::REST_ACTIVITY_PREFIX
				. $internalCode
				. ' extends CBPRestActivity {const REST_ACTIVITY_ID = '
				. ($activity ? $activity['ID'] : 0)
				. ';}'
			);

			$restLoadedActivity = CBPRuntime::REST_ACTIVITY_PREFIX . $internalCode;
			$this->addLoadedActivity($restLoadedActivity);

			return $restLoadedActivity;
		}

		[$filePath, $dirPath] = $this->findActivityFile($normalizedCode);
		$this->loadLocalization($dirPath, $normalizedCode . '.php');
		include_once($filePath);

		$this->addLoadedActivity($normalizedCode);

		return $normalizedCode;
	}

	public function includeActivityAiDescriptionFile(string $code): bool
	{
		$normalizedCode = $this->normalizeActivityCode($code);
		if (!$this->isCorrectActivityCode($normalizedCode))
		{
			return false;
		}

		[$filePath, $dirPath] = $this->findActivityAiDescriptionFile($normalizedCode);
		if (!$filePath)
		{
			return false;
		}

		$this->loadLocalization($dirPath, static::AI_DESCRIPTION_FILE_NAME);
		include_once($filePath);

		return true;
	}

	/**
	 * @param string $code
	 * @return array{0: string|null, 1: string|null}
	 */
	private function findActivityFile(string $code): array
	{
		foreach ($this->folders as $folder)
		{
			$fileName = $folder . '/' . $code . '/' . $code . '.php';
			if (IO\File::isFileExists($fileName))
			{
				return [$fileName, $folder . '/' . $code];
			}
		}

		return [null, null];
	}

	/**
	 * @param string $code
	 *
	 * * @return array{0: string|null, 1: string|null}
	 */
	private function findActivityAiDescriptionFile(string $code): array
	{
		foreach ($this->folders as $folder)
		{
			$fileName = $folder . '/' . $code . '/' . static::AI_DESCRIPTION_FILE_NAME;
			if (IO\File::isFileExists($fileName))
			{
				return [$fileName, $folder . '/' . $code];
			}
		}

		return [null, null];
	}

	public function normalizeActivityCode(string $code): string
	{
		$lowerCode = mb_strtolower($code);
		if (str_starts_with($lowerCode, 'cbp'))
		{
			$lowerCode = mb_substr($lowerCode, 3);
		}

		return $lowerCode;
	}

	private function isRestActivityCode(string $code): bool
	{
		return str_starts_with($code, CBPRuntime::REST_ACTIVITY_PREFIX);
	}

	private function isCorrectActivityCode(string $code): bool
	{
		return !(empty($code) || preg_match("#\W#", $code));
	}

	private function extractRestInternalCode(string $code): string
	{
		return mb_substr($code, mb_strlen(CBPRuntime::REST_ACTIVITY_PREFIX));
	}

	private function findRestActivityByInternalCode(string $internalCode, array $fieldsToSelect = ['ID']): ?array
	{
		$activity = RestActivityTable::getList([
			'select' => $fieldsToSelect,
			'filter' => ['=INTERNAL_CODE' => $internalCode],
			'cache' => ['ttl' => 3600],
			'limit' => 1,
		])->fetch();

		return is_array($activity) ? $activity : null;
	}

	private function includeActivityDescription(string $folder, string $dir, ?array $documentType): array
	{
		$arActivityDescription = []; // forbidden to rename
		$this->loadLocalization($folder . '/' . $dir, self::DESCRIPTION_FILE_NAME);
		include($folder . '/' . $dir . '/' . self::DESCRIPTION_FILE_NAME);

		return is_array($arActivityDescription) ? $arActivityDescription : [];
	}

	private function includeActivityDescriptionByDirectoryPath(string $dirPath): array
	{
		$arActivityDescription = []; // forbidden to rename
		$this->loadLocalization($dirPath, self::DESCRIPTION_FILE_NAME);
		include($dirPath . '/' . self::DESCRIPTION_FILE_NAME);

		return is_array($arActivityDescription) ? $arActivityDescription : [];
	}

	private function loadLocalization(string $path, string $filename): void
	{
		\Bitrix\Main\Localization\Loc::loadLanguageFile($path . '/' . $filename);
	}
}
