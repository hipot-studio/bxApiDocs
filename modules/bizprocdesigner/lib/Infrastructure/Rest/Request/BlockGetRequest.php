<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Request;

use Bitrix\Rest\V3\Interaction\Request\GetRequest;

/**
 * Reading one block of the agent-facing catalog.
 *
 * The resource id is the block type. A preset narrows the read to that preset of the same block:
 * the answer then also carries the property values the preset applies.
 */
final class BlockGetRequest extends GetRequest
{
	public ?string $presetId = null;

	/**
	 * The values the block is described in the context of, as the caller wrote them.
	 *
	 * A block resolves part of its own schema from the values it already holds - a map whose keys are the
	 * columns of the storage its code names, an option list read from the entity a setting points at - so
	 * without them the answer describes the block in the abstract. The preset is applied first and this
	 * refines it; completeness of the block is not required, because this is a request for a description
	 * and not a graph to be written.
	 *
	 * Carried untyped: the core fills a plain array property with the raw input, and the pairs are judged
	 * against the settings schema of this very block, which only the catalog can build
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentBlockContextResolver}).
	 * Optional - a read without it answers exactly as it always has.
	 *
	 * @var list<array{name: string, value: string|int|float|bool|array|null}>|null
	 */
	public ?array $contextSettings = null;
}
