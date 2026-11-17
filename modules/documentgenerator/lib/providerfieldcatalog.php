<?php

namespace Bitrix\DocumentGenerator;

use Bitrix\DocumentGenerator\DataProvider\Document as DocumentDataProvider;
use Bitrix\Main\Localization\Loc;

final class ProviderFieldCatalog
{
	public static function iterateDefaultTemplateFields(
		DataProviderManager $manager,
		string $providerClass,
		array $options = [],
		bool $addRootGroups = true,
		bool $isCopyFields = false,
		?callable $predicate = null,
		int $offset = 0,
		?int $limit = null
	): \Generator
	{
		if ($offset < 0 || ($limit !== null && $limit < 0))
		{
			throw new \InvalidArgumentException('Offset and limit must be non-negative.');
		}

		if ($limit === 0)
		{
			return ['exhausted' => false, 'matchedCount' => null, 'scannedMatches' => 0];
		}

		$source = $manager->getDataProvider($providerClass, ' ', $options);
		$document = $manager->getDataProvider(DocumentDataProvider::class, ' ');
		if (!$document)
		{
			return ['exhausted' => true, 'matchedCount' => 0, 'scannedMatches' => 0];
		}

		$documentGroup = null;
		if ($addRootGroups)
		{
			Loc::loadLanguageFile(__DIR__ . '/document.php');
			$documentGroup = Loc::getMessage('DOCUMENT_GROUP_NAME');
		}

		$seen = [];
		$scannedMatches = 0;
		$yielded = 0;
		foreach ([['SOURCE', $source], ['DOCUMENT', $document]] as [$scope, $root])
		{
			if (!$root)
			{
				continue;
			}

			foreach ($manager->iterateProviderFields($root, $isCopyFields) as $rawField)
			{
				if ($scope === 'DOCUMENT' && $rawField['VALUE'] === Template::MAIN_PROVIDER_PLACEHOLDER)
				{
					continue;
				}

				$code = $manager->valueToPlaceholder($rawField['VALUE']);
				if (isset($seen[$code]))
				{
					continue;
				}
				$seen[$code] = true;

				$field = $rawField;
				$field['VALUE'] = Document::THIS_PLACEHOLDER . '.' . $scope . '.' . $rawField['VALUE'];
				if ($addRootGroups)
				{
					array_unshift($field['GROUP'], $documentGroup);
				}

				if ($predicate !== null && !$predicate($code, $field))
				{
					continue;
				}

				++$scannedMatches;
				if ($scannedMatches <= $offset || ($limit !== null && $yielded >= $limit))
				{
					if (!$isCopyFields && $limit !== null && $scannedMatches >= $offset + $limit)
					{
						return ['exhausted' => false, 'matchedCount' => null, 'scannedMatches' => $scannedMatches];
					}

					continue;
				}

				++$yielded;
				yield $code => $field;

				if (!$isCopyFields && $limit !== null && $scannedMatches >= $offset + $limit)
				{
					return ['exhausted' => false, 'matchedCount' => null, 'scannedMatches' => $scannedMatches];
				}
			}
		}

		return [
			'exhausted' => true,
			'matchedCount' => $scannedMatches,
			'scannedMatches' => $scannedMatches,
		];
	}

	public static function resolveFields(
		DataProviderManager $manager,
		DataProvider $root,
		array $requests,
		bool $isCopyFields,
		bool $byCode
	): array
	{
		$wanted = [];
		foreach ($requests as $request)
		{
			if (!is_string($request) || $request === '' || str_contains($request, '{')
				|| str_contains($request, '}') || ($byCode && preg_match('/[.\s_]/u', $request) === 1)
				|| (!$byCode && (str_starts_with($request, 'this.') || in_array('', explode('.', $request), true)))
			)
			{
				throw new \InvalidArgumentException('Invalid sparse field request.');
			}
			$wanted[$request] = true;
		}
		if ($wanted === [])
		{
			return [];
		}
		$matches = [];
		foreach ($manager->iterateProviderFields($root, $isCopyFields) as $field)
		{
			$key = $byCode ? $manager->valueToPlaceholder($field['VALUE']) : $field['VALUE'];
			if (isset($wanted[$key]) && !isset($matches[$key]))
			{
				$matches[$key] = $field;
				if (!$isCopyFields && count($matches) === count($wanted))
				{
					break;
				}
			}
		}
		$result = [];
		foreach ($wanted as $key => $_)
		{
			if (isset($matches[$key]))
			{
				$result[$key] = $matches[$key];
			}
		}

		return $result;
	}

