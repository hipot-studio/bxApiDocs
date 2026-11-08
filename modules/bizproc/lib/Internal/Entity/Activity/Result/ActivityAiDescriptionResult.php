<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity\Result;

use Bitrix\Bizproc\Internal\Entity\Activity\ActivityAiDescriptionSource;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingConstraintCollection;
use Bitrix\Main\Result;

class ActivityAiDescriptionResult extends Result
{
	/**
	 * @param list<string> $skippedSettings Names the schema does not express and that are therefore accepted by
	 *     name alone. A degradation of the description rather than a contract: it is not published as something
	 *     the agent is allowed to write.
	 * @param list<string> $carriedSettings Names whose value may only be carried over verbatim from the published
	 *     template ({@see \Bitrix\Bizproc\Internal\Entity\Activity\SettingCategory::Carried}). Unlike the list
	 *     above this is a declared contract and belongs to the published block description.
	 */
	public function __construct(
		public readonly string $code,
		public readonly SettingCollection $settings,
		public readonly ActivityAiDescriptionSource $source = ActivityAiDescriptionSource::Manual,
		public readonly array $skippedSettings = [],
		public readonly array $carriedSettings = [],
		public readonly SettingConstraintCollection $settingConstraints = new SettingConstraintCollection(),
	)
	{
		parent::__construct();
	}
}