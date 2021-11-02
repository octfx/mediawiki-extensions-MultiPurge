<?php

declare(strict_types=1);

namespace MediaWiki\Extension\MultiPurge;

use GenericParameterJob;
use Job;
use MediaWiki\Extension\MultiPurge\Service\ServiceFactory;
use MediaWiki\MediaWikiServices;
use Status;

class MultiPurgeJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent::__construct( 'multiPurge', $params );
		$this->removeDuplicates = false;
	}

	public function run() {
		$purgeConfig = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'MultiPurge' );

		$services = $purgeConfig->get( 'MultiPurgeEnabledServices' );
		if ( empty( $services ) ) {
			return true;
		}

		$services = array_map( [ ServiceFactory::class, 'normalizeServiceName' ], $services );

		$serviceOrder = $purgeConfig->get( 'MultiPurgeServiceOrder' );
		if ( !empty( $serviceOrder ) ) {
			$serviceOrder = array_map( [ ServiceFactory::class, 'normalizeServiceName' ], $serviceOrder );
		}

		$enabled = array_intersect( $serviceOrder, $services );
		$factory = ServiceFactory::getInstance();

		$requests = [];

		foreach ( $enabled as $service ) {
			$requests[] = $factory->getPurgeService( $service )->getPurgeRequest( $this->params['urls'] );
		}

		$statuses = [];
		foreach ( $requests as $request ) {
			$statuses[] = $request->execute();
		}

		return array_reduce( $statuses, static function ( bool $carry, Status $status ) {
			if (!$status->isGood()) {
				wfLogWarning($status->getMessage()->plain());
			}

			return $carry && $status->isGood();
		}, true );
	}
}
