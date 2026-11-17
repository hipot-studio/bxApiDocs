<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Intranet\Internal\Validation\Rule\PositiveUserIds;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\ElementsType;
use Bitrix\Main\Validation\Rule\Enum\Type;

/** The caller checks permissions. Native failures may leave partial changes. */
class TransferToIntranetBulkCommand extends AbstractTransferToIntranetCommand
{
	/** @param list<int> $departmentIds HR department node IDs */
	public function __construct(
		#[NotEmpty]
		#[ElementsType(typeEnum: Type::Integer)]
		#[PositiveUserIds]
		private readonly array $userIds,
		array $departmentIds,
	)
	{
		parent::__construct($departmentIds);
	}

	protected function execute(): Result
	{
		$handler = new TransferToIntranetCommandHandler();
		$users = [];
		foreach (array_unique($this->userIds) as $userId)
		{
			$users[] = $handler->transferOne($userId, $this->transferDepartmentIds, $this->siteId)->toArray();
		}
		return (new Result())->setData(['users' => $users]);
	}
}
