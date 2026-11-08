<?php

namespace Bitrix\Im\V2\Rest;

/**
 * TPL-01: an additional data item whose content depends on the format options of the response.
 * Such an item must learn the options before it fills itself, so PopupData hands them over in a
 * separate pass instead of waiting for the serialization call.
 */
interface PopupDataOptionAware
{
	public function applyOptions(array $option): void;
}
