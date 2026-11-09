<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Grid\Row\Action\Dashboard;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupAvailability;
use Bitrix\BIConnector\Internal\Digest\Service\DigestSetupAvailabilityService;
use Bitrix\BIConnector\Superset\Grid\Row\Action\FormatMenuAction;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class ConfigureDigestAction extends FormatMenuAction
{
	public function __construct(
		private readonly DigestSetupAvailabilityService $availabilityService,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'configure-digest';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		return null;
	}

	protected function getText(): string
	{
		Loc::loadMessages(__FILE__);

		return (string)Loc::getMessage('BICONNECTOR_DASHBOARD_GRID_ACTION_CONFIGURE_DIGEST');
	}

	public function getControl(array $rawFields): ?array
	{
		$dashboardId = (int)($rawFields['ID'] ?? 0);
		if ($dashboardId <= 0)
		{
			return null;
		}

		$ormObject = SupersetDashboardTable::createObject()
			->setId($dashboardId)
			->setType((string)($rawFields['TYPE'] ?? ''))
			->setStatus((string)($rawFields['STATUS'] ?? ''))
			->setExternalId((int)($rawFields['EXTERNAL_ID'] ?? 0))
		;
		$availability = $this->availabilityService->check(
			new Dashboard($ormObject),
			(int)CurrentUser::get()->getId(),
		);
		if ($availability->state === DigestSetupAvailability::HIDDEN)
		{
			return null;
		}

		$options = [
			'dashboardId' => $dashboardId,
			'availability' => $availability->toArray(),
		];
		$this->onclick = 'BX.BIConnector.AiDigestSetup.open(' . Json::encode($options) . ')';

		$result = parent::getControl($rawFields);
		$result['html'] = $this->getMenuItem('--o-ai-robot biconnector-grid-menu-item-icon-bitrix-gpt');
		$result['attrs'] = ['data-testid' => 'dashboard-configure-digest'];

		return $result;
	}
}
