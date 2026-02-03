<?php

namespace Bitrix\Im\V2\Application;

use Bitrix\Call\Call;
use Bitrix\Im\V2\Anchor\DI\AnchorContainer;
use Bitrix\Im\V2\Application\Config\PreloadedEntities;
use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Integration\AI\Transcription\TranscribeManager;
use Bitrix\Im\V2\Promotion\Internals\DeviceType;
use Bitrix\Im\V2\Integration\AI\EngineManager;
use Bitrix\ImOpenLines\V2\Status\Status;
use Bitrix\Im\V2\TariffLimit\Limit;
use Bitrix\Intranet\Portal;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;

class Config implements \JsonSerializable
{
	use ContextCustomer;

	private const NODE = '#bx-im-external-recent-list';
	private const RU_REGIONS = ['ru', 'by', 'kz', 'uz'];

	private Context $applicationContext;

	public function __construct(?Context $applicationContext = null)
	{
		$this->applicationContext = $applicationContext ?? Context::getCurrent();
	}

	public function jsonSerialize(): array
	{
		return [
			'node' => self::NODE,
			'preloadedList' => $this->getPreloadedList(),
			'activeCalls' => $this->getActiveCalls(),
			'permissions' => $this->getPermissions(),
			'marketApps' => $this->getMarketApps(),
			'isCurrentUserAdmin' => $this->getCurrentUser()->isAdmin(),
			'loggerConfig' => $this->getLoggerConfig(),
			'counters' => $this->getCounters(),
			'settings' => $this->getSettings(),
			'promoList' => $this->getPromoList(),
			'phoneSettings' => $this->getPhoneSettings(),
			'sessionTime' => $this->getSessionTime(),
			'featureOptions' => $this->getFeatureOptions(),
			'sessionStatusMap' => $this->getSessionStatusMap(),
			'tariffRestrictions' => $this->getTariffRestrictions(),
			'anchors' => $this->getAnchors(),
			'copilot' => $this->getCopilotData(),
			'preloadedEntities' => $this->getPreloadedEntities()->toRestFormat(),
			'serviceHealthUrl' => $this->getServiceHealthUrl(),
			'aiSettings' => $this->getAiSettings(),
		];
	}

	public function getDesktopDownloadLink(): string
	{
		$region = Application::getInstance()->getLicense()->getRegion();

		return match ($region) {
			'ru' => 'https://www.bitrix24.ru/features/downloads/',
			'by' => 'https://www.bitrix24.by/apps/desktop.php',
			'kz' => 'https://www.bitrix24.kz/apps/desktop.php',
			'uz' => 'https://www.bitrix24.uz/apps/desktop.php',
			'uk' => 'https://www.bitrix24.uk/apps/desktop.php',
			'in' => 'https://www.bitrix24.in/apps/desktop.php',
			'eu' => 'https://www.bitrix24.eu/apps/desktop.php',
			'br' => 'https://www.bitrix24.com.br/apps/desktop.php',
			'la' => 'https://www.bitrix24.es/apps/desktop.php',
			'mx' => 'https://www.bitrix24.mx/apps/desktop.php',
			'co' => 'https://www.bitrix24.co/apps/desktop.php',
			'tr' => 'https://www.bitrix24.com.tr/apps/desktop.php',
			'fr' => 'https://www.bitrix24.fr/apps/desktop.php',
			'it' => 'https://www.bitrix24.it/apps/desktop.php',
			'pl' => 'https://www.bitrix24.pl/apps/desktop.php',
			'de' => 'https://www.bitrix24.de/apps/desktop.php',
			default => 'https://www.bitrix24.com/apps/desktop.php',
		};
	}

	public function getInternetCheckLink(): string
	{
		$region = Application::getInstance()->getLicense()->getRegion();

		return
			in_array($region, self::RU_REGIONS, true)
				? '//www.1c-bitrix.ru/200.ok'
				: '//www.bitrixsoft.com/200.ok'
			;
	}

