<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * A session of the agent contour over one template: whether it is open, until when, and - only when the
 * caller has just opened it - the base URL to address the contour with.
 *
 * `baseUrl` carries the secret of the token, so it is filled by the opening action ALONE
 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\Agent\Session::addAction()}) and stays null
 * on every read. A reader that answered with it would hand a live credential to a caller who only asked
 * whether a session exists - and asking is a read, which the platform above lets a read-only key perform.
 */
class AgentSessionDto extends Dto
{
	#[Description('Template this session belongs to.')]
	public int $templateId;

	#[Description('Whether an active session exists for this template and user.')]
	public bool $connected;

	#[Description('Unix time the session expires at, or null when there is no session.')]
	public ?int $expiresAt = null;

	#[Description('Base URL to address the contour with. Returned only when the session has just been opened; a read never carries it.')]
	public ?string $baseUrl = null;
}
