<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Landing\Vibe\Provider\AbstractVibeProvider;
use Bitrix\Landing\Vibe\Provider\VibeContextDto;
use Bitrix\Landing;
use Bitrix\Landing\Site;
use Bitrix\Landing\Scope\Guard;
use Bitrix\Landing\Vibe\Facade\Portal;
use Bitrix\Bitrix24\Feature;
use Bitrix\Main\UI\Extension;
use Bitrix\Pull;

class Vibe
{
	private string $moduleId;
	private string $embedId;
	private string $providerClass;
	private AbstractVibeProvider $provider;

	private ?int $entityId = null;
	private ?int $siteId = null;
	private ?int $landingId = null;
	private ?Landing\Landing $landingInstance = null;
	private ?Type\Status $status = null;

	private ?string $previewImg = null;

	private Portal $state;

	private const USE_DEMO_OPTION_CODE = 'use_demo_data_in_block_widgets';

	// region Common

	/**
	 * @param string $moduleId Module id.
	 * @param string $embedId Vibe embedId. Invalid characters are automatically removed
	 * to comply with the allowed contract (`a-z`, `A-Z`, `0-9`, `_`).
	 */
	public function __construct(string $moduleId, string $embedId)
	{
		$this->moduleId = $moduleId;
		$this->embedId = EmbedIdNormalizer::normalize($embedId);

		$this->detect();

		$this->state = new Portal();
	}

	/**
	 * @return string
	 */
	public function getModuleId(): string
	{
		return $this->moduleId;
	}

	/**
	 * @return string
	 */
	public function getEmbedId(): string
	{
		return $this->embedId;
	}

	/**
	 * If for current siteId exists Vibe - create object
	 * @param int $siteId
	 * @return Vibe|null
	 */
	public static function createBySiteId(int $siteId): ?Vibe
	{
		$res = Model\VibeTable::query()
			->setSelect(['ID', 'MODULE_ID', 'EMBED_ID'])
			->where('SITE_ID', $siteId)
			->setCacheTtl(86400)
			->exec()
		;
		if ($vibe = $res->fetch())
		{
			return new Vibe($vibe['MODULE_ID'], $vibe['EMBED_ID']);
		}

		return null;
	}

	/**
	 * Return Vibe objects for all exists embeds
	 * @return Vibe[] array
	 */
	public static function getList(): array
	{
		$res = Model\VibeTable::query()
			->setSelect(['ID', 'MODULE_ID', 'EMBED_ID'])
			->exec()
		;

		$mainVibes = [];
		$mainSorts = [];
		$vibes = [];
		$sorts = [];
		while ($row = $res->fetchObject())
		{
			$vibe = new Vibe($row->getModuleId(), $row->getEmbedId());
			$provider = $vibe->getProvider();

			if (
				!isset($provider)
				|| !$vibe->isAvailable()
			)
			{
				continue;
			}

			$sort = $provider->getSort();
			if ($vibe->isMainVibe())
			{
				$mainVibes[] = $vibe;
				$mainSorts[] = $sort;
			}
			else
			{
				$vibes[] = $vibe;
				$sorts[] = $sort;
			}
		}

		array_multisort($mainSorts, SORT_ASC, SORT_NUMERIC, $mainVibes, SORT_ASC, SORT_NUMERIC);
		array_multisort($sorts, SORT_ASC, SORT_NUMERIC, $vibes, SORT_ASC, SORT_NUMERIC);

		return array_merge($mainVibes, $vibes);
	}
	// endregion

