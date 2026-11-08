<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Access\Permission;

use Bitrix\Main\Access\Permission\PermissionDictionary as MainPermissionDictionary;
use Bitrix\Main\Localization\Loc;

final class PermissionDictionary extends MainPermissionDictionary
{
	public const VALUE_VARIATION_ALL = -1;
	public const VALUE_VARIATION_OWN = -2;

	public const BIZPROC_TEMPLATE_CREATE = 1;
	public const BIZPROC_TEMPLATE_EDIT = 2;
	public const BIZPROC_TEMPLATE_PUBLISH = 3;
	public const BIZPROC_TEMPLATE_DELETE = 4;
	public const BIZPROC_TEMPLATE_CONFIGURE_RIGHTS = 5;

	private const PERMISSION_PREFIX = 'BIZPROC_TEMPLATE_';

	/**
	 * Whether a multivariables VALUE is a sentinel ("all", "own") rather than a template ID. The single source
	 * of truth for "this scope value is not a template ID": a literal check on the spot silently misses a
	 * sentinel added later.
	 */
	public static function isSentinelValue(int $value): bool
	{
		return $value === self::VALUE_VARIATION_ALL || $value === self::VALUE_VARIATION_OWN;
	}

	public static function getPermission($permissionId): array
	{
		$permission = parent::getPermission($permissionId);

		$id = (int)$permissionId;

		// CREATE and CONFIGURE_RIGHTS are scope-less togglers (a plain yes/no grant); the rest are
		// multivariables over the template scope. Holding CONFIGURE_RIGHTS means full access to the rights
		// configuration (any right, every role, every template), so it carries no template area.
		$permission['type'] = in_array($id, [self::BIZPROC_TEMPLATE_CREATE, self::BIZPROC_TEMPLATE_CONFIGURE_RIGHTS], true)
			? self::TYPE_TOGGLER
			: self::TYPE_MULTIVARIABLES
		;

		switch ($id)
		{
			case self::BIZPROC_TEMPLATE_CREATE:
				$permission['title'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_CREATE_TITLE');
				$permission['hint'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_CREATE_HINT');
				break;
			case self::BIZPROC_TEMPLATE_EDIT:
				$permission['title'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_EDIT_TITLE');
				$permission['hint'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_EDIT_HINT');
				break;
			case self::BIZPROC_TEMPLATE_PUBLISH:
				$permission['title'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_PUBLISH_TITLE');
				$permission['hint'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_PUBLISH_HINT');
				break;
			case self::BIZPROC_TEMPLATE_DELETE:
				$permission['title'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_DELETE_TITLE');
				$permission['hint'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_DELETE_HINT');
				break;
			case self::BIZPROC_TEMPLATE_CONFIGURE_RIGHTS:
				$permission['title'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_CONFIGURE_RIGHTS_TITLE');
				$permission['hint'] = Loc::getMessage('BIZPROC_ACCESS_PERMISSION_TEMPLATE_CONFIGURE_RIGHTS_HINT');
				break;
		}

		if ($permission['type'] === self::TYPE_TOGGLER)
		{
			$permission['minValue'] = '0';
			$permission['maxValue'] = '1';
			$permission['defaultValue'] = '0';
		}
		elseif ($permission['type'] === self::TYPE_MULTIVARIABLES)
		{
			$permission['allSelectedCode'] = (string)self::VALUE_VARIATION_ALL;
			$permission['maxValue'] = (string)self::VALUE_VARIATION_ALL;
		}

		return $permission;
	}

	public static function getDefaultPermissionValue($permissionId): int
	{
		$permission = static::getPermission($permissionId);
		if ($permission['type'] === static::TYPE_MULTIVARIABLES)
		{
			return static::VALUE_VARIATION_ALL;
		}

		return static::VALUE_YES;
	}

	/**
	 * Returns only the flat bizproc-template permission matrix (5 rights).
	 * The parent reflection-based getList() would also expose helper constants
	 * (e.g. VALUE_VARIATION_ALL), so it is narrowed to the permission prefix here.
	 */
	public static function getList(): array
	{
		$class = new \ReflectionClass(static::class);

		$res = [];
		foreach ($class->getConstants() as $name => $id)
		{
			if (!str_starts_with($name, self::PERMISSION_PREFIX))
			{
				continue;
			}

			$res[$id] = [
				'NAME' => $name,
				'LEVEL' => static::getLevel($id),
			];
		}

		return $res;
	}
}
