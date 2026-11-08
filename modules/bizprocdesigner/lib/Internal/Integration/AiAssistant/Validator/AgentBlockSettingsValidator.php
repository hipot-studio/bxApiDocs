<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Internal\Entity\Activity\SettingConstraint;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingConstraintKind;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\Result;

final class AgentBlockSettingsValidator
{
	private readonly AgentBlockSettingValidator $settingValidator;
	private readonly ValidationProfile $profile;
	private readonly ?ValidationContext $context;
	private ?AgentSettingCollection $validSettings = null;

	public function __construct(
		?AgentBlockSettingValidator $settingValidator = null,
		?ValidationContext $context = null,
	)
	{
		$this->settingValidator = $settingValidator ?? new AgentBlockSettingValidator(context: $context);
		$this->context = $context;
		// Fail-closed like every other reader of the context: no context at all means the profile of the internal
		// assistant, that is the one that adds no check of its own.
		$this->profile = ValidationProfile::of($context);
	}

	/**
	 * @param string|null $blockPath address of the block itself, which the cross-setting constraints are
	 *        reported at - they belong to the block rather than to one of its settings. Omitted by a caller
	 *        that has no block around the settings, and the constraints are then addressed where they are read.
	 * @param string|null $blockId identity of the block, which the requiredness exemption of a block the server
	 *        wrote is matched by (ALG-02). Unsaid by a caller that has no block around the settings, and the
	 *        exemption then never applies - it is an exemption of a block, not of a settings list.
	 */
	public function validate(
		mixed $settings,
		string $path,
		?BlockTypeDetail $blockTypeDetail,
		?string $blockType = null,
		?string $presetId = null,
		?string $blockPath = null,
		?string $blockId = null,
		bool $allowPublishedExpressionKeys = false,
	): Result
	{
		$this->validSettings = null;

		if (!is_array($settings))
		{
			return (new Result())->addError(
				GraphError::at($path, "{$path} should be array", GraphErrorCode::SettingValueInvalid),
			);
		}

		if (empty($settings) && $blockTypeDetail && $this->hasRequiredSetting($blockTypeDetail))
		{
			return (new Result())->addError(
				GraphError::at($path, "{$path} should be not empty array", GraphErrorCode::SettingRequired),
			);
		}

		if ($blockTypeDetail)
		{
			$this->validSettings = new AgentSettingCollection();
		}

		$result = new Result();
		$seenNames = [];
		$submittedValues = [];
		// Built once for the block: the answer is a property of its schema and not of any one setting.
		$flatDuplicateOwners = $blockTypeDetail === null ? [] : self::flatDuplicateOwners($blockTypeDetail);

		foreach ($settings as $key => $setting)
		{
			$settingValidateResult = $this->settingValidator->validate(
				$setting,
				"$path.$key",
				$blockTypeDetail,
				$blockType,
				$presetId,
				$allowPublishedExpressionKeys,
			);
			$result->addErrors($settingValidateResult->getErrors());

			$name = $this->settingName($setting);
			if ($name !== null)
			{
				// Recorded before the duplicate below is refused, and whether or not the value passed its own
				// checks: a name the agent sent is a name the block carries, and reading it as absent would
				// answer one mistake with a second refusal saying the setting is missing.
				if (!array_key_exists($name, $submittedValues))
				{
					$submittedValues[$name] = $this->settingValidator->getValidSetting()?->value
						?? (is_array($setting) ? ($setting['value'] ?? null) : null)
					;
				}

				if (isset($seenNames[$name]))
				{
					$result->addError(GraphError::at(
						"{$path}.{$key}.name",
						"{$path}.{$key}.name is duplicated",
						GraphErrorCode::SettingNameDuplicated,
					));

					continue;
				}

				$seenNames[$name] = true;

				$result->addErrors($this->validateFlatDuplicateValue(
					$flatDuplicateOwners[$name] ?? null,
					$name,
					$submittedValues[$name],
					"$path.$key",
					$blockId,
					$blockType,
				)->getErrors());
			}

			if ($settingValidateResult->isSuccess() && $this->validSettings && $this->settingValidator->getValidSetting())
			{
				$this->validSettings->add($this->settingValidator->getValidSetting());
			}
		}

		if ($blockTypeDetail !== null)
		{
			$requirementsResult = $this->validateRequirements(
				$blockTypeDetail,
				$submittedValues,
				$path,
				$blockPath,
				$blockId,
				$blockType,
			);
			$result->addErrors($requirementsResult->getErrors());
		}

		return $result;
	}

