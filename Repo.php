<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos;

abstract class Repo {
	public $root_path = '';

	public function __construct( string $root_path, array $transforms = [] ) {
		$this->root_path = $root_path;
		foreach ( $transforms as $transform ) {
			if ( $transform instanceof Transform ) {
				continue;
			}

			throw new \Exception( sprintf( '"%s" is not a valid \Compare_Files_In_Repos\Transform', get_class( $transform ) ) );
		}

		$this->transforms = $transforms;
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
