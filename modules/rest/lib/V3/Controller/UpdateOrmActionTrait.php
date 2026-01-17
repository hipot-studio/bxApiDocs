<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\UpdateRequest;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

trait UpdateOrmActionTrait
{
	use OrmActionTrait;
	use ValidateDtoTrait;

	#[Title(new LocalizableMessage(code: 'REST_V3_CONTROLLER_UPDATEORMACTIONTRAIT_ACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code: 'REST_V3_CONTROLLER_UPDATEORMACTIONTRAIT_ACTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	public function updateAction(UpdateRequest $request): UpdateResponse
	{
		if ($request->id === null && $request->filter === null)
		{
			throw new RequiredFieldInRequestException('id || filter');
		}

		$dto = $request->fields->getAsDto();
		if (!$this->validateDto($dto, (RequiredGroup::Update)->value))
		{
			throw new DtoValidationException($this->getErrors());
		}

		$repository = $this->getOrmRepositoryByRequest($request);
		$result = $request->id !== null
			? $repository->update($request->id, $dto)
			: $repository->updateMulti($request->filter, $dto);

		return new UpdateResponse($result);
	}
}
