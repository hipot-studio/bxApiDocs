<?php

namespace Bitrix\Bizproc\Service;

use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiAutoDescriber;
use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiDescriptionMerger;
use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiPropertyConverter;
use Bitrix\Bizproc\Integration\AiAssistant\Interface\IBPActivityAiContextualDescription;
use Bitrix\Bizproc\Integration\AiAssistant\Interface\IBPActivityAiDescription;
use Bitrix\Bizproc\Integration\AiAssistant\Interface\IBPActivityAiSettingConstraints;
use Bitrix\Bizproc\Internal\Entity\Activity\ActivityAiDescriptionSource;
use Bitrix\Bizproc\Internal\Entity\Activity\Result\ActivityAiDescriptionResult;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCategory;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingConstraintCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOption;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingOptionCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingType;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class AiDescription extends \CBPRuntimeService
{
	public const ERROR_ACTIVITY_FILE_NOT_FOUND = 'AI_DESCRIPTION_ACTIVITY_FILE_NOT_FOUND';
	public const ERROR_CLASS_NOT_FOUND = 'AI_DESCRIPTION_CLASS_NOT_FOUND';
	public const ERROR_INTERFACE_NOT_IMPLEMENTED = 'AI_DESCRIPTION_INTERFACE_NOT_IMPLEMENTED';
	public const ERROR_BUILD_FAILED = 'AI_DESCRIPTION_BUILD_FAILED';
	public const ERROR_INVALID_SETTING_CONSTRAINT = 'AI_DESCRIPTION_INVALID_SETTING_CONSTRAINT';

	private const LOGGER_ID = 'bizproc.aiassistant.activity_description';

	/** @var array<string, Result|ActivityAiDescriptionResult> */
	private array $activityDescriptionCache = [];

	private ?LoggerInterface $logger = null;

	/**
	 * Returns the AI settings schema of the activity. A manual description (`.ai.php` next to the activity)
	 * always takes priority; when it is absent, the schema is derived from the activity's own properties map.
	 * Exceptions of a single activity are isolated into an error Result so that one broken activity
	 * cannot break a whole catalog.
	 *
	 * @param string $code Activity code like delayactivity
	 * @param list<string> $documentType ['module', 'entityType', 'documentType']
	 *
	 * @return Result|ActivityAiDescriptionResult
	 */
	public function getActivityDescription(
		string $code,
		array $documentType,
		array $context = []
	): Result|ActivityAiDescriptionResult
	{
		$cacheKey = $this->makeCacheKey($code, $documentType, $context);
		if ($cacheKey === null)
		{
			// A context that cannot be serialised cannot be keyed either, and answering from a key that
			// silently ignored it would serve one caller's schema to another. Refused as a build failure -
			// the same shape every other failure of a single activity takes here.
			return (new Result())->addError(new Error(
				"Activity '{$code}' AI description context is not serializable",
				self::ERROR_BUILD_FAILED
			));
		}

		if (!isset($this->activityDescriptionCache[$cacheKey]))
		{
			try
			{
				$result = $this->buildActivityDescription($code, $documentType, $context);
			}
			catch (\Throwable $exception)
			{
				$this->getLogger()->error(
					'Activity AI description build failed',
					[
						'activity' => $code,
						'exception' => $exception,
					],
				);
				$result = (new Result())->addError(new Error(
					"Activity '{$code}' AI description build failed",
					self::ERROR_BUILD_FAILED
				));
			}

			$this->activityDescriptionCache[$cacheKey] = $result;
		}

		return $this->activityDescriptionCache[$cacheKey];
	}

	/**
	 * The context belongs in the key: the same activity under the same document type answers with a different
	 * schema once it is pinned to a preset's values (option lists resolved from them), and a key blind to it
	 * would serve the first caller's answer to every other one.
	 *
	 * Null when the context cannot be serialised - a closure or a resource in it makes serialize() throw, and
	 * that throw used to happen outside the guard that turns a single activity's failure into a Result.
	 */
	private function makeCacheKey(string $code, array $documentType, array $context): ?string
	{
		try
		{
			$contextKey = md5(serialize($context));
		}
		catch (\Throwable)
		{
			return null;
		}

		return mb_strtolower($code) . '|' . implode(',', $documentType) . '|' . $contextKey;
	}

	/**
	 * The single construction point of the auto-describer, and the seam the passthrough recovery below is
	 * verified through: no shipped activity fails its map build on demand.
	 */
	protected function createAutoDescriber(): ActivityAiAutoDescriber
	{
		return new ActivityAiAutoDescriber();
	}

	private function buildActivityDescription(
		string $code,
		array $documentType,
		array $context = []
	): Result|ActivityAiDescriptionResult
	{
		$runtime = \CBPRuntime::getRuntime();
		if (!$runtime->includeActivityFile($code))
		{
			return (new Result())->addError(new Error(
				'Activity class file not found',
				self::ERROR_ACTIVITY_FILE_NOT_FOUND
			));
		}

		if (!$runtime->includeActivityAiDescriptionFile($code))
		{
			$autoDescriber = $this->createAutoDescriber();

			// REST path: buildMapSettingsLenient short-circuits to an empty schema for REST codes
			// (they have no PHP file to load a properties map from). Delegate to the full describe()
			// path which reads the real settings schema directly from RestActivityTable.
			if ($autoDescriber->isRestActivityCode($code))
			{
				// No context: a REST activity resolves nothing from a preset's values (see describe()).
				return $autoDescriber->describe($code, $documentType);
			}

			// Lenient path: unresolvable/required properties go to $skipped instead of aborting.
			// This allows legacy file-based activities (NODE_TYPE=null) to get a partial schema
			// rather than being dropped via NOT_SUITABLE.
			[$mapSettings, $mapSkipped] = $autoDescriber->buildMapSettingsLenient(
				$code,
				$documentType,
				$context,
			);

			return $this->makeDescriptionResult(
				code: $code,
				documentType: $documentType,
				autoDescriber: $autoDescriber,
				settings: $mapSettings,
				source: ActivityAiDescriptionSource::Auto,
				skippedSettings: $mapSkipped,
			);
		}

		$classname = $this->getActivityDescriptionClassName($code);
		if (!class_exists($classname))
		{
			return (new Result())->addError(new Error('Ai description class not found', self::ERROR_CLASS_NOT_FOUND));
		}

		$object = new $classname();
		if (!$object instanceof IBPActivityAiDescription)
		{
			return (new Result())->addError(new Error(
				'Ai description class not implements IBPActivityAiDescription interface',
				self::ERROR_INTERFACE_NOT_IMPLEMENTED
			));
		}

		// Hybrid orchestration: map = structural base, .ai.php = quality enrichment.
		try
		{
			// The context reaches the file the same way it reaches the properties map: a schema whose keys are
			// computed from the values the block is pinned to cannot be built without them.
			$manual = $object instanceof IBPActivityAiContextualDescription
				? $object->getAiDescribedSettingsInContext($documentType, $context)
				: $object->getAiDescribedSettings($documentType)
			;
		}
		catch (\Throwable)
		{
			$manual = null;
		}

		$autoDescriber = $this->createAutoDescriber();

		try
		{
			[$mapSettings, $mapSkipped] = $autoDescriber->buildMapSettingsLenient($code, $documentType, $context);
		}
		catch (\Throwable)
		{
			// The schema is lost, the passthrough names are not: they come from the activity's own contract and
			// nothing in the map build stands between us and them. Dropping them here would refuse the block by
			// name on the way back, which costs the agent the whole template.
			$mapSettings = new SettingCollection();
			$mapSkipped = $autoDescriber->passthroughSettingNames($code, $documentType);
		}

		$mapSettings = $this->withoutDeclaredPassthrough($mapSettings, $autoDescriber, $code, $documentType);

		// Stability: if both sources failed/empty -> return a build-failed error.
		if ($manual === null && $mapSettings->count() === 0)
		{
			return (new Result())->addError(new Error(
				"Activity '{$code}' AI description build failed",
				self::ERROR_BUILD_FAILED,
			));
		}

		// Manual-only: map is empty but .ai.php succeeded -> keep source=Manual.
		// $mapSkipped still carries the passthrough names, and they have to reach skippedSettings or
		// AgentSettingNameValidator refuses them by name. Names the file describes are subtracted: a name
		// present in both lists loses its typed check, because passthrough overrides the schema.
		if ($mapSettings->count() === 0)
		{
			$manualNames = [];
			foreach ($manual as $manualSetting)
			{
				$manualNames[] = $manualSetting->name;
			}

			return $this->makeDescriptionResult(
				code: $code,
				documentType: $documentType,
				autoDescriber: $autoDescriber,
				settings: $manual,
				source: ActivityAiDescriptionSource::Manual,
				skippedSettings: array_values(array_diff($mapSkipped, $manualNames)),
				constraintsSource: $object instanceof IBPActivityAiSettingConstraints ? $object : null,
			);
		}

		// Auto-only fallback: .ai.php failed but map is available -> use map with source=Auto.
		if ($manual === null)
		{
			return $this->makeDescriptionResult(
				code: $code,
				documentType: $documentType,
				autoDescriber: $autoDescriber,
				settings: $mapSettings,
				source: ActivityAiDescriptionSource::Auto,
				skippedSettings: $mapSkipped,
			);
		}

		// Hybrid: both sources available -> merge them.
		// mergeWithDropped() also returns names of map-only fields suppressed by G-opt (empty dynamic
		// selects). These names must be included in skippedSettings so that AgentSettingNameValidator
		// accepts them on round-trip and existing template values are not silently lost.
		[$merged, $gOptDropped] = (new ActivityAiDescriptionMerger())->mergeWithDropped($mapSettings, $manual);
		$mergedNames = [];
		foreach ($merged as $mergedSetting)
		{
			$mergedNames[] = $mergedSetting->name;
		}
		$skipped = array_values(array_diff($mapSkipped, $mergedNames));
		if ($gOptDropped)
		{
			$skipped = array_values(array_unique(array_merge($skipped, $gOptDropped)));
		}

		return $this->makeDescriptionResult(
			code: $code,
			documentType: $documentType,
			autoDescriber: $autoDescriber,
			settings: $merged,
			source: ActivityAiDescriptionSource::Hybrid,
			skippedSettings: $skipped,
			constraintsSource: $object instanceof IBPActivityAiSettingConstraints ? $object : null,
		);
	}

	/**
	 * Drops from the map-built base every name the activity itself declares passthrough. Only the described path
	 * takes it: there the file already expresses the schema, so a map entry of the same name publishes a second
	 * copy of it - flat next to the nested one, as the task activity did with its task fields. Without a file the
	 * map is the only description there is, and subtracting from it would leave the block with no schema at all.
	 *
	 * A name the file itself spells out survives the merge unchanged: that copy comes from the description, not
	 * from the map, and it is the richer of the two. The name keeps reaching `skippedSettings` either way, so the
	 * reverse path still accepts it and a template already holding it saves.
	 */
	private function withoutDeclaredPassthrough(
		SettingCollection $settings,
		ActivityAiAutoDescriber $autoDescriber,
		string $code,
		array $documentType,
	): SettingCollection
	{
		$declared = $autoDescriber->passthroughSettingNames($code, $documentType);
		if (!$declared)
		{
			return $settings;
		}

		$published = new SettingCollection();
		foreach ($settings as $setting)
		{
			if (!in_array($setting->name, $declared, true))
			{
				$published->add($setting);
			}
		}

		return $published;
	}

	/**
	 * The single point every branch above answers through, so one activity gets one shape of description.
	 *
	 * Here the schema is split by lifecycle category: only the editable settings stay in `settings`, the carried
	 * names become their own declared list, and the cross-setting constraints of the block join them. A carried
	 * name is answered by that list alone and no longer doubles as a skipped one; the computed, server-managed and
	 * enforced names keep reaching `skippedSettings`, because the round trip sends them back for symmetry and the
	 * reverse path accepts a name outside the schema by that list.
	 *
	 * @param list<string> $skippedSettings Names the schema does not express.
	 */
	private function makeDescriptionResult(
		string $code,
		array $documentType,
		ActivityAiAutoDescriber $autoDescriber,
		SettingCollection $settings,
		ActivityAiDescriptionSource $source,
		array $skippedSettings,
		?IBPActivityAiSettingConstraints $constraintsSource = null,
	): Result|ActivityAiDescriptionResult
	{
		$constraints = $this->readSettingConstraints($constraintsSource, $documentType);
		if ($constraints === null)
		{
			return (new Result())->addError(new Error(
				"Activity '{$code}' AI setting constraints are unreadable",
				self::ERROR_INVALID_SETTING_CONSTRAINT,
			));
		}

		$hookCarried = array_keys(array_filter(
			$autoDescriber->hookSettingCategories($code, $documentType),
			static fn(SettingCategory $category) => $category === SettingCategory::Carried,
		));
		$carried = array_values(array_unique(array_merge(
			$settings->getNamesByCategory(SettingCategory::Carried),
			$hookCarried,
		)));

		$editable = $settings->filterEditable();
		$unknownConstrained = array_diff(
			$constraints->getReferencedSettingNames(),
			$editable->getNames(),
			$carried,
		);
		if ($unknownConstrained)
		{
			// A constraint over a name the block does not have cannot be judged, and a rule that cannot be judged
			// must not be published as one that was.
			return (new Result())->addError(new Error(
				"Activity '{$code}' AI setting constraints refer to unknown settings: "
					. implode(', ', $unknownConstrained),
				self::ERROR_INVALID_SETTING_CONSTRAINT,
			));
		}

		$skipped = array_unique(array_merge(
			$skippedSettings,
			array_diff($settings->getNames(), $editable->getNames()),
		));

		return new ActivityAiDescriptionResult(
			code: $code,
			settings: $editable,
			source: $source,
			skippedSettings: array_values(array_diff($skipped, $carried)),
			carriedSettings: $carried,
			settingConstraints: $constraints,
		);
	}

	/**
	 * Null when the activity was asked for its constraints and could not answer: a lost constraint is a lost
	 * refusal, so the description fails instead of being published without the rule.
	 */
	private function readSettingConstraints(
		?IBPActivityAiSettingConstraints $source,
		array $documentType,
	): ?SettingConstraintCollection
	{
		if ($source === null)
		{
			return new SettingConstraintCollection();
		}

		try
		{
			return $source->getAiSettingConstraints($documentType);
		}
		catch (\Throwable)
		{
			return null;
		}
	}

	public function getActivityDescriptionClassName(string $code): string
	{
		return "CBPAI{$code}";
	}

	private function getLogger(): LoggerInterface
	{
		if ($this->logger === null)
		{
			$this->logger = (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
		}

		return $this->logger;
	}

	public function getEditableDocumentFieldSettings(array $documentType): ?SettingCollection
	{
		$fields = \CBPRuntime::GetRuntime()
			->getDocumentService()
			->getDocumentFields($documentType)
		;

		if (!is_array($fields))
		{
			return null;
		}

		$settings = new SettingCollection();
		foreach ($fields as $id => $field)
		{
			if (empty($field['Editable']))
			{
				continue;
			}

			$settings->add(
				new Setting(
					name: $id,
					description: (string)($field['Name'] ?? ''),
					type: $this->getSettingType($field, $documentType),
					required: !empty($field['Required']),
					options: $this->getOptions($field),
				)
			);
		}

		return $settings;
	}

	protected function getOptions(array $field): ?SettingOptionCollection
	{
		if (empty($field['Options']) || ! is_array($field['Options']))
		{
			return null;
		}

		$options = new SettingOptionCollection();
		foreach ($field['Options'] as $key => $value)
		{
			$options->add(new SettingOption(id: (string)$key, name: (string)$value));
		}

		return $options;
	}

	private function getSettingType(array $field, array $documentType): SettingType|string
	{
		$converter = new ActivityAiPropertyConverter();
		$fieldType = $converter->getFieldType($field, $documentType);
		if (!$fieldType)
		{
			return (string)($field['BaseType'] ?? '');
		}

		return $converter->getSettingType($fieldType);
	}
}