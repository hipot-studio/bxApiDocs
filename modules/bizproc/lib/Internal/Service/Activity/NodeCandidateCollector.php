<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Activity;

use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;

/**
 * Nodes a `nodereference` field may point at, taken from the slice of the scheme the editor sent.
 *
 * Walking a slice needs an instance and one instance serves one walk: it remembers the names of the kinds
 * it asked the catalog of the activities about, and a slice repeats a kind as often as it repeats a node of
 * it. Reading a list already collected asks the catalog nothing and is static for that reason.
 */
final class NodeCandidateCollector
{
	/** Value is among the candidates, or there is no value at all. */
	public const LINK_STATE_AVAILABLE = 'available';

	/** The slice offered candidates and the saved value is not one of them. */
	public const LINK_STATE_SOURCE_UNAVAILABLE = 'source-unavailable';

	/** The slice arrived and holds no node the field may point at, whatever the value is. */
	public const LINK_STATE_NO_CANDIDATES = 'no-candidates';

	/** The editor sent no slice, so nothing is known about the scheme. */
	public const LINK_STATE_SCHEME_UNKNOWN = 'scheme-unknown';

	/** Root the classic designer wraps a whole process into; the module keeps no constant of its own for it. */
	private const SEQUENTIAL_ROOT_TYPE = 'SequentialWorkflowActivity';

	/** Roots holding a canvas instead of standing on one ({@see canvasOf}). */
	private const CANVAS_WRAPPER_TYPES = [NodesToTemplate::ROOT_NODE_TYPE, self::SEQUENTIAL_ROOT_TYPE];

	/** @var array<string, string> Names of kinds already asked of the catalog, by code. */
	private array $typeNames = [];

	/**
	 * Candidates of the slice, in the order the slice is walked; the order is not part of the contract.
	 *
	 * @param array $slice Slice of the scheme as a workflow template: the root activity holding the canvas
	 *   in its children ({@see canvasOf}), or the canvas itself.
	 * @param string[] $nodeTypes White list of activity codes a link may point at.
	 * @param string $editedName Name of the node being edited; a link to itself is not a candidate.
	 * @return array<int, array{Name: string, Title: string, TitleIsFallback: bool, ParentTitle: string|null}>
	 */
	public function collect(array $slice, array $nodeTypes, string $editedName): array
	{
		$candidates = [];
		$seen = [];
		$editedName = trim($editedName);
		[$canvas, $editedNodeComesFirst] = $this->canvasOf($slice);

		foreach ($this->walk($canvas, null, $editedNodeComesFirst) as [$node, $parent, $isEditedNode])
		{
			if ($isEditedNode)
			{
				continue;
			}

			$name = $this->nodeName($node);
			if ($name === '' || $name === $editedName)
			{
				continue;
			}

			if (!in_array($this->nodeType($node), $nodeTypes, true))
			{
				continue;
			}

			// The first entry of a name wins: a later namesake would replace a candidate already offered.
			if (isset($seen[$name]))
			{
				continue;
			}

			$seen[$name] = true;
			$candidates[] = $this->makeCandidate($node, $parent);
		}

		return $candidates;
	}

