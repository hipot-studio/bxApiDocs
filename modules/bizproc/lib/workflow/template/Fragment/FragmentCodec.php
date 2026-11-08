<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Workflow\Template\Fragment;

use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Runtime\ActivitySearcher\Activities;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Result;

/**
 * The envelope of a saved fragment: its format number, the document type it was cut from, the nodes and
 * the triples of the links between them. Everything the fragment needs is inside, because the row it
 * lies in mirrors nothing of it.
 *
 * The envelope is written with the primitive the template itself is written with - serialize() plus the
 * compression of the portal - and it is read through the same helper, so untrusted bytes are parsed with
 * objects switched off in one place only. JSON is not an option here: node settings hold arrays with
 * mixed keys, and a JSON round trip changes their types.
 *
 * Reading looks at the format number. An unknown number is a refusal, never a silent attempt to parse.
 * Every refusal is one of the codes of {@see ErrorMessage}, the single list of the codes of the module.
 * The limits of SQL-02 are checked on writing and refuse with a code of their own: a refusal from the
 * database says nothing a user could act on and arrives too late.
 *
 * Both sides check them, and both check them on what they are about to pay for:
 *   - the ceilings on the graph are measured over the WHOLE tree, not over the blocks of the top level. The
 *     width of a nested level is as untrusted as the width of the root one, and the recursion the reader
 *     walks costs the same at either depth;
 *   - the ceiling in bytes is measured on the serialized form and never on the compressed one. Compression
 *     of homogeneous nodes reaches tens to one, so the compressed size limits the storage while the
 *     serialized size limits the parse - and it is the parse the reader pays for;
 *   - a refusal is reached before the heavy work: the raw input is measured before the forward converter,
 *     the serialization and the compression run, so a write that will be refused pays for none of them;
 *   - reading checks the ceilings again. An envelope may have been written by an earlier version of this
 *     code or changed in the database, and the side that walks it recursively has to defend itself.
 */
final class FragmentCodec
{
	public const FORMAT = 1;

	private const KEY_FORMAT = 'FORMAT';
	private const KEY_DOCUMENT_TYPE = 'DOCUMENT_TYPE';
	private const KEY_NODES = 'NODES';
	private const KEY_LINKS = 'LINKS';

	private const MEASURE_COUNT = 'count';
	private const MEASURE_DEEPER = 'deeper';

	private const BLOCK_ACTIVITY = 'activity';

	/**
	 * The selected blocks and connections as the scheme already carries them. Nodes and links are taken
	 * from the existing forward converter, so the fragment holds the graph in the very shape it lives in
	 * inside a template.
	 *
	 * @return Result data: fragment (the stored envelope), nodeCount
	 */
	public static function encode(array $blocks, array $connections, array $documentType): Result
	{
		$result = new Result();

		// The payload of the request is untrusted while both converters behind this read a block and a link as
		// arrays: an element that is neither is refused here, or it would reach them as a type error instead of
		// one of the codes of the surface.
		foreach (['blocks' => $blocks, 'connections' => $connections] as $parameter => $payload)
		{
			if (!self::everyElementIsArray($payload))
			{
				return $result->addError(ErrorMessage::INVALID_PARAM_TYPE->getCodedError([
					'#PARAM#' => $parameter,
					'#VALUE#' => 'array',
				]));
			}
		}

		// The graph is measured on the raw selection, before a single node of it is converted: the activity of
		// a block carries its whole subtree, so everything the ceilings are about is already here.
		$activities = self::activitiesOf($blocks);
		if ($activities === [])
		{
			return $result->addError(ErrorMessage::EMPTY_TEMPLATE->getCodedError());
		}

		$innerConnections = self::keepInnerConnections($blocks, $connections);
		$measured = self::measure($activities);

		if (
			$measured[self::MEASURE_COUNT] > Limits::MAX_NODES
			|| count($innerConnections) > self::maxLinks()
		)
		{
			return $result->addError(ErrorMessage::FRAGMENT_TOO_LARGE->getCodedError());
		}

		if ($measured[self::MEASURE_DEEPER])
		{
			return $result->addError(ErrorMessage::FRAGMENT_TOO_DEEP->getCodedError());
		}

		$root = (new NodesToTemplate($blocks, $innerConnections))->convert()[0];
		$envelope = [
			self::KEY_FORMAT => self::FORMAT,
			self::KEY_DOCUMENT_TYPE => $documentType,
			self::KEY_NODES => $root[NodesToTemplate::ELEMENT_CHILDREN],
			self::KEY_LINKS => $root[NodesToTemplate::ELEMENT_PROPERTIES][NodesToTemplate::PROPERTY_LINKS],
		];

		// Serialized once to be measured and once to be stored: the ceiling belongs to the form the reader
		// will parse, and the graph reaching this point is already within the ceilings above.
		if (strlen(serialize($envelope)) > Limits::MAX_ENVELOPE_BYTES)
		{
			return $result->addError(ErrorMessage::FRAGMENT_TOO_LARGE->getCodedError());
		}

		return $result->setData([
			'fragment' => (string)WorkflowTemplateTable::toSerializedForm($envelope),
			'nodeCount' => $measured[self::MEASURE_COUNT],
		]);
	}

