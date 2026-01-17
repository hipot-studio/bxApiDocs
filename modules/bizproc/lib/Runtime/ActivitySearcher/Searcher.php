<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Runtime\ActivitySearcher;

use Bitrix\Bizproc\RestActivityTable;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Activity\Mixins\ActivityDescriptionBuilder;
use Bitrix\Main\Loader;

class Searcher
{
	use ActivityDescriptionBuilder;

	public readonly array $folders;

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

	public function searchByType(string|array $type, ?array $documentType = null): Activities
	{
		$targetTypes = array_map(
			static fn($t) => mb_strtolower(trim((string)$t)),
			\CBPHelper::flatten($type)
		);

		$activities = new Activities();
		foreach ($this->folders as $folder)
		{
			if (is_dir($folder) && $handle = opendir($folder))
			{
				while (false !== ($dir = readdir($handle)))
				{
					if ($dir === '.' || $dir === '..')
					{
						continue;
					}

					if (!is_dir($folder . '/' . $dir))
					{
						continue;
					}

					$key = mb_strtolower($dir);
					if ($activities->has($key))
					{
						continue;
					}

					if (!file_exists($folder . '/' . $dir . '/.description.php'))
					{
						continue;
					}

					$arActivityDescription = $this->includeActivityDescription($folder, $dir, $documentType);

					//Support multiple types
					$activityType = (array)($arActivityDescription['TYPE'] ?? null);
					foreach ($activityType as $i => $singleType)
					{
						$activityType[$i] = mb_strtolower(trim($singleType));
					}

					if (count(array_intersect($targetTypes, $activityType)) > 0)
					{
						$arActivityDescription['PATH_TO_ACTIVITY'] = $folder . '/' . $dir;
						$activities->add($key, $this->buildActivityDescription($arActivityDescription));
					}
				}

				closedir($handle);
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
				\CBPHelper::flatten($type)
			)
		);

		$activities = [];

		if (in_array(ActivityType::ACTIVITY, $targetTypes, true))
		{
			$iterator = RestActivityTable::getList(['filter' => ['=IS_ROBOT' => 'N'], 'cache' => ['ttl' => 3600]]);
			while ($activity = $iterator->fetch())
			{
				$key = \CBPRuntime::REST_ACTIVITY_PREFIX . $activity['INTERNAL_CODE'];
				$activities[$key] = $this->buildRestActivityDescription($activity, $lang);
			}
		}

		if (in_array(ActivityType::ROBOT, $targetTypes, true))
		{
			$iterator = RestActivityTable::getList(['filter' => ['=IS_ROBOT' => 'Y'], 'cache' => ['ttl' => 3600]]);
			while ($activity = $iterator->fetch())
			{
				$key = \CBPRuntime::REST_ACTIVITY_PREFIX . $activity['INTERNAL_CODE'];
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
		if (
			!$this->isCorrectActivityCode($normalizedCode)
			|| (!$this->isActivityExists($normalizedCode) && !$this->isRestActivityCode($normalizedCode))
		)
		{
			return false;
		}

		if ($this->isRestActivityCode($normalizedCode))
		{
			$internalCode = $this->extractRestInternalCode($normalizedCode);
			$activity = $this->findRestActivityByInternalCode($internalCode);
			eval(
				'class CBP'
				. \CBPRuntime::REST_ACTIVITY_PREFIX
				. $internalCode
				. ' extends CBPRestActivity {const REST_ACTIVITY_ID = '
				. ($activity ? $activity['ID'] : 0)
				. ';}'
			);

			return \CBPRuntime::REST_ACTIVITY_PREFIX . $internalCode;
		}

		[$filePath, $dirPath] = $this->findActivityFile($normalizedCode);
		$this->loadLocalization($dirPath, $normalizedCode . '.php');
		include_once($filePath);

		return $normalizedCode;
	}

	private function findActivityFile(string $code): array
	{
		foreach ($this->folders as $folder)
		{
			$fileName = $folder . '/' . $code . '/' . $code . '.php';
			if (file_exists($fileName) && is_file($fileName))
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
		return str_starts_with($code, \CBPRuntime::REST_ACTIVITY_PREFIX);
	}

	private function isCorrectActivityCode(string $code): bool
	{
		if (empty($code) || preg_match("#\W#", $code))
		{
			return false;
		}

		return true;
	}

	private function extractRestInternalCode(string $code): string
	{
		return mb_substr($code, mb_strlen(\CBPRuntime::REST_ACTIVITY_PREFIX));
	}

	private function findRestActivityByInternalCode(string $internalCode): ?array
	{
		$activity = RestActivityTable::getList([
			'select' => ['ID'],
			'filter' => ['=INTERNAL_CODE' => $internalCode],
			'cache' => ['ttl' => 3600],
			'limit' => 1,
		])->fetch();

		return is_array($activity) ? $activity : null;
	}

	private function includeActivityDescription(string $folder, string $dir, ?array $documentType): array
	{
		$arActivityDescription = []; // forbidden to rename
		$this->loadLocalization($folder . '/' . $dir, '.description.php');
		include($folder . '/' . $dir . '/.description.php');

		return is_array($arActivityDescription) ? $arActivityDescription : [];
	}

	private function loadLocalization(string $path, string $filename): void
	{
		\Bitrix\Main\Localization\Loc::loadLanguageFile($path. '/'. $filename);
	}
}
