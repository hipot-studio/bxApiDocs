<?php

declare(strict_types=1);

namespace Bitrix\Intranet\User\Grid\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;

class RegistrationDataFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		$data = $row['data'];
		$login = $data['REGISTRATION_DATA_LOGIN'] ?? null;
		$xmlId = $data['REGISTRATION_DATA_XML_ID'] ?? null;
		if (
			!is_string($xmlId)
			|| $xmlId === ''
			|| $xmlId !== ($data['REGISTRATION_DATA_USER_XML_ID'] ?? null)
			|| in_array($data['EXTERNAL_AUTH_ID'] ?? null, ['bot', 'rest_system'], true)
			|| !is_string($login)
			|| trim($login) === ''
			|| preg_match('/^B24_[0-9]+$/D', $login)
		)
		{
			$login = '';
		}

		$row['columns']['REGISTRATION_DATA'] = sprintf(
			'<span data-testid="intranet-user-grid-registration-data">%s</span>',
			htmlspecialcharsbx($login),
		);

		unset(
			$row['data']['REGISTRATION_DATA_LOGIN'],
			$row['data']['REGISTRATION_DATA_XML_ID'],
			$row['data']['REGISTRATION_DATA_USER_XML_ID'],
		);

		return $row;
	}
}
