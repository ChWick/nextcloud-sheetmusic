<?php

declare(strict_types=1);

namespace OCA\SheetMusic\AppInfo;

use OCA\SheetMusic\Listener\SabrePluginListener;
use OCA\SheetMusic\Listener\SheetMusicFileEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\SabrePluginEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'sheetmusic';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(SabrePluginEvent::class, SabrePluginListener::class);
		$context->registerEventListener(NodeCreatedEvent::class, SheetMusicFileEventListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, SheetMusicFileEventListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, SheetMusicFileEventListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
