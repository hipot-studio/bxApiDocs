<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum;

/**
 * Machine readable class of a domain error of the agent graph.
 *
 * The code names a class of failures, not a single message: the graph validators produce dozens of texts,
 * and an external agent needs to branch on a handful of causes, not to match wording. It travels in
 * customData['errorCode'] of {@see \Bitrix\Main\Error}, next to the address of the problem in Error::code,
 * and reaches the agent as ValidationIssueDto::$code of the template.validate report - so the values here
 * are part of the public REST contract and must stay stable. The dry run is the only place it surfaces: a
 * refused write answers with the message and Error::code alone
 * ({@see \Bitrix\Rest\V3\Exception\Validation\ValidationException::output()} never reads customData).
 *
 * A class is extracted for a cause the agent reaches by authoring or amending a graph the ordinary way, so
 * that the refusals of a working day answer with one. Failures no class has been extracted for - the ones
 * only a broken server, a damaged description of an activity or a state the agent cannot bring about lead
 * to - carry no code at all: the address and the text are the whole answer there, and inventing a class per
 * message would freeze wording into the contract.
 */
enum GraphErrorCode: string
{
	/** The submitted block type is not in the catalog of the document type. */
	case BlockTypeUnknown = 'BlockTypeUnknown';

	/** The graph carries more blocks than the topology/layout engine accepts in a single save. */
	case BlockLimitExceeded = 'BlockLimitExceeded';

	/** The graph carries more connections than are accepted in a single save. */
	case ConnectionLimitExceeded = 'ConnectionLimitExceeded';

	/** The blocks of the graph carry more settings in total than are accepted in a single save. */
	case SettingLimitExceeded = 'SettingLimitExceeded';

	/** A single setting value is longer than is accepted in a single save. */
	case SettingValueLimitExceeded = 'SettingValueLimitExceeded';

	/** The submitted graph is larger, serialized, than is accepted in a single save. */
	case GraphSizeLimitExceeded = 'GraphSizeLimitExceeded';

	/**
	 * A connection or a complex-node rule names no port the block can be reached through: one the block does
	 * not have, or one that is no port id at all.
	 */
	case PortInvalid = 'PortInvalid';

	/**
	 * The body of a loop block does not lead back into the block. A refusal where the loop declares a loop-back
	 * INPUT and the closure is provable from it (ForEach), and a warning where the body returns into the ordinary
	 * entry input instead (While) - one fact, told at the severity BRIEF § 7.5 gives each shape.
	 */
	case LoopNotClosed = 'LoopNotClosed';

	/** The condition of a loop is true whatever the document holds, so nothing in it can stop the loop. */
	case LoopConditionAlwaysTrue = 'LoopConditionAlwaysTrue';

	/** The graph carries no trigger at all, so no event can start the process. */
	case StartTriggerMissing = 'StartTriggerMissing';

	/** The membership of a frame overlay is broken: an unusable member, or a block captured without being one. */
	case FrameMembershipViolated = 'FrameMembershipViolated';

	/** Two frame overlays overlap after layout. */
	case FramesIntersect = 'FramesIntersect';

	/** An immutable node outside the published baseline that the policy of its family does not admit. */
	case ImmutableNodePolicyViolation = 'ImmutableNodePolicyViolation';

	/** The server could not assemble the context a check rests on, so the graph was judged on nothing. */
	case ContextResolutionFailed = 'ContextResolutionFailed';

	/** The storage the node addresses does not belong to the bound template. */
	case StorageReferenceDenied = 'StorageReferenceDenied';

	/** Several nodes create one storage code and declare different columns, so the code has no single contract. */
	case StorageCreatorConflict = 'StorageCreatorConflict';

	/** No node that creates the storage runs before the node that uses it. */
	case StorageOrderInvalid = 'StorageOrderInvalid';

	/** The storage can be reached by a route that creates it on no branch, so a run may find it missing. */
	case StorageOrderRisk = 'StorageOrderRisk';

