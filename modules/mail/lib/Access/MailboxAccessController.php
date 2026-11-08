<?php

namespace Bitrix\Mail\Access;

use Bitrix\Mail\Access\Model\MailboxModel;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Mail\Integration\HumanResources\NodeMemberService;
use Bitrix\Main\Access\AccessibleItem;

final class MailboxAccessController extends BaseMailAccessController
{
	private ?array $batchOwners = null;
	private ?array $batchDepartmentIds = null;
	private array $batchDepartmentOwners = [];

	/** Keeps the normal rules and access events, sharing HR and mailbox access lookups within this batch. */
	public function withMailboxBatch(array $models, callable $check): mixed
	{
		$previous = [$this->batchOwners, $this->batchDepartmentIds, $this->batchDepartmentOwners];
		$this->batchOwners = array_fill_keys(
			array_map(static fn (MailboxModel $model): int => $model->getOwnerId(), $models),
			true,
		);
		$this->batchDepartmentIds = null;
		$this->batchDepartmentOwners = [];
		try
		{
			return MailboxAccess::withMailboxBatch($models, $this->getUser()->getUserId(), $check);
		}
		finally
		{
			[$this->batchOwners, $this->batchDepartmentIds, $this->batchDepartmentOwners] = $previous;
		}
	}

	/** Null means the caller must perform its usual individual lookup. */
	public function getBatchDepartmentAccess(int $ownerId, bool $withSubDepartments): ?bool
	{
		if ($this->batchOwners === null || !isset($this->batchOwners[$ownerId]))
		{
			return null;
		}
		$this->batchDepartmentIds ??= Container::getNodeRepository()
			->findAllByUserId($this->getUser()->getUserId())->getIds();
		$key = (int)$withSubDepartments;
		if (!array_key_exists($key, $this->batchDepartmentOwners))
		{
			$this->batchDepartmentOwners[$key] = array_fill_keys(
				$this->batchDepartmentIds === [] ? [] : NodeMemberService::filterUsersByDepartmentIds(
					array_keys($this->batchOwners),
					$this->batchDepartmentIds,
					$withSubDepartments,
				),
				true,
			);
		}

		return isset($this->batchDepartmentOwners[$key][$ownerId]);
	}

	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		return !is_null($itemId) ? MailboxModel::createFromId($itemId) : null;
	}
}
