<?php

namespace Bitrix\Rest\V3\Controller;

use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;

interface DeleteActionInterface
{
	public function deleteAction(DeleteRequest $request): DeleteResponse;
}
