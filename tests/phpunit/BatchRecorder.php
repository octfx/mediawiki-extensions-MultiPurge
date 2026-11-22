<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\MultiPurge\Tests;

/**
 * Records what MultiPurgeJob hands to MultiHttpClient::runMulti() without reaching the network.
 * Retains every request map on purpose: the batching tests assert exact coverage over bounded inputs.
 * The separate-process memory probe uses its own client that retains nothing.
 */
class BatchRecorder {

	public int $calls = 0;

	/** @var int[] Size of each runMulti() call, in call order */
	public array $callSizes = [];

	/** @var array[] Every request map handed to the client, in submission order */
	public array $requests = [];

	/** @var callable Produces a response list for one request: ( int $call, int|string $index, array $request ) */
	private $responder;

	public function __construct( ?callable $responder = null ) {
		$this->responder = $responder ?? static fn (): array => [ 200, 'OK', [], 'ok', null ];
	}

	/**
	 * Stands in for runMulti(): one call gets one batch, and each request is answered by the responder.
	 * The responder may throw to simulate a transport-level failure of the whole call.
	 *
	 * @param array[] $requests
	 * @return array[]
	 */
	public function recordBatch( array $requests ): array {
		$this->calls++;
		$this->callSizes[] = count( $requests );

		$responses = [];
		foreach ( $requests as $index => $request ) {
			$response = ( $this->responder )( $this->calls, $index, $request );
			// Recorded only after answering, so a responder that throws marks the request as undelivered.
			$this->requests[] = $request;
			$request['response'] = $response;
			$responses[$index] = $request;
		}

		return $responses;
	}
}