	public static function getNodeChildren(
		DataProviderManager $manager,
		ProviderFieldCursor $codec,
		DataProvider $root,
		string $path,
		?string $cursor,
		int $limit,
		array $query,
		bool $isCopyFields
	): array
	{
		if ($limit < 1 || $limit > 100 || ($path !== '' && (
			str_starts_with($path, 'this.') || in_array('', explode('.', $path), true)
		)))
		{
			throw new \InvalidArgumentException('Invalid children path or limit.');
		}
		foreach ($query as $key => &$value)
		{
			if (!in_array($key, ['NAME', 'TITLE'], true) || !is_string($value))
			{
				throw new \InvalidArgumentException('Invalid children query.');
			}
			$value = mb_strtolower($value);
		}
		unset($value);
		ksort($query);
		$context = $manager->getContext();
		$userId = $context->getUserId();
		if ($context->getIsCheckAccess() && !$userId)
		{
			$userId = Driver::getInstance()->getUserId();
		}
		$node = $root;
		$providersCount = $root->isRootProvider() ? 1 : 0;
		$stopRecursion = false;
		$ancestors = [];
		$fields = [];
		$segments = $path === '' ? [] : explode('.', $path);
		foreach (array_merge($segments, [null]) as $segment)
		{
			self::checkCurrentNodeAccess($node, $context, $userId);
			$identity = self::getNodeIdentity($node);
			$fields = $node->getFields();
			$schema = hash_init('sha256');
			foreach ($fields as $name => $field)
			{
				hash_update($schema, self::fingerprintField($node, $name, $field));
			}
			$ancestors[] = [$identity, hash_final($schema)];
			if ($segment === null)
			{
				break;
			}
			$field = null;
			foreach ($fields as $name => $siblingField)
			{
				if ((string)$name === $segment)
				{
					$field = $siblingField;
					break;
				}

				$sibling = $manager->createDataProvider($siblingField, ' ', $node);
				if ($sibling && (!$sibling->isRootProvider() || !$stopRecursion))
				{
					$stopRecursion = $providersCount > DataProviderManager::MAX_DEPTH_LEVEL_ROOT_PROVIDERS;
				}
			}
			if (!$field || (!$isCopyFields && isset($field['OPTIONS']['COPY'])))
			{
				$fields = [];
				break;
			}
			$child = $manager->createDataProvider($field, ' ', $node);
			if (!$child || ($child->isRootProvider() && $stopRecursion))
			{
				$fields = [];
				break;
			}
			$stopRecursion = $providersCount > DataProviderManager::MAX_DEPTH_LEVEL_ROOT_PROVIDERS;
			$providersCount += $child->isRootProvider() ? 1 : 0;
			$node = $child;
		}
		$culture = $context->getCulture();
		$cultureFormats = [];
		foreach ($culture->collectValues() as $key => $value)
		{
			if (!in_array($key, ['ID', 'CODE', 'NAME'], true))
			{
				$cultureFormats[$key] = $value;
			}
		}
		$scope = ProviderFieldCursor::fingerprint([
			'ancestors' => $ancestors,
			'path' => $path,
			'query' => $query,
			'limit' => $limit,
			'copy' => $isCopyFields,
			'context' => [
				'checkAccess' => $context->getIsCheckAccess(), 'user' => $userId,
				'timezone' => $context->getTimezone(), 'template' => $context->getTemplateId(),
				'region' => $context->getRegion(), 'language' => $context->getRegionLanguageId(),
				'culture' => $cultureFormats,
			],
		]);
		$position = $cursor === null ? 0 : $codec->decode($cursor, $scope, count($fields));
		$items = [];
		$ordinal = 0;
		$nextPosition = null;
		foreach ($fields as $name => $field)
		{
			$currentPosition = $ordinal++;
			$child = $manager->createDataProvider($field, ' ', $node);
			$hasChildren = $child && (!$child->isRootProvider() || !$stopRecursion);
			if ($hasChildren)
			{
				$stopRecursion = $providersCount > DataProviderManager::MAX_DEPTH_LEVEL_ROOT_PROVIDERS;
			}

			if ($currentPosition < $position || (!$isCopyFields && isset($field['OPTIONS']['COPY'])))
			{
				continue;
			}
			$matches = true;
			foreach ($query as $key => $needle)
			{
				$haystack = $key === 'NAME' ? (string)$name : (string)($field['TITLE'] ?? '');
				if (mb_strpos(mb_strtolower($haystack), $needle) === false)
				{
					$matches = false;
					break;
				}
			}
			if (!$matches)
			{
				continue;
			}
			if (count($items) === $limit)
			{
				$nextPosition = $currentPosition;
				break;
			}
			$value = $path === '' ? (string)$name : $path . '.' . $name;
			$items[] = array_merge($field, [
				'NAME' => (string)$name,
				'VALUE' => $value,
				'PLACEHOLDER' => $manager->valueToPlaceholder($value),
				'HAS_CHILDREN' => $hasChildren,
			]);
		}

		return [
			'items' => $items,
			'nextCursor' => $nextPosition === null ? null : $codec->encode($nextPosition, $scope),
			'hasMore' => $nextPosition !== null,
		];
	}

	private static function fingerprintField(DataProvider $node, int|string $name, array $field): string
	{
		$value = $field['VALUE'] ?? null;
		if (is_array($value) && array_is_list($value) && count($value) === 2
			&& $value[0] === $node && is_string($value[1]) && method_exists($node, $value[1]))
		{
			$method = new \ReflectionMethod($node, $value[1]);
			if ($method->isPublic() && !$method->isStatic())
			{
				$field['VALUE'] = [get_class($node), $value[1]];

				// The enclosing node scope binds the callback to its stable provider identity.
				return ProviderFieldCursor::fingerprint(['provider-method', $name, $field]);
			}
		}

		return ProviderFieldCursor::fingerprint(['descriptor', $name, $field]);
	}

	private static function checkCurrentNodeAccess(DataProvider $node, Context $context, int $userId): void
	{
		if ($context->getIsCheckAccess() && $userId !== 0 && !$node->hasAccess($userId))
		{
			throw new \Bitrix\Main\AccessDeniedException();
		}
	}

	private static function getNodeIdentity(DataProvider $node): array
	{
		$identity = [];
		$seen = new \SplObjectStorage();
		while ($node)
		{
			if ($seen->contains($node))
			{
				throw new \InvalidArgumentException('Cyclic children provider parent.');
			}
			$seen->attach($node);
			$source = $node->getSource();
			if (is_array($source))
			{
				throw new \InvalidArgumentException('Unsupported children provider source.');
			}
			$part = [get_class($node), $source, $node->getOptions(), $node->isRootProvider()];
			ProviderFieldCursor::fingerprint($part);
			$identity[] = $part;
			$node = $node->getParentProvider();
		}

		return $identity;
	}

}
