<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Listener;

use OCA\MobilityCheck\Service\AccessControlService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;

/**
 * Removes group role rows and directory allow-list entries when a Nextcloud
 * group is permanently deleted so authorization state never references a
 * group that no longer exists.
 *
 * @template-implements IEventListener<GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener
{
	public function __construct(private AccessControlService $access)
	{
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof GroupDeletedEvent) {
			return;
		}
		$this->access->purgeGroup($event->getGroup()->getGID());
	}
}
