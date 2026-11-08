<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Param;

use Bitrix\Im\V2\Message\Param;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Web\Json;

/**
 * Reasoning plan of a BitrixGPT simple-mode turn, built by aiassistant on the placeholder
 * message: {startedAt, steps: [{code, offset}]}. An empty value means there is no plan.
 */
class CopilotSimpleReasoningPlan extends Param
{
	protected ?string $type = Param::TYPE_JSON;

	public function setValue($value): self
	{
		if ($value === null || $value === [])
		{
			return $this->unsetValue();
		}

		$this->value = $value;
		$this->jsonValue = Json::encode($value);
		$this->markChanged();

		return $this;
	}

	public function saveValueFilter($value)
	{
		return '';
	}

	public function saveJsonFilter($value)
	{
		return $this->jsonValue;
	}

	public function loadJsonFilter($value)
	{
		if (empty($value))
		{
			return null;
		}

		try
		{
			$this->value = Json::decode($value);
		}
		catch (ArgumentException)
		{}

		return $value;
	}

	/**
	 * The plan travels verbatim: its keys are a fixed client contract, and the json key
	 * converter would lowercase them (startedAt -> startedat).
	 */
	public function toRestFormat(): ?array
	{
		return $this->getValue();
	}

	public function toPullFormat(): ?array
	{
		return $this->getValue();
	}
}
