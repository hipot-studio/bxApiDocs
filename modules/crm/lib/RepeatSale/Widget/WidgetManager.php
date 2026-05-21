<?php

namespace Bitrix\Crm\RepeatSale\Widget;

use Bitrix\Bitrix24\LicenseScanner\Manager;
use Bitrix\Crm\RepeatSale\Statistics\PeriodType;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Router\Page\RepeatSale;
use Bitrix\Crm\Tour\Config;
use Bitrix\Crm\Tour\RepeatSale\ConfigureSegment;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
use CUserOptions;

final class WidgetManager
{
	use Singleton;

	public function showBanner(): void
	{
		$widgetConfig = $this->getWidgetConfig();
		if ($widgetConfig === null)
		{
			return;
		}

		$type = $widgetConfig['type'] ?? null;
		$executeImmediately = $widgetConfig['executeImmediately'] ?? false;
		$showConfetti = $widgetConfig['showConfetti'] ?? false;
		$periodTypeId = $widgetConfig['periodTypeId'] ?? PeriodType::Day30->value;
		$isGlowingSettingsButton = $widgetConfig['isGlowingSettingsButton'] ?? false;
		$showSettingsButton = $widgetConfig['showSettingsButton'] ?? true;

		$params = Json::encode([
			'showConfetti' => $showConfetti,
			'periodTypeId' => $periodTypeId,
			'isGlowingSettingsButton' => $isGlowingSettingsButton,
			'showSettingsButton' => $showSettingsButton,
		]);

		if ($type && $executeImmediately):
			Extension::load([
				'crm.integration.ui.banner-dispatcher',
			]);
			?>
			<script>
			BX.ready(
				() => {
					const target = document.querySelector('[data-id="crm-repeat-sale-widget-button"]');

					const bannerDispatcher = new BX.Crm.Integration.UI.BannerDispatcher();
					bannerDispatcher.toQueue((onDone) => {
						BX.Crm.RepeatSale.Widget.execute(
							'<?= $type ?>',
							target,
							<?= $params ?>,
							null,
							onDone,
						);
					}, 'high');
				}
			);
		</script>
		<?php
		endif;
	}

	public function getWidgetConfig(): ?array
	{
		$availabilityChecker = Container::getInstance()->getRepeatSaleAvailabilityChecker();
		if (
			!$availabilityChecker->isAvailable()
			|| !$availabilityChecker->hasPermission()
			|| !$availabilityChecker->isItemsCountsLessThenLimit()
		)
		{
			return null;
		}

		$isEnablePending = $availabilityChecker->isEnablePending();
		$showSettingsButton = $this->shouldShowSettingsButton();
		$repeatSalePermission = Container::getInstance()->getUserPermissions()->repeatSale();
		if ($isEnablePending)
		{
			if (!$repeatSalePermission->canEdit() && !$availabilityChecker->isForceMode())
			{
				return null;
			}

			$isNeedShowStartBanner = (new StartBanner())->isNeedShowImmediately();

			return [
				'type' => $availabilityChecker->isForceMode() ? 'forceStart' : 'start',
				'executeImmediately' => $isNeedShowStartBanner && !Config::isToursDeactivated('crm.tour'),
				'showSettingsButton' => $showSettingsButton,
			];
		}

		if ($repeatSalePermission->canRead())
		{
			$isNeedShowConfetti = (new Confetti())->isNeedShowConfetti();
			$isGlowingSettingsButton = ConfigureSegment::getInstance()->isGlowingSettingsButton();

			$periodTypeId = CUserOptions::GetOption('crm', 'repeat-sale')['statistics-period-type-id']
				?? PeriodType::Day30->value;

			return [
				'type' => 'statistics',
				'executeImmediately' => $isNeedShowConfetti && !Config::isToursDeactivated('crm.tour'),
				'showConfetti' => $isNeedShowConfetti,
				'periodTypeId' => $periodTypeId,
				'isGlowingSettingsButton' => $isGlowingSettingsButton,
				'showSettingsButton' => $showSettingsButton,
			];
		}

		return null;
	}

	public function getFeedbackFormParams(): array
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return [];
		}

		$licenseScanner = Manager::getInstance();
		if ($licenseScanner->shouldLockPortal())
		{
			return [];
		}

		return [
			[
				'zones' => ['en'],
				'id' => 826,
				'lang' => 'en',
				'sec' => 'ma3ms9',
			],
			[
				'zones' => ['ru'],
				'id' => 818,
				'lang' => 'ru',
				'sec' => 'vgluix',
			],
			[
				'zones' => ['de'],
				'id' => 824,
				'lang' => 'de',
				'sec' => 'c59bdw',
			],
			[
				'zones' => ['br'],
				'id' => 820,
				'lang' => 'br',
				'sec' => '2a9g49',
			],
			[
				'zones' => ['es'],
				'id' => 822,
				'lang' => 'es',
				'sec' => '9e9sm1',
			],
		];
	}

	private function shouldShowSettingsButton(): bool
	{
		$request = Application::getInstance()->getContext()->getRequest();
		if (!$request)
		{
			return true;
		}

		$page = Container::getInstance()->getRouter()->matchPage($request);

		return !($page instanceof RepeatSale\Segment\ListPage);
	}
}
