<?php

namespace Bitrix\Landing\Controller;

use Bitrix\Landing\Landing as LandingCore;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine;

class Landing extends Controller
{
	/**
	 * No Extranet filter here on purpose: the actions only read, rows are already limited by
	 * Landing rights, and the caller of getById lives in group knowledge bases, which the module
	 * keeps open for extranet users (PublicAction::checkForExtranet()).
	 */
	public function getDefaultPreFilters(): array
	{
		return [
			new Engine\ActionFilter\Authentication(),
		];
	}

	/**
	 * Returns landing's data.
	 * @param int $landingId Landing id.
	 * @return array|null
	 */
	public function getByIdAction(int $landingId): ?array
	{
		$res = LandingCore::getList([
			'select' => [
				'*',
			],
			'filter' => [
				'ID' => $landingId,
			],
		]);
		if ($row = $res->fetch())
		{
			$row['ADDITIONAL_FIELDS'] = LandingCore::getAdditionalFieldsAsArray($landingId);

			return $row;
		}

		return null;
	}

	public function isPhoneRegionCodeTourAlreadySeenAction(): bool
	{
		return \CUserOptions::GetOption('ui-tour', 'landing_phone_aha_shown', null) !== null;
	}
}