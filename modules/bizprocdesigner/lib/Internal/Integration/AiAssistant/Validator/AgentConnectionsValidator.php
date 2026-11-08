<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Entity\AgentPortDefault;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\Result;

final class AgentConnectionsValidator
{
	/**
	 * Server-side DoS guard: the largest number of connections processed in a single save. Connection
	 * count drives the same polynomial layout work as blocks, so it is bounded server-side too. Kept at
	 * twice the block bound to allow branching/loops on a maximal graph without clipping. This constant is
	 * the single source of truth for the maxItems the Marta tool schema also advertises
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Tool\SaveWorkflowTool::getInputSchema()}):
	 * enforced here so the REST path is guarded as well, not only the schema-validated Marta path.
	 */
	public const MAX_CONNECTIONS = 512;

	private AgentConnectionCollection $validConnections;

	public function __construct()
	{
		$this->validConnections = new AgentConnectionCollection();
	}

	/**
	 * @param array<string, array{input?: list<string>, output?: list<string>, dynamic?: bool}> $blockPortsMap
	 *        known ports per block id; used to validate that a connection references an existing port.
	 *        Empty map (or an unknown block / empty direction) disables the existence check for that port,
	 *        UNLESS the block is marked `dynamic` (complex node): its port set is projected exhaustively
	 *        from its own rules, so an empty direction means the port does not exist and is an error.
	 * @param array<string, list<string>> $blockLoopbackInputPortsMap
	 *        loop-back input port ids per loop block id (e.g. ForEach:i1). A loop node listed here must
	 *        have its body closed back into a loop-back port; an unclosed loop is a diagnosable error.
	 *        Only loop nodes with a loop-back *input* are listed (ForEach); While has none and is absent
	 *        here by design - see {@see self::validateLoopClosure()}.
	 * @param list<string> $frameBlockIds
	 *        ids of frame overlays: presentational blocks with no ports. They are excluded from the
	 *        "graph with >1 block must be connected" guard so a single real block wrapped in a frame is not
	 *        falsely reported as needing a connection. Frame membership itself is checked separately by
	 *        {@see AgentFrameMembershipValidator}.
	 */
	public function validate(
		mixed $connections,
		array $blockIds = [],
		string $path = '',
		array $blockPortsMap = [],
		array $blockLoopbackInputPortsMap = [],
		array $frameBlockIds = [],
	): Result
	{
		$this->validConnections = new AgentConnectionCollection();

		if (!is_array($connections))
		{
			return (new Result())->addError(
				GraphError::at($path, "{$path} should be array", GraphErrorCode::GraphElementInvalid),
			);
		}

		if (count($connections) > self::MAX_CONNECTIONS)
		{
			return (new Result())->addError(
				GraphError::at(
					$path,
					"{$path} should contain at most " . self::MAX_CONNECTIONS . ' items',
					GraphErrorCode::ConnectionLimitExceeded,
				),
			);
		}

		$connectableBlockIds = $frameBlockIds === []
			? $blockIds
			: array_values(array_diff($blockIds, $frameBlockIds));
		if (empty($connections) && count($connectableBlockIds) > 1)
		{
			return (new Result())->addError(
				GraphError::at($path, "{$path} should be not empty array", GraphErrorCode::GraphElementInvalid),
			);
		}

		$result = new Result();
		foreach ($connections as $key => $connection)
		{
			$connectionValidateResult = $this->validateConnection(
				connection: $connection,
				path: "{$path}.{$key}",
				blockIds: $blockIds,
				blockPortsMap: $blockPortsMap,
				frameBlockIds: $frameBlockIds,
			);
			$result->addErrors($connectionValidateResult->getErrors());
		}

		$result->addErrors(
			$this->validateLoopClosure(
				connections: $connections,
				blockLoopbackInputPortsMap: $blockLoopbackInputPortsMap,
				path: $path,
			)->getErrors(),
		);

		return $result;
	}