	protected function getServiceHealthUrl(): string
	{
		$license = Application::getInstance()->getLicense();

		$baseUrl = $license->isCis()
			? 'https://status.bitrix24.ru/json_status.php?reg='
			: 'https://status.bitrix24.com/json_status.php?reg='
		;

		return $baseUrl . $license->getRegion();
	}

	protected function getPreloadedList(): array
	{
		return \Bitrix\Im\Recent::getList($this->getContext()->getUserId(), [
			'SKIP_NOTIFICATION' => 'Y',
			'SKIP_OPENLINES' => 'Y',
			'JSON' => 'Y',
			'GET_ORIGINAL_TEXT' => 'Y',
			'SHORT_INFO' => 'Y',
		]) ?: [];
	}

	protected function getActiveCalls(): array
	{
		if (!Loader::includeModule('call'))
		{
			return [];
		}

		return Call::getActiveCalls();
	}

	protected function getPermissions(): array
	{
		$permissionManager = new \Bitrix\Im\V2\Permission(true);

		return [
			'byChatType' => $permissionManager->getByChatTypes(),
			'byUserType' => $permissionManager->getByUserTypes(),
			'actionGroups' => $permissionManager->getActionGroupDefinitions(),
			'actionGroupsDefaults' => $permissionManager->getDefaultPermissionForGroupActions()
		];
	}

	protected function getMarketApps(): array
	{
		return (new \Bitrix\Im\V2\Marketplace\Application())->toRestFormat();
	}

	protected function getCurrentUser(): User
	{
		return User::getCurrent();
	}

	protected function getLoggerConfig(): array
	{
		return \Bitrix\Im\Settings::getLoggerConfig();
	}

	protected function getCounters(): array
	{
		return (new \Bitrix\Im\V2\Message\CounterService($this->getContext()->getUserId()))->get();
	}

	protected function getSettings(): array
	{
		$settings = (new \Bitrix\Im\V2\Settings\UserConfiguration($this->getContext()->getUserId()))->getGeneralSettings();
		$settings['notifications'] = (new \Bitrix\Im\V2\Settings\UserConfiguration($this->getContext()->getUserId()))->getNotifySettings();

		return $settings;
	}

	protected function getPromoList(): array
	{
		$promoService = ServiceLocator::getInstance()->get('Im.Services.Promotion');
		$promoType = $this->applicationContext->isDesktop() ? DeviceType::DESKTOP : DeviceType::BROWSER;

		return $promoService->getActive($promoType)->toRestFormat();
	}

	protected function getPhoneSettings(): array
	{
		return \CIMMessenger::getPhoneSettings();
	}

	protected function getSessionTime(): int
	{
		return (new \Bitrix\Im\V2\UpdateState())->getInterval() ?? 0;
	}

	protected function getFeatureOptions(): Features
	{
		return Features::get();
	}

	protected function getSessionStatusMap(): array
	{
		if (!\Bitrix\Main\Loader::includeModule('imopenlines'))
		{
			return [];
		}

		return Status::getMap();
	}

	protected function getTariffRestrictions(): array
	{
		return Limit::getInstance()->getRestrictions();
	}

	protected function getAnchors(): array
	{
		$anchorProvider = AnchorContainer::getInstance()
			->getAnchorProvider()
			->setContext($this->getContext());

		return $anchorProvider->getUserAnchors();
	}

	protected function getCopilotData(): array
	{
		return [
			'availableEngines' => (new EngineManager())->getAvailableEnginesForRest(),
		];
	}

	protected function getPreloadedEntities(): PreloadedEntities
	{
		return new PreloadedEntities();
	}

	protected function getAiSettings(): array
	{
		return [
			'maxTranscribableFileSize' => TranscribeManager::MAX_TRANSCRIBABLE_FILE_SIZE,
		];
	}

	public function getPortalSettingsUrl(): string
	{
		if (!Loader::includeModule('intranet'))
		{
			return '';
		}

		return Portal::getInstance()->getSettings()->getSettingsUrl();
	}
}
