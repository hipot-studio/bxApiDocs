<?php

namespace Bitrix\IntranetMobile\Controller;

use Bitrix\Intranet\ActionFilter\UserType;
use Bitrix\Intranet\Controller\Invite as IntranetInviteController;
use Bitrix\IntranetMobile\ActionFilter\InviteIntranetMobileAccessControl;
use Bitrix\IntranetMobile\Provider\InviteProvider;
use Bitrix\Mobile\Provider\UserRepository;

class Invite extends Base
{
	protected function getDefaultPreFilters(): array
	{
		$preFilters = parent::getDefaultPreFilters();
		$preFilters[] = new UserType(['employee', 'extranet']);
		$preFilters[] = new InviteIntranetMobileAccessControl();

		return $preFilters;
	}

	/**
	 * @restMethod intranetmobile.invite.getInviteSettings
	 * @return array
	 */
	public function getInviteSettingsAction(): array
	{
		return (new InviteProvider())->getInviteSettings();
	}

	/**
	 * @restMethod intranetmobile.invite.reinviteWithChangeContact
	 *
	 * @param int $userId
	 * @param string|null $newEmail
	 * @param string|null $newPhone
	 * @return array|null
	 */
	public function reinviteWithChangeContactAction(
		int $userId,
		?string $newEmail = null,
		?string $newPhone = null,
	): ?array
	{
		$intranetControllerResult = $this->forward(
			IntranetInviteController::class,
			'reinviteWithChangeContact',
			[
				'userId' => $userId,
				'newEmail' => $newEmail,
				'newPhone' => $newPhone,
			],
		);

		if ($intranetControllerResult['result'] ?? false)
		{
			$intranetControllerResult['user'] = UserRepository::getByIds([$userId])[0];
		}

		return $intranetControllerResult;
	}
}
