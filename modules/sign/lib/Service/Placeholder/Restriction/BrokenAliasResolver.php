<?php

namespace Bitrix\Sign\Service\Placeholder\Restriction;

use Bitrix\Sign\Config;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Placeholder\FieldAlias\AliasContext;
use Bitrix\Sign\Service\Placeholder\FieldAlias\FieldAliasService;

/**
 * Tells whether the field an inserted code resolved to is hidden from the list of codes anyway.
 *
 * Reuses the roundtrip criterion of FieldAliasService so that the warning and the code list hide exactly
 * the same codes, the emergency switch of the criterion included: while it is raised the list offers the
 * fields without a reversible alias instead of skipping them, so the warning must not report them either.
 */
class BrokenAliasResolver
{
	private readonly FieldAliasService $fieldAliasService;
	private readonly Config\Storage $config;

	public function __construct(
		?FieldAliasService $fieldAliasService = null,
		?Config\Storage $config = null,
	)
	{
		$this->fieldAliasService = $fieldAliasService ?? Container::instance()->getFieldAliasService();
		$this->config = $config ?? Config\Storage::instance();
	}

	public function isBroken(string $fieldName, AliasContext $context): bool
	{
		if ($this->config->isPlaceholderVerifiedAliasFilterDisabled())
		{
			return false;
		}

		return $this->fieldAliasService->toVerifiedAlias($fieldName, $context) === null;
	}
}
