<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests\Specials;

use ArrayObject;
use JobQueueMemory;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources;
use MediaWiki\HTMLForm\OOUIHTMLForm;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Status\Status;
use PermissionsError;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use SpecialPageTestBase;
use Wikimedia\Http\MultiHttpClient;

/**
 * @group MultiPurge
 * @group Database
 */
class SpecialPurgeResourcesTest extends SpecialPageTestBase {

	protected function newSpecialPage() {
		return new SpecialPurgeResources();
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::__construct
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::getRestriction
	 * @return void
	 */
	public function testUserWithoutEditInterfaceIsDenied() {
		$this->expectException( PermissionsError::class );

		$this->executeSpecialPage( '', null, null, $this->getTestUser()->getAuthority() );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::__construct
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::getRestriction
	 * @return void
	 */
	public function testUserWithEditInterfaceCanExecute() {
		$page = $this->newSpecialPage();

		$this->assertTrue( $page->userCanExecute( $this->getTestSysop()->getUser() ) );
	}

	/**
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testSetsPageTitle() {
		[ $html ] = $this->executeSpecialPage( '', null, null, $this->getTestSysop()->getAuthority(), true );

		$this->assertStringContainsString( '(multipurge-form-title)', $html );
	}

	/**
	 * A purge submission where every service succeeds reports success and queues nothing.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testPurgeSubmitAllServicesSucceed() {
		$this->enableBothServices();
		$this->mockServiceResponses( [] );

		$result = SpecialPurgeResources::trySubmit( $this->purgeFormData(), $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Message::class, $result );
		$this->assertSame( 'multipurge-special-purge-success', $result->getKey() );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs() );
	}

	/**
	 * A failed service is queued alone for retry.
	 * Successful services are neither re-run nor reported as failed, and the submission does not claim success.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testPurgeSubmitFailedServiceIsQueuedAloneForRetry() {
		$this->enableBothServices();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );

		$result = SpecialPurgeResources::trySubmit( $this->purgeFormData(), $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			'multipurge-special-purge-retry',
			$result->getErrorsByType( 'error' )[0]['message']
		);

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the failed service was queued' );
		$this->assertSame( Varnish::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * A service failing first must not stop the services configured after it.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testPurgeSubmitFailedServiceFirstStillDispatchesLaterServices() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Varnish::class, Cloudflare::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [ 'varnish.internal' => 500 ] );

		$result = SpecialPurgeResources::trySubmit(
			$this->purgeFormData(),
			$this->createMock( OOUIHTMLForm::class )
		);

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );

		$this->assertNotEmpty(
			array_filter(
				$sent->getArrayCopy(),
				static fn ( array $request ): bool => str_contains( $request['url'], 'cloudflare' )
			),
			'The service after the failing one was dispatched'
		);

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the failed service was queued' );
		$this->assertSame( Varnish::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * If the queue rejects the retry job, the caller sees an error, not a success.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testPurgeSubmitRejectedRetryEnqueueReportsError() {
		$this->enableBothServices();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );
		$this->installReadOnlyJobQueueGroupFactory();

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'error' );
		$this->setLogger( 'MultiPurge', $logger );

		$result = SpecialPurgeResources::trySubmit( $this->purgeFormData(), $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			'multipurge-special-purge-error',
			$result->getErrorsByType( 'error' )[0]['message']
		);
	}

	/**
	 * The submission mapper resolves each selected URL.
	 * Root-relative values expand against $wgServer, absolute and legacy values keep their target.
	 * Disallowed hosts are dropped with a warning while the valid ones are still purged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitResolvesAndFiltersFormUrls() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [] );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [
				'/load.php?modules=site',
				'https://wiki.example/load.php?modules=abs',
				'https://evil.example/x',
			],
			'statics' => [ 'load.php?lang=de&modules=site.styles&only=styles&skin=citizen' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result, 'One rejected selection makes the outcome partial' );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-partial', 3, 1 ],
			array_map( static fn ( array $error ): array
				=> array_merge( [ $error['message'] ], $error['params'] ),
				$result->getErrorsByType( 'error' ) )[0]
		);
		$this->assertSame(
			[
				'https://wiki.example/load.php?modules=site',
				'https://wiki.example/load.php?modules=abs',
				'https://wiki.example/load.php?lang=de&modules=site.styles&only=styles&skin=citizen',
			],
			json_decode( $sent[0]['postData'], true )['files']
		);
	}

	/**
	 * A submission with both valid and rejected selections still purges the valid ones.
	 * The operator must see that not everything selected was purged; the generic success would pretend otherwise.
	 * Nothing is queued, because a policy rejection is permanent.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithMixedSelectionReportsPartialRejection() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [] );

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [
				'/load.php?modules=site',
				'https://evil.example/x',
			],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-partial', 1, 1 ],
			array_map( static fn ( array $error ): array
				=> array_merge( [ $error['message'] ], $error['params'] ),
				$result->getErrorsByType( 'error' ) )[0]
		);
		$this->assertSame(
			[ 'https://wiki.example/load.php?modules=site' ],
			json_decode( $sent[0]['postData'], true )['files'],
			'Valid selections are still purged'
		);
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(),
			'A policy rejection is permanent, so nothing is queued' );
	}

	/**
	 * URLs come from parsed HTML, so entity-encoded ampersands must be decoded before the query is purged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitDecodesEntitiesInUrls() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [] );

		SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [ '/load.php?lang=de&amp;modules=site.styles&only=styles' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertSame(
			[ 'https://wiki.example/load.php?lang=de&modules=site.styles&only=styles' ],
			json_decode( $sent[0]['postData'], true )['files']
		);
	}

	/**
	 * A submission whose every URL is rejected does not claim a purge.
	 * The operator sees the rejection count and the rejections are logged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithOnlyRejectedUrlsReportsRejectionCount() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [] );

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [ 'https://evil.example/1', 'https://evil.example/2' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		// An all-rejected selection is not "no selection": the count is reported.
		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			'multipurge-special-purge-rejected',
			$result->getErrorsByType( 'error' )[0]['message']
		);
		$this->assertSame( [ 2 ], $result->getErrorsByType( 'error' )[0]['params'] );
		$this->assertSame( [], $sent->getArrayCopy(), 'Nothing was sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * A selection with nothing in it is the only case that reports "no urls".
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithEmptySelectionReportsNoUrls() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$this->mockServiceResponses( [] );

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			'multipurge-special-no-urls',
			$result->getErrorsByType( 'error' )[0]['message']
		);
	}

	/**
	 * The same rejected URL selected in two groups is one rejection, not two.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\PurgeUrlPolicy::resolveList
	 * @return void
	 */
	public function testSubmitDuplicateRejectedSelectionCountsUnique() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$this->mockServiceResponses( [] );

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [ '/load.php?modules=site', 'https://evil.example/dup' ],
			'statics' => [ 'https://evil.example/dup' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-partial', 1, 1 ],
			array_map( static fn ( array $error ): array
				=> array_merge( [ $error['message'] ], $error['params'] ),
				$result->getErrorsByType( 'error' ) )[0],
			'One unique valid and one unique rejected selection'
		);
	}

	/**
	 * A selection like "https//user:secret@host" has no syntactic scheme, so the mapper prefixes it as a local path.
	 * That path is still a valid purge target, but no MultiPurge log entry may echo it: paths can carry credentials.
	 * Core HTTP logs are out of scope here.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitLogsNoTargetUrlsForMalformedCredentialSelection() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [] );
		$entries = $this->installLogSpy();

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [
				'/load.php?modules=site',
				'https//user:secret@wiki.example/x',
			],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Message::class, $result, 'Both selections are valid local targets' );
		$this->assertContains(
			'https://wiki.example/https//user:secret@wiki.example/x',
			json_decode( (string)$sent[0]['postData'], true )['files'],
			'The malformed selection is still purged as a local path'
		);
		$this->assertNotEmpty( $entries->getArrayCopy() );
		foreach ( $entries as $entry ) {
			$this->assertStringNotContainsString( 'user:secret', $entry, 'No log entry may contain a target URL' );
			$this->assertStringNotContainsString( 'secret@wiki.example', $entry );
		}
	}

	/**
	 * A failed service must not hide that another selection was permanently rejected.
	 * The retry outcome and the rejection count are both reported, and the retry job carries only valid targets.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitMixedSelectionWithFailedServiceDisclosesRejection() {
		$this->enableBothServices();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [ '/load.php?modules=site', 'https://evil.example/x' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-retry', 'multipurge-special-purge-rejected' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) )
		);
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[1]['params'] );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the failed service was queued' );
		$this->assertSame(
			[ 'http://localhost/load.php?modules=site' ],
			$jobs[0]->getParams()['urls'],
			'The permanent rejection never reaches a retry job'
		);
	}

	/**
	 * A rejected retry enqueue must not hide a permanent rejection either.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitMixedSelectionWithLostRetryEnqueueDisclosesRejection() {
		$this->enableBothServices();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );
		$this->installReadOnlyJobQueueGroupFactory();

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [ '/load.php?modules=site', 'https://evil.example/x' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-error', 'multipurge-special-purge-rejected' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) )
		);
	}

	/**
	 * With no service enabled nothing ran, so the submission must not claim that any target was purged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithNoEnabledServicesDoesNotClaimPurges() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [],
		] );
		$sent = $this->mockServiceResponses( [] );

		$result = SpecialPurgeResources::trySubmit(
			$this->purgeFormData(),
			$this->createMock( OOUIHTMLForm::class )
		);

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			'multipurge-special-no-services',
			$result->getErrorsByType( 'error' )[0]['message']
		);
		$this->assertSame( [], $sent->getArrayCopy(), 'Nothing was sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * No enabled services plus a rejected selection reports both facts.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithNoEnabledServicesDisclosesRejection() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [],
		] );
		$this->mockServiceResponses( [] );

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [ '/load.php?modules=site', 'https://evil.example/x' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-no-services', 'multipurge-special-purge-rejected' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) )
		);
	}

	/**
	 * A MultiPurgeOnPurgeService subscriber can replace the valid targets with disallowed ones.
	 * The job then sends no requests while still returning success.
	 * The reported outcome must stay about the original selection and never claim that anything was purged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithServiceHookReplacingTargetsDoesNotClaimPurge() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( $service, array &$urls ): bool {
				$urls = [ 'https://evil.example/replaced' ];
				return true;
			}
		);

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'styles' => [ '/load.php?modules=site', 'https://evil.example/selected' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-partial', 1, 1 ],
			array_map( static fn ( array $error ): array
				=> array_merge( [ $error['message'] ], $error['params'] ),
				$result->getErrorsByType( 'error' ) )[0]
		);
		$this->assertSame( [], $sent->getArrayCopy(), 'No request ran' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
		$this->assertStringNotContainsString(
			'Purged',
			$result->getMessage()->inLanguage( 'en' )->text(),
			'The outcome must not claim a purge'
		);
	}

	/**
	 * A vetoed service sends no requests, so the success outcome must not claim a purge either.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithServiceHookVetoDoesNotClaimPurge() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function (): bool {
				return false;
			}
		);

		$result = SpecialPurgeResources::trySubmit(
			$this->purgeFormData(),
			$this->createMock( OOUIHTMLForm::class )
		);

		$this->assertInstanceOf( Message::class, $result );
		$this->assertSame( [], $sent->getArrayCopy(), 'No request ran' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
		$this->assertStringNotContainsString(
			'urged',
			$result->inLanguage( 'en' )->text(),
			'The outcome must not claim a purge'
		);
	}

	private function enableBothServices(): void {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class, Varnish::class ],
			'MultiPurgeVarnishServers' => [ 'varnish.internal' ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
	}

	private function purgeFormData(): array {
		return [
			'target' => 'Foo',
			'styles' => [ '/load.php?modules=site.styles&only=styles' ],
		];
	}

	/**
	 * @param array<string, int> $responsesByUrl Response code for requests whose URL contains the key
	 * @return ArrayObject Sent requests
	 */
	private function mockServiceResponses( array $responsesByUrl ): ArrayObject {
		$sent = new ArrayObject();
		$multiMock = $this->getMockBuilder( MultiHttpClient::class )
			->disableOriginalConstructor()
			->getMock();
		$multiMock->method( 'runMulti' )->willReturnCallback(
			static function ( array $requests ) use ( $responsesByUrl, $sent ) {
				foreach ( $requests as $i => $request ) {
					$sent->append( $request );
					$code = 200;
					foreach ( $responsesByUrl as $needle => $responseCode ) {
						if ( str_contains( $request['url'], $needle ) ) {
							$code = $responseCode;
							break;
						}
					}
					$request['response'] = [ $code, null, [], null, null ];
					$requests[$i] = $request;
				}
				return $requests;
			}
		);

		$httpMock = $this->getMockBuilder( HttpRequestFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$httpMock->method( 'createMultiClient' )->willReturn( $multiMock );
		$this->getServiceContainer()->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

		return $sent;
	}

	/**
	 * @return array Remaining MultiPurge jobs in the real queue
	 */
	private function popQueuedMultiPurgeJobs(): array {
		$queue = $this->getServiceContainer()->getJobQueueGroup()->get( 'MultiPurgePages' );
		$jobs = [];
		$job = $queue->pop();
		while ( $job ) {
			$jobs[] = $job;
			$job = $queue->pop();
		}
		return $jobs;
	}

	/**
	 * Real factories whose queues reject pushes, so a rejected retry enqueue can be exercised.
	 * The factory itself is rebuilt through core's service wiring to match the running version.
	 *
	 * @return void
	 */
	private function installReadOnlyJobQueueGroupFactory(): void {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => JobQueueMemory::class, 'readOnlyReason' => 'test queue read-only' ],
		] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
	}

	/**
	 * Replaces the MultiPurge channel with a spy that records every entry.
	 * A test can then assert that no untrusted target URL was logged at any level.
	 *
	 * @return ArrayObject Captured "message context" strings
	 */
	private function installLogSpy(): ArrayObject {
		$entries = new ArrayObject();
		$logger = new class( $entries ) extends AbstractLogger {
			public function __construct( private ArrayObject $entries ) {
			}

			public function log( $level, $message, array $context = [] ): void {
				$this->entries[] = "$message " . json_encode( $context );
			}
		};
		$this->setLogger( 'MultiPurge', $logger );
		return $entries;
	}
}
