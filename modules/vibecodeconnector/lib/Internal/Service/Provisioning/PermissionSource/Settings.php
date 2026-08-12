<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource;

use Bitrix\Main\Config\Option;

final class Settings
{
	public const VIBECODE = 'vibecode';
	public const PORTAL = 'portal';

	public function getValue(): ?string
	{
		$raw = Option::get('vibecodeconnector', 'permission_source', '');

		return $raw === '' ? null : $raw;
	}

	public function setVibecodeSource(bool $isVibecode): void
	{
		Option::set('vibecodeconnector', 'permission_source', $isVibecode ? self::VIBECODE : self::PORTAL);
	}
}
