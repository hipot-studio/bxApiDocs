<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment;

use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\SourceType;

/**
 * New names for the nodes of a fragment and the rewriting of the references between them (ALG-01).
 *
 * The names are issued here because this is the only place that knows which old name became which new one,
 * and without that map the references cannot be rewritten at all. Collisions in the recipient are not
 * checked: uniqueness rests on the format of the name, exactly as it does for the insertion from the
 * clipboard the editor already has (ADR, section 5).
 *
 * The walk is structural and goes over the values **and the keys** of the settings, recursively through the
 * `Children` of a complex node, and knows three forms of a reference to a node: a value equal to the name
 * whole; the composite `<name>:<port>`, split at the first colon; and a reference inside an expression,
 * `{=A...:Field}`, including one standing inside a calculated `{{= ... }}`. The parse of the expressions is the
 * one of the module ({@see \CBPActivity::ValueInlinePattern}), and only the object of a match is replaced,
 * in place, so the field, the modifier of the type and every space around them come back byte for byte.
 * Replacing over the serialized form is the approach the ADR rejected outright.
 *
 * Anything pointing outside the fragment, and any form that cannot be rewritten, stays as it is and lands
 * in the list of the places to review with its address - the node, the key of the property and the
 * reference itself. Silence would be worse than a refusal here: a setting left pointing at the template it
 * was cut from looks like it works.
 */
final class ReferenceRewriter
{
	private const ELEMENT_NODE = 'Node';
	private const NODE_ID = 'id';
	private const EXPRESSION_OPENING = '{=';
	private const NAME_GROUP_COUNT = 4;

	/**
	 * The format the editor issues a name in, reproduced on the server:
	 * {@see bizprocdesigner/install/js/bizprocdesigner/editor/chart/src/shared/utils/create-unique-id.js}.
	 */
	private const NAME_GROUP_MIN = 1000;
	private const NAME_GROUP_MAX = 9999;

	/**
	 * A name of a node as the editor and the legacy templates both write it: the letter, then four groups of
	 * digits. Used on the only forms that carry no source of their own - a bare value and a composite head -
	 * to tell a reference to a node outside the fragment from an ordinary string.
	 */
	private const NAME_PATTERN = '/^A[0-9]+(?:_[0-9]+){3}$/';

	/**
	 * @param array $nodes activities of the fragment, as the envelope carries them
	 * @param array $links triples of the links between them
	 *
	 * @return array{
	 *     nodes: array,
	 *     links: array,
	 *     unresolved: list<array{nodeName: string, property: string, reference: string}>
	 * }
	 */
	public function rewrite(array $nodes, array $links): array
	{
		$map = $this->issueNames($nodes);
		$unresolved = [];
		$rewritten = [];

		foreach ($nodes as $node)
		{
			$rewritten[] = is_array($node) ? $this->rewriteActivity($node, $map, $unresolved) : $node;
		}

		return [
			'nodes' => $rewritten,
			'links' => $this->rewriteLinks($links, $map),
			'unresolved' => array_values($unresolved),
		];
	}

	/**
	 * @return array<string, string> the old name of every node of the fragment, its children included
	 */
	private function issueNames(array $nodes): array
	{
		$map = [];
		$this->collectNames($nodes, $map);

		return $map;
	}

	private function collectNames(array $nodes, array &$map): void
	{
		foreach ($nodes as $node)
		{
			if (!is_array($node))
			{
				continue;
			}

			$name = (string)($node[NodesToTemplate::ELEMENT_NAME] ?? '');
			if ($name !== '' && !isset($map[$name]))
			{
				$map[$name] = self::createName();
			}

			$children = $node[NodesToTemplate::ELEMENT_CHILDREN] ?? null;
			if (is_array($children))
			{
				$this->collectNames($children, $map);
			}
		}
	}

	private static function createName(): string
	{
		$groups = [];
		for ($i = 0; $i < self::NAME_GROUP_COUNT; $i++)
		{
			$groups[] = random_int(self::NAME_GROUP_MIN, self::NAME_GROUP_MAX);
		}

		return 'A' . implode('_', $groups);
	}

