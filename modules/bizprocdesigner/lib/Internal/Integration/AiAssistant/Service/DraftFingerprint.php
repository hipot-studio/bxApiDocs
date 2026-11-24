<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

/**
 * Fingerprint of the content of a draft: how publication tells "this is the graph the human approved"
 * from "the draft was rewritten between the showing and the write".
 *
 * The id of the draft will not do this job, and that cost something to find out. template.draft.add
 * rewrites a draft IN PLACE - {@see \Bitrix\Bizproc\Api\Service\WorkflowTemplateService::saveTemplateDraft()}
 * updates the row under the same primary key - and what a read resolves is "the newest draft of THIS
 * user": a second agent session, a second pass of the same application and the human in the editor all
 * write under that same user into that one row. Comparing ids would pass exactly the substitution it was
 * meant to catch.
 *
 * Computed over the RAW TEMPLATE_DATA blob, the value {@see AgentWorkflowResolverService::getDraftTemplateData()}
 * answers with - NOT over the answer of template.get. That one is a graph produced by the converter, and
 * the converter normalizes: it can fold two different blobs onto one graph, and a substitution between
 * them would pass in silence. Both sides call this function on the one value, and that symmetry is the
 * only thing the comparison rests on - the same reason {@see StorageNodeFingerprint} holds it.
 *
 * The blob comes out of the database through unserialize(..., ['allowed_classes' => false]), so it holds
 * arrays and scalars only and serialize() over it is deterministic between requests.
 */
final class DraftFingerprint
{
	/** @param array<string, mixed> $templateData the raw draft blob */
	public static function of(array $templateData): string
	{
		return hash('sha256', serialize(self::canonicalize($templateData)));
	}

	/**
	 * Sorts keys, recursively. A reordered set of keys is the same content, and without this a harmless
	 * rewrite would read as a substitution: a refusal with nothing behind it. Order WITHIN a list survives,
	 * because integer keys sort into the order they already carry - which is what keeps the sequence of the
	 * activities part of the fingerprint.
	 *
	 * @param array<mixed> $value
	 * @return array<mixed>
	 */
	private static function canonicalize(array $value): array
	{
		ksort($value);

		foreach ($value as $key => $item)
		{
			if (is_array($item))
			{
				$value[$key] = self::canonicalize($item);
			}
		}

		return $value;
	}
}
