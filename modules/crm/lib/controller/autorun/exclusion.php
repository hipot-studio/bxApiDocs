<?php

namespace Bitrix\Crm\Controller\Autorun;

use Bitrix\Crm\Controller\Autorun\Dto\PreparedData;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Exclusion\Applicability;
use Bitrix\Crm\Exclusion\Manager;
use Bitrix\Crm\Item;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Result;

final class Exclusion extends Base
{
	protected function getSelect(): array
	{
		return [
			Item::FIELD_NAME_ID,
		];
	}

	protected function processItem(Factory $factory, Item $item, PreparedData $data): Result
	{
		$result = new Result();

		if (!Manager::checkCreatePermission())
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		$identifier = ItemIdentifier::createByItem($item);

		$applicabilityResult = Applicability::checkApplicability($identifier->getEntityTypeId(), $identifier->getEntityId());
		if (!$applicabilityResult->isSuccess())
		{
			return $result->addErrors($applicabilityResult->getErrors());
		}

		try
		{
			Manager::excludeEntity($identifier->getEntityTypeId(), $identifier->getEntityId());
		}
		catch (ObjectException $deletionException)
		{
			$result->addError(
				Error::createFromThrowable($deletionException),
			);
		}

		return $result;
	}
}
