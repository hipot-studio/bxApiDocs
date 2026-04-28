<?php

namespace Bitrix\Ldap\Internal;

use Bitrix\Main\ArgumentException;

/**
 * @package Bitrix\Ldap\Internal
 * You must not use classes from Internal namespace outside current module.
 *
 * Value object represents single entry from Active Directory response.
 */
class Entry
{
	protected array $attributes = [];

	/**
	 * @throws ArgumentException
	 */
	public function __construct(array $source)
	{
		if (empty($source['dn']))
		{
			throw new ArgumentException('dn attribute is required');
		}

		foreach ($source as $attribute => $values)
		{
			if ($attribute === 'count')
			{
				continue;
			}

			if (is_string($attribute))
			{
				$this->attributes[mb_strtolower($attribute)] = $this->isPhotoAttribute($attribute)
					? $values
					: $this->unwrapAttributeValues($values);
			}
		}
	}

	public function getAttribute(string $name)
	{
		return $this->attributes[$name] ?? null;
	}

	public function toArray(): array
	{
		return $this->attributes;
	}

	protected function unwrapAttributeValues($values)
	{
		if (is_array($values))
		{
			if (isset($values['count']) && $values['count'] === 1)
			{
				return $values[0];
			}

			unset($values['count']);
		}

		return $values;
	}

	protected function isPhotoAttribute(string $attribute): bool
	{
		$photoAttrs = ['thumbnailPhoto', 'jpegPhoto'];

		return in_array($attribute, $photoAttrs, true);
	}
}