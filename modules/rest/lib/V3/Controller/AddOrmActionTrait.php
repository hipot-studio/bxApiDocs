<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;

trait AddOrmActionTrait
{
	use OrmActionTrait;
	use ValidateDtoTrait;

	#[Title(new LocalizableMessage(code: 'REST_V3_CONTROLLER_ADDORMACTIONTRAIT_ACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code: 'REST_V3_CONTROLLER_ADDORMACTIONTRAIT_ACTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	final public function addAction(AddRequest $request): AddResponse
	{
		// convert fields to dto
		$dto = $request->fields->getAsDto();
		// validate
		if (!$this->validateDto($dto, (RequiredGroup::Add)->value))
		{
			throw new DtoValidationException($this->getErrors());
		}

		$repository = $this->getOrmRepositoryByRequest($request);
		$response = $repository->add($dto);

		return new AddResponse($response);
	}
}
