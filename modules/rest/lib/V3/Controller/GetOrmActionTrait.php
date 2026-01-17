<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

trait GetOrmActionTrait
{
	use OrmActionTrait;

	#[Title(new LocalizableMessage(code: 'REST_V3_CONTROLLER_GETORMACTIONTRAIT_ACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code: 'REST_V3_CONTROLLER_GETORMACTIONTRAIT_ACTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	final public function getAction(GetRequest $request): GetResponse
	{
		$dto = $this->getOrmRepositoryByRequest($request)->getOneWith($request->select, (new FilterStructure())->where('id', $request->id));
		if ($dto === null)
		{
			throw new EntityNotFoundException($request->id);
		}

		return new GetResponse($dto);
	}
}
