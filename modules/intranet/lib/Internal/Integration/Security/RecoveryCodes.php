<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\Localization\Loc;
use Bitrix\Security\Mfa\RecoveryCodesTable;
use Bitrix\Intranet\Internal;

class RecoveryCodes
{
	public function __construct(protected User $user)
	{
	}

	public function getList($isActiveOnly = false, $isRegenerationAllowed = false): array
	{
		$query = RecoveryCodesTable::query()
			->addSelect('CODE', 'VALUE')
			->addSelect('USED')
			->addSelect('USING_DATE')
			->addFilter('=USER_ID', $this->user->getId())
		;

		if ($isActiveOnly)
		{
			$query->addFilter('=USED', 'N');
		}

		$codes = $query->exec()->fetchAll();

		if (is_array($codes) && !empty($codes))
		{
			return $codes;
		}

		if ($isRegenerationAllowed)
		{
			return $this->regenerate();
		}

		return [];

	}

	public function prepareFileContent(array $codes): string
	{
		$domain = (new Internal\Integration\Main\Context\DomainProvider())->getDomain();

		$sections = [
			Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_HEADER'),
			Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_DESCRIPTION', ['#DOMAIN#' => $domain]),
			'',
			Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_INSTRUCTIONS_TITLE'),
			Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_INSTRUCTIONS_1'),
			Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_INSTRUCTIONS_2'),
			Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_INSTRUCTIONS_3'),
			'',
			Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_CODES_TITLE'),
		];

		foreach ($codes as $index => $code)
		{
			$sections[] = sprintf("%d. %s", $index + 1, $code['VALUE']);
		}

		$sections[] = '';
		$sections[] = Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_WARNING_SINGLE_USE');
		$sections[] = Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_WARNING_SECURITY');
		$sections[] = Loc::getMessage('INTRANET_INTERNAL_INTEGRATION_SECURITY_RECOVERY_CODES_WARNING_STORAGE');

		return implode("\r\n", $sections);
	}

	protected function regenerate(): array
	{
		\CUserOptions::SetOption('security', 'recovery_codes_generated', time());
		RecoveryCodesTable::regenerateCodes($this->user->getId());

		return $this->getList();
	}
}
