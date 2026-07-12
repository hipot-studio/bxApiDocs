<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

use Bitrix\Socialnetwork\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @method Member[] getIterator()
 */
class MemberCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Member::class;
	}
}
