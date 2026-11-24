<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Form;

use Bitrix\Im\V2\Common\ContextCustomer;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\Action\ButtonActionService;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\FormBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\RequestButton;

/**
 * A compatibility entry point for callers that were submitting a Form block before block buttons
 * became a common thing. Validation of the submitted values lives in ButtonActionService::run()
 * now, and publication became two-channel: the common click event plus the form submit one.
 */
class FormSubmitService
{
	use ContextCustomer;

	/** Does not persist the submitted values — only fires an event for internal subscribers. */
	public function submit(Message $message, FormBlock $block, RequestButton $button, array $values): void
	{
		(new ButtonActionService())
			->withContext($this->context)
			->publish($message, $block, $button, $values)
		;
	}
}
