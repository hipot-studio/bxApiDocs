<?php

declare(strict_types=1);

namespace Bitrix\Crm\Reservation\Validator;

use Bitrix\Crm\ProductRowCollection;
use Bitrix\Main\Result;

interface ValidatorInterface
{
	public function validateCollection(?ProductRowCollection $collection): Result;

	public function validateRows(array $currentRows, array $actualRows): Result;
}
