<?php

namespace Bitrix\DocumentGenerator\Engine;

use Bitrix\DocumentGenerator\Model\ExternalLinkTable;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class CheckHash extends Base
{
	const WRONG_HASH = 'WRONG_HASH_ERROR';

	/**
	 * Only these actions serve the document of the link, the rest are inherited from the private controller.
	 * Lower case, as action names are case-insensitive.
	 */
	private const LINKED_DOCUMENT_ACTIONS = ['get', 'getfile', 'getimage', 'getpdf', 'showpdf'];

	public function onBeforeAction(Event $event)
	{
		// raw parameters are checked before the action arguments are built: building them loads the whole document
		if ($this->isLinkedDocumentAction() && $this->isLinkValid())
		{
			return null;
		}

		$this->errorCollection[] = new Error(
			'Wrong hash', static::WRONG_HASH
		);
		// the action will not run, so the filters configured for it must not bind its arguments and load the document
		$this->getAction()->setArguments([]);

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	private function isLinkedDocumentAction(): bool
	{
		return in_array(mb_strtolower($this->getAction()->getName()), self::LINKED_DOCUMENT_ACTIONS, true);
	}

	private function isLinkValid(): bool
	{
		$id = $this->getSourceParameter('id');
		$hash = $this->getSourceParameter('hash');
		if (!is_scalar($id) || !is_string($hash) || $hash === '' || (int)$id <= 0)
		{
			return false;
		}

		$link = ExternalLinkTable::getByHash($hash);

		return is_array($link) && (int)$link['DOCUMENT_ID'] === (int)$id;
	}

	/**
	 * Same lookup as Binder::findParameterInSourceList(), which binds the document argument by the same id.
	 */
	private function getSourceParameter(string $name): mixed
	{
		foreach ($this->getAction()->getController()->getSourceParametersList() as $source)
		{
			if (isset($source[$name]))
			{
				return $source[$name];
			}

			if ($source instanceof \ArrayAccess && $source->offsetExists($name))
			{
				return $source[$name];
			}

			if (is_array($source) && array_key_exists($name, $source))
			{
				return $source[$name];
			}
		}

		return null;
	}
}
