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
	 * A failed service is queued alone for retry; successful services are neither
	 * re-run nor reported as failed, and the submission does not claim success.
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
	 * Real factories whose queues reject pushes, so a rejected retry enqueue can be
	 * exercised. The factory itself is rebuilt through core's service wiring, which
	 * supplies the stats service matching the running MediaWiki version.
	 *
	 * @return void
	 */
	private function installReadOnlyJobQueueGroupFactory(): void {
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => JobQueueMemory::class, 'readOnlyReason' => 'test queue read-only' ],
		] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
	}
}