	private function rewriteActivity(array $activity, array $map, array &$unresolved): array
	{
		$name = (string)($activity[NodesToTemplate::ELEMENT_NAME] ?? '');
		$newName = $map[$name] ?? $name;
		$activity[NodesToTemplate::ELEMENT_NAME] = $newName;

		// The name of a node and the id of its block on the canvas are one identity: renaming one of them
		// alone would leave the scheme and the template describing different graphs.
		if (isset($activity[self::ELEMENT_NODE]) && is_array($activity[self::ELEMENT_NODE]))
		{
			$activity[self::ELEMENT_NODE][self::NODE_ID] = $newName;
		}

		$properties = $activity[NodesToTemplate::ELEMENT_PROPERTIES] ?? null;
		if (is_array($properties))
		{
			foreach ($properties as $key => $value)
			{
				// The key of a property is the name of a setting, never a reference, and it is also the
				// address a place to review is reported under.
				$properties[$key] = $this->rewriteValue(
					$value,
					$map,
					$newName,
					(string)$key,
					$unresolved,
					self::declaresIdentity((string)$key),
				);
			}

			$activity[NodesToTemplate::ELEMENT_PROPERTIES] = $properties;
		}

		$children = $activity[NodesToTemplate::ELEMENT_CHILDREN] ?? null;
		if (is_array($children))
		{
			foreach ($children as $index => $child)
			{
				if (is_array($child))
				{
					$children[$index] = $this->rewriteActivity($child, $map, $unresolved);
				}
			}

			$activity[NodesToTemplate::ELEMENT_CHILDREN] = $children;
		}

		return $activity;
	}

	/**
	 * @param bool $declaresIdentity the value stands under a key that names the thing being described, so a
	 *        name-shaped string in it is an identity and not a reference {@see self::declaresIdentity()}
	 */
	private function rewriteValue(
		mixed $value,
		array $map,
		string $owner,
		string $property,
		array &$unresolved,
		bool $declaresIdentity = false,
	): mixed
	{
		if (is_array($value))
		{
			$rewritten = [];
			foreach ($value as $key => $item)
			{
				// A key carries a reference as a value does: `OutputNames` of a complex node keeps the name
				// of a node in the key of a row while `InputNames` keeps it in the value. A walk over the
				// values alone would leave such a node silently broken.
				$newKey = is_string($key)
					? (string)$this->rewriteValue($key, $map, $owner, $property, $unresolved, $declaresIdentity)
					: $key
				;

				$rewritten[$newKey] = $this->rewriteValue(
					$item,
					$map,
					$owner,
					$property,
					$unresolved,
					is_string($key) ? self::declaresIdentity($key) : $declaresIdentity,
				);
			}

			return $rewritten;
		}

		// Nothing but a string carries a reference, and the structure of the settings is not normalized.
		if (!is_string($value))
		{
			return $value;
		}

		if (isset($map[$value]))
		{
			return $map[$value];
		}

		$head = strstr($value, NodesToTemplate::LINK_DELIMITER, true);
		if ($head !== false && $head !== '')
		{
			if (isset($map[$head]))
			{
				return $map[$head] . substr($value, strlen($head));
			}

			if (!$declaresIdentity && self::looksLikeName($head))
			{
				self::report($unresolved, $owner, $property, $value);

				return $value;
			}
		}

		if (!$declaresIdentity && self::looksLikeName($value))
		{
			self::report($unresolved, $owner, $property, $value);

			return $value;
		}

		return $this->rewriteExpressions($value, $map, $owner, $property, $unresolved);
	}

