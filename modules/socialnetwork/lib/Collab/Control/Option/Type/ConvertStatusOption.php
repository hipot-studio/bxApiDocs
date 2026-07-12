<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Collab\Control\Option\Type;

use Bitrix\Main\Result;
use Bitrix\Socialnetwork\Collab\Collab;
use Bitrix\Socialnetwork\Collab\Control\Option\AbstractOption;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ConvertProgressMapper;

class ConvertStatusOption extends AbstractOption
{
	private const DB_NAME = ConvertProgressMapper::CONVERT_STATUS;

	public function __construct(string $value)
	{
		parent::__construct(static::DB_NAME, strtolower($value));
	}

	protected function applyImplementation(Collab $collab): Result
	{
		return new Result();
	}
}