	// region Detected
	private function detect(): void
	{
		$guard = new Guard\Vibe();
		$guard->start();

		$vibe = (Model\VibeTable::query())
			->where('MODULE_ID', '=', $this->moduleId)
			->where('EMBED_ID', '=', $this->embedId)
			->setSelect(['ID', 'SITE_ID', 'STATUS', 'PROVIDER_CLASS'])
			->setCacheTtl(86400)
			->fetchObject()
		;
		if (!$vibe)
		{
			return;
		}

		$this->entityId = $vibe->getId();
		$this->siteId = $vibe->getSiteId();
		$this->status = Type\Status::tryFrom($vibe->getStatus());
		if ($this->checkProviderClass($vibe->getProviderClass()))
		{
			$this->providerClass = $vibe->getProviderClass();
		}

		$site = (Landing\Site::getList([
			'select' => ['LANDING_ID_INDEX'],
			'filter' => [
				'=ID' => $this->siteId,
				'=ACTIVE' => 'Y',
				'TYPE' => Site\Type::SCOPE_CODE_VIBE,
				'=SPECIAL' => 'Y',
				'CHECK_PERMISSIONS' => 'N',
			],
			'cache' => ['ttl' => 86400],
		]))->fetch();

		if (!$site)
		{
			// todo: what?
		}

		if ($site['LANDING_ID_INDEX'])
		{
			// todo: check exists page
			$this->landingId = (int)$site['LANDING_ID_INDEX'];
			$this->previewImg = Landing\Manager::getUrlFromFile(Site::getPreview($this->getSiteId(), true));
		}
	}

	/**
	 * @return int|null
	 */
	public function getSiteId(): ?int
	{
		return $this->siteId;
	}

	/**
	 * @return int|null
	 */
	public function getLandingId(): ?int
	{
		return $this->landingId;
	}

	/**
	 * Get Landing instance of main vibe page
	 * @return Landing\Landing|null
	 */
	public function getLanding(): ?Landing\Landing
	{
		$lid = $this->landingId;
		if ($lid === null)
		{
			return null;
		}

		if (!isset($this->landingInstance))
		{
			$guard = new Guard\Vibe();
			$guard->start();

			$landing = Landing\Landing::createInstance($lid);
			if ($landing->exist())
			{
				$this->landingInstance = $landing;
			}
		}

		return $this->landingInstance;
	}

	/**
	 * @return string|null
	 */
	public function getPreviewImg(): ?string
	{
		return $this->previewImg;
	}

	/**
	 * @return Type\Status|null
	 */
	public function getStatus(): ?Type\Status
	{
		return $this->status;
	}

	private function saveStatus(Type\Status $status): bool
	{
		if (!isset($this->entityId))
		{
			return false;
		}

		return Model\VibeTable::update(
			$this->entityId,
			[
				'STATUS' => $status->value,
			]
		)->isSuccess();
	}
	// endregion

	// region Register
	/**
	 * @param class-string<AbstractVibeProvider> $dataProvider - class of data provider
	 * @return bool
	 * @throws \Exception
	 */
	public function register(string $dataProvider): bool
	{
		if (!$this->checkProviderClass($dataProvider))
		{
			return false;
		}

		$regResult =
			$this->isRegistered(false)
				? $this->registerUpdate($dataProvider)
				: $this->registerNew($dataProvider);

		if (!$regResult)
		{
			return false;
		}

		$this->providerClass = $dataProvider;

		return true;
	}

	private function registerNew(string $dataProvider): bool
	{
		$siteId = (int)$this->createDefaultSite();
		if ($siteId <= 0)
		{
			return false;
		}

		$resAdd = Model\VibeTable::add([
			'MODULE_ID' => $this->moduleId,
			'EMBED_ID' => $this->embedId,
			'SITE_ID' => $siteId,
			'STATUS' => Type\Status::Registered->value,
			'PROVIDER_CLASS' => $dataProvider,
		]);

		if ($resAdd->isSuccess())
		{
			$this->entityId = $resAdd->getId();
			$this->siteId = $siteId;
			$this->status = Landing\Vibe\Type\Status::Registered;

			return true;
		}

		return false;
	}

	private function registerUpdate(string $dataProvider): bool
	{
		$fields = [];

		if ($this->status === Type\Status::Unregistered)
		{
			$fields['STATUS'] = Type\Status::Registered->value;
		}

		if ($this->providerClass !== $dataProvider)
		{
			$fields['PROVIDER_CLASS'] = $dataProvider;
		}

		if (empty($fields))
		{
			return true;
		}

		return Model\VibeTable::update(
			$this->entityId,
			$fields
		)->isSuccess();
	}

