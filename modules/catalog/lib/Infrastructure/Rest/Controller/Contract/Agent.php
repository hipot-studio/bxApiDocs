<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Controller\Contract;

use Bitrix\Catalog\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Catalog\Infrastructure\Rest\Dto\AgentContractEntityDto;
use Bitrix\Catalog\Internal\Service\Rest\AgentContractEntityService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;

#[DtoType(AgentContractEntityDto::class)]
class Agent extends AbstractController
{
	/**
	 * Single id or filter restricted to field `id` (= / in).
	 */
	public function deleteAction(DeleteRequest $request, AgentContractEntityService $service): DeleteResponse
	{
		$data = $this->requireResultData($service->delete($this->resolveDeleteIds($request)));

		return new DeleteResponse((bool)($data['result'] ?? true));
	}
}
