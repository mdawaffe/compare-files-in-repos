<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos;

abstract class Transform {
	use \Psr\Log\LoggerAwareTrait;

	public function __construct() {
		$this->logger = new \Psr\Log\NullLogger;
	}

	abstract public function transform( string $contents, string $path ) : string;
}