	private function createDefaultSite(): ?int
	{
		$guard = new Guard\Vibe();
		$guard->start();

		$resAdd = Landing\Site::add([
			'TITLE' => Loc::getMessage('LANDING_VIBE_SITE_NAME'),
			'CODE' => strtolower(Site\Type::SCOPE_CODE_VIBE),
			'TYPE' => Site\Type::SCOPE_CODE_VIBE,
			'SPECIAL' => 'Y',
		]);

		$defaultSiteId = null;
		if ($resAdd->isSuccess())
		{
			$defaultSiteId = (int)$resAdd->getId();
		}

		return $defaultSiteId;
	}

	public function unregister(): bool
	{
		$vibe = (Model\VibeTable::query())
			->where('ID', '=', $this->entityId)
			->where('MODULE_ID', '=', $this->moduleId)
			->where('EMBED_ID', '=', $this->embedId)
			->setSelect(['ID'])
			->fetchObject()
		;
		if (!$vibe)
		{
			return false;
		}

		$res = Model\VibeTable::update(
			$vibe->getId(),
			[
				'STATUS' => Type\Status::Unregistered->value,
			]
		);
		$this->status = Type\Status::Unregistered;

		return $res->isSuccess();
	}

	/**
	 * Check is current Vibe already registered, save in table, create site
	 * @param bool $considerStatus - if false, check only exists, not check status (can be Unregistered)
	 * @return bool
	 */
	public function isRegistered(bool $considerStatus = true): bool
	{
		$isProviderExists = $this->getProvider() !== null;
		$isVibeCreated = isset($this->entityId);
		$isSiteCreated = isset($this->siteId);
		$isStatusOk = isset($this->status) && $this->status !== Type\Status::Unregistered;

		return
			$isProviderExists
			&& $isVibeCreated
			&& $isSiteCreated
			&& (!$considerStatus || $isStatusOk);
	}
	// endregion

	// region Provider
	public function getProvider(): ?AbstractVibeProvider
	{
		if (!isset($this->provider))
		{
			$className = $this->getProviderClass() ?? null;
			if (!$className)
			{
				return null;
			}

			$this->provider = new $className(new VibeContextDto(
				$this->moduleId,
				$this->embedId
			));
		}

		return $this->provider;
	}

	/**
	 * @return class-string<AbstractVibeProvider>|null - class name of data provider, null if not set or set incorrectly
	 */
	private function getProviderClass(): ?string
	{
		return $this->providerClass ?? null;
	}

	private function checkProviderClass(string $providerClass): bool
	{
		return
			class_exists($providerClass)
			&& is_subclass_of($providerClass, AbstractVibeProvider::class);
	}
	// endregion

	// region Status
	/**
	 * Check if we can use this vibe (view, edit, settings - all)
	 * @return bool
	 */
	public function isAvailable(): bool
	{
		if (!$this->isRegistered())
		{
			return false;
		}

		$isAvailable = $this->state->isIntranet();
		if (!$isAvailable)
		{
			return false;
		}

		try
		{
			if ($this->moduleId !== 'intranet')
			{
				$isAvailable = Loader::includeModule($this->moduleId);
			}
		}
		catch (\Exception $e)
		{
			return false;
		}

		return $isAvailable && $this->provider->isAvailable();
	}

	public function canView(): bool
	{
		return
			$this->isAvailable()
			&& $this->getProvider()?->canView()
		;
	}

	public function canEdit(): bool
	{
		return
			$this->canView()
			&& $this->getProvider()?->canEdit()
		;
	}

	public function getTitle(): ?string
	{
		return $this->getProvider()?->getTitle();
	}

