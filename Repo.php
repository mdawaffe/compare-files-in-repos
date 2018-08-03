<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos;

abstract class Repo {
	use \Psr\Log\LoggerAwareTrait;

	public $root_path = '';
	protected $options = [];

	public function __construct( string $root_path, array $transforms = [] ) {
		$this->logger = new \Psr\Log\NullLogger;

		$this->root_path = rtrim( $root_path, '/' );
		foreach ( $transforms as $transform ) {
			if ( $transform instanceof Transform ) {
				continue;
			}

			throw new \Exception( sprintf( '"%s" is not a valid \Compare_Files_In_Repos\Transform', get_class( $transform ) ) );
		}

		$this->transforms = $transforms;
	}

	protected static function normalize_datetime( string $datetime ) : string {
		return gmdate( 'c', strtotime( $datetime ) );
	}

	protected function option_string( array $maybe_overwrite = [] ) {
		$options = array_merge( $this->options, array_intersect_key( $maybe_overwrite, $this->options ) );

		return join( ' ', array_map( function( $name, $value ) {
			if ( is_null( $value ) ) {
				return sprintf(
					'--%s',
					escapeshellarg( str_replace( '_', '-', $name ) )
				);
			}

			return sprintf(
				'--%s %s',
				escapeshellarg( str_replace( '_', '-', $name ) ),
				escapeshellarg( str_replace( '_', '-', $value ) )
			);
		}, array_keys( $options ), array_values( $options ) ) );
	}

	public function set_options( array $options ) : array {
		$old = $this->options;
		$this->options = $options;
		return $old;
	}

	private function apply_transforms( string $file_contents, string $file_path ) : string {
		return array_reduce( $this->transforms, function( string $file_contents, $transform ) use ( $file_path ) : string {
			return $transform->transform( $file_contents, $file_path );
		}, $file_contents );
	}

	public function get_file( string $file_path, string $revision = null ) {
		try {
			$file_contents = $this->contents_of_file_at_revision( $file_path, $revision );
		} catch ( Exception $e ) {
			return false;
		}

		return $this->apply_transforms( $file_contents, $file_path );
	}

	abstract public function is_slow() : bool;

	abstract public function revision_of_file( string $file_path ) : string;

	abstract public function meta_data_of_revision( string $revision ) : array;

	abstract protected function contents_of_file_at_revision( string $file_path, string $revision = null ) : string;

	abstract public function is_ignored( string $file_path ) : bool;

	abstract public function revisions_of_file( string $file_path ) : iterable;
}
