<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Main\SystemException;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Exception\ClassRequireAttributeException;
use Bitrix\Rest\V3\Interaction\Request\AggregateRequest;
use Bitrix\Rest\V3\Interaction\Response\AggregateResponse;

trait AggregateOrmActionTrait
{
	use OrmActionTrait;

	/**
	 * @throws SystemException
	 * @throws ArgumentException
	 * @throws ClassRequireAttributeException
	 */
	#[Title(new LocalizableMessage(code: 'REST_V3_CONTROLLER_AGGREGATEORMACTIONTRAIT_ACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code: 'REST_V3_CONTROLLER_AGGREGATEORMACTIONTRAIT_ACTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	final public function aggregateAction(AggregateRequest $request): AggregateResponse
	{
		$repository = $this->getOrmRepositoryByRequest($request);
		$result = $repository->getAllWithAggregate($request->select, $request->filter);

		return new AggregateResponse($result);
	}
}