	/**
	 * Title used on the vibe page itself (`landing.mainpage.pub`).
	 * If provider does not define it, it falls back to {@see self::getTitle()}.
	 *
	 * @return string|null
	 */
	public function getViewTitle(): ?string
	{
		$provider = $this->getProvider();
		if (!isset($provider))
		{
			return null;
		}

		return $provider->getViewTitle() ?? $provider->getTitle();
	}

	/**
	 * Get title of main vibe page
	 * @return string|null
	 */
	public function getPageTitle(): ?string
	{
		return $this->getLanding()?->getTitle();
	}

	public function getLimitCode(): string
	{
		return $this->getProvider()?->getLimitCode() ?? AbstractVibeProvider::DEFAULT_LIMIT_CODE;
	}

	public function isMainVibe(): bool
	{
		return $this->getProvider()?->isMainVibe() === true;
	}

	public function isProcessing(): bool
	{
		return $this->status === Landing\Vibe\Type\Status::Processed;
	}

	/**
	 * Check is Mainpage site is fully created, add all pages etc
	 * @return bool
	 */
	public function isReady(): bool
	{
		return $this->getLandingId() && $this->isFullyCreated();
	}

	protected function isFullyCreated(): bool
	{
		return $this->status === Landing\Vibe\Type\Status::Created;
	}

	public function isPublished(): bool
	{
		return (bool)$this->getProvider()?->isPublished();
	}

	// todo: i think bad logic, move to another
	public static function isFeatureEnable(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Feature::isFeatureEnabled(Portal::VIBE_FEATURE);
		}

