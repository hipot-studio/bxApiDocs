<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat\Message\Block;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\Message\BlocksBuilder\Action\ButtonActionService;
use Bitrix\Im\V2\Message\BlocksBuilder\BuilderError;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\AbstractBlock;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\Button;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Buttons\RequestButton;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\Field\Footer\FooterElement;
use Bitrix\Im\V2\Message\BlocksBuilder\Entity\Blocks\FormBlock;

class Action extends BaseController
{
	/**
	 * A click on a button of any block. A switch closes the click it ships with, not the whole entry
	 * point: an element of the footer is refused while the footer switch is off, a submit of the Form
	 * block - while the Form block switch is off, and it answers such a submit with the very code the
	 * narrow door Block.Form.submit answers with. A button of the `buttons` field outside a form - the
	 * one of a Card, for instance - depends on neither switch, the way it never did.
	 *
	 * @restMethod im.v2.Chat.Message.Block.Action.run
	 */
	public function runAction(Message $message, string $blockId, string $buttonId, array $values = [], string $actionUuid = ''): ?array
	{
		$service = new ButtonActionService();

		$block = $message->getBlocksBuilder()?->getBlockById($blockId);
		$element = $block === null ? null : $service->findElement($block, $buttonId);

		$unavailableCode = $this->getUnavailableCode($block, $element);
		if ($unavailableCode !== null)
		{
			$this->addError(new BuilderError($unavailableCode));

			return null;
		}

		$result = $service->run($message, $blockId, $buttonId, $values);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['status' => 'accepted'];
	}

	/**
	 * A click whose block or element was not found is left to the service: it answers an unknown
	 * identifier the same way for every caller.
	 */
	private function getUnavailableCode(?AbstractBlock $block, FooterElement|Button|null $element): ?string
	{
		if ($element instanceof FooterElement && !Features::isMessageBlockFooterAvailable())
		{
			return BuilderError::FOOTER_NOT_AVAILABLE;
		}

		$isFormSubmit = $block instanceof FormBlock && $element instanceof RequestButton;
		if ($isFormSubmit && !Features::isMessageFormBlockAvailable())
		{
			return BuilderError::FORM_BLOCK_NOT_AVAILABLE;
		}

		return null;
	}
}
