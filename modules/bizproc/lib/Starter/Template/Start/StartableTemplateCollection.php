<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Template\Start;

use Bitrix\Bizproc\Internal\Entity\AbstractCollection;

/**
 * @extends AbstractCollection<StartableTemplate>
 */
final class StartableTemplateCollection extends AbstractCollection
{
	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof StartableTemplate;
	}
}