	/**
	 * @return Result data: documentType, nodes, links
	 */
	public static function decode(?string $stored): Result
	{
		$result = new Result();

		$serialized = self::serializedFormWithinTheCeiling((string)$stored);
		if ($serialized === null)
		{
			return $result->addError(ErrorMessage::FRAGMENT_TOO_LARGE->getCodedError());
		}

		$envelope = self::parseEnvelope($serialized);

		if ($envelope === null || ($envelope[self::KEY_FORMAT] ?? null) !== self::FORMAT)
		{
			return $result->addError(ErrorMessage::FRAGMENT_FORMAT_UNSUPPORTED->getCodedError());
		}

		$documentType = $envelope[self::KEY_DOCUMENT_TYPE] ?? null;
		$nodes = $envelope[self::KEY_NODES] ?? null;
		$links = $envelope[self::KEY_LINKS] ?? null;

		if (!is_array($documentType) || !is_array($links) || !is_array($nodes) || $nodes === [])
		{
			return $result->addError(ErrorMessage::FRAGMENT_FORMAT_UNSUPPORTED->getCodedError());
		}

		$measured = self::measure($nodes);

		if (
			$measured[self::MEASURE_COUNT] > Limits::MAX_NODES
			|| count($links) > self::maxLinks()
		)
		{
			return $result->addError(ErrorMessage::FRAGMENT_TOO_LARGE->getCodedError());
		}

		if ($measured[self::MEASURE_DEEPER])
		{
			return $result->addError(ErrorMessage::FRAGMENT_TOO_DEEP->getCodedError());
		}

		return $result->setData([
			'documentType' => $documentType,
			'nodes' => $nodes,
			'links' => $links,
		]);
	}

	/**
	 * The way back to the shape of the canvas: a synthetic root over the nodes and the links, and then
	 * the existing reverse converter, which recalculates icons, ports and the fallback by class. That is
	 * why a fragment that has lain for a year needs no converter of its own.
	 *
	 * @param Activities|null $catalog the catalog of the activities the caller has already walked out
	 *        ({@see ApplicabilityFilter::catalog()}). The walk costs about a tenth of a second and the
	 *        preparation of an insertion needs it twice - once to judge the nodes and once to draw them - so
	 *        the second walk is spared by handing the first one over. Left out, the converter walks it itself.
	 *
	 * @return array [blocks, connections]
	 */
	public static function toNodes(
		array $nodes,
		array $links,
		bool $complexNodeConnectionsAvailable = false,
		?Activities $catalog = null,
	): array
	{
		$root = NodesToTemplate::createNodeRootActivity($links, $nodes);

		return (new TemplateToNodes([$root], $complexNodeConnectionsAvailable, $catalog))->convert();
	}

	/**
	 * A link leading out of the selection is dropped silently: the fragment carries only the links whose
	 * both ends are inside it, and an insertion never restores the rest.
	 */
	private static function keepInnerConnections(array $blocks, array $connections): array
	{
		$inside = array_fill_keys(array_column($blocks, 'id'), true);

		return array_values(array_filter(
			$connections,
			static fn(array $connection): bool => isset(
				$inside[$connection['sourceBlockId'] ?? ''],
				$inside[$connection['targetBlockId'] ?? ''],
			),
		));
	}

