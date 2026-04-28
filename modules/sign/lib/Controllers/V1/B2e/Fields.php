<?php

namespace Bitrix\Sign\Controllers\V1\B2e;

use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Attribute\Access\LogicOr;
use Bitrix\Sign\Attribute\ActionAccess;
use Bitrix\Sign\Engine\Controller;
use Bitrix\HumanResources;
use Bitrix\Sign\Service\Document\FieldService;

class Fields extends Controller
{
	#[LogicOr(
		new ActionAccess(ActionDictionary::ACTION_B2E_DOCUMENT_READ),
		new ActionAccess(ActionDictionary::ACTION_B2E_TEMPLATE_READ),
	)]
	public function loadAction(
		FieldService $fieldService,
		array $options = [],
	): array
	{
		$currentUserId = (int)CurrentUser::get()->getId();
		if ($currentUserId < 1)
		{
			$this->addError(new Main\Error('User not found'));

			return [];
		}

		$fieldsDataResult = $fieldService->loadByUserId($currentUserId, $options);
		if (!$fieldsDataResult->isSuccess())
		{
			return [];
		}

		return $fieldsDataResult->getData();
	}
}
