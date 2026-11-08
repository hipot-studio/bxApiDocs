<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MailTemplate;

use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateListItem;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplatePage;
use Bitrix\Mail\Internal\Entity\MailTemplate\TemplateReference;
use Bitrix\Main\Result;

interface TemplateProvider
{
	public const RESULT_TEMPLATE = 'template';

	public const ERROR_PROVIDER_UNAVAILABLE = 'MAIL_TEMPLATE_PROVIDER_UNAVAILABLE';
	public const ERROR_TEMPLATE_NOT_AVAILABLE = 'MAIL_TEMPLATE_NOT_AVAILABLE';
	public const ERROR_PREPARATION_FAILED = 'MAIL_TEMPLATE_PREPARATION_FAILED';

	/**
	 * Whether the source can serve this very user: the module behind it has to be there, and the user
	 * has to be allowed to read what it holds.
	 */
	public function isAvailable(int $userId): bool;

	public function search(
		string $query,
		int $userId,
		int $offset = 0,
		int $limit = 20,
	): TemplatePage;

	/**
	 * The returned items follow the order of the first occurrence of each requested reference.
	 *
	 * @param list<TemplateReference> $references
	 * @return list<TemplateListItem>
	 */
	public function getByIds(array $references, int $userId): array;

	/**
	 * @return list<TemplateListItem>
	 */
	public function getRecentlyCreated(int $userId, int $limit = 5): array;

	/**
	 * On success, result data contains PreparedTemplate under self::RESULT_TEMPLATE.
	 */
	public function prepare(TemplateReference $reference, int $userId): Result;
}