		return true;
	}

	/**
	 * If true - enable some functionality at free tariff (by default in free tariff vibe is fully disabled)
	 * @param bool $flag
	 * @return void
	 */
	public function setFreeTariffMode(bool $flag = true): void
	{
		// todo: do! add field to table
		// Landing\Manager::setOption(self::FREE_MODE_OPTION_CODE, $flag ? 'Y' : 'N');
	}

	/**
	 * If true - enable some functionality at free tariff (by default in free tariff vibe is fully disabled)
	 * @return bool
	 */
	public function isFreeTariffMode(): bool
	{
		// todo: do
		return true;
		// return Landing\Manager::getOption(self::FREE_MODE_OPTION_CODE, 'N') === 'Y';
	}
	// endregion

	// region Creating
	/**
	 * Check is widgets must use demo data instead real data
	 * @return bool
	 */
	public static function isUseDemoData(): bool
	{
		return Landing\Manager::getOption(self::USE_DEMO_OPTION_CODE, 'N') === 'Y';
	}

	public function createDemoPage(): bool
	{
		return $this->createPageByTemplate();
	}

	public function createPageByTemplate(?Templates $code = null): bool
	{
		$provider = $this->getProvider();
		if (
			!isset($provider)
			|| !$this->isAvailable())
		{
			return false;
		}

		$siteId = $this->getSiteId();
		if (!$siteId)
		{
			return false;
		}

		$this->onStartPageCreation();

		$guard = new Guard\Vibe();
		$guard->start();
		$installer = new Installer($this);

		if ($code === null)
		{
			$newPageId = $installer->createDemoPage();
		}
		else
		{
			$newPageId = $installer->createPageByTemplate($code);
			// todo: do onTemplateCreation? (free tariff mode)
		}

		if (!$newPageId)
		{
			return false;
		}

		$this->landingId = $newPageId;
		$this->onFinishPageCreation();

		return true;
	}

	/**
	 * Mark is Vibe start creating.
	 * Not created or check site or pages, just mark start of creating process.
	 * @return void
	 */
	private function onStartPageCreation(): void
	{
		if ($this->status !== Type\Status::Created)
		{
			$this->saveStatus(Type\Status::Processed);
		}

		$this->publish();
	}

	// todo: change name
	private function onTemplateCreation(): void
	{
		// todo: move logic to eventhandler
		EventManager::getInstance()->registerEventHandler(
			'intranet',
			'onLicenseHasChanged',
			'landing',
			Integration\Intranet\EventHandler::class,
			'onLicenseHasChanged'
		);
		$this->setFreeTariffMode();
	}

	/**
	 * Mark is Vibe is fully created, add all pages etc.
	 * Not created or check site or pages, just mark end of creating process.
	 * @return void
	 * @throws LoaderException
	 */
	private function onFinishPageCreation(): void
	{
		$siteId = $this->getSiteId();
		$landingId = $this->getLandingId();
		if (!isset($siteId, $landingId))
		{
			return;
		}

		// todo: how for all?
		// $this->createSonetGroupForPublicationOnce();

		Landing\Site::update($siteId, [
			'LANDING_ID_INDEX' => $landingId,
		]);

		if (Loader::includeModule('pull'))
		{
			Pull\Event::add(
				Landing\Manager::getUserId(),
				[
					'module_id' => 'landing',
					'command' => 'Vibe:onCreate',
					'params' => [
						// todo: add vibe entity type
					],
				]
			);
		}

		$this->saveStatus(Type\Status::Created);
	}

	// todo: how create for all embeds?
	private function createSonetGroupForPublicationOnce(): bool
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return false;
		}

		$storedGroupId = (int)Landing\Manager::getOption('mainpage_id_publication_group', 0);
		if ($storedGroupId > 0)
		{
			return true;
		}

		$firstSubject = \CSocNetGroupSubject::GetList(
			["SORT" => "ASC", "NAME" => "ASC"],
			["SITE_ID" => SITE_ID],
			false,
			false,
			["ID", "NAME"]
		)->Fetch();

		$fields = array(
			"SITE_ID" => SITE_ID,
			"NAME" => Loc::getMessage('LANDING_VIBE_SOCIAL_GROUP_FOR_PUBLICATION_NAME'),
			"VISIBLE" => 'Y',
			"OPENED" => 'Y',
			"CLOSED" => 'N',
			"LANDING" => 'Y',
			"SUBJECT_ID" => $firstSubject['ID'] ?? 0,
			"INITIATE_PERMS" => 'E',
			"SPAM_PERMS" => 'E',
		);
		$newGroupId = (int)\CSocNetGroup::createGroup(Landing\Manager::getUserId(), $fields);
		if ($newGroupId && $newGroupId > 0)
		{
			// todo: move to own table
			Option::set('landing', 'mainpage_id_publication_group', $newGroupId);

			return true;
		}

		return false;
	}

	public function publish(): void
	{
		$this->getProvider()?->onPublish();
	}

	public function withdraw(): void
	{
		$this->getProvider()?->onWithdraw();
	}
	// endregion

	// region Render
	/**
	 * Get URL for public page
	 * @return string
	 */
	public function getUrlPublic(): string
	{
		$provider = $this->getProvider();
		if (!isset($provider))
		{
			return '/';
		}

		$publicUrl = $provider->getUrlPublic();
		if (!str_starts_with($publicUrl, '/'))
		{
			$publicUrl = '/' . $publicUrl;
		}
		if (!str_ends_with($publicUrl, '/'))
		{
			$publicUrl .= '/';
		}

		return $publicUrl;
	}

	public function renderView(): void
	{
		if (!$this->canView())
		{
			return;
		}

		global $APPLICATION;
		$APPLICATION->IncludeComponent(
			'bitrix:landing.mainpage.pub',
			'',
			[
				'MODULE_ID' => $this->moduleId,
				'EMBED_ID' => $this->embedId,
			],
		);
	}

	/**
	 * Render limit slider.
	 * @param string|null $code - if null - will be used code from getLimitCode()
	 * @return void
	 */
	public function showLimitSlider(?string $code = null): void
	{
		Extension::load([
			'sidepanel',
			'ui.info-helper',
		]);

		$sliderCode = $code ?? $this->getLimitCode();
		echo "<script>
			if (typeof BX.SidePanel !== 'undefined')
			{
				BX.UI.InfoHelper.show('$sliderCode');
			}
		</script>";
	}
	// endregion
}