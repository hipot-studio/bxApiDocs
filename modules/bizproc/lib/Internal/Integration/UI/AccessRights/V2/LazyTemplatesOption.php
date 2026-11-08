<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2;

use Bitrix\Main\Config\Option;
use Bitrix\UI\AccessRights\V2\Control\Value\VariablesSource;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem;

final class LazyTemplatesOption
{
	public const MODULE_ID = 'bizproc';
	public const NAME = 'access_rights_lazy_templates';
	public const VALUE_ENABLED = 'Y';

	public static function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::NAME, self::VALUE_ENABLED) === self::VALUE_ENABLED;
	}

	/**
	 * The option alone does not decide the mode: bizproc may run next to a ui that predates the server-side
	 * value source, and then the page stays static whatever the option says. Defined here once because both halves of
	 * the "own templates" pair read it - the page that withholds the value from a cell it cannot sign
	 * ({@see AccessRightsProvider::loadUserGroupModels()}) and the save that carries the value over instead of
	 * deleting it ({@see \Bitrix\Bizproc\Internal\Service\RolePermissionService::withUnexpressedValues()}). Two
	 * copies of this condition would let a save from a page that never showed the value delete it.
	 *
	 * Two probes and not one, because the elements of the contract reached ui in separate commits and an update
	 * is assembled commit by commit: a ui carrying the value source without the placeholder constant, or the
	 * other way round, is a state this module can be installed next to. One element per commit is enough - what
	 * came along with the probed element cannot be missing while the element itself is there.
	 *
	 * Three of the five calls this module makes into the new ui API are never probed directly -
	 * MultiVariables::variablesSource(), allSelectedTitle() and unloadedVariableTitlePattern(). They rest on the
	 * invariant that an update takes a ui commit whole: each of them came to ui with the same addition as one of
	 * the two probed elements, so it is there whenever its probe passes.
	 *
	 * The class names are parameters holding the ui defaults: a class of ui that is loaded cannot be taken off
	 * the autoloader, so a test points the probes at names of its own instead.
	 */
	public static function isUsable(
		string $valueSourceClass = VariablesSource::class,
		string $rightItemClass = RightItem::class,
	): bool
	{
		return self::isEnabled() && self::isContractAvailable($valueSourceClass, $rightItemClass);
	}

	/**
	 * The probes alone, without the option. Told apart from {@see self::isUsable()} because the two halves are
	 * not equally transferable: the mode of a render travels with its payload, while the presence of a ui class
	 * is state of this very process and can never be claimed by a request
	 * ({@see AccessRightsProvider::isOwnSentinelExpressible()}).
	 */
	public static function isContractAvailable(
		string $valueSourceClass = VariablesSource::class,
		string $rightItemClass = RightItem::class,
	): bool
	{
		return class_exists($valueSourceClass)
			&& defined($rightItemClass . '::UNLOADED_VARIABLE_VALUE_PLACEHOLDER')
		;
	}
}
