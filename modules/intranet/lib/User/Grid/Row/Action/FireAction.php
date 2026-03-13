<?php

namespace Bitrix\Intranet\User\Grid\Row\Action;

use Bitrix\Intranet\User\Access\UserActionDictionary;
use Bitrix\Main\Localization\Loc;

class FireAction extends JsGridAction
{
	protected static function getActionType(): UserActionDictionary
	{
		return UserActionDictionary::FIRE;
	}

	public function processRequest(\Bitrix\Main\HttpRequest $request): ?\Bitrix\Main\Result
	{
		return null;
	}

	protected function getText(): string
	{
		return Loc::getMessage('INTRANET_USER_GRID_ROW_ACTIONS_FIRE') ?? '';
	}

	public function getExtensionMethod(): string
	{
		return 'activityAction';
	}

	protected function getActionParams(array $rawFields): array
	{
		$user = $this->getSettings()->getUserCollection()?->getByUserId($rawFields['ID']);
		$userFullName = '';

		if ($user)
		{
			$userFullName = \CUser::FormatName(
				\CSite::GetNameFormat(false),
				[
					'NAME' => $user->getName(),
					'LAST_NAME' => $user->getLastName(),
					'SECOND_NAME' => $user->getSecondName(),
					'LOGIN' => $user->getLogin(),
				],
				true,
				false
			);
		}

		return [
			'action' => 'fire',
			'userId' => $rawFields['ID'],
			'userFullName' => $userFullName,
			'currentUserId' => $this->getSettings()->getCurrentUserId(),
		];
	}
}