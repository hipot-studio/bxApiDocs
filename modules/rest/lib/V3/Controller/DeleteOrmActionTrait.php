<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Main\Localization\LocalizableMessage;
use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;

trait DeleteOrmActionTrait
{
	use OrmActionTrait;

	#[Title(new LocalizableMessage(code: 'REST_V3_CONTROLLER_DELETEORMACTIONTRAIT_ACTION_TITLE', phraseSrcFile: __FILE__))]
	#[Description(new LocalizableMessage(code: 'REST_V3_CONTROLLER_DELETEORMACTIONTRAIT_ACTION_DESCRIPTION', phraseSrcFile: __FILE__))]
	final public function deleteAction(DeleteRequest $request): DeleteResponse
	{
		if ($request->id === null && $request->filter === null)
		{
			throw new RequiredFieldInRequestException('id || filter');
		}
		$repository = $this->getOrmRepositoryByRequest($request);
		$result = $request->id !== null ? $repository->delete($request->id) : $repository->deleteMulti($request->filter);

		return new DeleteResponse($result);
	}
}
