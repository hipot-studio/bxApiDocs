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
			$pinButton = $this->getPinButton($id, $isPinned, (string)$value['TITLE']);
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
				$onclick = htmlspecialcharsbx("top.BX.UI.InfoHelper.show('" . \CUtil::JSEscape($sliderCode) . "');");
			}

			$link = $this->getLockedTitleButton($title, $onclick, 'tariff-lock');
		}
		elseif ($value['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_GROUP)
		{
			Extension::load('ui.icons.disk');
			$classTypePrefix = 'group';

			$ormFilter = $this->getSettings()->getOrmFilter();
			$isFiltered = isset($ormFilter['GROUPS.ID']) && in_array((int)$value['ID'], $ormFilter['GROUPS.ID']);
			$eventGroup = Json::encode([
				'ID' => (int)$value['ID'],
				'TITLE' => $value['TITLE'],
				'IS_FILTERED' => $isFiltered,
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

			$payload = htmlspecialcharsbx($eventGroup);
			$isPressed = $isFiltered ? 'true' : 'false';
			$onclick = 'BX.BIConnector.SupersetDashboardGridManager.Instance'
				. '.handleGroupTitleClick(JSON.parse(this.dataset.bicPayload))'
			;

			$link = "
				<div class ='biconnector-grid-group-name'>
					<div class='ui-icon {$iconClass} biconnector-grid-group-icon'>
						<i style='margin-left: 0;'></i>
					</div>
					<div>
						<button
							type='button'
							class='biconnector-grid-name-link biconnector-grid-name-link-button'
							data-bic-focus-role='group-enter'
							aria-pressed='{$isPressed}'
							data-bic-payload='{$payload}'
							onclick='{$onclick}'
						>
							{$title}
						</button>
						<div class='biconnector-grid-group-name-subtitle'>{$subtitle}</div>
					</div>
				</div>
			";
		}
		elseif (!$value['IS_ACCESS_ALLOWED'])
		{
			$link = $title;
		}
		elseif (!$this->isBuilderEnabled())
		{
			$link = $this->getLockedTitleButton($title, "BX.UI.InfoHelper.show('limit_crm_BI_constructor')");
		}
		elseif ($this->isDashboardLocked())
		{
			$link = $this->getLockedTitleButton(
				$title,
				htmlspecialcharsbx(SelfHostedLicenseLock::getOpenSliderScript()),
				'ui-icon-set --lock-m',
				'data-testid="biconnector-selfhost-license-locked-dashboard"',
			);
		}
		else
		{
			$value['DETAIL_URL'] .=
				(str_contains($value['DETAIL_URL'], '?') ? '&' : '?')
				. 'openFrom=grid'
			;

			if (!$this->isDashboardAvailableByType($value['TYPE'] ?? ''))
			{
				$link = $this->getLockedTitleButton(
					$title,
					"BX.UI.InfoHelper.show('limit_benefit_market_active')",
				);
			}
			else
			{
				$link = "<a href='{$value['DETAIL_URL']}' target='_blank' data-bic-focus-role='title'>{$title}</a>";
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

	protected function getPinButton(int $dashboardId, bool $isPinned, string $title): string
	{
		if (!$this->isBuilderEnabled() || $this->isDashboardLocked())
		{
			return '';
		}

		$iconClass = $isPinned ? '--pin-2 dashboard-unpin-icon' : '--o-pin-list dashboard-pin-icon';
		$method =
			$isPinned
				? 'BX.BIConnector.SupersetDashboardGridManager.Instance.unpin'
				: 'BX.BIConnector.SupersetDashboardGridManager.Instance.pin'
		;
		$label = htmlspecialcharsbx((string)Loc::getMessage(
			$isPinned ? 'BI_DASHBOARD_UNPIN_ARIA' : 'BI_DASHBOARD_PIN_ARIA',
			['#TITLE#' => $title],
		));

		return <<<HTML
			<button
				type="button"
				class="dashboard-title-pin-button"
				data-bic-focus-role="pin"
				aria-label="{$label}"
				onclick="event.stopPropagation(); {$method}({$dashboardId})"
			>
				<i
					class="ui-icon-set {$iconClass}"
					aria-hidden="true"
				></i>
			</button>
		HTML;
	}

	/**
	 * The name of a report that opens a restriction instead of the report: a button, since it navigates nowhere.
	 * The padlock carries the reason as its name, otherwise the report would read as an ordinary one.
	 * It follows the title in the DOM, so the name of the button starts with the visible text; CSS shows it first.
	 *
	 * @param string $escapedTitle Title already passed through htmlspecialcharsbx.
	 * @param string $escapedOnclick Handler already escaped for an attribute.
	 */
	private function getLockedTitleButton(
		string $escapedTitle,
		string $escapedOnclick,
		string $lockClass = 'ui-icon-set --lock-m',
		string $extraAttributes = '',
	): string
	{
		$lockLabel = htmlspecialcharsbx((string)Loc::getMessage('BI_DASHBOARD_NAME_LOCKED'));
		$onclick = $escapedOnclick !== '' ? "onclick=\"{$escapedOnclick}\"" : '';

		return <<<HTML
			<button
				type="button"
				class="biconnector-grid-name-link biconnector-grid-name-link-button"
				data-bic-focus-role="title"
				{$extraAttributes}
				{$onclick}
			>
				{$escapedTitle}
				<span class="{$lockClass}" role="img" aria-label="{$lockLabel}"></span>
			</button>
			HTML;
	}

	protected function isBuilderEnabled(): bool
	{
		return Feature::isBuilderEnabled();
	}

	protected function isDashboardLocked(): bool
	{
		return SelfHostedLicenseLock::isDashboardLocked();
	}

	protected function isDashboardAvailableByType(string $type): bool
	{
		return $this->marketAccessManager->isDashboardAvailableByType($type);
	}

	/**
	 * @param array $dashboardData Dashboard fields described in prepareRow method.
	 *
	 * @return bool
	 */
	protected function canEditDashboard(array $dashboardData): bool
	{
		if (!$this->isBuilderEnabled() || $this->isDashboardLocked())
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
			&& $this->isDashboardAvailableByType((string)($dashboardData['TYPE'] ?? ''))
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
			data-bic-focus-role="constructor"
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
