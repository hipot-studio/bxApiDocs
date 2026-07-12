<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Param;

use Bitrix\Im\Common;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\BlocksBuilder;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderService;
use Bitrix\Im\V2\Message\Param;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Web\Json;

class BlocksBuilderParam extends Param
{
	protected ?string $type = Param::TYPE_JSON;
	protected ?BlocksBuilder $builder = null;

	public function setValue($value): self
	{
		if ($value === null)
		{
			return $this->unsetValue();
		}
		if ($value instanceof BlocksBuilder)
		{
			$this->builder = $value;
		}

		if (isset($this->builder))
		{
			$this->value = $this->builder->toArray();
			$this->jsonValue = $this->builder->getJson();
		}

		return $this;
	}

	public function loadJsonFilter($value)
	{
		if (!empty($value))
		{
			try
			{
				$this->value = Json::decode($value);
			}
			catch (ArgumentException $e)
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
		return null;
	}

	public function saveValueFilter($value)
	{
		return '';
	}

	public function saveJsonFilter($value)
	{
		return $this->jsonValue;
	}

	public function getValue()
	{
		if (!is_array($this->value))
		{
			return null;
		}

		if ($this->builder !== null)
		{
			return $this->builder;
		}

		$builderResult = ServiceLocator::getInstance()->get(BuilderService::class)->get($this->value);
		if (!$builderResult->isSuccess())
		{
			return null;
		}

		$this->builder = $builderResult->getBlocksBuilder();

		return $this->builder;
	}
}