	/**
	 * Validates that the tail of a loop body returns into the dedicated loop-back input (ForEach:i1, the
	 * input whose role is LoopBody); the ordinary entry input i0 is not a valid body return.
	 *
	 * Input-based by design, so While is out of scope: it carries the same role on its output side (o0)
	 * alone and the body closes back into i0, so {@see BlockTypeDetail::getLoopbackInputPortIds()} returns
	 * [] for it and $blockLoopbackInputPortsMap carries no entry. An unclosed While passes without a
	 * diagnostic. Moving the role check from the port title changed the signal, never this scope.
	 *
	 * @param array<string, list<string>> $blockLoopbackInputPortsMap
	 */
	private function validateLoopClosure(
		array $connections,
		array $blockLoopbackInputPortsMap,
		string $path,
	): Result
	{
		$result = new Result();
		if (empty($blockLoopbackInputPortsMap))
		{
			return $result;
		}

		$closedPortsByBlock = [];
		foreach ($connections as $connection)
		{
			if (!is_array($connection))
			{
				continue;
			}

			$targetBlockId = $connection['destinationBlockId'] ?? null;
			$targetPortId = $connection['targetPortId'] ?? null;
			if (!is_string($targetBlockId) || !is_string($targetPortId))
			{
				continue;
			}

			$closedPortsByBlock[$targetBlockId][$targetPortId] = true;
		}

		foreach ($blockLoopbackInputPortsMap as $blockId => $loopbackPorts)
		{
			$isClosed = false;
			foreach ($loopbackPorts as $loopbackPort)
			{
				if (isset($closedPortsByBlock[$blockId][$loopbackPort]))
				{
					$isClosed = true;
					break;
				}
			}

			if (!$isClosed)
			{
				// The offending value is the link that was never sent, so the error names the loop block
				// instead of pointing at a position in the payload.
				$result->addError(GraphError::unaddressed(
					"{$path} loop block '{$blockId}' body is not closed back into its loop-back port ("
					. implode(', ', $loopbackPorts)
					. ')',
					GraphErrorCode::LoopNotClosed,
					(string)$blockId,
				));
			}
		}

		return $result;
	}

	private function validateConnection(
		mixed $connection,
		string $path,
		array $blockIds,
		array $blockPortsMap,
		array $frameBlockIds = [],
	): Result
	{
		if (!is_array($connection))
		{
			return (new Result())->addError(
				GraphError::at($path, "$path should be object", GraphErrorCode::GraphElementInvalid),
			);
		}

		$result = new Result();

		$destinationBlockId = $connection['destinationBlockId'] ?? null;
		$destinationValidateResult = $this->validateConnectionBlockId(
			id: $destinationBlockId,
			path: "{$path}.destinationBlockId",
			blockIds: $blockIds,
			frameBlockIds: $frameBlockIds,
		);
		$result->addErrors($destinationValidateResult->getErrors());

		$sourceBlockId = $connection['sourceBlockId'] ?? null;
		$sourceValidateResult = $this->validateConnectionBlockId(
			id: $sourceBlockId,
			path: "{$path}.sourceBlockId",
			blockIds: $blockIds,
			frameBlockIds: $frameBlockIds,
		);
		$result->addErrors($sourceValidateResult->getErrors());

		$sourcePortId = $connection['sourcePortId'] ?? null;
		$result->addErrors(
			$this->validateConnectionPort(
				portId: $sourcePortId,
				blockId: $sourceBlockId,
				direction: 'output',
				path: "{$path}.sourcePortId",
				blockPortsMap: $blockPortsMap,
			)->getErrors(),
		);

		$targetPortId = $connection['targetPortId'] ?? null;
		$result->addErrors(
			$this->validateConnectionPort(
				portId: $targetPortId,
				blockId: $destinationBlockId,
				direction: 'input',
				path: "{$path}.targetPortId",
				blockPortsMap: $blockPortsMap,
			)->getErrors(),
		);

		if ($result->isSuccess())
		{
			$this->validConnections->add(
				new AgentConnection(
					destinationBlockId: $destinationBlockId,
					sourceBlockId: $sourceBlockId,
					sourcePortId: $sourcePortId,
					targetPortId: $targetPortId,
				),
			);
		}

		return $result;
	}