	/**
	 * The flat copy of a described map: a name the schema does not describe while one of the block's own maps
	 * already carries a child of that very name. The catalog stopped publishing such names (BRIEF § 7.3 - the task
	 * activity published its task fields both flat and as children of `Fields`), and the reverse path still accepts
	 * them by name so that a template written before that keeps saving. What it may NOT do is author a new one:
	 * the flat value is accepted only as the one the template already carries (ALG-02 `carriedValueAllowed`), and
	 * a new value goes through the map, which is the shape the block actually reads.
	 *
	 * The rule follows the description rather than a list of names, so it applies to whatever activity ends up in
	 * that shape, and it is not gated on the profile: like the child-value check it is a consequence of how the
	 * activity describes itself, and BRIEF § 4.4 keeps those on both entrypoints.
	 *
	 * A carried value is looked up on the whole template the agent was handed and not on the published version
	 * alone ({@see ValidationContext::carriesBlockValue()}): a block that lives only in the draft has no published
	 * twin, and refusing its flat values would leave that draft unsavable.
	 */
	private function validateFlatDuplicateValue(
		?string $owner,
		string $name,
		mixed $value,
		string $path,
		?string $blockId,
		?string $blockType,
	): Result
	{
		$result = new Result();
		if (
			$owner === null
			|| $this->context === null
			|| $this->context->carriesBlockValue($blockId, $blockType, $name, $value)
		)
		{
			return $result;
		}

		return $result->addError(GraphError::at(
			"{$path}.value",
			"{$path}.value is a flat copy of the \"{$owner}\" setting and may only carry the value this template"
			. " already holds; write a new one into \"{$owner}\" instead",
			GraphErrorCode::SettingValueInvalid,
		));
	}

	/**
	 * The names a described map of this block already carries as children, keyed lower-cased and mapped to the
	 * setting that owns them - minus the names the schema describes in their own right, which are copies of
	 * nothing. Names are matched without regard to case, exactly as a setting name and a map key are matched
	 * everywhere else in this contour.
	 *
	 * @return array<string, string>
	 */
	private static function flatDuplicateOwners(BlockTypeDetail $blockTypeDetail): array
	{
		$owners = [];
		$described = [];
		foreach ($blockTypeDetail->settings as $setting)
		{
			$described[mb_strtolower($setting->name)] = true;
			foreach ($setting->children ?? [] as $child)
			{
				$owners[mb_strtolower($child->name)] ??= $setting->name;
			}
		}

		return array_diff_key($owners, $described);
	}

