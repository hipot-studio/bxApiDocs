<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Attribute\Title;
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
		$dto = $request->fields->convertToDto((RequiredGroup::Update)->value);

		$repository = $this->getOrmRepositoryByRequest($request);
		if ($request->id)
		{
			$result = $repository->update($request->id, $dto);
		}
		else if ($request->filter)
		{
			$result = $repository->updateMulti($request->filter, $dto);
		}
		else
		{
			throw new RequiredFieldInRequestException('id || filter');
		}

		return new UpdateResponse($result);
	}
}
