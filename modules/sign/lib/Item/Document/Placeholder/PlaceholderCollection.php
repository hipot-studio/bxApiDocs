<?php

namespace Bitrix\Sign\Item\Document\Placeholder;

use Bitrix\Sign\Item\Collection;

/**
 * @extends Collection<Placeholder>
 */
final class PlaceholderCollection extends Collection
{
	protected function getItemClassName(): string
	{
		return Placeholder::class;
	}
}
