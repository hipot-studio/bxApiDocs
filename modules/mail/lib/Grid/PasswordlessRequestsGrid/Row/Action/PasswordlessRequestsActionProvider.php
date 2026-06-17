<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Action;

use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Settings\PasswordlessRequestsSettings;
use Bitrix\Main\Grid\Row\Action\DataProvider;

/**
 * @method PasswordlessRequestsSettings getSettings()
 */
class PasswordlessRequestsActionProvider extends DataProvider
{
	/**
	 * @return array<JsGridAction>
	 */
	public function prepareActions(): array
	{
		return [
			new EditRequestAction($this->getSettings()),
			new RevokeRequestAction($this->getSettings()),
			new ResendRequestAction($this->getSettings()),
			new DeleteRequestAction($this->getSettings()),
		];
	}
}
