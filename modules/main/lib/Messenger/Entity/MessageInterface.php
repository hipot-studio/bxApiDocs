<?php

declare(strict_types=1);

namespace Bitrix\Main\Messenger\Entity;

/**
 * @internal
 */
interface MessageInterface extends \JsonSerializable
{
	/**
	 * Create message instance from data array.
	 *
	 * @param  array            $data
	 *
	 * @return MessageInterface
	 */
	public static function createFromData(array $data): MessageInterface;
}
