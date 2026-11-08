<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Field;

use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\Activity\NodeCandidateCollector;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\Main\Diag\LoggerFactory;

/**
 * Declaration of a field that links to another node of the same scheme, for a node to call from its own
 * properties map. The map is the single place the candidates are collected in - the panel of the settings
 * and the save go through it alike - so the list the user chose from and the list the save is checked
 * against are one list.
 *
 * That check is no boundary of access: the candidates are collected from the slice the client sent, so
 * checking a saved value against them helps the editor and says nothing about what the link may reach.
 * A node declaring such a field has to answer for the link itself while it runs - look the linked node
 * up inside the running instance of its own process, hold it to the kind the field allows, and refuse
 * the link when what it finds is not that.
 */
final class NodeReferenceDeclaration
{
	private const LOGGER_ID = 'bizproc.activity.node_reference';

	/**
	 * @param string $activityType Code of the activity declaring the field, e.g. `MyNodeActivity`.
	 * @param array $property Presentation of the field the activity owns: `Name`, `FieldName`, `Required`.
	 * @param string[] $nodeTypes White list of activity codes a link may point at.
	 * @param string $editedName Name of the node being edited, empty for a node that has none yet.
	 * @param array $context Context of getPropertiesMap(): `Template` holds the slice, `Properties` the
	 *   values of the node being edited.
	 */
	public static function build(
		string $activityType,
		array $property,
		array $nodeTypes,
		string $editedName,
		array $context = [],
	): array
	{
		if (!empty($property['Multiple']))
		{
			static::logDeclarationError("field of {$activityType} asked for multiplicity the type has none of");
		}

		$collector = new NodeCandidateCollector();

		// One answer decides both: null is "nothing is known about the scheme", an array is the scheme
		// itself. Reading the two apart would let them drift.
		$candidates = static::collectCandidates($collector, $activityType, $nodeTypes, $editedName, $context);
		$schemeKnown = $candidates !== null;

		return array_merge($property, [
			'Type' => FieldType::NODEREFERENCE,
			'Multiple' => false,
			// A link points at a node of the scheme, and an expression names no node.
			'AllowSelection' => false,
			'Settings' => static::buildSettings(
				$collector,
				$nodeTypes,
				$candidates ?? [],
				$schemeKnown,
				$property,
				$context,
			),
		]);
	}

	/**
	 * Nodes of the slice a link may point at, or null where nothing is known about the scheme.
	 *
	 * A node the catalog does not call a node has no scheme to link within, and the mistake belongs to the
	 * log of the developer rather than to an exception on the user. A catalog that answers nothing at all -
	 * a half-installed module, a cold cache - is no mistake of a declaration and is logged as itself. Either
	 * way the field is left exactly as ignorant as a silent editor leaves it, so both answer the same null:
	 * neither may turn into a refusal to save a template nobody touched.
	 */
	private static function collectCandidates(
		NodeCandidateCollector $collector,
		string $activityType,
		array $nodeTypes,
		string $editedName,
		array $context,
	): ?array
	{
		$description = Container::instance()->getActivitySearcherService()->searchByCode($activityType);
		if ($description === null)
		{
			static::logDeclarationError("the catalog of the activities answers nothing for {$activityType}");

			return null;
		}

		if (!in_array(ActivityType::NODE->value, $description->getType(), true))
		{
			static::logDeclarationError("{$activityType} declares a node reference without being a node");

			return null;
		}

		$slice = $context['Template'] ?? null;

		// A slice is never empty: the node being edited is in it, first. So an empty array is not a scheme
		// without nodes but a template the client never sent - the same silence as a missing key.
		return is_array($slice) && $slice !== []
			? $collector->collect($slice, $nodeTypes, $editedName)
			: null
		;
	}

	private static function buildSettings(
		NodeCandidateCollector $collector,
		array $nodeTypes,
		array $candidates,
		bool $schemeKnown,
		array $property,
		array $context,
	): array
	{
		$fieldName = (string)($property['FieldName'] ?? '');
		$saved = $context['Properties'][$fieldName] ?? null;
		$saved = is_scalar($saved) ? trim((string)$saved) : '';

		return [
			'SchemeKnown' => $schemeKnown,
			'Candidates' => $candidates,
			'SelectedTitle' => static::resolveSelectedTitle(
				$collector,
				$candidates,
				$nodeTypes,
				$saved,
				$schemeKnown,
			),
		];
	}

	/**
	 * Caption of a saved value the candidates do not hold. With the scheme unknown the node cannot be looked
	 * up to be named, so the value carries the name of the kind it was chosen from; once the scheme is
	 * known, the name of the node is the whole of what is left known about it.
	 *
	 * A white list of several kinds is narrowed to its first one on purpose: with no scheme there is nothing
	 * to tell which of the allowed kinds the saved node was, and naming one of them beats naming none. The
	 * ambiguity costs a caption on a surface that cannot edit the link anyway, never the link itself.
	 */
	private static function resolveSelectedTitle(
		NodeCandidateCollector $collector,
		array $candidates,
		array $nodeTypes,
		string $saved,
		bool $schemeKnown,
	): ?string
	{
		if ($saved === '' || NodeCandidateCollector::findCandidate($candidates, $saved) !== null)
		{
			return null;
		}

		if (!$schemeKnown)
		{
			$kindName = $nodeTypes ? $collector->nodeTypeName((string)reset($nodeTypes)) : '';

			return $kindName === '' ? $saved : $kindName;
		}

		return $saved;
	}

	private static function logDeclarationError(string $message): void
	{
		(new LoggerFactory())->createById(self::LOGGER_ID)?->error($message);
	}
}
