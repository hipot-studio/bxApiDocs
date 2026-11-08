<?php

namespace Bitrix\MailMobile\Internal\Services;

use Bitrix\Crm\Activity\Mail\MailMessageChainProvider as CrmMailMessageChainProvider;
use Bitrix\Mail\Helper;
use Bitrix\Mail\Helper\Dto\MailMessage;
use Bitrix\Mail\Helper\Dto\MailMessageChain;
use Bitrix\MailMobile\Public\Features\FavoritesFeature;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\CurrentUser;
use Closure;

final class FavoriteMessageReadService
{
	public const CRM_MESSAGE_TYPE = 1;

	/** @var Closure(int, int, string, string): MailMessageChain */
	private Closure $chainLoader;

	/** @var Closure(int, bool, bool, int, string, string): MailMessage */
	private Closure $messageLoader;

	/** @var Closure(): int */
	private Closure $userIdLoader;

	/** @var Closure(): bool */
	private Closure $featureLoader;

	public function __construct(
		private readonly FavoriteMessageEnricher $enricher,
		?callable $chainLoader = null,
		?callable $messageLoader = null,
		?callable $userIdLoader = null,
		?callable $featureLoader = null,
		private readonly bool $attachmentDiskObjectsEnabled = false,
	)
	{
		$this->chainLoader = Closure::fromCallable($chainLoader ?? function (
			int $id,
			int $type,
			string $act,
			string $mailUfMessageToken,
		): MailMessageChain {
			if ($type === self::CRM_MESSAGE_TYPE)
			{
				return (new CrmMailMessageChainProvider($act))->getChain($id);
			}

			return $this->createMailProvider()->getChain($id, $mailUfMessageToken);
		});
		$this->messageLoader = Closure::fromCallable(
			$messageLoader ?? function (
				int $id,
				bool $body,
				bool $files,
				int $type,
				string $act,
				string $mailUfMessageToken,
			): MailMessage {
				if ($type === self::CRM_MESSAGE_TYPE)
				{
					return (new CrmMailMessageChainProvider($act))->getMessage($id, $body, $files);
				}

				return $this->createMailProvider()->getMessage(
					$id,
					$body,
					$files,
					$mailUfMessageToken,
				);
			},
		);
		$this->userIdLoader = Closure::fromCallable($userIdLoader ?? static fn (): int => (int)CurrentUser::get()->getId());
		$this->featureLoader = Closure::fromCallable(
			$featureLoader ?? static fn (): bool => Feature::isEnabled(FavoritesFeature::class),
		);
	}

	/**
	 * The mail provider a chain and a message are read through. The disk objects of the attachments are asked
	 * for only when the large attachment scenario is available: the ask looks an object up and creates it when
	 * it is absent, and an ordinary read of a message must cost neither the search nor the write.
	 *
	 * A mail module released before the ask reads a message the former way instead of failing it.
	 */
	private function createMailProvider(): Helper\MailMessageChainProvider
	{
		$provider = new Helper\MailMessageChainProvider();

		if (
			$this->attachmentDiskObjectsEnabled
			&& method_exists($provider, 'enableAttachmentDiskObjects')
		)
		{
			$provider->enableAttachmentDiskObjects();
		}

		return $provider;
	}

	public function getChain(
		int $id,
		int $type,
		string $act,
		string $mailUfMessageToken = '',
	): MailMessageChain
	{
		$chain = ($this->chainLoader)($id, $type, $act, $mailUfMessageToken);
		if (($chain->properties['readOnly'] ?? false) === true)
		{
			foreach ($chain->list as $message)
			{
				$message->readOnly = true;
			}
		}
		$chain->list = $this->enricher->enrich(
			$chain->list,
			($this->userIdLoader)(),
			($this->featureLoader)(),
		);

		return $chain;
	}

	public function getMessage(
		int $id,
		bool $takeBody,
		bool $takeFiles,
		int $type,
		string $act,
		string $mailUfMessageToken = '',
	): MailMessage
	{
		$message = ($this->messageLoader)(
			$id,
			$takeBody,
			$takeFiles,
			$type,
			$act,
			$mailUfMessageToken,
		);

		return $this->enricher->enrich(
			[$message],
			($this->userIdLoader)(),
			($this->featureLoader)(),
		)[0];
	}
}
