<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access;

use Bitrix\Main\Config\Option;

/**
 * Portal switch of the "own templates" scope value. Read on every scope read, above the effective-permission
 * cache, so switching it off takes effect from the next request without invalidating anything. Storing and
 * merging of the rights rows is untouched: switching it back on returns the granted scope as it was.
 *
 * Off by default: the update must not open the scope on every portal by itself, it is switched on per portal
 * while the value is rolled out.
 *
 * PAIRING WITH THE LAZY MODE of the rights page
 * ({@see \Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\LazyTemplatesOption::isUsable()}): this
 * switch goes on where the lazy mode is available, because only that page mode can grant the value. With
 * either half of the pair off a granted value keeps acting while the matrix cannot show it - the scope read
 * below honours this switch alone, and the page withholds the cell it cannot sign - so the pair is brought
 * back on to see or revoke grants. That is a visibility limit, not a data risk: whether a save may carry a
 * hidden value over is answered by the render that built its payload and travels with the page
 * ({@see \Bitrix\Bizproc\Public\Provider\PermissionMatrixProvider::SAVE_PARAM_OWN_SENTINEL_EXPRESSIBLE}),
 * so flipping the switches around an open page neither deletes a grant nor restores a deliberate removal.
 */
final class OwnTemplatesOption
{
	public const MODULE_ID = 'bizproc';
	public const NAME = 'access_rights_own_templates';
	public const VALUE_ENABLED = 'Y';
	public const VALUE_DISABLED = 'N';

	public static function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::NAME, self::VALUE_DISABLED) === self::VALUE_ENABLED;
	}
}