	/**
	 * @param array<int, array> $candidates
	 */
	public static function findCandidate(array $candidates, string $name): ?array
	{
		foreach ($candidates as $candidate)
		{
			if (is_array($candidate) && (string)($candidate['Name'] ?? '') === $name)
			{
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * An editor that sent no slice said nothing about the scheme, and that is a state rather than a
	 * refusal - the saved value outlives it untouched. A slice that did arrive holding nothing to link to
	 * is a state of its own for the same reason: with no list to have chosen from, there is nothing to
	 * hold the value against. Only a list that had something to offer can find a value missing from it.
	 *
	 * @param array<int, array> $candidates
	 */
	public static function resolveLinkState(array $candidates, string $value, bool $schemeKnown): string
	{
		if (!$schemeKnown)
		{
			return self::LINK_STATE_SCHEME_UNKNOWN;
		}

		if (!$candidates)
		{
			return self::LINK_STATE_NO_CANDIDATES;
		}

		return ($value === '' || self::findCandidate($candidates, $value) !== null)
			? self::LINK_STATE_AVAILABLE
			: self::LINK_STATE_SOURCE_UNAVAILABLE
		;
	}

	/**
	 * Name of the kind of a node, as the catalog of activities calls it: a node keeps it until someone
	 * titles it, so this is what a candidate without a title is shown by.
	 */
	public function nodeTypeName(string $nodeType): string
	{
		$nodeType = trim($nodeType);
		if ($nodeType === '')
		{
			return '';
		}

		return $this->typeNames[$nodeType] ??=
			Container::instance()->getActivitySearcherService()->searchByCode($nodeType)?->getName() ?? ''
		;
	}

	/**
	 * Nodes standing on the canvas, unwrapped out of the root a template holds its canvas in, along with
	 * whether that root promises the node being edited first among them.
	 *
	 * Two roots are wrappers and not places: the one the node editor builds
	 * ({@see NodesToTemplate::createNodeRootActivity}) and the sequential root of the classic designer. Left
	 * in place, either would make every node of the canvas report itself as sitting inside the template, and
	 * the node being edited would be looked for one level above where the caller puts it. The list is closed
	 * by type on purpose: a wrapper cannot be told from a container by the mere holding of children, since
	 * the node being edited may itself be a container - and then its children are candidates, not a canvas.
	 *
	 * Only the node editor promises the node being edited first; the classic root carries the whole process
	 * and says nothing about the order, so there nothing but the name excludes the node being edited.
	 *
	 * A slice that carries no root is already its own canvas.
	 *
	 * @return array{0: array, 1: bool}
	 */
	private function canvasOf(array $slice): array
	{
		$canvas = [];
		$editedNodeComesFirst = true;

		foreach ($slice as $node)
		{
			$type = is_array($node) ? $this->nodeType($node) : '';
			if (!in_array($type, self::CANVAS_WRAPPER_TYPES, true))
			{
				$canvas[] = $node;

				continue;
			}

			if ($type === self::SEQUENTIAL_ROOT_TYPE)
			{
				$editedNodeComesFirst = false;
			}

			$children = $node['Children'] ?? null;
			$canvas = array_merge($canvas, is_array($children) ? $children : []);
		}

		return [$canvas, $editedNodeComesFirst];
	}

	/**
	 * Walks the canvas, a node before its children.
	 *
	 * @return \Generator<int, array{0: array, 1: array|null, 2: bool}>
	 */
	private function walk(array $level, ?array $parent = null, bool $editedNodeComesFirst = true): \Generator
	{
		$position = 0;
		foreach ($level as $node)
		{
			// The node editor puts the node being edited first on the canvas, whatever it is named there.
			$isEditedNode = $editedNodeComesFirst && $position === 0;
			$position++;

			if (!is_array($node))
			{
				continue;
			}

			yield [$node, $parent, $isEditedNode];

			$children = $node['Children'] ?? null;
			if (is_array($children))
			{
				yield from $this->walk($children, $node, false);
			}
		}
	}

	private function makeCandidate(array $node, ?array $parent): array
	{
		$title = $this->nodeTitle($node);

		return [
			'Name' => $this->nodeName($node),
			'Title' => $title === '' ? $this->nodeTypeName($this->nodeType($node)) : $title,
			'TitleIsFallback' => $title === '',
			// null tells "sits on the canvas" from "sits inside a container", so a container with no title
			// of its own still answers with a string.
			'ParentTitle' => $parent === null ? null : $this->containerTitle($parent),
		];
	}

	private function containerTitle(array $parent): string
	{
		$title = $this->nodeTitle($parent);

		return $title === '' ? $this->nodeTypeName($this->nodeType($parent)) : $title;
	}

	private function nodeName(array $node): string
	{
		$name = $node['Name'] ?? null;

		return is_scalar($name) ? trim((string)$name) : '';
	}

	private function nodeType(array $node): string
	{
		$type = $node['Type'] ?? null;

		return is_scalar($type) ? trim((string)$type) : '';
	}

	private function nodeTitle(array $node): string
	{
		$title = $node['Properties']['Title'] ?? null;

		return is_scalar($title) ? trim((string)$title) : '';
	}
}
