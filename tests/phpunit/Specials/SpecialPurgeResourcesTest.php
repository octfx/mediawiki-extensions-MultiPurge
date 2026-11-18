<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests\Specials;

use ArrayObject;
use JobQueueDB;
use JobQueueMemory;
use MediaWiki\Extension\MultiPurge\PurgeUrlPolicy;
use MediaWiki\Extension\MultiPurge\Services\Cloudflare;
use MediaWiki\Extension\MultiPurge\Services\Varnish;
use MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources;
use MediaWiki\HTMLForm\OOUIHTMLForm;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\JobQueue\Exceptions\JobQueueReadOnlyError;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Status\Status;
use PermissionsError;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use SpecialPageTestBase;
use UploadFromFile;
use Wikimedia\Http\MultiHttpClient;

/**
 * @group MultiPurge
 * @group Database
 */
class SpecialPurgeResourcesTest extends SpecialPageTestBase {

	private const FORM_PAGE = 'MpFormResources';
	private const FORM_FILE = 'MpFormImage.png';
	private const STYLE_URL = '/load.php?lang=en&modules=site.styles&only=styles&skin=vector-2022';
	private const SCRIPT_URL = '/load.php?lang=en&modules=startup&only=scripts&skin=vector-2022';
	private const STATIC_URL = 'load.php?lang=de&modules=site.styles&only=styles&skin=citizen';

	/**
	 * 600x400 PNG, large enough that the parser emits a real thumbnail URL.
	 */
	// phpcs:ignore Generic.Files.LineLength -- the base64 constant cannot be wrapped
	private const FIXTURE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAlgAAAGQCAIAAAD9V4nPAAAE0UlEQVR42u3VMQ0AAAgEsdeJEpSgFxNsNKmCWy7TBQBvRQIAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAI1QBACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEACMEwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBwAgBMEIVADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCADBCAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQAIwQACOUAAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBAAjBMAIVQDACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAHACAEwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgAwQgC4sFgoqTstyC3UAAAAAElFTkSuQmCC';

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
	 * The initial form asks for a title only; it must not offer purge options.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testLoadFormSubmitsTitleOnly() {
		[ $html ] = $this->executeSpecialPage( '', null, null, $this->getTestSysop()->getAuthority() );

		$this->assertStringContainsString( '(multipurge-special-load-submit)', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-submit)', $html );
		$this->assertStringNotContainsString( "name='wpstyles[]'", $html );
		$this->assertStringNotContainsString( "name='wpstatics[]'", $html );
	}

