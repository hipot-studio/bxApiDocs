<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\WorkflowTemplate;

use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantWorkflowTemplateConverterService;

/**
 * The mark of a publication the editor reads its "published" status off.
 *
 * The canvas calls a block published while `node.updated` of the graph it shows equals `node.published` of
 * the live template, and the publication of the editor gives every block one and the same moment as both
 * (`buildPublicationData()` of the chart store). The save of a draft moves `updated` alone, so a scheme
 * published as it lies in the draft keeps the marks of the draft, and the canvas shows each of its blocks
 * as unpublished for good. A publication made past the editor stamps the scheme here instead.
 *
 * A block is a direct child of the root of a node graph, exactly what
 * {@see \Bitrix\Bizproc\Workflow\Template\Converter\TemplateToNodes} turns into the blocks of the canvas; the
 * activities nested in a complex node or a frame are not blocks and carry no marks.
 */
final class PublicationMark
{
	private const EDITOR_SECTION = 'node';
	private const UPDATED = 'updated';
	private const PUBLISHED = 'published';

	/** The current moment in milliseconds, the unit Date.now() of the editor stamps with. */
	public static function now(): int
	{
		return (int)floor(microtime(true) * 1000);
	}

	/**
	 * @param array<int, mixed> $template the TEMPLATE field of a scheme
	 * @param int $moment the moment of the publication, in milliseconds
	 * @return array<int, mixed> the same scheme with every block marked as published at that moment
	 */
	public static function stamp(array $template, int $moment): array
	{
		$root = $template[0] ?? null;
		if (
			!is_array($root)
			|| ($root[NodesToTemplate::ELEMENT_TYPE] ?? null) !== NodesToTemplate::ROOT_NODE_TYPE
			|| !is_array($root[NodesToTemplate::ELEMENT_CHILDREN] ?? null)
		)
		{
			return $template;
		}

		$nodeElement = AiAssistantWorkflowTemplateConverterService::NODE_ELEMENT;
		foreach ($root[NodesToTemplate::ELEMENT_CHILDREN] as $index => $child)
		{
			$node = is_array($child) ? ($child[$nodeElement] ?? null) : null;
			$section = is_array($node) ? ($node[self::EDITOR_SECTION] ?? []) : null;
			if (!is_array($section))
			{
				continue;
			}

			$section[self::UPDATED] = $moment;
			$section[self::PUBLISHED] = $moment;
			$template[0][NodesToTemplate::ELEMENT_CHILDREN][$index][$nodeElement][self::EDITOR_SECTION] = $section;
		}

		return $template;
	}
}
