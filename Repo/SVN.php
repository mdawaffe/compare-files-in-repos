<?php

declare( strict_types = 1 );

namespace Compare_Files_In_Repos\Repo;

class SVN extends \Compare_Files_In_Repos\Repo {
	private $options = [];
	private $executable = 'svn';
	private $path_prefix = '/';

	public function __construct( string $root_path, array $transforms = [] ) {
		parent::__construct( $root_path, $transforms );

		$xml = $this->exec( sprintf(
			'info --xml %s',
			escapeshellarg( $this->root_path )
		), $status );

		if ( 0 !== $status ) {
			throw new \Exception( "Could not find an SVN Repo at '%s'", $this->root_path );
		}

		$entity_loader = libxml_disable_entity_loader( true );

		$info = simplexml_load_string( $xml );

		$url = (string) $info->entry->url;
		$root = (string) $info->entry->repository->root;

		$this->path_prefix = substr( $url, strlen( $root ) ) . '/';

		libxml_disable_entity_loader( $entity_loader );
	}

	private function exec( $command, &$status = null ) {
		$real_command = join( ' ', array_filter( [ $this->executable, $this->option_string(), $command ] ) );
		$redacted_command = join( ' ', array_filter( [ $this->executable, $this->option_string( [ 'password' => 'REDACTED' ] ), $command ] ) );

		$exec = proc_open( $real_command, [
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		], $pipes, $this->root_path );
		
		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );
		
		$status = proc_close( $exec );

		$this->logger->debug( $redacted_command, compact( 'status' ) );
		
		if ( trim( $error ) ) {
			$command = $redacted_command;
			$this->logger->warning( $error, compact( 'command', 'status' ) );
		}
		
		return $output;
	}

	private function option_string( array $maybe_overwrite = [] ) {
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

	public function set_executable( string $executable = 'svn' ) : string {
		$old = $this->executable;
		$this->executable = $executable;
		return $old;
	}

	public function is_slow() : bool {
		static $is_slow;
		if ( isset( $is_slow ) ) {
			return $is_slow;
		}

		$xml = $this->exec( 'info --xml' );
		
		$entity_loader = libxml_disable_entity_loader( true );

		$info = simplexml_load_string( $xml );
		$is_slow = 0 !== strpos( (string) $info->entry->url, 'file://' );

		libxml_disable_entity_loader( $entity_loader );

		return $is_slow;
	}

	public function revision_of_file( string $file_path ) : string {
		$rev = trim( $this->exec( sprintf(
			'info %s | grep "Last Changed Rev:"',
			escapeshellarg( $file_path )
		) ) );

		return explode( ': ', $rev )[1];
	}

	public function meta_data_of_revision( string $revision ) : array {
		$xml = $this->exec( sprintf(
			'log --xml -r %s',
			escapeshellarg( $revision )
		) );

		$entity_loader = libxml_disable_entity_loader( true );

		$log = simplexml_load_string( $xml );
		$logentry = $log->logentry;

		$data = [
			'author' => trim( (string) $logentry->author ),
			'date' => trim( (string) $logentry->date ),
			'message' => trim( (string) $logentry->msg ),
		];

		libxml_disable_entity_loader( $entity_loader );

		return $data;
	}

	protected function contents_of_file_at_revision( string $file_path, string $revision = null ) : string {
		if ( ! $revision ) {
			$contents = file_get_contents( $this->root_path . '/' . $file_path );
			if ( false === $contents ) {
				throw new Exception( sprintf( 'File "%s" does not exist', $file_path ) );
			}

			return $contents;
		}

		$contents = $this->exec( sprintf(
			'cat -r %s %s',
			escapeshellarg( $revision ),
			escapeshellarg( $file_path )
		), $status );

		if ( 0 !== $status ) {
			throw new Exception( sprintf( 'File "%s" does not exist at revision %s', $file_path, $revision ) );
		}

		return $contents;
	}

	public function is_ignored( string $file_path ) : bool {
		$path_pieces = explode( '/', $file_path );
		if ( in_array( '.svn', $path_pieces, true ) ) {
			$this->logger->debug( sprintf( 'Ignoring .svn file: %s', $file_path ) );
			return true;
		}

		// Doesn't work for files that do not exist
		$svn_status_flag = substr( $this->exec( sprintf(
			'status %s',
			escapeshellarg( $this->root_path . '/' . $file_path )
		) ), 0, 1 );

		return 'I' === $svn_status_flag;
	}

	public function revisions_of_file( string $file_path ) : iterable {
		$limit = 100;

		$revision = 'BASE';
		$previous_file_path = $file_path;

		do {
			$xml = $this->exec( sprintf(
				'log --verbose --quiet --xml --limit %d %s@%s',
				$limit,
				escapeshellarg( $file_path ),
				escapeshellarg( (string) $revision )
			), $status );

			if ( 0 !== $status ) {
				break;
			}

			$entity_loader = libxml_disable_entity_loader( true );

			$log = simplexml_load_string( $xml );
			$revisions = [];
			foreach ( $log->logentry as $logentry ) {
				$revision = (int) $logentry['revision'];
				foreach ( $logentry->paths->path as $path ) {
					$the_path = substr( (string) $path, strlen( $this->path_prefix ) );
					if ( $the_path === $file_path && isset( $path['copyfrom-path'] ) ) {
						$previous_file_path = substr( (string) $path['copyfrom-path'], strlen( $this->path_prefix ) );
					}
				}

				$revisions[] = [
					$revision,
					$file_path,
				];

				$file_path = $previous_file_path;
			}

			libxml_disable_entity_loader( $entity_loader );

			foreach ( $revisions as [ $revision, $file_path ] ) {
				yield [ (string) $revision, $file_path ];
			}

			$revision = $revision - 1;
		} while ( count( $revisions ) === $limit && 0 < $revision );
	}
}
