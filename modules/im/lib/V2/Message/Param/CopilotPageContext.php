<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Param;

use Bitrix\Im\V2\Message\Param;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Web\Json;

class CopilotPageContext extends Param
{
	private const MAX_JSON_LENGTH = 60000;

	protected ?string $type = Param::TYPE_JSON;

	public function setValue($value): self
	{
		$jsonValue = Json::encode($value);
		if (strlen($jsonValue) >= self::MAX_JSON_LENGTH)
		{
			return $this;
		}

		$this->value = $value;
		$this->jsonValue = $jsonValue;

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
		if (!empty($value))
		{
			try
			{
				$this->value = Json::decode($value);
			}
			catch (ArgumentException $ext)
			{}
		}
		else
		{
			$value = null;
		}

		return $value;
	}

	public function toRestFormat(): ?array
	{
		return Converter::toJson()->process($this->getValue());
	}

	public function toPullFormat(): ?array
	{
		return Converter::toJson()->process($this->getValue());
	}
}
