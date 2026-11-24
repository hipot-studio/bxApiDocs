<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons;

/**
 * A block carrying the `buttons` field. The footer is a field of every block and is not part of this
 * contract.
 */
interface ButtonsHolder
{
	public function getButtons(): ButtonCollection;
}
