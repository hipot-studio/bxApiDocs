<?php

declare(strict_types=1);

namespace Bitrix\Mail\Access\Install\AgentInstaller;

use Bitrix\Mail\Access\Permission\PermissionDictionary;
use Bitrix\Mail\Access\Repository\PermissionRepository;
use Bitrix\Main\Access\Permission\PermissionDictionary as MainPermissionDictionary;
use Bitrix\Main\SystemException;

/**
 * Grants the right to delete messages to every role of the portal, the ones created by hand
 * included: a missing permission row reads as a denial, so a role left out loses deletion.
 */
class MessageDeletePermissionInstaller extends AbstractInstaller
{
	private PermissionRepository $permissionRepository;

	public function __construct(?PermissionRepository $permissionRepository = null)
	{
		parent::__construct();
		$this->permissionRepository = $permissionRepository ?? new PermissionRepository();
	}

	protected function run(): void
	{
		$errors = [];

		foreach ($this->roleRepository->getRoleList() as $role)
		{
			$roleId = (int)$role['ID'];

			try
			{
				$error = $this->grantToRole($roleId);
			}
			catch (\Throwable $exception)
			{
				$error = $exception->getMessage();
			}

			// A single broken role must not stop the walk: the rest of the portal would lose deletion.
			if ($error !== null)
			{
				$errors[] = $roleId . ': ' . $error;
			}
		}

		if (!empty($errors))
		{
			// The access version stays behind, so the agent repeats the walk over the roles left unset.
			throw new SystemException(implode('; ', $errors));
		}
	}

	/**
	 * @return string|null the reason the role was left without the right, null when it has one
	 */
	private function grantToRole(int $roleId): ?string
	{
		$currentPermissions = $this->permissionRepository->getPermissionsForRole($roleId);
		if (array_key_exists(PermissionDictionary::MAIL_MESSAGE_DELETE, $currentPermissions))
		{
			return null;
		}

		$result = $this->permissionRepository->addWithResult(
			$roleId,
			PermissionDictionary::MAIL_MESSAGE_DELETE,
			MainPermissionDictionary::VALUE_YES,
		);

		return $result->isSuccess() ? null : implode('; ', $result->getErrorMessages());
	}
}