	/** The graph moves control outside its connections, so the order of creating and using storage is unproven. */
	case StorageOrderUnknown = 'StorageOrderUnknown';

	/** An expression addresses a block the submitted graph does not carry. */
	case ExpressionBlockUnknown = 'ExpressionBlockUnknown';

	/** An expression addresses a block that does not run before the one reading it. */
	case ExpressionBlockNotAncestor = 'ExpressionBlockNotAncestor';

	/** An expression addresses an output the block it names does not have; details carry the ones it has. */
	case ExpressionOutputUnknown = 'ExpressionOutputUnknown';

	/** The outputs of an addressed block cannot be listed before the graph is saved, so nothing is claimed. */
	case ExpressionOutputsUnknown = 'ExpressionOutputsUnknown';

	/** An entry of a settings list is not the {name, value} pair it is read as - not an object, or a nameless one. */
	case SettingEntryInvalid = 'SettingEntryInvalid';

	/** A settings list names one setting twice, so which of the two values the block carries would depend on order. */
	case SettingNameDuplicated = 'SettingNameDuplicated';

	/** A setting name is neither described by the schema of its owner nor admitted beside it. */
	case SettingNameUnknown = 'SettingNameUnknown';

	/** A setting the schema declares required carries no value once defaults and baseline values are applied. */
	case SettingRequired = 'SettingRequired';

	/** A cross-setting rule of the block is not satisfied by the settings it names. */
	case SettingConstraintViolated = 'SettingConstraintViolated';

	/** The value of a setting has a shape the schema cannot hold - a map where a list of objects is declared. */
	case SettingValueInvalid = 'SettingValueInvalid';

	/** A number a setting carries is outside the bounds its schema publishes. */
	case SettingValueOutOfBounds = 'SettingValueOutOfBounds';

	/** A map carries a key its child schema does not declare. */
	case MapKeyUnknown = 'MapKeyUnknown';

	/** A map is missing a key its child schema declares required. */
	case MapKeyRequired = 'MapKeyRequired';

	/** A map whose keys are free carries a nested structure where only a scalar can be read. */
	case MapValueNested = 'MapValueNested';

	/** A block identifier is no name an activity can be addressed by: empty, or outside the grammar a new one owes. */
	case BlockIdInvalid = 'BlockIdInvalid';

	/** Two blocks of the graph carry one identifier, so a reference to it would address either of them. */
	case BlockIdDuplicated = 'BlockIdDuplicated';

	/**
	 * The preset the block names is not one its type offers, or the type carries its name on a preset and the
	 * block came without one. Which presets a type has is published by the catalog, so the answer is a lookup
	 * there rather than a repair of what was sent.
	 */
	case PresetIdInvalid = 'PresetIdInvalid';

	/** The activity a sub-action or a node filter names is not one the complex node admits in that place. */
	case SubActionUnknown = 'SubActionUnknown';

	/**
	 * An element of the graph - a block, a connection, a rule, a construction of one - is not what the contract
	 * declares: the wrong shape, a field of the wrong type, a field the element must carry left out, a value
	 * outside a set the contract itself fixes and names in the message, or a link to a block the submitted graph
	 * does not carry. Which element it is the address already says, so the class does not repeat it; what it asks
	 * for is a repair of what was written there. Settings keep a family of their own
	 * ({@see self::SettingEntryInvalid}, {@see self::SettingValueInvalid}), and a block named by an expression
	 * rather than by a link keeps one too ({@see self::ExpressionBlockUnknown}).
	 */
	case GraphElementInvalid = 'GraphElementInvalid';

	/**
	 * The element is written as the contract declares, but the place it was put in does not admit it: a field
	 * belonging to another kind of block, a construction the composition of this node or this portal does not
	 * offer. Repairing it changes nothing - it has to be dropped or moved - and that different next step is the
	 * whole reason this is a class beside {@see self::GraphElementInvalid} and not the same one.
	 */
	case GraphElementNotAllowed = 'GraphElementNotAllowed';
}
