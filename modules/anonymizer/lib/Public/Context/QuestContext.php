<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Public\Context;

use Bitrix\Anonymizer\Public\Providers\ProviderInterface;
use Bitrix\Main\Error;

/**
 * DTO passed to the quest completion Handler.
 * Required: questId, provider. Optional: error.
 *
 * @template TProvider of ProviderInterface
 */
class QuestContext
{
	public int $questId;

	/** @var TProvider */
	public ProviderInterface $provider;

	public ?Error $error = null;

	/**
	 * @param TProvider $provider
	 */
	public function __construct(int $questId, ProviderInterface $provider)
	{
		$this->questId = $questId;
		$this->provider = $provider;
	}
}
