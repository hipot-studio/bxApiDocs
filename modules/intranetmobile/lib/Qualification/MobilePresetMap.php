<?php

declare(strict_types=1);

namespace Bitrix\IntranetMobile\Qualification;

final class MobilePresetMap
{
	public const TASK = 'task';
	public const CRM = 'crm';
	public const STREAM = 'stream';
	public const COLLABORATION = 'collaboration';
	public const TERMINAL = 'terminal';
	public const BIZPROC = 'bizproc';
	public const SIGN = 'sign';

	/**
	 * @var array<string, string>
	 */
	private const WEB_PRESET_BY_MOBILE_PRESET = [
		self::TASK => 'tasks',
		self::CRM => 'crm',
		self::STREAM => 'social',
		self::COLLABORATION => 'social',
		self::TERMINAL => 'crm',
		self::BIZPROC => 'bizproc_automation',
		self::SIGN => 'sign',
	];

	public function getWebPresetId(string $mobilePresetId): ?string
	{
		return self::WEB_PRESET_BY_MOBILE_PRESET[$mobilePresetId] ?? null;
	}
}
