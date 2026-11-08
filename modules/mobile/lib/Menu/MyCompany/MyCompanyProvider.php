<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany;

use Bitrix\Intranet\Invitation;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\MyCompanyFeature;
use Bitrix\Mobile\Internal\Integration\Bitrix24\PortalSettings;
use Bitrix\Mobile\Provider\CommonUserDto;
use Bitrix\Mobile\Provider\ThemeProvider;

/**
 * The "My company" section as a whole: availability, the portal line above the blocks and the invite
 * permission it shares with the menu answer. Composition of the blocks belongs to BlockProvider.
 */
final class MyCompanyProvider
{
	private ?bool $canInvite = null;

	public function __construct(private readonly CommonUserDto $user)
	{
	}

	/** Rollout flag and employee check: both are cheap and gate every foreign module call below. */
	public function isAvailable(): bool
	{
		if (Feature::isDisabled(MyCompanyFeature::class))
		{
			return false;
		}

		return $this->isCompanyEmployee();
	}

	/**
	 * Portal line above the blocks. The theme goes along because the line lies on its wallpaper and
	 * the client picks a readable text color from it.
	 *
	 * @return array{name: string, url: string, theme: ?array}
	 */
	public function getPortal(): array
	{
		return [
			'name' => $this->getPortalName(),
			'url' => UrlManager::getInstance()->getHostUrl(),
			'theme' => $this->getCurrentTheme(),
		];
	}

	/**
	 * Portal title, the same string the web shows. Read by the entry plate too, hence public rather
	 * than inlined into the portal line. The product name is a last resort against a blank row.
	 */
	public function getPortalName(): string
	{
		$name = (new PortalSettings())->getTitle();

		return $name === '' ? (string)Loc::getMessage('MOBILE_MY_COMPANY_PORTAL_NAME') : $name;
	}

	/**
	 * One permission of the request, read once and handed to whoever asks: the invite button of the
	 * employees card, the specialists tile and the matching restriction of the menu answer.
	 */
	public function canInvite(): bool
	{
		return $this->canInvite ??= Loader::includeModule('intranet')
			&& Loader::includeModule('intranetmobile')
			&& Invitation::canCurrentUserInvite()
		;
	}

	/**
	 * The module check goes in front of the provider: its picker is built in the constructor and
	 * costs a query and a themes include, and none of that is of use without intranet.
	 */
	private function getCurrentTheme(): ?array
	{
		if (!Loader::includeModule('intranet'))
		{
			return null;
		}

		return (new ThemeProvider($this->user->id))->getCurrentTheme();
	}

	private function isCompanyEmployee(): bool
	{
		return !$this->user->isExtranet && !$this->user->isCollaber && !$this->user->isGuest;
	}
}