	/**
	 * Whether every element of a payload is of the shape the parameter is declared in. A payload the caller
	 * composed is a list of arrays, and the whole of it is walked anyway - by the measuring of the graph and
	 * by the keeping of the inner links - so proving the shape first costs nothing and spares both of them a
	 * guess about an element that is no element of theirs.
	 */
	private static function everyElementIsArray(array $payload): bool
	{
		foreach ($payload as $element)
		{
			if (!is_array($element))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * The activities of a selection, each one carrying its own subtree. A block without one converts to
	 * nothing and is nothing to measure; anything else is counted as it stands, because a shape the
	 * converter cannot read is no reason to leave a node out of a ceiling.
	 */
	private static function activitiesOf(array $blocks): array
	{
		$activities = [];

		foreach ($blocks as $block)
		{
			$activity = $block[self::BLOCK_ACTIVITY] ?? null;
			if ($activity !== null)
			{
				$activities[] = $activity;
			}
		}

		return $activities;
	}

	/**
	 * The nodes of the whole tree and the depth of it out of one walk: both ceilings are read off the same
	 * recursion, and it stops at the first one that is passed. The input is untrusted, and the answer
	 * beyond a ceiling is the same refusal whatever the real number is - so a tree of a million nodes is
	 * refused after two hundred of them and never counted to the end.
	 *
	 * @return array{count: int, deeper: bool}
	 */
	private static function measure(array $nodes, int $level = 1, int $counted = 0): array
	{
		if ($level > Limits::MAX_CHILDREN_DEPTH)
		{
			return [self::MEASURE_COUNT => $counted, self::MEASURE_DEEPER => true];
		}

		foreach ($nodes as $node)
		{
			$counted++;
			if ($counted > Limits::MAX_NODES)
			{
				return [self::MEASURE_COUNT => $counted, self::MEASURE_DEEPER => false];
			}

			$children = is_array($node) ? ($node[NodesToTemplate::ELEMENT_CHILDREN] ?? []) : [];
			if (!is_array($children) || $children === [])
			{
				continue;
			}

			$measured = self::measure($children, $level + 1, $counted);
			$counted = $measured[self::MEASURE_COUNT];

			if ($measured[self::MEASURE_DEEPER] || $counted > Limits::MAX_NODES)
			{
				return $measured;
			}
		}

		return [self::MEASURE_COUNT => $counted, self::MEASURE_DEEPER => false];
	}

	/**
	 * A link joins two nodes of the fragment, so the complete graph over the ceiling of the nodes is the
	 * ceiling of the links - composed of the declared one rather than declared as a number of its own. It is
	 * the ceiling of the nodes that holds the size of a fragment; this one holds the work the links alone
	 * could still cost a reader, and no scheme of two hundred nodes comes near it.
	 *
	 * A ceiling equal to the number of nodes would refuse a legitimate selection: a node branching into
	 * several ports contributes a connection per port, and several of them may end on one node.
	 */
	private static function maxLinks(): int
	{
		return Limits::MAX_NODES * Limits::MAX_NODES;
	}

	/**
	 * The serialized form of an envelope, and only while it stays within the ceiling in bytes. The
	 * compressed bytes say nothing about the cost of the parse - homogeneous nodes compress tens to one -
	 * so the unpacking itself is given the ceiling as its length.
	 *
	 * Null here is the answer of the unpacking and not the ceiling alone: the unpacking refuses rather than
	 * truncates once the length is passed, and it refuses the same way for a stream that carries the header
	 * of a compressed one and a body that is broken. So a stored row damaged past its first two bytes is
	 * answered as "too large", which names the wrong cause. Telling the two apart takes unpacking the stream
	 * step by step against a budget of bytes; that is not done here, and the refusal is honest about the
	 * envelope being unusable either way. An envelope that is no compressed data at all reaches the parse as
	 * it stands and is answered by it.
	 */
	private static function serializedFormWithinTheCeiling(string $stored): ?string
	{
		$serialized = self::isCompressed($stored)
			? @gzuncompress($stored, Limits::MAX_ENVELOPE_BYTES)
			: $stored
		;

		return is_string($serialized) && strlen($serialized) <= Limits::MAX_ENVELOPE_BYTES ? $serialized : null;
	}

	/**
	 * The header of a zlib stream, the one {@see WorkflowTemplateTable::toSerializedForm()} writes while the
	 * portal keeps its templates compressed: the method of the compression in the low half of the first byte
	 * and the check of the two bytes together. A serialized array never passes it, so a portal keeping them
	 * plain is told from a compressed envelope without unpacking anything.
	 */
	private static function isCompressed(string $stored): bool
	{
		if (strlen($stored) < 2)
		{
			return false;
		}

		return (ord($stored[0]) & 0x0f) === 8 && ((ord($stored[0]) << 8) + ord($stored[1])) % 31 === 0;
	}

	/**
	 * The envelope is written by one user and read by another, so a broken one has to come back as a
	 * refusal and never as a diagnostic escaping the parse.
	 */
	private static function parseEnvelope(string $stored): ?array
	{
		try
		{
			$envelope = @WorkflowTemplateTable::getFromSerializedForm($stored);
		}
		catch (\Throwable)
		{
			return null;
		}

		return is_array($envelope) && $envelope !== [] ? $envelope : null;
	}
}