	/**
	 * The purge form offers the parsed styles, scripts, thumbnails, and statics as checkboxes,
	 * and posts back to its subpage so a submission meets the same fields it was rendered with.
	 * Thumbnails come from the parser output of a page with a real uploaded file.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::parseLoads
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::makeSelects
	 * @return void
	 */
	public function testPurgeFormOffersParsedResourcesAndStatics() {
		[ $fixture, $thumbSrcs ] = $this->setUpFormWiki();
		$this->mockSpecialPageHttp( $fixture . '<img src="/images/sample.png?label=&lt;b&gt;sample&lt;/b&gt;">', [] );

		[ $html ] = $this->executeSpecialPage(
			self::FORM_PAGE,
			null,
			null,
			$this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString( '(multipurge-special-purge-submit)', $html );
		$this->assertStringContainsString(
			'Special:PurgeResources/MpFormResources',
			$html,
			'The purge form posts back to its subpage'
		);
		$this->assertStringContainsString( "value='" . $this->asHtml( self::STYLE_URL ) . "'", $html );
		$this->assertStringContainsString(
			"value='" . $this->asHtml( self::SCRIPT_URL ) . "'",
			$html
		);
		foreach ( $thumbSrcs as $src ) {
			$this->assertStringContainsString( "value='" . $this->asHtml( $src ) . "'", $html,
				'The form offers the parsed thumbnail' );
		}
		$this->assertStringContainsString( "value='" . $this->asHtml( self::STATIC_URL ) . "'", $html );
		$this->assertStringContainsString( 'Site Styles', $html, 'The associative static key is the label' );
		$this->assertStringContainsString( '>/images/sample.png?label=&lt;b&gt;sample&lt;/b&gt;</label>', $html );
		$this->assertStringNotContainsString( '>/images/sample.png?label=<b>sample</b></label>', $html );
	}

	/**
	 * Numeric static entries have no label, so the url doubles as the option label.
	 * The parsed page groups must survive alongside them.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::makeSelects
	 * @return void
	 */
	public function testPurgeFormWithNumericStaticsKeepsParsedGroups() {
		$this->overrideConfigValue( 'MultiPurgeStaticPurges', [ '/load.php?modules=startup&only=scripts' ] );
		[ $fixture ] = $this->setUpFormWiki();
		$this->mockSpecialPageHttp( $fixture, [] );

		[ $html ] = $this->executeSpecialPage(
			self::FORM_PAGE,
			null,
			null,
			$this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString( "value='" . $this->asHtml( self::STYLE_URL ) . "'", $html,
			'Parsed page groups survive numeric statics' );
		$this->assertStringContainsString(
			"value='" . $this->asHtml( self::SCRIPT_URL ) . "'",
			$html
		);
		$this->assertStringContainsString(
			"value='" . $this->asHtml( '/load.php?modules=startup&only=scripts' ) . "'",
			$html
		);
	}

	/**
	 * Empty static configuration must not remove the parsed page groups or offer an empty statics group.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::makeSelects
	 * @return void
	 */
	public function testPurgeFormWithEmptyStaticsOmitsStaticGroup() {
		$this->overrideConfigValue( 'MultiPurgeStaticPurges', [] );
		[ $fixture ] = $this->setUpFormWiki();
		$this->mockSpecialPageHttp( $fixture, [] );

		[ $html ] = $this->executeSpecialPage(
			self::FORM_PAGE,
			null,
			null,
			$this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString( "value='" . $this->asHtml( self::STYLE_URL ) . "'", $html );
		$this->assertStringContainsString(
			"value='" . $this->asHtml( self::SCRIPT_URL ) . "'",
			$html
		);
		$this->assertStringNotContainsString( "name='wpstatics[]'", $html );
		$this->assertStringNotContainsString( '(multipurge-static-label)', $html );
	}

	/**
	 * A page without load.php or image resources still gets a purge form for the configured statics,
	 * and a statics-only submission purges them.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::makeSelects
	 * @return void
	 */
	public function testPageWithoutResourcesStillOffersAndPurgesStatics() {
		$staticUrl = 'load.php?lang=de&amp;modules=site.styles&only=styles';
		$this->overrideConfigValues( [
			'MultiPurgeStaticPurges' => [ $staticUrl ],
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki( false, false );
		$sent = $this->mockSpecialPageHttp( $fixture, [] );

		[ $html ] = $this->executeSpecialPage(
			self::FORM_PAGE,
			null,
			null,
			$this->getTestSysop()->getAuthority()
		);
		$this->assertStringContainsString( '(multipurge-special-purge-submit)', $html );
		$this->assertStringContainsString( "value='" . $this->asHtml( $staticUrl ) . "'", $html );
		$this->assertStringNotContainsString( "name='wpstyles[]'", $html );
		$this->assertStringNotContainsString( "name='wpscripts[]'", $html );

		[ $html ] = $this->submitPurgeForm( [ 'wpstatics' => [ $staticUrl ] ] );
		$this->assertStringContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertStringNotContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertSame(
			$this->resolveThroughPolicy( [ '/' . $staticUrl ] ),
			json_decode( (string)$sent[0]['postData'], true )['files']
		);
	}

	/**
	 * A submission selects styles, thumbnails, and statics together; each selected url reaches the service.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionSendsAllSelectedUrls() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture, $thumbSrcs ] = $this->setUpFormWiki();
		$sent = $this->mockSpecialPageHttp( $fixture, [] );

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
			'wpthumbs' => $thumbSrcs,
			'wpstatics' => [ self::STATIC_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertStringNotContainsString( 'mw-htmlform-ooui-header-errors', $html,
			'A completed purge must not render the error box' );
		$this->assertSame(
			$this->resolveThroughPolicy( array_merge(
				[ self::STYLE_URL ],
				$thumbSrcs,
				[ '/' . self::STATIC_URL ]
			) ),
			json_decode( (string)$sent[0]['postData'], true )['files']
		);
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs() );
	}

	/**
	 * A page without styles must still purge as a purge submission, not redirect to a load request.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testScriptsOnlySubmissionPurgesInsteadOfRedirecting() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki( false, true );
		$sent = $this->mockSpecialPageHttp( $fixture, [] );

		[ $html, $response ] = $this->submitPurgeForm( [
			'wpscripts' => [ self::SCRIPT_URL ],
		] );

		$this->assertNull( $response->getHeader( 'Location' ), 'A purge submission must not redirect' );
		$this->assertStringContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame(
			$this->resolveThroughPolicy( [ self::SCRIPT_URL ] ),
			json_decode( (string)$sent[0]['postData'], true )['files']
		);
	}

	/**
	 * Submitting the purge form with nothing checked reports "no urls"; it must not fall back to a load request.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testEmptyPurgeSubmissionReportsNoUrls() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki( false, true );
		$sent = $this->mockSpecialPageHttp( $fixture, [] );

		[ $html, $response ] = $this->submitPurgeForm( [] );

		$this->assertNull( $response->getHeader( 'Location' ), 'An empty purge submission must not redirect' );
		$this->assertStringContainsString( '(multipurge-special-no-urls)', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame( [], $sent->getArrayCopy(), 'Nothing was sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * Only values the form offered may be purged; an injected foreign url is rejected by the form itself.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionRejectsValueOutsideOfferedOptions() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki();
		$sent = $this->mockSpecialPageHttp( $fixture, [] );

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ 'https://evil.example/injected' ],
		] );

		$this->assertStringContainsString( '(htmlform-select-badoption)', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame( [], $sent->getArrayCopy(), 'The injected value was not purged' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * The purge submission relies on the HTMLForm edit token; a bad token runs nothing.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithBadEditTokenRunsNothing() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki();
		$sent = $this->mockSpecialPageHttp( $fixture, [] );

		[ $html ] = $this->submitPurgeForm( [
			'wpEditToken' => 'not-a-valid-token',
			'wpstyles' => [ self::STYLE_URL ],
		] );

		$this->assertSame( [], $sent->getArrayCopy(), 'A submission without a valid token purges nothing' );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertStringContainsString( '(multipurge-special-purge-submit)', $html,
			'The form is shown again instead' );
	}

	/**
	 * A purge submission whose service failed is queued for retry and shown as an error, never as success.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testFailedPurgeSubmissionShowsErrorNotSuccess() {
		$this->enableBothServices();
		[ $fixture ] = $this->setUpFormWiki();
		$this->mockSpecialPageHttp( $fixture, [ 'varnish.internal' => 500 ] );

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-retry)', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the failed service was queued' );
		$this->assertSame( Varnish::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * A purge submission whose page can no longer be fetched must not become a load redirect.
	 * The submission reports that nothing ran, keeps CSRF and ignores submitted values, and purges nothing.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testPurgeSubmissionWithFailedRefetchReportsErrorInsteadOfRedirecting() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$this->setUpFormWiki();
		// The unstubbed get() returns null, so the refetch of the page render fails.
		$sent = $this->mockSpecialPageHttp( null, [] );

		[ $html, $response ] = $this->submitPurgeForm( [
			// The forged value has no matching field and must never reach a purge request.
			'wpstyles' => [ 'https://evil.example/forged' ],
		] );

		$this->assertNull( $response->getHeader( 'Location' ),
			'A failed refetch must not turn the purge submission into a load redirect' );
		$this->assertStringContainsString( '(multipurge-special-purge-unavailable', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame( [], $sent->getArrayCopy(), 'Nothing was sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * A purge submission whose options all disappeared must not become a load redirect either.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testPurgeSubmissionWithVanishedOptionsReportsErrorInsteadOfRedirecting() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$this->setUpFormWiki();
		// Statics are cleared so the refetched page yields no selectable group at all.
		$this->overrideConfigValue( 'MultiPurgeStaticPurges', [] );
		$sent = $this->mockSpecialPageHttp( '<html><body>no resources left</body></html>', [] );

		[ $html, $response ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
		] );

		$this->assertNull( $response->getHeader( 'Location' ),
			'Vanished options must not turn the purge submission into a load redirect' );
		$this->assertStringContainsString( '(multipurge-special-purge-unavailable', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame( [], $sent->getArrayCopy(), 'Nothing was sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * A service-hook veto with an all-valid selection sends no request, so the rendered form must not claim success.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithServiceHookVetoShowsNoWorkNotSuccess() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki();
		$sent = $this->mockSpecialPageHttp( $fixture, [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function (): bool {
				return false;
			}
		);

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-no-work', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame( [], $sent->getArrayCopy(), 'No request ran' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(),
			'A permanent veto is not queued, so no retry loop can start' );
	}

	/**
	 * A hook that replaces all targets with a disallowed url leaves no request to send,
	 * however valid the original selection was.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithHookReplacedDisallowedTargetsShowsNoWork() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki();
		$sent = $this->mockSpecialPageHttp( $fixture, [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$urls ): bool {
				$urls = [ 'https://evil.example/replaced' ];
				return true;
			}
		);

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-no-work', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame( [], $sent->getArrayCopy(), 'The disallowed replacement was not sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * A vetoed service must not stop the others from purging, and the form reports which services sent nothing.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithOneVetoedServiceRunsOthersAndReportsNoWork() {
		$this->enableBothServices();
		[ $fixture ] = $this->setUpFormWiki();
		$sent = $this->mockSpecialPageHttp( $fixture, [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service ): bool {
				return $service !== Cloudflare::class;
			}
		);

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-no-work', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertNotEmpty( array_filter(
			$sent->getArrayCopy(),
			static fn ( array $request ): bool => str_contains( $request['url'], 'varnish.internal' )
		), 'The service after the vetoed one still sent its purge' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(),
			'The vetoed service is not queued and the others succeeded' );
	}

	/**
	 * A purge POST selecting from a group the refetched page no longer offers must be rejected,
	 * not silently trimmed to the groups that remain.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithOneVanishedGroupIsRejectedNotSilentlyPurged() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		// The refetched render has scripts and thumbs but no styles group, while statics stay configured.
		[ $fixture ] = $this->setUpFormWiki( false, true );
		$sent = $this->mockSpecialPageHttp( $fixture, [] );

		[ $html, $response ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
			'wpstatics' => [ self::STATIC_URL ],
		] );

		$this->assertNull( $response->getHeader( 'Location' ), 'The rejected purge must not redirect' );
		$this->assertStringContainsString( '(multipurge-special-purge-stale-selection', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame( [], $sent->getArrayCopy(),
			'The still-valid selection is not purged either; the whole POST is rejected' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * A service hook removing one of two selected targets leaves partial work sent,
	 * which the form must disclose instead of reporting plain success.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithHookRemovingOneTargetReportsOmitted() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki();
		$sent = $this->mockSpecialPageHttp( $fixture, [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$urls ): bool {
				$urls = array_values( array_filter( $urls,
					static fn ( string $url ): bool => str_contains( $url, 'lang=de' ) ) );
				return true;
			}
		);

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
			'wpstatics' => [ self::STATIC_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-omitted', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame( $this->resolveThroughPolicy( [ '/' . self::STATIC_URL ] ),
			json_decode( (string)$sent[0]['postData'], true )['files'],
			'The kept target was still sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * A service hook replacing one target with a disallowed url sends the rest while the policy drops the replacement.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithHookReplacingOneTargetWithDisallowedUrlReportsOmitted() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki();
		$sent = $this->mockSpecialPageHttp( $fixture, [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$urls ): bool {
				$urls = array_map(
					static fn ( string $url ): string => str_contains( $url, 'lang=de' )
						? $url
						: 'https://evil.example/replaced',
					$urls
				);
				return true;
			}
		);

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
			'wpstatics' => [ self::STATIC_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-omitted', $html );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );
		$this->assertSame( $this->resolveThroughPolicy( [ '/' . self::STATIC_URL ] ),
			json_decode( (string)$sent[0]['postData'], true )['files'],
			'The valid target was still sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
	}

	/**
	 * A vetoed service next to a failed one is shown as having sent nothing, never as queued for retry.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithVetoedAndFailedServiceDisclosesIdleAlongsideRetry() {
		$this->enableBothServices();
		[ $fixture ] = $this->setUpFormWiki();
		$this->mockSpecialPageHttp( $fixture, [ 'varnish.internal' => 500 ] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service ): bool {
				return $service !== Cloudflare::class;
			}
		);

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-retry)', $html );
		$this->assertStringContainsString( '(multipurge-special-purge-no-work', $html,
			'The vetoed service is disclosed as having sent nothing' );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the failed service was queued, never the vetoed one' );
		$this->assertSame( Varnish::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * One service drops a target and its remaining request fails: the rendered outcome shows both facts.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithDroppedTargetAndFailedRequestDisclosesOmission() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki();
		$this->mockSpecialPageHttp( $fixture, [ 'api.cloudflare' => 500 ] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$urls ): bool {
				$urls = array_values( array_filter( $urls,
					static fn ( string $url ): bool => str_contains( $url, 'lang=de' ) ) );
				return true;
			}
		);

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
			'wpstatics' => [ self::STATIC_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-retry)', $html );
		$this->assertStringContainsString( '(multipurge-special-purge-omitted', $html,
			'The target the same service dropped stays visible next to the retry' );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'The failed service was queued as before' );
		$this->assertSame( Cloudflare::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * One failed service queues while the next push is rejected: the mixed outcome states both facts
	 * without claiming every failed service was queued, rendered in English and German.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::execute
	 * @return void
	 */
	public function testPurgeSubmissionWithOneQueuedAndOneRejectedRetryReportsBoth() {
		$this->enableBothServices();
		[ $fixture ] = $this->setUpFormWiki();
		$this->mockSpecialPageHttp( $fixture, [ 'api.cloudflare' => 500, 'varnish.internal' => 500 ] );
		$this->installSinglePushJobQueue();

		$selection = [ 'wpstyles' => [ self::STYLE_URL ] ];

		[ $html ] = $this->submitPurgeForm( $selection, 'en' );

		$this->assertStringContainsString( 'At least one failed service was queued for retry', $html,
			'The rendered English outcome states the queued retry precisely' );
		$this->assertStringContainsString( 'could not be queued', $html,
			'The rendered English outcome states the rejected enqueue too' );
		$this->assertStringNotContainsString( 'the failed ones were queued', $html,
			'The all-queued wording must not appear in the mixed outcome' );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( 'The purge request completed', $html );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'One failed service made it into the queue before the rejection' );
		$this->assertSame( Cloudflare::class, $jobs[0]->getParams()['service'] );

		// The German rendering repeats the same scenario with a fresh single-push budget.
		SinglePushJobQueue::reset();
		[ $html ] = $this->submitPurgeForm( $selection, 'de' );

		$this->assertStringContainsString( 'zur Wiederholung in die Warteschlange gestellt', $html,
			'The rendered German outcome states the queued retry precisely' );
		$this->assertStringContainsString( 'konnte nicht eingereiht werden', $html,
			'The rendered German outcome states the rejected enqueue too' );
		$this->assertStringNotContainsString( 'die fehlgeschlagenen wurden zur Wiederholung', $html,
			'The German all-queued wording must not appear in the mixed outcome' );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'The German run also queued exactly one service' );
		$this->assertSame( Cloudflare::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * The load form submission redirects to the resource view for the entered title.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testLoadSubmissionRedirectsToResourceView() {
		$this->mockSpecialPageHttp( null, [] );

		$request = new FauxRequest( [
			'wptarget' => 'MpFormResources',
			'wpFormIdentifier' => 'purge-resources-form',
		], true );

		[ , $response ] = $this->executeSpecialPage(
			'',
			$request,
			null,
			$this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString(
			'Special:PurgeResources/MpFormResources',
			(string)$response->getHeader( 'Location' )
		);
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

		// HTMLForm treats a good Status as success; the outcome text is displayed by the form flow.
		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusGood( $result );
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
			'mode' => 'purge',
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
			'mode' => 'purge',
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
			'mode' => 'purge',
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
			'mode' => 'purge',
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
			'mode' => 'purge',
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
			'mode' => 'purge',
			'styles' => [
				'/load.php?modules=site',
				'https//user:secret@wiki.example/x',
			],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result, 'Both selections are valid local targets' );
		$this->assertStatusGood( $result );
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
			'mode' => 'purge',
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
			'mode' => 'purge',
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
			'mode' => 'purge',
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
			'mode' => 'purge',
			'styles' => [ '/load.php?modules=site', 'https://evil.example/selected' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-no-work', 'multipurge-special-purge-rejected' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) )
		);
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[0]['params'],
			'The one enabled service sent no request' );
		$this->assertSame( [], $sent->getArrayCopy(), 'No request ran' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued' );
		$this->assertStringNotContainsString(
			'Purged',
			$result->getMessage()->inLanguage( 'en' )->text(),
			'The outcome must not claim a purge'
		);
	}

	/**
	 * A vetoed service runs "successfully" while sending no request, so the submission must not claim success.
	 * A permanent veto is not queued either, because retrying cannot change the hook's answer.
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

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame( 'multipurge-special-purge-no-work',
			$result->getErrorsByType( 'error' )[0]['message'] );
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[0]['params'] );
		$this->assertSame( [], $sent->getArrayCopy(), 'No request ran' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing was queued for a veto' );
	}

	/**
	 * An all-invalid configuration purges nothing and queues nothing, permanently.
	 * The submission must report idle services instead of claiming purges, and the misconfiguration is logged per name.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithAllInvalidServicesDoesNotClaimPurges() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ 'NoSuchService', 'AlsoNoService' ],
		] );
		$sent = $this->mockServiceResponses( [] );

		$logger = $this->createMock( LoggerInterface::class );
		// Every unknown name produces one getServiceOrder warning and one job warning.
		$logger->expects( $this->exactly( 4 ) )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$result = SpecialPurgeResources::trySubmit(
			$this->purgeFormData(),
			$this->createMock( OOUIHTMLForm::class )
		);

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame( 'multipurge-special-purge-no-work',
			$result->getErrorsByType( 'error' )[0]['message'] );
		$this->assertSame( [ 2 ], $result->getErrorsByType( 'error' )[0]['params'] );
		$this->assertSame( [], $sent->getArrayCopy(), 'Nothing was sent' );
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'No permanent retry was queued' );
	}

	/**
	 * Enabled services whose order intersects to nothing can never purge.
	 * The submission reports that no service is selected instead of claiming success.
	 * The order typo is warned about.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithEmptyServiceIntersectionReportsNoServices() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeServiceOrder' => [ 'Typo' ],
		] );
		$sent = $this->mockServiceResponses( [] );

		$logger = $this->createMock( LoggerInterface::class );
		$logger->expects( $this->once() )->method( 'warning' )->with(
			$this->stringContains( 'Unknown purge service' ),
			$this->callback( static fn ( array $context ): bool =>
				( $context['service'] ?? null ) === 'Typo'
				&& ( $context['setting'] ?? null ) === 'MultiPurgeServiceOrder' )
		);
		$this->setLogger( 'MultiPurge', $logger );

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
	 * A mixed configuration still dispatches the valid service.
	 * The submission discloses that the invalid service sent nothing instead of claiming every service purged.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithMixedValidAndInvalidServicesReportsIdleServices() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ 'NoSuchService', Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$sent = $this->mockServiceResponses( [] );

		$logger = $this->createMock( LoggerInterface::class );
		// The invalid name produces one getServiceOrder warning and one job warning.
		$logger->expects( $this->exactly( 2 ) )->method( 'warning' );
		$this->setLogger( 'MultiPurge', $logger );

		$result = SpecialPurgeResources::trySubmit(
			$this->purgeFormData(),
			$this->createMock( OOUIHTMLForm::class )
		);

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame( 'multipurge-special-purge-no-work',
			$result->getErrorsByType( 'error' )[0]['message'] );
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[0]['params'] );
		$this->assertCount( 1, $sent->getArrayCopy(), 'The valid service was dispatched' );
		$this->assertStringContainsString(
			'cloudflare',
			$sent[0]['url'],
			'The dispatched request went to the valid service'
		);
		$this->assertSame( [], $this->popQueuedMultiPurgeJobs(), 'Nothing needed a retry' );
	}

	/**
	 * A vetoed service stays unqueued while a failed one is queued, and both facts are shown.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithVetoedAndFailedServicesQueuesOnlyTheFailedOne() {
		$this->enableBothServices();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service ): bool {
				return $service !== Cloudflare::class;
			}
		);

		$result = SpecialPurgeResources::trySubmit(
			$this->purgeFormData(),
			$this->createMock( OOUIHTMLForm::class )
		);

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-retry', 'multipurge-special-purge-no-work' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) ),
			'The vetoed service is disclosed next to the retry, never as queued'
		);
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[1]['params'] );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the failed service was queued' );
		$this->assertSame( Varnish::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * A lost retry enqueue must also disclose a service that sent nothing.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithVetoedAndLostRetryDisclosesIdle() {
		$this->enableBothServices();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );
		$this->installReadOnlyJobQueueGroupFactory();
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service ): bool {
				return $service !== Cloudflare::class;
			}
		);

		$result = SpecialPurgeResources::trySubmit(
			$this->purgeFormData(),
			$this->createMock( OOUIHTMLForm::class )
		);

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-error', 'multipurge-special-purge-no-work' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) )
		);
		// The read-only queue rejects pushes by construction, so nothing can sit in it to pop.
	}

	/**
	 * A failed service next to one that sent partial work discloses the omitted targets too.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithOmittedTargetsAndFailedServiceDisclosesBoth() {
		$this->enableBothServices();
		$this->mockServiceResponses( [ 'varnish.internal' => 500 ] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$urls ): bool {
				$urls = array_values( array_filter( $urls,
					static fn ( string $url ): bool => str_contains( $url, 'lang=de' ) ) );
				return true;
			}
		);

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'mode' => 'purge',
			'styles' => [ '/load.php?modules=site.styles&only=styles' ],
			'statics' => [ 'load.php?lang=de&modules=site.styles&only=styles&skin=citizen' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-retry', 'multipurge-special-purge-omitted' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) )
		);
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[1]['params'],
			'One selected target was not sent' );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the failed service was queued' );
		$this->assertSame( Varnish::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * The same service drops one target and its remaining request fails: the queued retry keeps
	 * the original parameters, so the dropped target may never be sent and must be disclosed.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithDroppedTargetAndFailedRequestDisclosesOmissionAlongsideRetry() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$this->mockServiceResponses( [ 'api.cloudflare' => 500 ] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$urls ): bool {
				$urls = array_values( array_filter( $urls,
					static fn ( string $url ): bool => str_contains( $url, 'lang=de' ) ) );
				return true;
			}
		);

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'mode' => 'purge',
			'styles' => [ '/load.php?modules=site.styles&only=styles' ],
			'statics' => [ 'load.php?lang=de&modules=site.styles&only=styles&skin=citizen' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-retry', 'multipurge-special-purge-omitted' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) ),
			'The omission stays visible next to the retry, without promising the target will be sent'
		);
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[1]['params'],
			'One selected target was not sent' );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'The failed service was queued as before' );
		$this->assertSame( Cloudflare::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * A rejected retry enqueue must still disclose the target the same service dropped.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithDroppedTargetAndLostRetryDisclosesOmission() {
		$this->overrideConfigValues( [
			'Server' => 'https://wiki.example',
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		$this->mockServiceResponses( [ 'api.cloudflare' => 500 ] );
		$this->installReadOnlyJobQueueGroupFactory();
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$urls ): bool {
				$urls = array_values( array_filter( $urls,
					static fn ( string $url ): bool => str_contains( $url, 'lang=de' ) ) );
				return true;
			}
		);

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'mode' => 'purge',
			'styles' => [ '/load.php?modules=site.styles&only=styles' ],
			'statics' => [ 'load.php?lang=de&modules=site.styles&only=styles&skin=citizen' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-error', 'multipurge-special-purge-omitted' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) )
		);
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[1]['params'] );
	}

	/**
	 * One service drops a target another service still sends: the omission is reported per service truthfully.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithOneServiceDroppingWhatAnotherSendsStillReportsOmission() {
		$this->enableBothServices();
		$sent = $this->mockServiceResponses( [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$urls ): bool {
				if ( $service !== Cloudflare::class ) {
					return true;
				}
				$urls = array_values( array_filter( $urls,
					static fn ( string $url ): bool => str_contains( $url, 'lang=de' ) ) );
				return true;
			}
		);

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'mode' => 'purge',
			'styles' => [ '/load.php?modules=site.styles&only=styles' ],
			'statics' => [ 'load.php?lang=de&modules=site.styles&only=styles&skin=citizen' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-omitted' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) )
		);
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[0]['params'],
			'The url one service dropped is one omission even though another service sent it' );
		$this->assertStringContainsString( 'by at least one enabled service',
			$result->getMessage()->inLanguage( 'en' )->text(),
			'The wording qualifies the omission by service' );
		$this->assertStringContainsString( 'mindestens einem aktivierten Dienst',
			$result->getMessage()->inLanguage( 'de' )->text(),
			'The German wording qualifies the omission by service' );
		$this->assertNotEmpty( array_filter(
			$sent->getArrayCopy(),
			static fn ( array $request ): bool => str_contains( $request['url'], 'modules=site.styles&only=styles' )
		), 'The other service did send the dropped target' );
	}

	/**
	 * One failed service queues while the next push is rejected: both outcomes are shown.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithOneQueuedAndOneRejectedRetryReportsBoth() {
		$this->enableBothServices();
		$this->mockServiceResponses( [ 'api.cloudflare' => 500, 'varnish.internal' => 500 ] );
		$this->installSinglePushJobQueue();

		$result = SpecialPurgeResources::trySubmit(
			$this->purgeFormData(),
			$this->createMock( OOUIHTMLForm::class )
		);

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-retry-mixed' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) ),
			'The mixed queue outcome is its own message, not the all-queued retry text'
		);

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'One failed service made it into the queue before the rejection' );
		$this->assertSame( Cloudflare::class, $jobs[0]->getParams()['service'] );
	}

	/**
	 * Two services dropping the same selected url are one omission, not two.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @return void
	 */
	public function testSubmitWithSameUrlOmittedByTwoServicesCountsDistinct() {
		$this->enableBothServices();
		$this->mockServiceResponses( [] );
		$this->setTemporaryHook( 'MultiPurgeOnPurgeService',
			static function ( string $service, array &$urls ): bool {
				$urls = array_values( array_filter( $urls,
					static fn ( string $url ): bool => str_contains( $url, 'lang=de' ) ) );
				return true;
			}
		);

		$result = SpecialPurgeResources::trySubmit( [
			'target' => 'Foo',
			'mode' => 'purge',
			'styles' => [ '/load.php?modules=site.styles&only=styles' ],
			'statics' => [ 'load.php?lang=de&modules=site.styles&only=styles&skin=citizen' ],
		], $this->createMock( OOUIHTMLForm::class ) );

		$this->assertInstanceOf( Status::class, $result );
		$this->assertStatusNotOK( $result );
		$this->assertSame(
			[ 'multipurge-special-purge-omitted' ],
			array_map( static fn ( array $error ): string => $error['message'],
				$result->getErrorsByType( 'error' ) )
		);
		$this->assertSame( [ 1 ], $result->getErrorsByType( 'error' )[0]['params'],
			'The one selected url the hook removed is counted once, not once per service' );
	}

	/**
	 * A rate-limited Cloudflare purge is handed off inside the job's run and reported as a
	 * queued retry, never as success, and only the failed chunk's targets are queued.
	 *
	 * @covers \MediaWiki\Extension\MultiPurge\Specials\SpecialPurgeResources::trySubmit
	 * @covers \MediaWiki\Extension\MultiPurge\MultiPurgeJob::run
	 * @return void
	 */
	public function testCloudflareFailureIsHandedOffAndReportedAsRetryNotSuccess() {
		$this->overrideConfigValues( [
			'MultiPurgeEnabledServices' => [ Cloudflare::class ],
			'MultiPurgeCloudFlareZoneId' => 'test-zone',
			'MultiPurgeCloudFlareApiToken' => 'test-token',
		] );
		[ $fixture ] = $this->setUpFormWiki();
		$this->mockSpecialPageHttp( $fixture, [ 'api.cloudflare' => 429 ] );

		[ $html ] = $this->submitPurgeForm( [
			'wpstyles' => [ self::STYLE_URL ],
		] );

		$this->assertStringContainsString( '(multipurge-special-purge-retry)', $html,
			'The handoff is reported as a queued retry' );
		$this->assertStringContainsString( 'mw-htmlform-ooui-header-errors', $html );
		$this->assertStringNotContainsString( '(multipurge-special-purge-success)', $html,
			'A handed-off retry is not a completed purge' );

		$jobs = $this->popQueuedMultiPurgeJobs();
		$this->assertCount( 1, $jobs, 'Only the replacement job is queued' );
		$this->assertTrue( $jobs[0]->getParams()['replacement'] );
		$this->assertSame( Cloudflare::class, $jobs[0]->getParams()['service'] );
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
			'mode' => 'purge',
			'styles' => [ '/load.php?modules=site.styles&only=styles' ],
		];
	}

	/**
	 * @param array<string, int> $responsesByUrl Response code for requests whose URL contains the key
	 * @return ArrayObject Sent requests
	 */
	private function mockServiceResponses( array $responsesByUrl ): ArrayObject {
		return $this->mockSpecialPageHttp( null, $responsesByUrl );
	}

	/**
	 * One HttpRequestFactory mock for both HTTP uses of the special page:
	 * fetching the rendered page and running the configured services.
	 *
	 * @param string|null $pageHtml HTML returned for the fetched page render, or null when no page is fetched
	 * @param array<string, int> $responsesByUrl Response code for requests whose URL contains the key
	 * @return ArrayObject Sent service requests
	 */
	private function mockSpecialPageHttp( ?string $pageHtml, array $responsesByUrl ): ArrayObject {
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
		if ( $pageHtml !== null ) {
			$httpMock->method( 'get' )->willReturn( $pageHtml );
		}
		$services = $this->getServiceContainer();
		// Earlier fixture work may already have activated the factory, so reset before redefining.
		$services->resetServiceForTesting( 'HttpRequestFactory' );
		$services->redefineService( 'HttpRequestFactory', static fn () => $httpMock );

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
	 * Real factories whose queues accept exactly one push batch, so one service queues and the next is rejected.
	 * The factory itself is rebuilt through core's service wiring to match the running version.
	 *
	 * @return void
	 */
	private function installSinglePushJobQueue(): void {
		// The accepted batch persists in the test-DB-backed queue and stays poppable; the next is rejected.
		SinglePushJobQueue::reset();
		$this->overrideConfigValue( MainConfigNames::JobTypeConf, [
			'default' => [ 'class' => JobQueueMemory::class ],
			'MultiPurgePages' => [ 'class' => SinglePushJobQueue::class ],
		] );
		$this->getServiceContainer()->resetServiceForTesting( 'JobQueueGroupFactory' );
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

	/**
	 * Uploads the fixture image, saves the fixture page, and returns the parser output.
	 * The image markup is genuine parser output, so the form must match what the parser really emits.
	 *
	 * @return array{0: string, 1: string[]} [ parser output HTML, img src attributes ]
	 */
	private function createParsedPage( string $pageText ): array {
		$tempFile = $this->getNewTempFile();
		file_put_contents( $tempFile, base64_decode( self::FIXTURE_PNG ) );

		$request = new FauxRequest( [], true );
		$request->setUpload( 'file', [
			'name' => self::FORM_FILE,
			'type' => 'image/png',
			'tmp_name' => $tempFile,
			'size' => filesize( $tempFile ),
			'error' => '',
		] );

		$user = $this->getTestSysop()->getUser();
		$upload = new UploadFromFile();
		$upload->initialize( self::FORM_FILE, $request->getUpload( 'file' ) );
		$this->assertStatusGood( $upload->performUpload( 'test upload', '', false, $user ) );

		$this->assertStatusGood( $this->editPage( self::FORM_PAGE, $pageText ) );
		$parserOutput = $this->getExistingTestPage( self::FORM_PAGE )
			->getParserOutput( ParserOptions::newFromAnon() );
		$this->assertNotFalse( $parserOutput );

		$bodyHtml = $parserOutput->getRawText();
		preg_match_all( '/<img [^>]*src="([^"]+)"/', $bodyHtml, $matches );
		$this->assertNotEmpty( $matches[1], 'The parser output contains the fixture thumbnail' );

		return [ $bodyHtml, $matches[1] ];
	}

	/**
	 * Builds the page HTML the special page fetches: skin load.php tags plus the parsed body.
	 *
	 * @return array{0: string, 1: string[]} [ page HTML, img src attributes ]
	 */
	private function setUpFormWiki( bool $withStyles = true, bool $withScripts = true ): array {
		// Server first, so parser image URLs land on the host the purge policy allows.
		$this->overrideConfigValue( 'Server', 'https://wiki.example' );
		// Uploads go to a throwaway directory; LocalFileRepo is what carries the storage location.
		$this->overrideConfigValue( 'LocalFileRepo', array_merge(
			$this->getServiceContainer()->getMainConfig()->get( 'LocalFileRepo' ),
			[ 'directory' => $this->getNewTempDirectory() ]
		) );
		// Both factories cache the old storage location, so both must see the new one.
		$this->getServiceContainer()->resetServiceForTesting( 'FileBackendGroup' );
		$this->getServiceContainer()->resetServiceForTesting( 'RepoGroup' );

		[ $bodyHtml, $thumbSrcs ] = $this->createParsedPage( '[[File:' . self::FORM_FILE . '|thumb]]' );
		// Fixture edits fire MultiPurge's own hooks; drain them so queue assertions cover only the submission.
		$this->popQueuedMultiPurgeJobs();

		$html = '';
		if ( $withStyles ) {
			$html .= '<link rel="stylesheet" href="' . $this->asHtml( self::STYLE_URL ) . '"/>';
		}
		if ( $withScripts ) {
			$html .= '<script src="' . $this->asHtml( self::SCRIPT_URL ) . '"></script>';
		}

		return [ $html . $bodyHtml, $thumbSrcs ];
	}

	/**
	 * The string as it appears inside a fetched HTML attribute, entity-encoded ampersands included.
	 */
	private function asHtml( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES );
	}

	/**
	 * Resolves offered option values the same way the submission must: expand against $wgServer.
	 * @return string[]
	 */
	private function resolveThroughPolicy( array $urls ): array {
		$services = $this->getServiceContainer();
		$policy = PurgeUrlPolicy::newFromConfigs(
			$services->getMainConfig(),
			$services->getConfigFactory()->makeConfig( 'MultiPurge' )
		);
		return array_map( static fn ( string $url ): string => (string)$policy->resolve( $url ), $urls );
	}

	/**
	 * Posts the purge form for the fixture subpage.
	 * The executor injects a matching edit token, which is how the real form flow is CSRF-checked.
	 *
	 * @param array<string, mixed> $data POST fields that override the defaults
	 * @return array{0: string, 1: \MediaWiki\Request\FauxResponse}
	 */
	private function submitPurgeForm( array $data, ?string $language = null ): array {
		// HTMLForm lowercases field names, so the POST uses wptarget, wpmode, and the group names.
		$request = new FauxRequest( $data + [
			'wptarget' => self::FORM_PAGE,
			'wpFormIdentifier' => 'purge-resources-form',
			'wpmode' => 'purge',
		], true );

		return $this->executeSpecialPage(
			self::FORM_PAGE,
			$request,
			$language,
			$this->getTestSysop()->getAuthority()
		);
	}
}

/**
 * Memory queue that accepts exactly one push batch, so one service queues while the next push is rejected.
 * The suite loader only includes *Test.php files, so this helper cannot live in its own file.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
class SinglePushJobQueue extends JobQueueDB {
	private static int $acceptedBatches = 0;

	public static function reset(): void {
		self::$acceptedBatches = 0;
	}

	protected function getDB( $index ) {
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		return $lb->getConnection( $index, [], $this->domain );
	}

	protected function doBatchPush( array $jobs, $flags ) {
		// JobQueueGroup::get() builds a fresh queue object per call, so the push limit must be class-wide.
		if ( self::$acceptedBatches >= 1 ) {
			throw new JobQueueReadOnlyError( 'test queue accepts only one push batch' );
		}
		self::$acceptedBatches++;
		parent::doBatchPush( $jobs, $flags );
	}
}