	/**
	 * What the block owes over its whole settings set (ALG-01 checkRequired and checkConstraints): a setting the
	 * schema declares required carries a value, and the cross-setting rules of the block hold. Both are judged
	 * after the declared defaults are applied, so the answer is about the block that would be saved rather than
	 * about the payload that was typed.
	 *
	 * @param array<string, mixed> $submittedValues values as sent, keyed by the lower-cased setting name
	 */
	private function validateRequirements(
		BlockTypeDetail $blockTypeDetail,
		array $submittedValues,
		string $path,
		?string $blockPath,
		?string $blockId,
		?string $blockType,
	): Result
	{
		$result = new Result();
		if (!$this->enforcesSettingRequirements($blockTypeDetail, $submittedValues, $blockId, $blockType))
		{
			return $result;
		}

		$values = $this->effectiveSettingValues($blockTypeDetail, $submittedValues);
		$requiredNames = [];
		foreach ($blockTypeDetail->settings as $setting)
		{
			if ($setting->required)
			{
				$requiredNames[] = $setting->name;
			}
		}

		foreach ($blockTypeDetail->settings as $setting)
		{
			if (!$setting->required
				|| !AgentSettingValueValidator::isEmptyValue($values[mb_strtolower($setting->name)] ?? null)
			)
			{
				continue;
			}

			$settingPath = "{$path}.{$setting->name}";
			$result->addError(GraphError::at(
				$settingPath,
				"{$settingPath} is required",
				GraphErrorCode::SettingRequired,
				['requiredKeys' => $requiredNames],
			));
		}

		$constraintsPath = ($blockPath ?? $path) . '.settingConstraints';
		foreach ($blockTypeDetail->settingConstraints as $constraint)
		{
			$result->addErrors($this->validateConstraint($constraint, $values, $constraintsPath)->getErrors());
		}

		return $result;
	}

	/**
	 * The values the requirements are judged with: what the agent sent, completed by the defaults the schema
	 * publishes for the settings it left out (ALG-01 applyDeclaredDefaults). A default the catalog published is
	 * an answer the block already has, so demanding the same value of the agent would refuse a graph the server
	 * itself can complete.
	 *
	 * The published baseline does not complete these values: the block ALG-02 excuses is the one whose whole
	 * settings set is unchanged, so its baseline values ARE the submitted ones and completing them would add
	 * nothing. What the exemption lifts is the check itself, and it is decided in one place
	 * ({@see self::enforcesSettingRequirements()}).
	 *
	 * @param array<string, mixed> $submittedValues
	 * @return array<string, mixed> keyed by the lower-cased setting name
	 */
	private function effectiveSettingValues(BlockTypeDetail $blockTypeDetail, array $submittedValues): array
	{
		$values = $submittedValues;

		foreach ($blockTypeDetail->settings as $setting)
		{
			$name = mb_strtolower($setting->name);
			if ($setting->defaultValue !== null && !array_key_exists($name, $values))
			{
				$values[$name] = $setting->defaultValue;
			}
		}

		return $values;
	}

	/**
	 * One cross-setting rule of the block against the settings it names. The names are the block's own - the
	 * description refuses a rule over a name the block does not have - so a name missing from the values here
	 * means the agent sent none, which is exactly what the rule counts.
	 *
	 * @param array<string, mixed> $values
	 */
	private function validateConstraint(SettingConstraint $constraint, array $values, string $path): Result
	{
		$filled = [];
		foreach ($constraint->settings as $name)
		{
			if (!AgentSettingValueValidator::isEmptyValue($values[mb_strtolower($name)] ?? null))
			{
				$filled[] = $name;
			}
		}

		$satisfied = match ($constraint->kind)
		{
			SettingConstraintKind::ExactlyOne => count($filled) === 1,
			SettingConstraintKind::AtLeastOne => count($filled) >= 1,
		};

		if ($satisfied)
		{
			return new Result();
		}

		$expectation = $constraint->kind === SettingConstraintKind::ExactlyOne ? 'exactly one' : 'at least one';
		$listed = implode(', ', $constraint->settings);

		return (new Result())->addError(GraphError::at(
			$path,
			"{$path} requires {$expectation} of the settings {$listed} to carry a value, "
			. ($filled === [] ? 'none does' : count($filled) . ' do: ' . implode(', ', $filled)),
			GraphErrorCode::SettingConstraintViolated,
			['constraint' => $constraint->toArray()],
		));
	}