	/**
	 * Ports are optional. When present, a port id must be a non-empty string and, if the ports of the
	 * referenced block are known, must exist in the corresponding direction. A missing port defaults
	 * to o0/i0 downstream and is not an error - except for a complex (dynamic) node, whose exhaustive
	 * port set is projected from its own rules: if the default port is absent there, the portless link
	 * would materialize onto a non-existent port and is rejected.
	 */
	private function validateConnectionPort(
		mixed $portId,
		mixed $blockId,
		string $direction,
		string $path,
		array $blockPortsMap,
	): Result
	{
		if ($portId === null)
		{
			// A missing port defaults to o0/i0 downstream. For a complex node the port set is dynamic and
			// exhaustive (projected from its rules), so an absent default port means the portless link would
			// silently materialize onto a non-existent port - a dangling connection. Fail-closed here; every
			// other block keeps the backward-compatible pass-through for portless links.
			if (is_string($blockId) && $blockId !== '' && !empty($blockPortsMap[$blockId]['dynamic']))
			{
				$defaultPortId = $direction === 'output'
					? AgentPortDefault::SOURCE_PORT_ID
					: AgentPortDefault::TARGET_PORT_ID;

				if (!in_array($defaultPortId, $blockPortsMap[$blockId][$direction] ?? [], true))
				{
					return (new Result())->addError(GraphError::at(
						$path,
						"{$path} omits the port and defaults to '{$defaultPortId}', which does not exist on the complex node",
						GraphErrorCode::PortInvalid,
					));
				}
			}

			return new Result();
		}

		if (!is_string($portId) || $portId === '')
		{
			return (new Result())->addError(
				GraphError::at($path, "$path should be not empty string", GraphErrorCode::PortInvalid),
			);
		}

		if (!is_string($blockId) || $blockId === '')
		{
			return new Result();
		}

		$knownPorts = $blockPortsMap[$blockId][$direction] ?? [];
		if (empty($knownPorts))
		{
			// Complex nodes expose a dynamic, exhaustive port set derived from their own rules: an empty
			// direction means the node has no such port, so a referenced port cannot exist. For every other
			// block an empty/unknown map keeps the backward-compatible bypass (static topology unknown).
			if (!empty($blockPortsMap[$blockId]['dynamic']))
			{
				return (new Result())->addError(GraphError::at(
					$path,
					"$path references a port that does not exist on the block",
					GraphErrorCode::PortInvalid,
				));
			}

			return new Result();
		}

		if (!in_array($portId, $knownPorts, true))
		{
			return (new Result())->addError(GraphError::at(
				$path,
				"$path references a port that does not exist on the block",
				GraphErrorCode::PortInvalid,
			));
		}

		return new Result();
	}

	private function validateConnectionBlockId(mixed $id, string $path, array $blockIds, array $frameBlockIds = []): Result
	{
		if (!is_string($id) || $id === '')
		{
			return (new Result())->addError(
				GraphError::at($path, "$path should be not empty string", GraphErrorCode::GraphElementInvalid),
			);
		}

		if (!in_array($id, $blockIds, true))
		{
			return (new Result())->addError(
				GraphError::at($path, "$path is incorrect block id", GraphErrorCode::GraphElementInvalid),
			);
		}

		// A frame overlay is presentational and owns no ports; a connection to it has no port to land on and
		// would leak the frame into the layout adjacency as a dangling edge. Reject it so only real,
		// connectable blocks appear in connections. Membership is expressed via memberBlockIds, not links.
		if (in_array($id, $frameBlockIds, true))
		{
			return (new Result())->addError(
				GraphError::at($path, "$path must not reference a frame block", GraphErrorCode::GraphElementNotAllowed),
			);
		}

		return new Result();
	}

	public function getValidConnections(): AgentConnectionCollection
	{
		return $this->validConnections;
	}
}
