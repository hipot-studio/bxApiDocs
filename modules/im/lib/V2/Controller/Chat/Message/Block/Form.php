<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Message\Block;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\FormBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\RequestButton;
use Bitrix\Im\V2\Message\BlocksBuilder\FormSubmitError;
use Bitrix\Im\V2\Message\BlocksBuilder\Validation\FormSubmitValidationService;
use Bitrix\Im\V2\Message\Form\FormSubmitService;

class Form extends BaseController
{
	/**
	 * @restMethod im.v2.Chat.Message.Block.Form.submit
	 */
	public function submitAction(Message $message, string $blockId, string $buttonId, array $values = [], string $actionUuid = ''): ?array
	{
		if (!Features::isMessageFormBlockAvailable())
		{
			$this->addError(new BuilderError(BuilderError::FORM_BLOCK_NOT_AVAILABLE));

			return null;
		}

		$builder = $message->getBlocksBuilder();
		if ($builder === null)
		{
			$this->addError(new BuilderError(BuilderError::BLOCK_NOT_FOUND));

			return null;
		}

		$block = $builder->getBlockById($blockId);
		if (!($block instanceof FormBlock))
		{
			$this->addError(new BuilderError(BuilderError::ELEMENT_NOT_FOUND));

			return null;
		}

		$button = $block->getButtonById($buttonId);
		if (!($button instanceof RequestButton))
		{
			$this->addError(new BuilderError(BuilderError::ELEMENT_NOT_FOUND));

			return null;
		}

		if ($button->isDisabled())
		{
			$this->addError(new FormSubmitError(FormSubmitError::FORM_DISABLED));

			return null;
		}

		$result = (new FormSubmitValidationService())->validate($block, $values);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		(new FormSubmitService())->submit($message, $block, $button, $result->getResult());

		return ['status' => 'accepted'];
	}
}