	private function rewriteExpressions(
		string $value,
		array $map,
		string $owner,
		string $property,
		array &$unresolved,
	): string
	{
		if (!str_contains($value, self::EXPRESSION_OPENING))
		{
			return $value;
		}

		$rewritten = preg_replace_callback(
			\CBPActivity::ValueInlinePattern,
			static function (array $match) use ($map, $owner, $property, &$unresolved): string
			{
				[$expression, $expressionOffset] = $match[0];
				[$object, $objectOffset] = $match['object'];

				if (isset($map[$object]))
				{
					// Only the object of the match moves: the field, the modifier of the type and the
					// spacing around them are left exactly as the author of the fragment wrote them.
					return substr_replace(
						$expression,
						$map[$object],
						$objectOffset - $expressionOffset,
						strlen($object),
					);
				}

				if (self::addressesTheSourceTemplate($object, (string)($match['field'][0] ?? '')))
				{
					self::report($unresolved, $owner, $property, $expression);
				}

				return $expression;
			},
			$value,
			flags: PREG_OFFSET_CAPTURE,
		);

		return $rewritten ?? $value;
	}

	/**
	 * Whether a key names the thing being described rather than points at another node (errata of
	 * 2026-09-09). The editor issues the identifiers of its own structures - the cards and the constructions
	 * of the rules of a node, and the copy of the activity a rule mirrors - out of the very same generator
	 * the names of the nodes come from, so by shape alone such an identifier is a name. Its clipboard leaves
	 * them exactly as they are and says nothing about them ({@see
	 * bizprocdesigner/install/js/bizprocdesigner/editor/chart/src/entities/blocks/utils/exchange-buffer.js},
	 * `rewriteActivityIds` renames only what it collected off `Name` and `Children`), and a fragment must not
	 * say more about the same content than the clipboard does - a node whose rules carry three such
	 * identifiers was landing in the list of the places to review with nothing to review.
	 *
	 * Only the reporting is affected, never the rewriting: an identifier that IS the name of a node of the
	 * fragment is answered by the map several lines earlier and travels renamed like any other reference.
	 */
	private static function declaresIdentity(string $key): bool
	{
		return in_array(mb_strtolower($key), ['id', mb_strtolower(NodesToTemplate::ELEMENT_NAME)], true);
	}

	/**
	 * Whether a reference left unrewritten needs the attention of a user. Everything the source template
	 * owned does - its fields, parameters, variables and constants point at nothing in the new process, and
	 * so does a node that stayed behind. What the runtime of any process answers by itself - `System`,
	 * `Workflow`, `User` - and the input of the complex node a child stands in resolve wherever the fragment
	 * lands, so neither is a place to review.
	 */
	private static function addressesTheSourceTemplate(string $object, string $field): bool
	{
		$source = SourceType::getObjectSourceType($object, $field);

		return $source !== null
			&& !in_array($source[0], [SourceType::System, SourceType::Input], true)
		;
	}

	private function rewriteLinks(array $links, array $map): array
	{
		$rewritten = [];
		foreach ($links as $link)
		{
			if (!is_array($link))
			{
				$rewritten[] = $link;

				continue;
			}

			foreach ([0, 1] as $end)
			{
				if (isset($link[$end]))
				{
					$link[$end] = self::rewriteEnd((string)$link[$end], $map);
				}
			}

			$rewritten[] = $link;
		}

		return $rewritten;
	}

	private static function rewriteEnd(string $end, array $map): string
	{
		$name = strstr($end, NodesToTemplate::LINK_DELIMITER, true);
		if ($name === false)
		{
			return $map[$end] ?? $end;
		}

		return isset($map[$name]) ? $map[$name] . substr($end, strlen($name)) : $end;
	}

	private static function looksLikeName(string $value): bool
	{
		return preg_match(self::NAME_PATTERN, $value) === 1;
	}

	/**
	 * The same reference stands in the settings of a node more than once - a complex node keeps a copy of
	 * its child inside `Rules` - and a user needs the place, not the count of its mentions.
	 */
	private static function report(array &$unresolved, string $owner, string $property, string $reference): void
	{
		$unresolved[$owner . "\0" . $property . "\0" . $reference] = [
			'nodeName' => $owner,
			'property' => $property,
			'reference' => $reference,
		];
	}
}
