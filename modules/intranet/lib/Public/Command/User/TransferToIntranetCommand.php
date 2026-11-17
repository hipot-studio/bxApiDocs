<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Command\User;

use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\PositiveNumber;

/** The caller checks permissions. Native failures may leave partial changes. */
class TransferToIntranetCommand extends AbstractTransferToIntranetCommand
{
	/** @param list<int> $departmentIds HR department node IDs */
	public function __construct(
		#[PositiveNumber]
		private readonly int $userId,
		array $departmentIds,
	)
	{
		parent::__construct($departmentIds);
	}

	protected function execute(): Result
	{
		$handler = new TransferToIntranetCommandHandler();
		$user = $handler->transferOne($this->userId, $this->transferDepartmentIds, $this->siteId);
		return (new Result())->setData(['users' => [$user->toArray()]])->addErrors($user->getErrors());
	}
}
