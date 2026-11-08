<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\Panel;

use Bitrix\Main\Grid\Panel\Action\DataProvider;
use Bitrix\Main\Grid\Panel\Action\ForAllCheckboxAction;

final class LocationActionPanelProvider extends DataProvider
{
	public function __construct(
		\Bitrix\Main\Grid\Settings $settings,
		private readonly bool $canEdit,
	)
	{
		parent::__construct($settings);
	}

	public function prepareActions(): array
	{
		return $this->canEdit
			? [new BatchDeleteAction(), new ForAllCheckboxAction()]
			: [];
	}
}
