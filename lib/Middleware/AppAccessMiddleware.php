<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Middleware;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Controller\ApiJsonErrorResponse;
use OCA\MobilityCheck\Exception\AppAccessDeniedException;
use OCA\MobilityCheck\Exception\ForbiddenException;
use OCA\MobilityCheck\Service\AccessControlService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * §2.4 — App entry gate. Runs before every MobilityCheck controller
 * action and short-circuits with HTTP 403 (page or JSON depending
 * on context) when {@see AccessControlService::canUseApp()} returns
 * false. After this gate passes, every controller still enforces
 * the §2.2 / §2.3 role checks via `AccessControlService::require*`.
 *
 * Public download endpoints (exports via short-lived token) do not
 * need this middleware because they authenticate via the token,
 * not the session — they short-circuit when the controller method
 * is recognised as a public endpoint.
 */
class AppAccessMiddleware extends Middleware
{
	private const PUBLIC_METHODS = [
		// Token-authenticated export download — verifies token itself.
		'download',
	];

	public function __construct(
		private IUserSession $userSession,
		private AccessControlService $accessControl,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
		private IFactory $l10nFactory,
	) {
	}

	public function beforeController($controller, $methodName): void
	{
		$class = is_object($controller) ? get_class($controller) : '';
		if (!str_starts_with($class, 'OCA\\MobilityCheck\\Controller\\')) {
			return;
		}
		if (in_array($methodName, self::PUBLIC_METHODS, true)) {
			return;
		}
		$user = $this->userSession->getUser();
		if ($user === null) {
			// Let Nextcloud's standard auth layer handle the redirect/401.
			return;
		}
		if ($this->accessControl->canUseApp($user->getUID())) {
			return;
		}
		throw new AppAccessDeniedException(
			$this->accessControl->denialReasonWhenCannotUseApp($user->getUID())
		);
	}

	public function afterException($controller, $methodName, \Exception $exception)
	{
		$path = (string)($this->request->getPathInfo() ?? '');
		$isApi = str_contains($path, '/api/') || $this->request->getMethod() !== 'GET';

		// Per-role denials (e.g. driver opening /settings). Translate into
		// the same access-denied UX as the app-level entry gate so users
		// never see a raw 500.
		if ($exception instanceof ForbiddenException) {
			if ($isApi) {
				return ApiJsonErrorResponse::fromThrowable($exception);
			}
			$l = $this->l10nFactory->get(Application::APP_ID);
			$response = new TemplateResponse(
				Application::APP_ID,
				'access-denied',
				[
					'message' => $l->t('You do not have the role required for this MobilityCheck area.'),
					'hint' => $l->t('Use the navigation to return to a section that matches your role, or ask a MobilityCheck administrator to grant you the missing role.'),
					'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
				]
			);
			$response->setStatus(Http::STATUS_FORBIDDEN);
			$response->renderAs(TemplateResponse::RENDER_AS_USER);
			return $response;
		}

		if (!$exception instanceof AppAccessDeniedException) {
			throw $exception;
		}
		$reason = $exception->getDenialReason();

		if ($isApi) {
			$code = match ($reason) {
				AccessControlService::DENIAL_RESTRICTION => 'restriction',
				AccessControlService::DENIAL_NO_APP_ROLE => 'no_app_role',
				AccessControlService::DENIAL_INSUFFICIENT_ROLE => 'INSUFFICIENT_ROLE',
				default => 'access_denied',
			};
			return new JSONResponse(
				['ok' => false, 'error' => ['code' => $code]],
				Http::STATUS_FORBIDDEN,
			);
		}

		$l = $this->l10nFactory->get(Application::APP_ID);
		[$message, $hint] = match ($reason) {
			AccessControlService::DENIAL_RESTRICTION => [
				$l->t('Your organisation restricts MobilityCheck access. You are not on the allowed-users list and you are not a member of any allowed group.'),
				$l->t('Ask a Nextcloud or MobilityCheck administrator to add you to the allowed users or groups in MobilityCheck → Settings → Access.'),
			],
			AccessControlService::DENIAL_INSUFFICIENT_ROLE => [
				$l->t('You do not have the role required for this MobilityCheck area.'),
				$l->t('Use the navigation to return to a section that matches your role, or ask a MobilityCheck administrator to grant you the missing role.'),
			],
			AccessControlService::DENIAL_NO_APP_ROLE => [
				$l->t('You are not enrolled in MobilityCheck yet.'),
				$l->t('A MobilityCheck administrator must assign you at least one role (driver, fleet manager, line manager, fleet admin, workshop or auditor) before you can open the app.'),
			],
			default => [
				$l->t('You are not allowed to use MobilityCheck right now.'),
				$l->t('If you believe this is a mistake, contact your MobilityCheck administrator.'),
			],
		};
		$response = new TemplateResponse(
			Application::APP_ID,
			'access-denied',
			[
				'message' => $message,
				'hint' => $hint,
				'homeUrl' => $this->urlGenerator->linkToDefaultPageUrl(),
			]
		);
		$response->setStatus(Http::STATUS_FORBIDDEN);
		$response->renderAs(TemplateResponse::RENDER_AS_USER);
		return $response;
	}
}
