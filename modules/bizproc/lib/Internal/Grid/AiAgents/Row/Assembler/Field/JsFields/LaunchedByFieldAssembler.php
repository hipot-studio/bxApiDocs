<?php

namespace Bitrix\Bizproc\Internal\Grid\AiAgents\Row\Assembler\Field\JsFields;

use Bitrix\Intranet\User\Grid\Row\Assembler\Field\Helpers\UserPhoto;

class LaunchedByFieldAssembler extends JsExtensionFieldAssembler
{
	use UserPhoto;

	protected function getExtensionClassName(): string
	{
		return 'EmployeeField';
	}

	protected function getRenderParams($rawValue): array
	{
		$user = $rawValue['LAUNCHED_BY_USER_DATA'] ?? null;
		if (!$user)
		{
			return ['user' => null];
		}

		return [
			'user' => [
				'id' => $user['ID'],
				'photoUrl' => $this->getUserPhotoUrl($user),
				'profileLink' => "/company/personal/user/{$user['ID']}/",
				'fullName' => \CUser::FormatName(\CSite::GetNameFormat(), $user, true, false),
			],
		];
	}

	protected function prepareColumnForExport($data): string
	{
		$user = $data['LAUNCHED_BY_USER_DATA'] ?? null;
		if (!$user)
		{
			return '';
		}

		return \CUser::FormatName(\CSite::GetNameFormat(), $user, true, false);
	}
}