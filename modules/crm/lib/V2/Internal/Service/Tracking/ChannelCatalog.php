<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking;

use Bitrix\Crm\V2\Internal\Entity\Tracking\ChannelCode;
use Bitrix\Main\Localization\Loc;

/**
 * The base channels of the end-to-end analytics in the order of the legacy list, named as the tiles of the UI
 * name them. The channels of the contact center are not here. The equality with legacy is kept by a test.
 *
 * @internal
 */
final class ChannelCatalog
{
	/** Whether a channel can be set up, by code. */
	private const CHANNELS = [
		'site24' => false,
		'shop24' => false,
		'crm-shop' => true,
		'site' => true,
		'call' => true,
		'mail' => true,
		'order' => true,
	];

	/**
	 * @return list<array{code: ChannelCode, name: string, isConfigurable: bool}>
	 */
	public function list(): array
	{
		$list = [];
		foreach (self::CHANNELS as $code => $isConfigurable)
		{
			$list[] = [
				'code' => ChannelCode::from($code),
				'name' => $this->name($code),
				'isConfigurable' => $isConfigurable,
			];
		}

		return $list;
	}

	private function name(string $code): string
	{
		$messageCode = 'CRM_V2_TRACKING_CHANNEL_CATALOG_NAME_' . mb_strtoupper(str_replace('-', '_', $code));

		return Loc::getMessage($messageCode) ?: $code;
	}
}
