<?php

namespace Bitrix\BIConnector\ExternalSource\Internal\Validator;

use Bitrix\Main\ORM\Fields\Field;
use Bitrix\Main\ORM\Fields\Validators\Validator;
use Bitrix\BIConnector\ExternalSource\Const\Delimiter;

class DelimiterValidator extends Validator
{
	public function validate($value, $primary, array $row, Field $field)
	{
		// Empty means "parsing settings are unknown": every non-CSV dataset is stored that way.
		if ($value === null || $value === '')
		{
			return true;
		}

		if (Delimiter::tryFrom($value) === null)
		{
			return $this->getErrorMessage($value, $field);
		}

		return true;
	}
}
