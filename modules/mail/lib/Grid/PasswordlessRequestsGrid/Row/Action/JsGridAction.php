<?php

declare(strict_types=1);

namespace Bitrix\Mail\Grid\PasswordlessRequestsGrid\Row\Action;

use Bitrix\Mail\Grid\PasswordlessRequestsGrid\Settings\PasswordlessRequestsSettings;
use Bitrix\Mail\Helper\Dto\PasswordlessRequestsGrid\PasswordlessRequestRowDto;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Web\Json;

abstract class JsGridAction extends BaseAction
{
	private string $actionId;
	private string $extensionName;
	private string $gridId;

	public function __construct(PasswordlessRequestsSettings $settings)
	{
		$this->actionId = $this->getActionId();
		$this->extensionName = $settings->getExtensionName();
		$this->gridId = $settings->getID();
	}

	abstract protected function isEnabled(PasswordlessRequestRowDto $dto): bool;
	abstract public function getActionId(): string;

	/**
	 * @return array{mailboxId: int}
	 */
	abstract protected function getActionParams(PasswordlessRequestRowDto $dto): array;

	/**
	 * @param array{
	 *     ID: int,
	 *     EMAIL: string,
	 *     USER_ID: int,
	 *     ACTIVE: string,
	 *     OWNER_DATA: array,
	 *     DATE_SENT: ?int,
	 * } $rawFields
	 * @return ?array{
	 *     text: string,
	 *     ONCLICK?: string,
	 *     className?: string,
	 * }
	 */
	public function getControl(array $rawFields): ?array
	{
		$dto = PasswordlessRequestRowDto::fromGridRow($rawFields);

		$actionParams = $this->getActionParams($dto);
		$params = Json::encode([
			'actionId' => $this->actionId,
			'params' => $actionParams,
		]);

		$this->onclick = sprintf(
			"BX.%s.GridManager.getInstance('%s').runAction(%s)",
			$this->extensionName,
			$this->gridId,
			$params,
		);

		$control = parent::getControl($rawFields);

		if (isset($control) && !$this->isEnabled($dto))
		{
			$disabledClass = 'menu-popup-item-disabled';
			$control['className'] =
				isset($control['className'])
					? $control['className'] . ' ' . $disabledClass
					: $disabledClass
			;

			unset($control['ONCLICK']);
		}

		return $control;
	}
}
