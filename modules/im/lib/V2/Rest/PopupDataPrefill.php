<?php

namespace Bitrix\Im\V2\Rest;

/**
 * An additional data item that carries no section of its own and only prepares what the other items
 * serialize - a personal title written into the messages themselves, for one. PopupData runs prefill()
 * over every such item after the nested levels are merged in and before it serializes anything, so the
 * preparation does not depend on the position of the item in the list, and the item contributes no key
 * to the answer.
 */
interface PopupDataPrefill
{
	public function prefill(): void;
}
