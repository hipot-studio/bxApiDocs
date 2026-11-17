<?php

namespace Bitrix\DocumentGenerator;

use Bitrix\DocumentGenerator\DataProvider\ArrayDataProvider;

final class ProviderFieldIterator
{
	public static function iterate(
		DataProviderManager $manager,
		DataProvider $root,
		bool $isCopyFields = false
	): \Generator
	{
		$ordinal = 0;
		yield from self::walk($manager, $root, [], [], false, [], false, $isCopyFields, $ordinal);
	}

	private static function walk(
		DataProviderManager $manager,
		DataProvider $parent,
		array $chain,
		array $group,
		bool $isArray,
		array $providers,
		bool $stopRecursion,
		bool $isCopyFields,
		int &$ordinal
	): \Generator
	{
		if ($parent->isRootProvider())
		{
			$providers[] = get_class($parent);
		}

		$fields = $parent->getFields();
		$copyPlaceholders = [];
		if ($isCopyFields)
		{
			foreach ($fields as $placeholder => $field)
			{
				if (isset($field['OPTIONS']['COPY']))
				{
					$copyPlaceholders[$placeholder] = $field['OPTIONS']['COPY'];
				}
			}
		}

		$copySources = array_values($copyPlaceholders);
		$copyCandidates = [];
		$collectCopyCandidates = $copySources !== [];
		foreach ($fields as $placeholder => $field)
		{
			$chain[] = $placeholder;
			if (isset($field['TITLE']) && !empty($field['TITLE']))
			{
				$group[] = $field['TITLE'];
			}
			else
			{
				$group[] = $manager->valueToPlaceholder((string)$placeholder);
			}

			$provider = $manager->createDataProvider($field, ' ', $parent);
			if (
				$provider
				&& (($provider->isRootProvider() && !$stopRecursion) || !$provider->isRootProvider())
			)
			{
				if ($provider instanceof ArrayDataProvider)
				{
					$isArray = true;
				}
				$stopRecursion = false;
				if (count($providers) > DataProviderManager::MAX_DEPTH_LEVEL_ROOT_PROVIDERS)
				{
					$stopRecursion = true;
				}

				$childFields = self::walk(
					$manager,
					$provider,
					$chain,
					$group,
					$isArray,
					$providers,
					$stopRecursion,
					$isCopyFields,
					$ordinal,
				);
				if ($collectCopyCandidates)
				{
					foreach ($childFields as $key => $childField)
					{
						self::rememberCopyCandidate($childField, $copySources, $copyCandidates);
						yield $key => $childField;
					}
				}
				else
				{
					yield from $childFields;
				}
				$isArray = false;
			}
			else
			{
				if ($isArray)
				{
					$field['OPTIONS']['IS_ARRAY'] = true;
				}
				if ($isCopyFields || !isset($field['OPTIONS']['COPY']))
				{
					$descriptor = array_merge($field, [
						'VALUE' => implode('.', $chain),
						'GROUP' => $group,
					]);
					if ($collectCopyCandidates)
					{
						self::rememberCopyCandidate($descriptor, $copySources, $copyCandidates);
					}
					yield $ordinal++ => $descriptor;
				}
			}

			array_pop($group);
			array_pop($chain);
		}

		foreach ($copyPlaceholders as $destination => $source)
		{
			$matchingFields = $copyCandidates;
			foreach ($matchingFields as $field)
			{
				if (is_string($field['VALUE']) && mb_strpos($field['VALUE'], $source) !== false)
				{
					$field['VALUE'] = str_replace($source, $destination, $field['VALUE']);
					self::rememberCopyCandidate($field, $copySources, $copyCandidates);
					yield $ordinal++ => $field;
				}
			}
		}
	}

	private static function rememberCopyCandidate(array $field, array $copySources, array &$copyCandidates): void
	{
		if (!is_string($field['VALUE']))
		{
			return;
		}

		foreach ($copySources as $source)
		{
			if (mb_strpos($field['VALUE'], $source) !== false)
			{
				$copyCandidates[] = $field;
				return;
			}
		}
	}
}
