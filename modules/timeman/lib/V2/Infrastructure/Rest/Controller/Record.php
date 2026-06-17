<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Rest\Controller;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Timeman\Security\UserPermissionsManager;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\V2\Infrastructure\Rest\Dto\Mapping\RecordDtoMapper;
use Bitrix\Timeman\V2\Infrastructure\Rest\Dto\Mapping\RecordListRequestMapper;
use Bitrix\Timeman\V2\Infrastructure\Rest\Dto\RecordDto;
use Bitrix\Timeman\V2\Infrastructure\Rest\Request\Record\ListRequest;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter;
use Bitrix\Timeman\V2\Public\Provider\RecordProvider;

#[DtoType(RecordDto::class)]
final class Record extends RestController
{
	protected int $userId = 0;

	protected function init(): void
	{
		$this->userId = (int)CurrentUser::get()->getId();

		parent::init();
	}

	public function listAction(
		ListRequest $request,
		RecordProvider $provider,
		RecordDtoMapper $mapper,
	): ListResponse
	{
		$listParams = RecordListRequestMapper::mapToListParams($request);
		$recordOwnerUserId = $this->extractRecordOwnerUserId($listParams->filter);
		if (!$this->getAccessManager()->canReadWorktime($recordOwnerUserId))
		{
			throw new AccessDeniedException();
		}

		$records = $provider->getRecords($listParams);

		return new ListResponse($mapper->mapCollection($records, $request));
	}

	protected function getAccessManager(): UserPermissionsManager
	{
		return DependencyManager::getInstance()->getUserPermissionsManager($this->getUser());
	}

	private function getUser(): ?\CUser
	{
		global $USER;

		if ($USER instanceof \CUser)
		{
			return $USER;
		}

		return null;
	}

	private function extractRecordOwnerUserId(?FilterInterface $filter): int
	{
		if (!$filter instanceof Filter)
		{
			throw new AccessDeniedException();
		}

		$userId = $filter->getUserId();
		if ($userId === null)
		{
			throw new AccessDeniedException();
		}

		return $userId;
	}
}
