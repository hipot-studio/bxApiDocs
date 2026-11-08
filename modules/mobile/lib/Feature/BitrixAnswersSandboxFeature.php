<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

/**
 * Debug switch of the "Bitrix24 Answers" screen: the card carries the address of the sandbox page of
 * the bridge instead of the address of the answers widget.
 *
 * The web half of the bridge belongs to the helpdesk team and its page does not exist yet, so until
 * then the screen has nothing to talk to and neither half of the contract can be checked.
 *
 * The switch is not an element of the "My company" section: it opens no block of its own and only
 * substitutes the address of the block that is already there, so the rollout flag of the section is
 * none of its business.
 */
final class BitrixAnswersSandboxFeature extends FeatureFlag
{
	private const OPTION_CODE = 'feature_bitrix_answers_sandbox_enabled';

	public function isEnabled(): bool
	{
		return $this->isOptionOn('mobile', self::OPTION_CODE, false);
	}

	public function enable(): void
	{
		Option::set('mobile', self::OPTION_CODE, true);
	}

	public function disable(): void
	{
		Option::delete('mobile', ['name' => self::OPTION_CODE]);
	}
}
