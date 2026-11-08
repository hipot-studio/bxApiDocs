<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Configuration\DashboardTariffConfigurator;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\DetailLinkFieldAssembler;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseLock;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\Uri;

class NameFieldAssembler extends DetailLinkFieldAssembler
{
	public function __construct(
		array $columnIds,
		?Settings $settings,
		private readonly MarketAccessManager $marketAccessManager,
	)
	{
		parent::__construct($columnIds, $settings);
	}

	protected function prepareColumn($value): string
	{
		$id = (int)$value['ID'];
		$title = htmlspecialcharsbx($value['TITLE']);

		$classTypePrefix = 'dashboard';
		$pinButton = '';
		if ($value['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_DASHBOARD)
		{
			$isPinned = (bool)$value['IS_PINNED'];
			$pinButton = $this->getPinButton($id, $isPinned);
		}

		$constructorButton = '';

		if (
			!empty($value['EDIT_URL'])
			&& $this->canShowConstructorButton($value)
			&& $this->canEditDashboard($value)
		)
		{
			$constructorButton = $this->getConstructorButton($value);
		}

		if ($value['IS_TARIFF_RESTRICTED'])
		{
			$onclick = '';
			$sliderCode = DashboardTariffConfigurator::getSliderRestrictionCodeByAppId($value['APP_ID']);
			if (!empty($sliderCode))
			{
				$onclick = "onclick=\"top.BX.UI.InfoHelper.show('{$sliderCode}');\"";
			}

			$link = "
				<a 
					style='cursor: pointer' 
					{$onclick}
				>
					<span class='tariff-lock'></span> {$title}
				</a>
			";
		}
		elseif ($value['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_GROUP)
		{
			Extension::load('ui.icons.disk');
			$classTypePrefix = 'group';

			$ormFilter = $this->getSettings()->getOrmFilter();
			$eventGroup = Json::encode([
				'ID' => (int)$value['ID'],
				'TITLE' => $value['TITLE'],
				'IS_FILTERED' => isset($ormFilter['GROUPS.ID']) && in_array((int)$value['ID'], $ormFilter['GROUPS.ID']),
			]);

			$iconClass =
				$value['TYPE'] === SupersetDashboardGroupTable::GROUP_TYPE_SYSTEM
					? 'ui-icon-file-air-folder-24'
					: 'ui-icon-file-air-folder-person'
			;

			$dashboardCount = $value['COUNT_DASHBOARDS'];
			$subtitle = Loc::getMessagePlural('BI_GROUP_SUBTITLE', $dashboardCount, [
				'#COUNT#' => $dashboardCount,
			]);

			$link = "
				<div class ='biconnector-grid-group-name'>
					<div class='ui-icon {$iconClass} biconnector-grid-group-icon'>
						<i style='margin-left: 0;'></i>
					</div>
					<div>
						<a
							class ='biconnector-grid-name-link'
							onclick='BX.BIConnector.SupersetDashboardGridManager.Instance.handleGroupTitleClick($eventGroup)'
						>
							{$title}
						</a>
						<div class='biconnector-grid-group-name-subtitle'>{$subtitle}</div>
					</div>
				</div>
			";
		}
		elseif (!$value['IS_ACCESS_ALLOWED'])
		{
			$link = $title;
		}
		elseif (!Feature::isBuilderEnabled())
		{
			$link = <<<HTML
				<a
					class="biconnector-grid-name-link"
					onclick="BX.UI.InfoHelper.show('limit_crm_BI_constructor')"
				>
					<span class="ui-icon-set --lock-m"></span>
					{$title}
				</a>
				HTML;
		}
		elseif (SelfHostedLicenseLock::isDashboardLocked())
		{
			$openSlider = SelfHostedLicenseLock::getOpenSliderScript();
			// A button and not a link: the padlock opens the restriction and navigates nowhere. The padlock itself
			// carries the reason as text, otherwise the report would read as an ordinary one to a screen reader.
			$lockLabel = htmlspecialcharsbx((string)Loc::getMessage('BI_DASHBOARD_NAME_LOCKED'));
			$link = <<<HTML
				<button
					type="button"
					class="biconnector-grid-name-link biconnector-grid-name-link-button"
					data-testid="biconnector-selfhost-license-locked-dashboard"
					onclick="{$openSlider}"
				>
					<span class="ui-icon-set --lock-m" role="img" aria-label="{$lockLabel}"></span>
					{$title}
				</button>
				HTML;
		}
		else
		{
			$value['DETAIL_URL'] .=
				(str_contains($value['DETAIL_URL'], '?') ? '&' : '?')
				. 'openFrom=grid'
			;

			if (!$this->marketAccessManager->isDashboardAvailableByType($value['TYPE'] ?? ''))
			{
				$link = "
					<div style='display: flex; align-items: center;'>
						<span class='ui-icon-set --lock-m'></span>
						<a
							class ='biconnector-grid-name-link'
							onclick='BX.UI.InfoHelper.show(\"limit_benefit_market_active\")'
						>
							{$title}
						</a>
					</div>
				";
			}
			else
			{
				$link = "<a href='{$value['DETAIL_URL']}' target='_blank'>{$title}</a>";
			}
		}

		$buttons = '';
		if (!empty($pinButton) || !empty($constructorButton))
		{
			$buttons = "
				<div class='dashboard-title-buttons'>
					{$constructorButton}
					{$pinButton}
				</div>
			";
		}

		return <<<HTML
			<div class="{$classTypePrefix}-title-wrapper">
				<div class="{$classTypePrefix}-title-wrapper__item {$classTypePrefix}-title-preview">
					<div class="biconnector-grid-title">
						{$link}
					</div>
					{$buttons}
				</div>
			</div>
		HTML;
	}

	protected function getPinButton(int $dashboardId, bool $isPinned): string
	{
		if (!Feature::isBuilderEnabled() || SelfHostedLicenseLock::isDashboardLocked())
		{
			return '';
		}

		$iconClass = $isPinned ? '--pin-2 dashboard-unpin-icon' : '--o-pin-list dashboard-pin-icon';
		$method =
			$isPinned
				? 'BX.BIConnector.SupersetDashboardGridManager.Instance.unpin'
				: 'BX.BIConnector.SupersetDashboardGridManager.Instance.pin'
		;

		return <<<HTML
			<a
				onclick="event.stopPropagation(); {$method}({$dashboardId})"
			>
				<i
					class="ui-icon-set {$iconClass}"
				></i>
			</a>
		HTML;
	}

	/**
	 * @param array $dashboardData Dashboard fields described in prepareRow method.
	 *
	 * @return bool
	 */
	protected function canEditDashboard(array $dashboardData): bool
	{
		if (!Feature::isBuilderEnabled() || SelfHostedLicenseLock::isDashboardLocked())
		{
			return false;
		}

		if (!SupersetInitializer::isSupersetReady())
		{
			return false;
		}

		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => (int)$dashboardData['ID'],
			'TYPE' => $dashboardData['TYPE'],
			'STATUS' => $dashboardData['STATUS'],
		]);

		// Neither a system nor a market report is edited in place: the button copies it first,
		// so for them the copy right opens the button.
		$isCopiedBeforeEditing = in_array(
			$dashboardData['TYPE'],
			[
				SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM,
				SupersetDashboardTable::DASHBOARD_TYPE_MARKET,
			],
			true,
		);

		$action =
			$isCopiedBeforeEditing
				? ActionDictionary::ACTION_BIC_DASHBOARD_COPY
				: ActionDictionary::ACTION_BIC_DASHBOARD_EDIT
		;

		return AccessController::getCurrent()->check($action, $accessItem);
	}

	private function canShowConstructorButton(array $dashboardData): bool
	{
		return
			($dashboardData['ENTITY_TYPE'] ?? null) === DashboardGroupRepository::TYPE_DASHBOARD
			&& !empty($dashboardData['IS_ACCESS_ALLOWED'])
			&& empty($dashboardData['IS_TARIFF_RESTRICTED'])
			&& !empty($dashboardData['IS_AVAILABLE_DASHBOARD'])
			&& $this->marketAccessManager->isDashboardAvailableByType((string)($dashboardData['TYPE'] ?? ''))
		;
	}

	private function getConstructorButton(array $dashboardData): string
	{
		$editUrl = new Uri((string)$dashboardData['EDIT_URL']);
		$editUrl->addParams(['edit' => 'true']);

		$params = Json::encode([
			'dashboardId' => (int)$dashboardData['ID'],
			'type' => (string)$dashboardData['TYPE'],
			'editUrl' => $editUrl->getLocator(),
			'appId' => (string)$dashboardData['APP_ID'],
		]);

		$onclick = htmlspecialcharsbx(
			'event.stopPropagation(); '
			. 'BX.BIConnector.SupersetDashboardGridManager.Instance'
			. ".openDashboardInConstructor({$params});"
		);

		$label = (string)Loc::getMessage('BICONNECTOR_DASHBOARD_EDIT_IN_CONSTRUCTOR');

		return <<<HTML
		<button
			type="button"
			class="dashboard-title-constructor-button"
			title="{$label}"
			aria-label="{$label}"
			onclick="{$onclick}"
		>
			<i
				class="dashboard-title-constructor-icon"
				aria-hidden="true"
			></i>
		</button>
	HTML;
	}
}
