<?php

declare(strict_types=1);

namespace OCA\SheetMusic\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\SheetMusic\Listener\SabrePluginListener;
use OCA\SheetMusic\Listener\SheetMusicFileEventListener;
use OCA\SheetMusic\Share\SheetMusicPublicShareTemplateProvider;
use OCA\SheetMusic\Sync\MobileSheets\MobileSheetsSyncBackend;
use OCA\SheetMusic\Sync\SyncClientRegistry;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BeforeSabrePubliclyLoadedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'sheetmusic';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		/** @psalm-suppress UndefinedClass OCA\DAV is part of the server, not of the OCP package */
		$context->registerEventListener(SabrePluginAddEvent::class, SabrePluginListener::class);
		$context->registerEventListener(BeforeSabrePubliclyLoadedEvent::class, SabrePluginListener::class);
		$context->registerEventListener(NodeCreatedEvent::class, SheetMusicFileEventListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, SheetMusicFileEventListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, SheetMusicFileEventListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, SheetMusicFileEventListener::class);
		$context->registerPublicShareTemplateProvider(SheetMusicPublicShareTemplateProvider::class);

		$context->registerService(SyncClientRegistry::class, function (ContainerInterface $c): SyncClientRegistry {
			return new SyncClientRegistry([
				$c->get(MobileSheetsSyncBackend::class),
			]);
		});
	}

	public function boot(IBootContext $context): void {
	}
}
