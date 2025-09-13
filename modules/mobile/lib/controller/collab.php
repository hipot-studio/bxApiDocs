<?php

namespace Bitrix\Mobile\Controller;

use Bitrix\Extranet\PortalSettings;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Collab\ActionFilter\CollabAccessControl;
use Bitrix\Mobile\Collab\Dto\CollabPermissionSettingsDto;
use Bitrix\Mobile\Collab\Dto\CollabSecuritySettingsDto;
use Bitrix\Mobile\Collab\Dto\CollabSettingsUserDto;
use Bitrix\Main\Engine\JsonController;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Collab\Dto\CollabTaskPermissionsSettingsDto;
use Bitrix\Mobile\Collab\Provider\LanguageProvider;
use Bitrix\Mobile\Trait\PublicErrorsTrait;
use Bitrix\SocialNetwork\Collab\Access\CollabAccessController;
use Bitrix\SocialNetwork\Collab\Access\CollabDictionary;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Config\Option;

final class Collab extends JsonController
{
	use PublicErrorsTrait;

	public function configureActions(): array
	{
		$actions = [];

		foreach ($this->getQueryActionNames() as $queryActionName)
		{
			$actions[$queryActionName] = [
				'+prefilters' => [
					new CloseSession(),
				],
			];
		}

		return $actions;
	}

	protected function getQueryActionNames(): array
	{
		return [
			'getInviteSettings',
			'getCreateSettings',
			'getIsCollabNameExistsStatus',
			'getSharingMessageText',
		];
	}

	protected function getDefaultPreFilters(): array
	{
		$preFilters = parent::getDefaultPreFilters();
		$preFilters[] = new CollabAccessControl();

		return $preFilters;
	}

	protected function init()
	{
		parent::init();

		Loader::requireModule('socialnetwork');
		Loader::requireModule('im');
	}

	/**
	 * @restMethod mobile.Collab.getInviteSettings
	 * @return array
	 * @throws LoaderException
	 */
	public function getInviteSettingsAction(int $collabId): array
	{
		if (!$this->isCollabToolEnabled())
		{
			return [
				'canCurrentUserInvite' => false,
				'isBitrix24Included' => false,
				'canInviteCollabers' => false,
			];
		}

		$canCurrentUserInvite = CollabAccessController::can($this->getCurrentUser()?->getId(), CollabDictionary::INVITE, $collabId);
		$isBitrix24Included = Loader::includeModule('bitrix24');
		$languages = (new LanguageProvider())->getLanguages();

		$canInviteCollabers = false;
		if (Loader::includeModule('extranet'))
		{
			$canInviteCollabers = PortalSettings::getInstance()->isEnabledCollabersInvitation();
		}

		return [
			'canCurrentUserInvite' => $canCurrentUserInvite,
			'isBitrix24Included' => $isBitrix24Included,
			'canInviteCollabers' => $canInviteCollabers,
			'languages' => $languages,
		];
	}

	/**
	 * @restMethod mobile.Collab.getSharingMessageText
	 * @return string
	 */
	public function getSharingMessageTextAction(string $languageCode = LANGUAGE_ID): string
	{
		return Loc::getMessage('COLLAB_INVITE_SHARING_MESSAGE_TEXT', null, $languageCode);
	}

	/**
	 * @restMethod mobile.Collab.getCreateSettings
	 * @return array
	 * @throws LoaderException
	 */
	public function getCreateSettingsAction(): array
	{
		$result = [
			'permissions' => null,
			'taskPermissions' => null,
			'autoDeleteEnabledInPortalSettings' => null,
			'security' => new CollabSecuritySettingsDto(),
		];

		if (!$this->isCollabToolEnabled())
		{
			return $result;
		}

		$user = $this->getCurrentUser();

		if ($user)
		{
			$result['permissions'] = new CollabPermissionSettingsDto(
				$owner = new CollabSettingsUserDto(
					$user->getId(),
					$user->getFirstName(),
					$user->getLastName(),
					$user->getFullName(),
				),
				$moderators = [],
			);
			$result['taskPermissions'] = new CollabTaskPermissionsSettingsDto();
			$result['autoDeleteEnabledInPortalSettings'] = Option::get('im', 'isAutoDeleteMessagesEnabled', 'Y') === 'Y';
		}

		return $result;
	}

	/**
	 * @restMethod mobile.Collab.getIsCollabNameExistsStatus
	 * @return array
	 * @throws LoaderException
	 */
	public function getIsCollabNameExistsStatusAction(string $name): array
	{
		if (!$this->isCollabToolEnabled())
		{
			return [];
		}

		return [
			'isExists' => \Bitrix\Socialnetwork\Provider\GroupProvider::getInstance()->isExistingGroup($name),
			'name' => $name,
		];
	}

	/**
	 * @throws LoaderException
	 */
	public function isCollabToolEnabledAction(): array
	{
		return [
			'isCollabToolEnabled' => $this->isCollabToolEnabled(),
		];
	}

	/**
	 * @return bool
	 * @throws LoaderException
	 */
	private function isCollabToolEnabled(): bool
	{
		if (Loader::includeModule('intranet'))
		{
			return ToolsManager::getInstance()->checkAvailabilityByToolId('collab');
		}

		return true;
	}
}
