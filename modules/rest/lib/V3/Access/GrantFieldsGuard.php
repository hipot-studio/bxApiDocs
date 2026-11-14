<?php

namespace Bitrix\Rest\V3\Access;

use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;

/**
 * Checks the DTO fields named by the client against the fields of the grant, the same way the select is checked:
 * a path is allowed by itself or by its root.
 */
final class GrantFieldsGuard
{
	private const FIELD_PATH_SEPARATOR = '.';

	/**
	 * @param list<string> $grantFields
	 */
	public function __construct(private readonly array $grantFields, private readonly string $dtoShortName)
	{
	}

	/**
	 * @param list<string> $paths
	 * @param list<string> $exemptFields fields allowed outside the grant
	 * @throws UnknownDtoPropertyException
	 */
	public function assertAllowed(array $paths, array $exemptFields = []): void
	{
		if ($this->grantFields === [])
		{
			return;
		}

		$allowedFields = array_merge($this->grantFields, $exemptFields);

		foreach ($paths as $path)
		{
			$root = explode(self::FIELD_PATH_SEPARATOR, $path, 2)[0];
			if (!in_array($path, $allowedFields, true) && !in_array($root, $allowedFields, true))
			{
				throw new UnknownDtoPropertyException($this->dtoShortName, $root);
			}
		}
	}
}
