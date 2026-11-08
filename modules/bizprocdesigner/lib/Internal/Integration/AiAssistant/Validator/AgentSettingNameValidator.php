<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\Result;
use Bitrix\Bizproc\Internal\Entity\Activity\Setting;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;

final class AgentSettingNameValidator
{
	private ?Setting $setting = null;

	public function validate(mixed $name, mixed $value, string $path, ?BlockTypeDetail $blockTypeDetail): Result
	{
		if ($blockTypeDetail === null)
		{
			$this->setting = null;

			return $this->validateNameShape($name, $path);
		}

		// Both lists name a setting the typed schema does not hold, and both are accepted by name alone: the
		// carried one because the activity declares it as carried from the published template, the skipped one
		// because the description could not express it. Refusing either would cost the agent the whole template.
		return $this->validateAgainstSchema(
			$name,
			$value,
			$path,
			$blockTypeDetail->settings,
			array_values(array_unique(
				array_merge($blockTypeDetail->skippedSettings, $blockTypeDetail->carriedSettings)
			)),
		);
	}

	/**
	 * The name check itself, over the pair any settings owner can supply: the typed schema the agent was given
	 * and the names allowed beside it. A complex node's sub-action goes through the same core
	 * ({@see AgentComplexRulesValidator::validateNestedSettingNames()}), so one name gets one verdict wherever
	 * it appears. Nothing here reads the request context - the verdict must be the same on all three agent
	 * entrypoints (REST validate, REST draft push, Marta tool).
	 *
	 * @param list<string> $passthroughNames names accepted without being described by the schema
	 */
	public function validateAgainstSchema(
		mixed $name,
		mixed $value,
		string $path,
		SettingCollection $settings,
		array $passthroughNames,
	): Result
	{
		$this->setting = null;

		$nameResult = $this->validateNameShape($name, $path);
		if (!$nameResult->isSuccess())
		{
			return $nameResult;
		}

		$isPassthrough = in_array($name, $passthroughNames, true);
		$this->setting = $settings->findFirstByName($name);

		if ($this->setting === null)
		{
			return $isPassthrough
				? new Result()
				: (new Result())->addError(GraphError::at(
					"{$path}.name",
					"{$path}.name is incorrect",
					GraphErrorCode::SettingNameUnknown,
				))
			;
		}

		// Passthrough overrides the typed schema only for a structural value it cannot express: storage nodes
		// persist `Fields`/`DynamicFilterFields`/`SelectedFields` as arrays while getPropertiesMap() types
		// them as scalars. A scalar value stays under the typed check.
		if ($isPassthrough && is_array($value))
		{
			$this->setting = null;
		}

		return new Result();
	}

	public function getSetting(): ?Setting
	{
		return $this->setting;
	}

	private function validateNameShape(mixed $name, string $path): Result
	{
		return is_string($name) && $name !== ''
			? new Result()
			: (new Result())->addError(GraphError::at(
				"{$path}.name",
				"{$path}.name should be not empty string",
				GraphErrorCode::SettingEntryInvalid,
			))
		;
	}
}
