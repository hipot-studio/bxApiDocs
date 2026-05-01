<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook\Access;

use Bitrix\Main;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;

class SetCreatorAccessCommand extends Main\Command\AbstractCommand
{
	public PermissionType $permission = PermissionType::CreateOwn;

	public function __construct(
		public readonly array $accessCodes,
		?PermissionType $permission = null,
	)
	{
		if (!empty($permission))
		{
			$this->permission = $permission;
		}
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();
		try
		{
			(new SetCreatorAccessCommandHandler())($this);
		}
		catch (\Throwable $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'accessCodes' => $this->accessCodes,
			'permission' => $this->permission->value,
		];
	}
}
