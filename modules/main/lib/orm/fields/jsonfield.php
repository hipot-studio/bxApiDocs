<?php

namespace Bitrix\Main\ORM\Fields;

use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Web\Json;

class JsonField extends ScalarField
{
	public function __construct($name, $parameters = [])
	{
		$this->addSaveDataModifier([$this, 'encode']);
		$this->addFetchDataModifier([$this, 'decode']);

		parent::__construct($name, $parameters);
	}

	public function encode($value)
	{
		return Json::encode($value, 0);
	}

	public function decode($value)
	{
		return Json::decode($value);
	}

	/**
	 * @param mixed $value
	 *
	 * @return mixed|SqlExpression
	 */
	public function cast($value)
	{
		if ($value === null)
		{
			return $this->is_nullable ? $value : '';
		}

		if ($value instanceof SqlExpression)
		{
			return $value;
		}

		return $value;
	}

	public function convertValueFromDb($value)
	{
		return $this->getConnection()->getSqlHelper()->convertFromDbString($value);
	}

	public function convertValueToDb($value)
	{
		if ($value instanceof SqlExpression)
		{
			return $value;
		}

		return $value === null && $this->is_nullable
			? $value
			: $this->getConnection()->getSqlHelper()->convertToDbString($value);
	}

	public function isValueEmpty($value)
	{
		if (is_array($value))
		{
			return empty($value);
		}

		return parent::isValueEmpty($value);
	}
}
