<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Runtime\ActivitySearcher;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\RestActivityTable;
use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Activity\Mixins\ActivityDescriptionBuilder;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\IO;
use Bitrix\Main\Loader;
use CBPRuntime;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Searcher
{
	use ActivityDescriptionBuilder;

	private const DESCRIPTION_FILE_NAME = '.description.php';
	private const AI_DESCRIPTION_FILE_NAME = '.ai.php';
	private const LOGGER_ID = 'bizproc.runtime.activity_searcher';

	/** Lower-cased: matched against a normalized activity code. {@see self::isNamedAsTrigger()} */
	private const LEGACY_TRIGGER_CODE_SUFFIX = 'trigger';

	private readonly array $folders;

	private array $loadedActivities = [];

	private array $triggerActivityFlags = [];

	/**
	 * Descriptions built from `.description.php`, by normalized code, for the request. A null value is a
	 * remembered miss, told from an unasked code by array_key_exists().
	 *
	 * @var array<string, ActivityDescription|null>
	 */
	private array $fileDescriptions = [];

	private array $includedAiDescriptionFiles = [];

	private ?LoggerInterface $logger = null;

	private ?UnifiedPanelDescriptorProvider $unifiedPanelDescriptorProvider = null;

	public function __construct(?UnifiedPanelDescriptorProvider $unifiedPanelDescriptorProvider = null)
	{
		$this->unifiedPanelDescriptorProvider = $unifiedPanelDescriptorProvider;

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

	private function getLogger(): LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
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

					$activityDescription = $this->includeActivityDescription($folder, $dirName, $documentType);
					if (!$activityDescription)
					{
						continue;
					}

					$activityType = $activityDescription->getType();
					foreach ($activityType as $i => $singleType)
					{
						$activityType[$i] = mb_strtolower(trim($singleType));
					}

					if (count(array_intersect($targetTypes, $activityType)) > 0)
					{
						$this->filterPresetsByType($activityDescription, $targetTypes);
						$activityDescription->setPathToActivity($folder . '/' . $dirName);
						$activities->add($key, $this->applyUnifiedPanelDescriptor($key, $activityDescription));
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

	private function filterPresetsByType(ActivityDescription $description, array $targetTypes): void
	{
		$presets = $description->getPresets();
		if ($presets === null)
		{
			return;
		}

		$filteredPresets = [];
		foreach ($presets as $preset)
		{
			if (array_key_exists('TYPE', $preset))
			{
				$presetTypes = array_map(
					static fn($type) => mb_strtolower(trim((string)$type)),
					\CBPHelper::flatten($preset['TYPE']),
				);
				if (count(array_intersect($targetTypes, $presetTypes)) === 0)
				{
					continue;
				}

				unset($preset['TYPE']);
			}

			$filteredPresets[] = $preset;
		}

		$description->setPresets($filteredPresets);
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
			// A REST activity is not memoized: its description comes from the database, which the request may
			// change, and $lang is part of the answer.
			$activity = $this->findRestActivityByInternalCode($this->extractRestInternalCode($normalizedCode), ['*']);
			if (!$activity)
			{
				return null;
			}

			return $this->buildRestActivityDescription($activity, $lang);
		}

		if (!array_key_exists($normalizedCode, $this->fileDescriptions))
		{
			$this->fileDescriptions[$normalizedCode] = $this->buildFileActivityDescription($normalizedCode);
		}

		return $this->copyOfFileDescription($normalizedCode);
	}

	/** The description as it is remembered: read from the file and already completed with the panel descriptor. */
	private function buildFileActivityDescription(string $normalizedCode): ?ActivityDescription
	{
		[, $dirPath] = $this->findActivityFile($normalizedCode);
		if (empty($dirPath))
		{
			return null;
		}

		$activityDescription = $this->includeActivityDescriptionByDirectoryPath($dirPath);
		if (!$activityDescription)
		{
			return null;
		}

		$activityDescription->setPathToActivity($dirPath);

		return $this->applyUnifiedPanelDescriptor($normalizedCode, $activityDescription);
	}

	/**
	 * A copy and never the remembered instance itself: ActivityDescription is mutable and consumers mutate
	 * what they get. A shallow copy suffices only while the objects a description holds are never modified
	 * in place - a mutable value object added later has to be cloned here too.
	 */
	private function copyOfFileDescription(string $normalizedCode): ?ActivityDescription
	{
		$description = $this->fileDescriptions[$normalizedCode];
		if ($description === null)
		{
			return null;
		}

		$description = clone $description;
		$presets = $description->getPresets();
		if ($presets !== null)
		{
			foreach ($presets as &$preset)
			{
				unset($preset['TYPE']);
			}
			unset($preset);

			$description->setPresets($presets);
		}

		return $description;
	}

	/**
	 * The single point where a description is completed with the unified-panel descriptor, so no consumer
	 * has to. REST activities stay out: they are robots and activities, never nodes or triggers.
	 */
	private function applyUnifiedPanelDescriptor(
		string $normalizedCode,
		ActivityDescription $description,
	): ActivityDescription
	{
		return $this->getUnifiedPanelDescriptorProvider()->enrich(
			$normalizedCode,
			$description,
			$this->isTriggerActivityByDescription($normalizedCode, $description),
		);
	}

	private function getUnifiedPanelDescriptorProvider(): UnifiedPanelDescriptorProvider
	{
		return $this->unifiedPanelDescriptorProvider ??= new UnifiedPanelDescriptorProvider();
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

	/**
	 * The single answer to "is this activity a trigger" - callers must not re-derive it from the code, the
	 * description or the class. A caller already holding the description asks
	 * {@see self::isTriggerActivityByDescription()} instead.
	 */
	public function isTriggerActivity(string $activityCode): bool
	{
		$normalizedCode = $this->normalizeActivityCode($activityCode);
		if (!array_key_exists($normalizedCode, $this->triggerActivityFlags))
		{
			$this->triggerActivityFlags[$normalizedCode] = $this->detectTriggerActivity($normalizedCode);
		}

		return $this->triggerActivityFlags[$normalizedCode];
	}

	/**
	 * The recognition itself, and the only place the class-loading fallback is reached from.
	 *
	 * @param ActivityDescription|null $description Description of the same activity already in hand.
	 */
	private function detectTriggerActivity(string $normalizedCode, ?ActivityDescription $description = null): bool
	{
		// The contract answers straight away for a class this request already loaded - no description lookup,
		// no file read, no localization. This is the common case on the save path, where every node of the
		// template is asked about right after its own class validated its properties.
		// class_exists() without autoload is case-insensitive and $normalizedCode is already lower-cased, so
		// the name matches both the CBPSetField and the CBPsetfield spelling, the same way
		// {@see self::includeActivityFile()} relies on it.
		$declaredClass = 'CBP' . $normalizedCode;
		if (class_exists($declaredClass, false))
		{
			return is_subclass_of($declaredClass, \IBPTriggerActivity::class);
		}

		$description ??= $this->searchByCode($normalizedCode);
		if ($description !== null)
		{
			if (self::declaresTriggerType($description->getType()))
			{
				return true;
			}

			// Cost guard, not a second recognition: a description naming some other category answers without
			// loading a class, which a catalog traversal would otherwise pay for every activity it touches.
			// Triggers declaring no type still reach the contract check below, and so does an activity carrying
			// the legacy `Trigger` name suffix even when its description names another category: that is exactly
			// the set the suffix used to recognize on its own, so a custom trigger written against the old
			// convention keeps being recognized. For shipped activities the guard costs nothing either way -
			// TriggerNamingConventionInventoryTest holds the suffix and the contract to the same set.
			if ($this->declaresAnyActivityType($description) && !$this->isNamedAsTrigger($normalizedCode))
			{
				return false;
			}
		}

		// The contract itself, checked on the class and not on an instance: constructing an activity here
		// would be a side effect.
		try
		{
			$loadedCode = $this->includeActivityFile($normalizedCode);
		}
		catch (\Throwable $e)
		{
			// Recognition is asked while a template is being loaded or saved and must not break it, so an
			// activity that cannot be loaded - a missing module of its own - is no trigger.
			$this->getLogger()->debug(
				'Activity class of {code} could not be loaded while recognizing a trigger — {error}',
				['code' => $normalizedCode, 'error' => $e->getMessage()],
			);

			return false;
		}

		if (!is_string($loadedCode))
		{
			return false;
		}

		return is_subclass_of('CBP' . $loadedCode, \IBPTriggerActivity::class);
	}

	/**
	 * The same verdict as {@see self::isTriggerActivity()}, spared the lookup by the description already in
	 * hand. Both entry points share one request cache.
	 *
	 * @param ActivityDescription $description Description of $activityCode, as searchByCode() returns it.
	 */
	public function isTriggerActivityByDescription(string $activityCode, ActivityDescription $description): bool
	{
		$normalizedCode = $this->normalizeActivityCode($activityCode);
		if (!array_key_exists($normalizedCode, $this->triggerActivityFlags))
		{
			$this->triggerActivityFlags[$normalizedCode] = $this->detectTriggerActivity(
				$normalizedCode,
				$description,
			);
		}

		return $this->triggerActivityFlags[$normalizedCode];
	}

	/**
	 * The single comparison telling whether declared TYPE values name the trigger category. Public and
	 * taking the raw values so a caller holding the description as an array reuses it instead of
	 * comparing on its own.
	 */
	public static function declaresTriggerType(array $declaredTypes): bool
	{
		foreach ($declaredTypes as $type)
		{
			if (mb_strtolower(trim((string)$type)) === ActivityType::TRIGGER->value)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The pre-contract naming convention: an activity whose code ends in `Trigger` was a trigger by its name
	 * alone. Recognition never answers by it, it only refuses to answer "no" for free about such a code.
	 */
	private function isNamedAsTrigger(string $normalizedCode): bool
	{
		return str_ends_with($normalizedCode, self::LEGACY_TRIGGER_CODE_SUFFIX);
	}

	private function declaresAnyActivityType(ActivityDescription $description): bool
	{
		foreach ($description->getType() as $type)
		{
			if (trim((string)$type) !== '')
			{
				return true;
			}
		}

		return false;
	}

	public function includeActivityFile(string $code): bool | string
	{
		$normalizedCode = $this->normalizeActivityCode($code);
		if ($this->isLoadedActivity($normalizedCode))
		{
			return $normalizedCode;
		}


		if ($this->isRestActivityCode($normalizedCode))
		{
			$internalCode = $this->extractRestInternalCode($normalizedCode);
			$activity = $this->findRestActivityByInternalCode($internalCode);

			$restLoadedActivityCode =
				Container::instance()
					->getEvalService()
					->defineRestActivityClass($internalCode, $activity ? (int)$activity['ID'] : 0);
			if ($restLoadedActivityCode)
			{
				$this->addLoadedActivity($restLoadedActivityCode);

				return $restLoadedActivityCode;
			}
		}

		if (
			!$this->isCorrectActivityCode($normalizedCode)
			|| !$this->isActivityExists($normalizedCode)
		)
		{
			return false;
		}

		[$filePath, $dirPath] = $this->findActivityFile($normalizedCode);
		if ($filePath === null)
		{
			return false;
		}

		// Cheap guard: if the expected main activity class is not yet declared by any already-loaded
		// file there is no conflict possible - skip the expensive file-read+regex scan entirely.
		// PHP class_exists() without autoload is case-insensitive; $normalizedCode is already
		// lower-cased by normalizeActivityCode(), so 'CBP' . $normalizedCode correctly matches
		// both CBPSetField and CBPsetfield naming conventions.
		// Only when the class IS already declared do we pay for the full content scan.
		$expectedClass = 'CBP' . $normalizedCode;
		if (class_exists($expectedClass, false) && $this->hasConflictingClassDeclaration($filePath))
		{
			// Including would trigger a non-catchable class-redeclaration fatal.
			// Treat the activity as already loaded so callers can fail on the expected
			// class-exists check rather than breaking the whole request.
			$this->addLoadedActivity($normalizedCode);

			return $normalizedCode;
		}

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

		if (isset($this->includedAiDescriptionFiles[$filePath]))
		{
			return true;
		}

		$this->includedAiDescriptionFiles[$filePath] = true;
		if ($this->hasConflictingClassDeclaration($filePath))
		{
			// including the file would be a non-catchable fatal class redeclaration; the file is reported
			// as present, so the caller fails on the expected class check instead of breaking the whole request
			return true;
		}

		$this->loadLocalization($dirPath, static::AI_DESCRIPTION_FILE_NAME);
		include_once($filePath);

		return true;
	}

	private function hasConflictingClassDeclaration(string $filePath): bool
	{
		$content = IO\File::getFileContents($filePath);
		if (!is_string($content))
		{
			return false;
		}

		// Strip block comments (/* ... */) and line comments (// ...) to avoid false positives
		// from class-like tokens appearing inside comment blocks.
		$stripped = preg_replace('/\/\*.*?\*\//s', '', $content);
		$stripped = preg_replace('/\/\/[^\n]*/m', '', (string)$stripped);
		$stripped = (string)$stripped;

		// Extract the namespace declared in the file (first `namespace` statement).
		// PHP allows only one namespace per file in the common single-namespace pattern.
		$namespace = '';
		if (preg_match('/^\s*namespace\s+([\\\\\w]+)\s*[;{]/m', $stripped, $nsMatch))
		{
			$namespace = trim($nsMatch[1]);
		}

		preg_match_all(
			'/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/mi',
			$stripped,
			$matches
		);
		foreach ($matches[1] as $className)
		{
			if ($namespace !== '')
			{
				// Namespaced declaration: FQN is `Namespace\ClassName`.
				// Only check the FQN to avoid false positives against unrelated global classes.
				if (class_exists($namespace . '\\' . $className, false))
				{
					return true;
				}
			}
			else
			{
				// Global namespace: bare class name IS the FQN.
				if (class_exists($className, false))
				{
					return true;
				}
			}
		}

		return false;
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

	private function includeActivityDescription(string $folder, string $dir, ?array $documentType): ?ActivityDescription
	{
		$arActivityDescription = []; // forbidden to rename
		$this->loadLocalization($folder . '/' . $dir, self::DESCRIPTION_FILE_NAME);

		try
		{
			$result = include($folder . '/' . $dir . '/' . self::DESCRIPTION_FILE_NAME);
		}
		catch (\Throwable $e)
		{
			// A broken .description.php must not crash the whole catalog traversal - skip this activity.
			$this->getLogger()->warning(
				'Skipping activity due to broken .description.php: {path} — {error}',
				['path' => $folder . '/' . $dir . '/' . self::DESCRIPTION_FILE_NAME, 'error' => $e->getMessage()],
			);

			return null;
		}

		if ($result instanceof \Bitrix\Bizproc\Activity\ActivityDescription)
		{
			return $result;
		}

		if (is_array($arActivityDescription) && !empty($arActivityDescription))
		{
			return $this->buildActivityDescription($arActivityDescription);
		}

		return null;
	}

	private function includeActivityDescriptionByDirectoryPath(string $dirPath): ?ActivityDescription
	{
		$arActivityDescription = []; // forbidden to rename
		$this->loadLocalization($dirPath, self::DESCRIPTION_FILE_NAME);

		try
		{
			$result = include($dirPath . '/' . self::DESCRIPTION_FILE_NAME);
		}
		catch (\Throwable $e)
		{
			// A broken .description.php must not crash the whole catalog traversal - skip this activity.
			$this->getLogger()->warning(
				'Skipping activity due to broken .description.php: {path} — {error}',
				['path' => $dirPath . '/' . self::DESCRIPTION_FILE_NAME, 'error' => $e->getMessage()],
			);

			return null;
		}

		if ($result instanceof \Bitrix\Bizproc\Activity\ActivityDescription)
		{
			return $result;
		}

		if (is_array($arActivityDescription) && !empty($arActivityDescription))
		{
			return $this->buildActivityDescription($arActivityDescription);
		}

		return null;
	}

	private function loadLocalization(string $path, string $filename): void
	{
		\Bitrix\Main\Localization\Loc::loadLanguageFile($path . '/' . $filename);
	}
}
