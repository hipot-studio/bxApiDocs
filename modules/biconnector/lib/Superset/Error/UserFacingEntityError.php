<?php

namespace Bitrix\BIConnector\Superset\Error;

use Bitrix\Main\ORM\EntityError;

class UserFacingEntityError extends EntityError
{
	public function jsonSerialize(): array
	{
		return array_merge(parent::jsonSerialize(), ['userFacing' => true]);
	}
}
