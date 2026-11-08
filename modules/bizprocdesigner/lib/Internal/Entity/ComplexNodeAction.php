<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * A single allowed sub-action of a complex node, projected for the AI block catalog (DTO-02).
 *
 * The dictionary element mirrors what the manual editor collects for a complex node, but keeps only
 * the fields the agent needs. Data comes from the same source as the manual editor
 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\ComplexActivityService}) - never a parallel AI description.
 *
 * @param SettingCollection $settingsSchema machine-readable settings schema of the sub-action (DTO-04), built by
 *     the same domain service that describes ordinary nodes
 *     ({@see \Bitrix\Bizproc\Service\AiDescription::getActivityDescription}) and serialised on the way out.
 *     An empty collection is valid - an HTML-only sub-action or a schema that is unavailable in the environment.
 * @param list<string> $skippedSettings names of the sub-action's properties that exist but are absent from the
 *     settings schema: properties the description could not express by type plus the ones the activity declares
 *     as carried from the published template. This is the union an ordinary block judges a name by
 *     ({@see \Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail::$skippedSettings} and
 *     {@see \Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail::$carriedSettings}), so a name gets the same
 *     verdict at both levels. Kept for reverse validation only and deliberately not published in the catalog -
 *     the agent must not read them as a writable surface.
 * @param array|null $documentSchema machine-readable contract of the sub-action's `document` construction
 *     field: only the FORMAT of the value (a bizproc expression `{=<sourceBlockId>:<propertyId>}`), never the
 *     concrete value set - that is a function of the graph topology and thus uncomputable in the catalog.
 *     `null` when the sub-action does not handle the document (`handlesDocument:false`) or the complex node has
 *     no fixed document type; in that case the key is not emitted at all (additive, symmetric to an empty
 *     settings schema - not a regression).
 */
class ComplexNodeAction implements Arrayable
{
	public function __construct(
		public readonly string $activityCode,
		public readonly string $title,
		public readonly bool $handlesDocument,
		public readonly int $sort = 0,
		public readonly ?string $presetId = null,
		public readonly SettingCollection $settingsSchema = new SettingCollection(),
		public readonly array $skippedSettings = [],
		public readonly ?array $documentSchema = null,
	) {}

	public function toArray(): array
	{
		$result = [
			'activityCode' => $this->activityCode,
			'title' => $this->title,
			'handlesDocument' => $this->handlesDocument,
			'sort' => $this->sort,
			'presetId' => $this->presetId,
			'settingsSchema' => $this->settingsSchema->toArray(),
		];

		if ($this->documentSchema !== null)
		{
			$result['documentSchema'] = $this->documentSchema;
		}

		return $result;
	}
}
