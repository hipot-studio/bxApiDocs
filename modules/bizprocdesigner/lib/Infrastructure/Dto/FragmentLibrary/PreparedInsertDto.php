<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\FragmentLibrary;

use Bitrix\Bizproc\Api\Response\TemplateFragmentService\PrepareInsertResponse;
use Bitrix\Bizproc\Workflow\Template\Fragment\Enum\SkipReason;
use JsonSerializable;

/**
 * A fragment prepared for the canvas of the recipient (DTO-02). The blocks and the connections are the very
 * structures the loading of a scheme answers with, so the editor puts them on the canvas through the code it
 * already has; the node names in them are the new ones.
 *
 * The reason of a skip crosses the wire as the value of {@see SkipReason} and never as a phrase: the phrases
 * of the library belong to the editor, which owns every one of them.
 */
final class PreparedInsertDto implements JsonSerializable
{
	/**
	 * @param list<array{nodeName: string, title: string, reason: string}> $skipped
	 * @param list<array{nodeName: string, title: string, references: list<string>}> $needsReview
	 * @param array $sourceDocumentType the triple the fragment was cut from, for the text of the warning
	 */
	public function __construct(
		public readonly array $blocks,
		public readonly array $connections,
		public readonly array $skipped,
		public readonly array $needsReview,
		public readonly array $sourceDocumentType,
	)
	{}

	public static function fromResponse(PrepareInsertResponse $response): self
	{
		return new self(
			blocks: $response->getBlocks(),
			connections: $response->getConnections(),
			skipped: array_map(
				static fn(array $entry): array => [
					'nodeName' => $entry['nodeName'],
					'title' => $entry['title'],
					'reason' => $entry['reason']->value,
				],
				$response->getSkipped(),
			),
			needsReview: $response->getNeedsReview(),
			sourceDocumentType: $response->getSourceDocumentType(),
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'blocks' => $this->blocks,
			'connections' => $this->connections,
			'skipped' => $this->skipped,
			'needsReview' => $this->needsReview,
			'sourceDocumentType' => $this->sourceDocumentType,
		];
	}
}
