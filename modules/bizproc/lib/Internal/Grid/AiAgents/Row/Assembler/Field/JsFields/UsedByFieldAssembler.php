<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field\JsFields;

use Bitrix\Intranet\User\Grid\Row\Assembler\Field\Helpers\UserPhoto;

class UsedByFieldAssembler extends JsExtensionFieldAssembler
{
	use UserPhoto;

	protected function getExtensionClassName(): string
	{
		return 'UsedByField';
	}

	protected function getRenderParams($rawValue): array
	{
		$usersData = $rawValue['USED_BY_USERS_DATA'] ?? [];
		$users = [];
		foreach ($usersData as $user)
		{
			$users[] = [
				'id' => $user['ID'],
				'photoUrl' => $this->getUserPhotoUrl($user),
				'profileLink' => "/company/personal/user/{$user['ID']}/",
				'fullName' => \CUser::FormatName(\CSite::GetNameFormat(), $user, true, false),
			];
		}

		return [
			'users' => $users,
			'chatId' => $rawValue['CHAT_ID'] ?? null,
			'chatName' => $rawValue['CHAT_NAME'] ?? null,
			'departments' => $rawValue['DEPARTMENTS'] ?? null,
		];
	}

	protected function prepareColumnForExport($data): string
	{
		return '';
	}
}