<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\Location\Grid\Row\Action;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Crm\V2\Internal\Integration\Sale\Location\LocationAdapter;
use Bitrix\Main\Error;
use Bitrix\Main\Grid\Row\Action\BaseAction;
use Bitrix\Main\Grid\Row\Action\Control\SendRowActionOnclick;
use Bitrix\Main\Grid\Settings;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class DeleteAction extends BaseAction
{
	public function __construct(
		private readonly Settings $settings,
		private readonly LocationAdapter $locationAdapter,
		private readonly UserPermissions $userPermissions,
	)
	{
	}

	public static function getId(): ?string
	{
		return 'delete';
	}

	public function processRequest(HttpRequest $request): ?Result
	{
		if (!$this->userPermissions->isCrmAdmin())
		{
			return (new Result())->addError(ErrorCode::getAccessDeniedError());
		}

		if (!check_bitrix_sessid())
		{
			return (new Result())->addError(new Error('Invalid session.', 'INVALID_SESSION'));
		}

		$id = (int)$request->getPost('id');
		if ($id <= 0)
		{
			return (new Result())->addError(new Error('Location id is invalid.', 'INVALID_ID'));
		}

		return $this->locationAdapter->deleteRoot($id);
	}

	protected function getText(): string
	{
		Container::getInstance()->getLocalization()->loadMessages();

		return (string)Loc::getMessage('CRM_COMMON_ACTION_DELETE');
	}

	public function getControl(array $rawFields): ?array
	{
		$id = (int)($rawFields['ID'] ?? 0);
		if ($id <= 0)
		{
			return null;
		}

		$currentLanguageField = 'NAME_' . mb_strtoupper(LANGUAGE_ID);
		$locationName = (string)($rawFields[$currentLanguageField] ?? '');
		if ($locationName === '')
		{
			$locationName = (string)($rawFields['NAME'] ?? '');
		}
		if ($locationName === '')
		{
			foreach ($rawFields as $fieldName => $fieldValue)
			{
				$fieldName = (string)$fieldName;
				$fieldValue = (string)$fieldValue;
				if (
					$fieldName !== $currentLanguageField
					&& str_starts_with($fieldName, 'NAME_')
					&& $fieldValue !== ''
				)
				{
					$locationName = $fieldValue;

					break;
				}
			}
		}

		$encodedLocationName = Json::encode($locationName);
		$sendRowAction = (string)new SendRowActionOnclick($this, ['id' => $id], $this->settings->getID());
		$this->onclick =
			'BX.Crm.Config.LocationGridManager.Instance.confirmDelete(' . $encodedLocationName . ', '
			. "() => { {$sendRowAction}; })"
		;

		return parent::getControl($rawFields);
	}
}
