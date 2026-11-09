<?php

namespace Bitrix\Sign\Contract\Document;

use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Result\Service\Sign\Document\ManualFillingDecisionResult;

interface ManualFillingDecisionProvider
{
	/**
	 * Returns whether the provider can make manual filling decisions.
	 */
	public function isReady(): bool;

	/**
	 * Calculates whether the document needs manual field values.
	 */
	public function decide(Document $document): ManualFillingDecisionResult;
}
