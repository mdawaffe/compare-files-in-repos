<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos\Transform;

class Substitute extends \Compare_Files_In_Repos\Transform {
	private $substitutions = [];

	public function __construct( array $substitutions, callable $file_matcher = null ) {
		$this->substitutions = $substitutions;
		$this->file_matcher = $file_matcher;
	}

	public function transform( string $file_contents, string $file_path ) : string {
		if ( $this->file_matcher && ! call_user_func( $this->file_matcher, $file_path ) ) {
			return $file_contents;
		}

		return preg_replace(
			array_keys( $this->substitutions ),
			array_values( $this->substitutions ),
			$file_contents
		);
	}
}
