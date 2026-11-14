<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Tracking;

/**
 * The closed list of the built-in sources of the end-to-end analytics, in the order of the legacy catalog.
 *
 * A new built-in source of legacy has to be added here: the catalog is compared with legacy by a test.
 *
 * @internal
 */
enum BuiltInSourceCode: string
{
	case Google = 'google';
	case Fb = 'fb';
	case Instagram = 'instagram';
	case VkAds = 'vkads';
	case Vk = 'vk';
	case Yandex = 'yandex';
	case OneC = '1c';
	case Avito = 'avito';
	case Organic = 'organic';
	case SenderMail = 'sender-mail';

	/**
	 * Organic traffic and email marketing are described by the product and have nothing to set up.
	 */
	public function isConfigurable(): bool
	{
		return $this !== self::Organic && $this !== self::SenderMail;
	}
}
