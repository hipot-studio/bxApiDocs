<?php

namespace Bitrix\Rest\V3\Structure;

trait UserFieldsTrait
{
	protected array $userFields = [];

	public function getUserFields(): array
	{
		return $this->userFields;
	}
}
