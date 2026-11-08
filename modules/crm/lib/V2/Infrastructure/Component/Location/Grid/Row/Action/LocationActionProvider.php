<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\Row\Action;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Integration\Sale\Location\LocationAdapter;
use Bitrix\Main\Grid\Row\Action\DataProvider;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\Localization\Loc;

final class LocationActionProvider extends DataProvider
{
	private readonly LocationAdapter $locationAdapter;
	private readonly UserPermissions $userPermissions;

	public function __construct(
		Settings $settings,
		private readonly bool $canEdit,
		?LocationAdapter $locationAdapter = null,
		?UserPermissions $userPermissions = null,
	)
	{
		parent::__construct($settings);
		$this->locationAdapter = $locationAdapter ?? new LocationAdapter();
		$this->userPermissions = $userPermissions ?? Container::getInstance()->getUserPermissions();
	}

	public function prepareActions(): array
	{
		if (!$this->canEdit)
		{
			return [];
		}

		return [
			new DeleteAction($this->getSettings(), $this->locationAdapter, $this->userPermissions),
		];
	}

	public function prepareControls(array $rawFields): array
	{
		$controls = parent::prepareControls($rawFields);
		$editUrl = (string)($rawFields['PATH_TO_LOCATIONS_EDIT'] ?? '');
		if (!$this->canEdit || $editUrl === '')
		{
			return $controls;
		}

		Container::getInstance()->getLocalization()->loadMessages();
		$actionText = (string)Loc::getMessage('CRM_COMMON_ACTION_EDIT');

		return [
			[
				'TITLE' => $actionText,
				'TEXT' => $actionText,
				'HREF' => $editUrl,
			],
			...$controls,
		];
	}
}
