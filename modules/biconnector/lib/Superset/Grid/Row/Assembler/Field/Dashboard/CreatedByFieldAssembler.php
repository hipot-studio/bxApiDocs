<?php

namespace Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\BIConnector\Superset\Grid\Row\Assembler\Field\Base\UserFieldAssembler;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

class CreatedByFieldAssembler extends UserFieldAssembler
{
	protected function prepareColumn($value): string
	{
		if ($value['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_GROUP)
		{
			return '';
		}

		$ormFilter = $this->getSettings()->getOrmFilter();
		$isFiltered = isset($ormFilter['CREATED_BY_ID']) && in_array((int)$value['CREATED_BY_ID'], $ormFilter['CREATED_BY_ID']);

		$createdById = (int)$value['CREATED_BY_ID'];
		$type = $value['TYPE'];

		if ($type === SupersetDashboardTable::DASHBOARD_TYPE_MARKET)
		{
			$authorText = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_GRID_AUTHOR_MARKET_MSGVER_1');

			return "<span class=\"biconnector-grid-market-cell\">
				<img
					src=\"/bitrix/images/biconnector/superset-dashboard-grid/icon-type-market.png\"
					width='24' height='24' alt=\"\"
				>
				<span class=\"biconnector-grid-username\">{$authorText}</span>
			</span>";
		}

		if ($type === SupersetDashboardTable::DASHBOARD_TYPE_SYSTEM)
		{
			$authorText = Loc::getMessage('BICONNECTOR_SUPERSET_DASHBOARD_GRID_AUTHOR_SYSTEM');

			return "<span class=\"biconnector-grid-market-cell\">
				<img src=\"/bitrix/images/biconnector/database-connections/system.svg\" width='24' height='24' alt=\"\">
				<span class=\"biconnector-grid-username\">{$authorText}</span>
			</span>";
		}

		if ($createdById > 0)
		{
			$userName = $this->loadUserName($createdById);
			if ($userName === '')
			{
				return $this->renderNamelessUser($isFiltered);
			}

			$user = \CUser::getByID($createdById)->fetch();
			$avatar = '';
			if ((int)$user['PERSONAL_PHOTO'] > 0)
			{
				$avatarSrc = $this->getAvatarSrc((int)$user['PERSONAL_PHOTO']);
				$avatar = " style=\"background-image: url('{$avatarSrc}');\"";
			}

			// The name comes already escaped for HTML and the handler gets it unescaped. The former onclick passed
			// the escaped one: its JSON hid "&" from the HTML parser, so the entities reached the handler as they were.
			$payload = htmlspecialcharsbx(Json::encode([
				'ID' => $createdById,
				'TITLE' => htmlspecialcharsback($userName),
				'IS_FILTERED' => $isFiltered,
			]));
			$isPressed = $isFiltered ? 'true' : 'false';
			$onclick = 'BX.BIConnector.SupersetDashboardGridManager.Instance'
				. '.handleCreatedByClick(JSON.parse(this.dataset.bicPayload))'
			;

			$selector = 'biconnector-grid-username-cell';
			$closeIcon = '';
			if ($isFiltered)
			{
				$selector .= ' biconnector-grid-username-cell-active';
				$closeIcon = '<span class="biconnector-grid-filter-close ui-icon-set --cross-20"'
					. ' aria-hidden="true"></span>'
				;
			}

			return <<<HTML
<button
	type="button"
	class="$selector"
	data-bic-focus-role="author-filter"
	aria-pressed="{$isPressed}"
	data-bic-payload="{$payload}"
	onclick="{$onclick}"
>
	<span class="biconnector-grid-avatar ui-icon ui-icon-common-user">
		<i{$avatar}></i>
	</span>
	<span class="biconnector-grid-username">$userName</span>
	$closeIcon
</button>
HTML;
		}

		return '';
	}
}