	/**
	 * Whether the requirement checks of BRIEF § 1.3 apply to this block. They are what the check ADDS to the
	 * description of an activity rather than what follows from it, so they belong to the external contour alone
	 * while the internal assistant keeps the strictness it has (BRIEF § 4.4).
	 *
	 * And they apply to what the agent authored rather than to what the server already wrote: a block travelling
	 * back unchanged - from the published version or from the graph the agent was handed - is excused
	 * ({@see ValidationContext::carriesBlockUnchanged()}), or the very first edit of a template older than these
	 * checks would be refused over a node the agent never touched and, having no value to put there, could not fix.
	 * The handed graph is the DRAFT whenever the template has one, so the exemption covers the unfinished draft a
	 * person saved in the editor as well: what `template.get` answers with, the check accepts back.
	 *
	 * The single place that strictness is decided, and it is decided by the profile (TPL-02): no branch on the
	 * request source is opened for these checks anywhere in this catalog.
	 *
	 * @param array<string, mixed> $submittedValues
	 */
	private function enforcesSettingRequirements(
		BlockTypeDetail $blockTypeDetail,
		array $submittedValues,
		?string $blockId,
		?string $blockType,
	): bool
	{
		if (!$this->profile->enforcesSettingRequirements())
		{
			return false;
		}

		// Fail-closed like every other reader of the context: no context at all is no graph to be excused by, and
		// the block is judged as one the agent authored.
		return $this->context === null
			|| !$this->context->carriesBlockUnchanged(
				$blockId,
				$blockType,
				$submittedValues,
				self::comparedSettingNames($blockTypeDetail),
			)
		;
	}

	/**
	 * The names the exemption compares the two sides by: the WHOLE set the description publishes as belonging to
	 * the block (ALG-02) - the editable schema, the names declared carried, and the names the description could
	 * not express. The last list is not a leftover: a value the schema failed to type is still a value the agent
	 * is handed, sends back and may edit ({@see AgentSettingNameValidator}, which accepts exactly these names
	 * beside the schema), so leaving it out would excuse a block the agent did change.
	 *
	 * The one thing ALG-02 keeps out of the comparison is a value the SERVER writes whatever the agent sends
	 * ({@see \CBPActivity::getAiEnforcedSettingValues()}, applied on the way in by
	 * {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftConverterService}):
	 * comparing it would refuse a block that ends up identical to the published one anyway. No shipped activity
	 * declares such a value today - the base class answers with an empty map and nothing overrides it - and the
	 * description does not publish the names apart, so they cannot be subtracted here. The place to subtract them
	 * is the block description, which is where the lifecycle category is known; until an activity declares one,
	 * this set is the whole set and nothing is silently excused.
	 *
	 * @return list<string>
	 */
	private static function comparedSettingNames(BlockTypeDetail $blockTypeDetail): array
	{
		$names = array_merge($blockTypeDetail->carriedSettings, $blockTypeDetail->skippedSettings);
		foreach ($blockTypeDetail->settings as $setting)
		{
			$names[] = $setting->name;
		}

		return array_values(array_unique($names));
	}

	/**
	 * A repeated setting name is refused instead of being silently collapsed: readers disagree on which
	 * occurrence wins - the validators read the first one, the draft converter persists the last - so a
	 * duplicate would let a node be authorised on one value and saved with another. Names are compared
	 * case-insensitively, exactly as the readers match them.
	 */
	private function settingName(mixed $setting): ?string
	{
		if (!is_array($setting))
		{
			return null;
		}

		$name = $setting['name'] ?? null;

		return is_string($name) && $name !== '' ? mb_strtolower($name) : null;
	}

	public function getValidSettings(): ?AgentSettingCollection
	{
		return $this->validSettings;
	}

	/**
	 * An empty settings payload is rejected only when the schema declares a required setting: a block whose
	 * schema is optional-only legitimately round-trips with no settings at all.
	 */
	private function hasRequiredSetting(BlockTypeDetail $blockTypeDetail): bool
	{
		foreach ($blockTypeDetail->settings as $setting)
		{
			if ($setting->required)
			{
				return true;
			}
		}

		return false;
	}
}
