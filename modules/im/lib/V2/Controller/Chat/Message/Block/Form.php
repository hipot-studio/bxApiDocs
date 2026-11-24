<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Message\Block;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\Action\ButtonActionService;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\FormBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\RequestButton;

class Form extends BaseController
{
	/**
	 * The narrow door of the Form block into the same click service, kept for released mobile
	 * builds. Everything that is not a submit button of a form is answered the way this method
	 * always answered it - the general Block.Action.run accepts a click on any block instead.
	 *
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
		if (!($block instanceof FormBlock) || !($block->getButtonById($buttonId) instanceof RequestButton))
		{
			$this->addError(new BuilderError(BuilderError::ELEMENT_NOT_FOUND));

			return null;
		}

		$result = (new ButtonActionService())->run($message, $blockId, $buttonId, $values);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['status' => 'accepted'];
	}
}
