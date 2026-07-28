<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\UI\MessageEditor;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\Dictionary;

final class Context implements \JsonSerializable
{
	private ?Dictionary $customData = null;

	public function __construct(
		private ?int $userId = null,
		?array $customData = null,
	)
	{
		$this->userId ??= (int)CurrentUser::get()->getId();

		if (is_array($customData) && !empty($customData))
		{
			$this->getCustomData()->setValues($customData);
		}
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getCustomData(): Dictionary
	{
		$this->customData ??= new Dictionary();

		return $this->customData;
	}

	public function getCustomDataInt(string $key): ?int
	{
		$value = $this->getCustomData()->get($key);
		if (is_int($value))
		{
			return $value;
		}

		if (is_numeric($value))
		{
			return (int)$value;
		}

		return null;
	}

	public function __clone()
	{
		if ($this->customData !== null)
		{
			$this->customData = clone $this->customData;
		}
	}

	public function jsonSerialize(): array
	{
		$result = [
			'userId' => $this->userId,
		];

		if ($this->customData !== null && !$this->customData->isEmpty())
		{
			$result['customData'] = $this->customData;
		}

		return $result;
	}
}
