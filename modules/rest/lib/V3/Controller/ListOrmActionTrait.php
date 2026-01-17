<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

trait ListOrmActionTrait
{
	use OrmActionTrait;

	#[Title(new LocalizableMessage(code: 'REST_V3_CONTROLLER_LISTORMACTIONTRAIT_ACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code: 'REST_V3_CONTROLLER_LISTORMACTIONTRAIT_ACTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	final public function listAction(ListRequest $request): ListResponse
	{
		$collection = $this->getOrmRepositoryByRequest($request)->getAll(
			$request->select,
			$request->filter,
			$request->order,
			$request->pagination,
		);

		return new ListResponse($collection);
	}
}
